# ADR-0002: Keep `ux-search` and `search-bundle` as separate packages

**Status:** Superseded by [ADR-0003](ux-search-absorption.md)
**Date:** 2026-08-15
**Deciders:** Tac Tacelosky

## What this proposed

Retire the `tacman/ux-search` soft-fork by making it a *real* fork — rename
`Mezcalito\UxSearchBundle` to `Survos\UxSearchBundle`, publish it as `survos/ux-search`,
and move it into mono as `bu/ux-search-bundle` — while keeping it a separate package from
`survos/search-bundle`.

The argument was that the two halves were genuinely different (a Survos-free query kernel
and UI, versus field-bundle-driven autowiring and index-owning adapters), that the stated
driver for merging was already disproven (the Elasticsearch adapter had been added
*across* the package boundary via `UxSearchAdapterPass`, so the extension point demonstrably
worked), and that the real pain — two release cycles — was a build-process problem that
mono's `monorepo-builder` already solves.

## Why it was superseded

ADR-0003 took the absorption path instead, and its mandatory pre-flight was run and
passed: no consumer wants the UI layer without SearchBundle. With one package, the
layering concern is better served by an internal invariant than by a package boundary,
and re-splitting would cost a second consumer migration on top of the one already owed.

The durable content of this ADR — where to place a new adapter, and which parts of the
merged bundle must stay Survos-free — survives in [adapters.md](adapters.md).
