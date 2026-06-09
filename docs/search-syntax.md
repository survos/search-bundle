# SQLite FTS5 search syntax

The `SqliteFts5Adapter` does **not** pass the raw search box straight to FTS5.
Raw user input — especially the partial strings produced on every keystroke by
the live component — frequently isn't valid FTS5 and would throw
`fts5: syntax error near "…"`, killing the page. Instead,
[`Fts5MatchQuery::build()`](../src/Adapter/SqliteFts5/Fts5MatchQuery.php)
rewrites the input into an expression that is **always** syntactically valid.

## What users can type

| Intent | Type | Becomes (the MATCH expression) |
|---|---|---|
| word | `chess` | `"chess"` |
| both words | `gold silver` | `"gold" AND "silver"` |
| either | `gold or silver` | `"gold" OR "silver"` |
| exclude | `textile not velvet` | `"textile" NOT "velvet"` |
| grouping | `chess not (king or queen)` | `"chess" NOT ("king" OR "queen")` |
| prefix | `victoria*` | `"victoria"*` |

### Operators are case-insensitive

`and` / `or` / `not` work in any case — `chess not (king or queen)` is identical
to `chess NOT (king OR queen)`. (FTS5 itself only honours uppercase operators;
the builder promotes them so users don't have to know that.)

### Things that "just work" so the page never 500s

- **Mid-typing** — `chess (NOT`, `textile not `, a lone `"`, `gold or` → the
  dangling/leading parts are dropped, leaving a valid query.
- **Unbalanced parens** — extra `)` is dropped, unclosed `(` is closed.
- **`and not`** collapses to `NOT` (`textile and not velvet` →
  `"textile" NOT "velvet"`); FTS5 has no combined "AND NOT".
- Adjacent operands always get an **explicit `AND`** — FTS5 rejects implicit AND
  next to a group (`"a" ("b")` is a syntax error), so the builder never emits it.

## `#` — raw escape hatch

Prefix the query with `#` to send the rest to FTS5 **verbatim**, unsanitized.
Use it to test real FTS5 syntax the builder doesn't generate, e.g. proximity:

```
# NEAR("sea" "shell", 5)
# "chess piece" NEAR/3 ivory
```

A malformed raw expression is caught by the adapter and returns an **empty result
set** (with intact facets) rather than a 500. Genuine non-FTS SQL errors still
propagate, so real bugs stay loud.

> `NEAR` is intentionally *not* a sanitized operator: FTS5 has no infix
> `a NEAR b` form (it needs `NEAR(… , n)`), so a bare `NEAR` is treated as a
> literal word. Use the `#` hatch for proximity searches.

## Tests

`tests/Adapter/SqliteFts5/Fts5MatchQueryTest.php` covers the exact-output
contract, an end-to-end semantics check, and a property test that runs every
sanitized expression against a real in-memory FTS5 table to prove it never
throws. Run with any app's PHPUnit, e.g.:

```bash
cd bu/search-bundle && /home/tac/sites/zm/vendor/bin/phpunit
```
