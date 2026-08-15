# ADR-0003: Absorb `survos/ux-search` into `survos/search-bundle`

**Status:** Accepted; pre-flight validated
**Date:** 2026-08-15
**Deciders:** Tac Tacelosky

## Context

`survos/ux-search` (branch `1.x`) is currently a soft-fork of
[`Mezcalito/ux-search`](https://github.com/Mezcalito/ux-search), published as
`tacman/ux-search`, replacing `mezcalito/ux-search` in Composer, but still keeping the
original `Mezcalito\UxSearchBundle` namespace. The README frames it as temporary: it
exists only to carry two patches while they are in review and says it will be marked
abandoned once upstream merges and releases them.

That framing no longer matches reality:

- Upstream has gone quiet on our issues and pull requests, so there is no realistic path
  to reconciliation where the patches land and the fork retires.
- Elasticsearch is a real new adapter and not merely a patch carried under review.
- We already publish our own 1.x point releases, so consumers are pinned to us in
  practice.
- Keeping the `Mezcalito\UxSearchBundle` namespace while diverging materially is
  misleading and prevents clean coexistence with the upstream package.

`survos/search-bundle` currently depends on `survos/ux-search`. Maintaining two
repositories, release cycles, and Packagist packages for one logical unit is unnecessary
unless something other than `search-bundle` consumes `ux-search` directly.

## Decision

Absorb `ux-search` into `search-bundle` as an internal component, crediting Mezcalito UX
Search as the origin and inspiration in the README. Retire the standalone
`survos/ux-search` package.

This decision supersedes ADR-0002 and the separate-fork plan if the pre-flight check
confirms that SearchBundle is the only real consumer. If another project genuinely uses
only the UI layer, stop and use the alternative real-fork path instead.

## Mandatory pre-flight

**Result:** Validated on 2026-08-15. The direct local consumers (`zm`, `openfoto`, and `harvest`) also require SearchBundle and use its integration layer; no independent UI-only consumer was found. Packagist reported one dependent for `tacman/ux-search`, consistent with SearchBundle. The absorption path therefore applies.

- Search all known Survos projects and Packagist for requirements on
  `survos/ux-search`, `tacman/ux-search`, or `mezcalito/ux-search` outside
  `survos/search-bundle`.
- Confirm SearchBundle's dependency and constraint.
- If any independent UI-only consumer exists, stop absorption and retain a separate
  package under the `Survos\UxSearchBundle` namespace and `survos/ux-search` name.

## Migration

1. Move the fork's PHP, assets, templates, translations, configuration, documentation,
   and tests into SearchBundle under the `Survos\SearchBundle` namespace.
2. Rename PHP namespaces, service IDs, configuration keys, translation domains, Twig
   component names, and template override paths.
3. Merge PHP and Node dependencies and build configuration; remove the standalone
   ux-search Composer requirement.
4. Keep the Elasticsearch adapter in SearchBundle as the first native Survos adapter.
5. Port the upstream-derived test suite into SearchBundle and run it in SearchBundle CI.
6. Replace soft-fork language with permanent first-party documentation and credit:
   “UX layer inspired by [Mezcalito UX Search](https://github.com/Mezcalito/ux-search).”
7. Update all consumers to require SearchBundle and use its new namespace and component
   names.
8. Release an appropriately versioned SearchBundle, then archive or redirect the
   standalone repository and mark `tacman/ux-search` abandoned in favor of
   `survos/search-bundle`.

## Alternative path

If pre-flight identifies a genuine consumer of only the UI layer, keep the package
separate as a real fork:

- namespace it `Survos\UxSearchBundle`;
- publish it as `survos/ux-search` rather than replacing `mezcalito/ux-search`;
- remove all temporary soft-fork language and retain clear upstream credit; and
- have SearchBundle depend on it through a normal version constraint.

## Consequences

- SearchBundle becomes the single installable and releasable unit for the query kernel,
  UI, field integration, and index-owning adapters.
- SearchBundle acquires the fork's Node build and direct PHP dependencies.
- Existing application overrides and configuration require a deliberate namespace
  migration.
- Retirement actions occur only after consumer migration and milestone review.
