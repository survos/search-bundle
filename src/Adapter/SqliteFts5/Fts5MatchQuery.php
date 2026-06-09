<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Adapter\SqliteFts5;

/**
 * Builds a syntactically valid FTS5 MATCH expression from arbitrary user input.
 *
 * The search runs on every keystroke (live component), so partial and malformed
 * input is the norm — `textile NOT` mid-type, a lone `"`, an unclosed `(`.
 * Passing those straight to MATCH throws `fts5: syntax error near ""`. We
 * tokenize into phrases, boolean operators and parentheses, then re-emit a
 * structurally valid expression:
 *
 *   - every bare term becomes a quoted phrase (punctuation inside a phrase can
 *     never be a syntax error), with a trailing `*` kept as a prefix; adjacent
 *     operands are joined with an explicit AND (FTS5 forbids implicit AND next
 *     to a group);
 *   - and/or/not are treated as operators regardless of case (FTS5 only honours
 *     uppercase, so we promote them — see {@see matchOperator()}), and `(` `)`
 *     group, so `chess not (king or queen)` works as written;
 *   - a doubled operator ending in NOT ("and not") collapses to NOT, since FTS5
 *     has no "AND NOT"; other leading, doubled and dangling operators are
 *     dropped, empty groups are removed, and unbalanced `(` are closed.
 *
 * Returns '' when nothing usable remains; callers then skip FTS (browse).
 */
final class Fts5MatchQuery
{
    public static function build(string $query): string
    {
        $query = trim($query);

        // Raw escape hatch: a leading '#' passes the rest straight to FTS5,
        // unsanitized, for testing real FTS5 syntax. A malformed raw expression
        // can throw — the adapter swallows fts5 errors so the page survives.
        if (str_starts_with($query, '#')) {
            return trim(substr($query, 1));
        }

        // Parentheses are structural tokens; split everything else on whitespace.
        $spaced = preg_replace('/([()])/', ' $1 ', $query);
        $tokens = preg_split('/\s+/', (string) $spaced, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Stack of open groups (root at index 0). Each entry is a list of
        // ['type' => 'op'|'operand', 'sql' => string].
        $stack = [[]];

        foreach ($tokens as $token) {
            if ($token === '(') {
                $stack[] = [];
                continue;
            }

            if ($token === ')') {
                if (count($stack) === 1) {
                    continue; // unmatched ) — drop it
                }
                $inner = self::closeGroup(array_pop($stack));
                if ($inner !== null) {
                    self::pushOperand($stack, '(' . $inner . ')');
                }
                continue;
            }

            $operator = self::matchOperator($token);
            if ($operator !== null) {
                $i = count($stack) - 1;
                $lastType = $stack[$i] === [] ? null : end($stack[$i])['type'];
                if ($lastType === 'operand') {
                    // Normal case: operator with a left operand.
                    $stack[$i][] = ['type' => 'op', 'sql' => $operator];
                } elseif ($lastType === 'op' && $operator === 'NOT') {
                    // Collapse a doubled operator ending in NOT ("and not",
                    // "or not") into the stronger NOT — FTS5 has no "AND NOT".
                    $stack[$i][array_key_last($stack[$i])]['sql'] = 'NOT';
                }
                // else: leading operator, post-`(` operator, or a non-NOT
                // doubled operator — drop it.
                continue;
            }

            $prefix = '';
            if (str_ends_with($token, '*')) {
                $token = rtrim($token, '*');
                $prefix = '*';
            }

            // Strip stray quotes; the tokenizer ignores them inside a phrase anyway.
            $term = str_replace('"', '', $token);
            if ($term === '') {
                continue;
            }

            self::pushOperand($stack, '"' . $term . '"' . $prefix);
        }

        // Close any groups left open by an unbalanced `(`.
        while (count($stack) > 1) {
            $inner = self::closeGroup(array_pop($stack));
            if ($inner !== null) {
                self::pushOperand($stack, '(' . $inner . ')');
            }
        }

        return self::closeGroup($stack[0]) ?? '';
    }

    /**
     * Recognise a boolean operator token, returning its canonical (uppercase)
     * form or null. and/or/not are promoted from any case (FTS5 only honours
     * uppercase, so lowercase would otherwise be searched as literal words).
     *
     * NEAR is intentionally NOT an operator here: FTS5 has no infix `a NEAR b`
     * form (it needs the `NEAR(phrase ..., n)` call syntax), so promoting it
     * would build a valid-but-empty query. Proximity searches go through the
     * `#` raw escape hatch instead; a bare "near" stays a literal search word.
     */
    /**
     * Append an operand (phrase or `(group)`) to the current group, inserting an
     * explicit AND when it would otherwise sit directly after another operand.
     * FTS5 permits implicit AND between bare phrases but NOT when a parenthesized
     * group is adjacent (`"a" ("b")` is a syntax error), so we always make it
     * explicit — uniform and equivalent.
     *
     * @param non-empty-list<list<array{type: string, sql: string}>> $stack
     */
    private static function pushOperand(array &$stack, string $sql): void
    {
        $i = count($stack) - 1;
        if ($stack[$i] !== [] && end($stack[$i])['type'] === 'operand') {
            $stack[$i][] = ['type' => 'op', 'sql' => 'AND'];
        }
        $stack[$i][] = ['type' => 'operand', 'sql' => $sql];
    }

    private static function matchOperator(string $token): ?string
    {
        $upper = strtoupper($token);

        return in_array($upper, ['AND', 'OR', 'NOT'], true) ? $upper : null;
    }

    /**
     * Render one group's tokens to FTS5, dropping trailing dangling operators.
     * Returns null when nothing usable remains (e.g. an empty `()`).
     *
     * @param list<array{type: string, sql: string}> $group
     */
    private static function closeGroup(array $group): ?string
    {
        while ($group !== [] && end($group)['type'] === 'op') {
            array_pop($group);
        }
        if ($group === []) {
            return null;
        }

        return implode(' ', array_map(static fn (array $item): string => $item['sql'], $group));
    }
}
