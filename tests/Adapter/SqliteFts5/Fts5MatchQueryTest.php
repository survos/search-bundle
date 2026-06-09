<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Tests\Adapter\SqliteFts5;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\SearchBundle\Adapter\SqliteFts5\Fts5MatchQuery;

final class Fts5MatchQueryTest extends TestCase
{
    /**
     * The exact MATCH expression produced for representative inputs. This is the
     * contract the rest of the search adapter depends on.
     */
    #[DataProvider('provideExpressions')]
    public function testBuildProducesExpectedExpression(string $input, string $expected): void
    {
        self::assertSame($expected, Fts5MatchQuery::build($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideExpressions(): iterable
    {
        // --- terms become quoted phrases; adjacent terms are implicit AND ---
        yield 'single term' => ['chess', '"chess"'];
        yield 'adjacent terms get explicit AND' => ['gold silver', '"gold" AND "silver"'];
        yield 'prefix wildcard' => ['victoria*', '"victoria"*'];
        yield 'prefix mid-list' => ['gold* silver', '"gold"* AND "silver"'];
        yield 'term then group' => ['gold (silver or bronze)', '"gold" AND ("silver" OR "bronze")'];

        // --- and/or/not are promoted from any case ---
        yield 'uppercase operators' => ['cat AND dog', '"cat" AND "dog"'];
        yield 'lowercase or' => ['gold or silver', '"gold" OR "silver"'];
        yield 'lowercase not' => ['textile not velvet', '"textile" NOT "velvet"'];
        yield 'grouped lowercase' => ['chess not (king or queen)', '"chess" NOT ("king" OR "queen")'];
        yield 'mixed case' => ['Chess Not (King Or Queen)', '"Chess" NOT ("King" OR "Queen")'];
        yield 'uppercase grouped' => ['chess NOT (king OR queen)', '"chess" NOT ("king" OR "queen")'];

        // --- "and not" / "or not" collapse to the stronger NOT (no FTS5 "AND NOT") ---
        yield 'and not collapses' => ['textile and not velvet', '"textile" NOT "velvet"'];
        yield 'or not collapses' => ['gold or not silver', '"gold" NOT "silver"'];
        yield 'not not idempotent' => ['a not not b', '"a" NOT "b"'];
        yield 'doubled and keeps first' => ['a and and b', '"a" AND "b"'];

        // --- NEAR is not an infix operator: it stays a literal word ---
        yield 'near is literal' => ['near east', '"near" AND "east"'];
        yield 'NEAR uppercase literal' => ['cat NEAR dog', '"cat" AND "NEAR" AND "dog"'];

        // --- grouping & nesting ---
        yield 'two groups' => ['(a or b) not (c or d)', '("a" OR "b") NOT ("c" OR "d")'];

        // --- partial / malformed input never produces a broken expression ---
        yield 'empty' => ['', ''];
        yield 'whitespace' => ['   ', ''];
        yield 'lone quote' => ['"', ''];
        yield 'trailing operator' => ['textile NOT', '"textile"'];
        yield 'trailing operator lower' => ['textile not ', '"textile"'];
        yield 'leading operator' => ['and gold', '"gold"'];
        yield 'operator only' => ['or', ''];
        yield 'dangling open paren' => ['chess (NOT', '"chess"'];
        yield 'unmatched close paren' => ['a ) b', '"a" AND "b"'];
        yield 'empty group' => ['( )', ''];
        yield 'empty group no space' => ['()', ''];
        yield 'stray quote in term' => ['a"b', '"ab"'];

        // --- the `#` raw escape hatch passes through verbatim ---
        yield 'raw hatch' => ['# chess AND (king OR queen)', 'chess AND (king OR queen)'];
        yield 'raw hatch trims' => ['  # raw passthrough  ', 'raw passthrough'];
        yield 'raw hatch can be invalid' => ['# bogus ((', 'bogus (('];
    }

    /**
     * The core guarantee: for *sanitized* (non-`#`) input, no matter how broken,
     * the produced expression is always accepted by FTS5 — the page never 500s
     * mid-keystroke.
     */
    #[DataProvider('provideNastyInput')]
    public function testSanitizedExpressionAlwaysExecutes(string $input): void
    {
        $pdo = self::fts5Connection();
        $match = Fts5MatchQuery::build($input);

        if ($match === '') {
            $this->expectNotToPerformAssertions();

            return; // empty => caller skips FTS entirely
        }

        try {
            $stmt = $pdo->prepare('SELECT count(*) FROM t WHERE t MATCH :m');
            $stmt->execute(['m' => $match]);
            $stmt->fetchColumn();
        } catch (PDOException $e) {
            self::fail(sprintf('Input %s produced invalid FTS5 %s: %s', var_export($input, true), var_export($match, true), $e->getMessage()));
        }

        self::assertTrue(true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNastyInput(): iterable
    {
        $cases = [
            '', '   ', '"', '""', '(', ')', '()', '(((', ')))', ')(', '* *', '-', ':',
            'foo: bar', 'a"b"c', "l'art naïve", 'café AND thé',
            'chess (NOT', 'chess not (king or', 'a (b not ', '(a or b',
            'textile and not velvet', 'a AND', 'OR', 'not not not', 'and or not',
            'gold* (silver* or bronze*)', '((nested))', 'a ) b ( c',
        ];
        foreach ($cases as $i => $case) {
            yield sprintf('#%d %s', $i, var_export($case, true)) => [$case];
        }
    }

    /**
     * End-to-end semantics: `chess not (king or queen)` keeps plain chess rows
     * and excludes anything also matching king or queen — the bug that let
     * "Chess Piece: Queen" leak back in via operator precedence.
     */
    public function testGroupedNotExcludesAlternatives(): void
    {
        $pdo = self::fts5Connection();
        $pdo->exec("INSERT INTO t(rowid, b) VALUES
            (1, 'chess opening theory'),
            (2, 'chess king safety'),
            (3, 'chess piece queen'),
            (4, 'checkers strategy')");

        $match = Fts5MatchQuery::build('chess not (king or queen)');
        self::assertSame('"chess" NOT ("king" OR "queen")', $match);

        $stmt = $pdo->prepare('SELECT rowid FROM t WHERE t MATCH :m ORDER BY rowid');
        $stmt->execute(['m' => $match]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([1], array_map('intval', $rows), 'only plain-chess row 1 should survive');
    }

    private static function fts5Connection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $pdo->exec('CREATE VIRTUAL TABLE t USING fts5(b)');
        } catch (PDOException) {
            self::markTestSkipped('SQLite FTS5 extension is not available');
        }

        return $pdo;
    }
}
