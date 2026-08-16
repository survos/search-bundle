# Consumer migration after the ux-search absorption

**Status:** DONE. mono, openfoto, harvest, zm and mediary are all migrated.
Remaining: retire the package on Packagist, and the three follow-ups at the bottom.
**Delete this file when the apps are migrated and the package is retired.**

**mediary was a fifth consumer, missed by the original sweep** (migrated 2026-08-16).
The grep in "Done — the three apps" below only covered `{zm,openfoto,harvest}`. Widen it
to all of `~/sites/*` before believing this file again.

[ADR-0003](ux-search-absorption.md) absorbed `tacman/ux-search` into SearchBundle but
deferred its own migration steps 7 and 8 ("Retirement actions occur only after consumer
migration and milestone review"). This is that work. Until it's done, `zm`, `openfoto`, and
`harvest` are broken against mono HEAD: they require a package that no longer backs
anything and reference classes that no longer exist.

## The rename table

| Before | After |
|---|---|
| `tacman/ux-search` (Composer) | *(nothing — folded into `survos/search-bundle`)* |
| `Mezcalito\UxSearchBundle\…` | `Survos\SearchBundle\…` |
| `Mezcalito\UxSearchBundle\MezcalitoUxSearchBundle` (bundles.php) | *(remove — SearchBundle is already registered)* |
| `<twig:Mezcalito:UxSearch:Layout>` | `<twig:Survos:Search:Layout>` |
| `{% extends '@!MezcalitoUxSearch/…' %}` | `{% extends '@!SurvosSearch/…' %}` |
| `templates/bundles/MezcalitoUxSearchBundle/` | `templates/bundles/SurvosSearchBundle/` |
| `config/packages/mezcalito_ux_search.yaml` | `config/packages/survos_search.yaml` |
| `mezcalito_ux_search:` config root, `mezcalito_ux_search.*` parameters | `survos_search:` / `survos_search.*` |
| `MEZCALITO_UX_SEARCH_DEFAULT_DSN` env var | rename to taste, e.g. `SEARCH_DEFAULT_DSN` |
| `domain='mezcalito_ux_search'` | `translations/survos_search.{en,fr}.php` |

## Done — mono

- [x] `bu/folio-bundle` — imports in `Search/FolioRowSearch.php`; the
      `class_exists(\Mezcalito\…\AbstractSearch)` guard in `SurvosFolioBundle.php`, which was
      silently preventing `FolioRowSearch` from registering at all; the
      `<twig:Mezcalito:UxSearch:Layout>` tag in `templates/folio/search.html.twig`; and the
      `suggest` entry, which had become a duplicate of the hard `require` on search-bundle.
- [x] `bu/search-bundle` — `Maker/MakeSearch.php` pointed new users at upstream's docs URL;
      `docs/ux-search/create-own-adapter.md` sent contributors to upstream's issue tracker.

## Done — the three apps

Re-run the grep rather than trusting any list:

```bash
grep -rni 'mezcalito\|tacman/ux-search' ~/sites/{zm,openfoto,harvest} \
  | grep -vE '/(vendor|var|node_modules|\.idea)/'
```

**zm**
- [x] `composer.json` — drop `tacman/ux-search: ^1.0.10`; also carries a `repositories`
      entry for `github.com/survos/search-bundle.git`
- [x] `config/packages/mezcalito_ux_search.yaml` — rename file and config root; its
      `default:` reads `%env(MEZCALITO_UX_SEARCH_DEFAULT_DSN)%`
- [x] `.env` lines 74–75 — the `MEZCALITO_UX_SEARCH_DEFAULT_DSN=doctrine://default` var and
      the comment above it
- [x] `src/Search/DatasetArtifactSearch.php` (5 imports), `src/Search/FolioRowSearch.php` (4)
- [x] `templates/bundles/MezcalitoUxSearchBundle/{Hits.html.twig,Facet/RefinementList.html.twig}`
      → `SurvosSearchBundle/`. `RefinementList` uses the parent-template form
      `{% extends '@!MezcalitoUxSearch/Facet/RefinementList.html.twig' %}`; `Hits` uses
      `domain='mezcalito_ux_search'`
- [x] `templates/search/index.html.twig`, `templates/home/index.html.twig` — `<twig:Mezcalito:UxSearch:Layout>`
- [x] `docs/landing-page-plan.md`, `docs/command/survosSearchCreate.md` — prose references

**openfoto** — has a template override too, contrary to an earlier draft of this file:
- [x] `composer.json` — drop `tacman/ux-search: ^1.0.10`
- [x] `config/bundles.php:37` — remove the `MezcalitoUxSearchBundle` registration
- [x] `config/packages/mezcalito_ux_search.yaml`
- [x] `templates/bundles/MezcalitoUxSearchBundle/Hits.html.twig` — also uses
      `domain='mezcalito_ux_search'`

**harvest** — also had `templates/bundles/MezcalitoUxSearchBundle/Hits.html.twig`, an
importmap pair and a controllers.json block, plus a dead `MEZCALITO_UX_SEARCH_DEFAULT_DSN`
in `.env` that nothing read (no `survos_search.yaml` here):
- [x] `composer.json` — drop `tacman/ux-search: ^1.0`

**Regenerated, don't hand-edit:** `config/reference.php` in zm and openfoto (psalm config
types, includes `MezcalitoUxSearchConfig`), every `composer.lock`, and
`openfoto/.idea/commandlinetools/*.xml` (IDE cache).

**Correction to an earlier draft of this file:** it claimed no app referenced the package in
`importmap.php` or `assets/controllers.json`. Wrong — all three did, under the Composer name
`@tacman/ux-search` rather than `@mezcalito/…`, which is what the original grep missed. Each
needed an importmap `path` entry and a `controllers.json` block repointed at
`@survos/search-bundle`, with the paths changing shape too (`assets/src`, `assets/styles`,
no `dist/`). harvest also had a template override, which the earlier draft said it lacked.

## Retire the package — still open

- [ ] Mark `tacman/ux-search` abandoned on Packagist, replacement `survos/search-bundle`.
- [ ] Archive `github.com/survos/ux-search`, or leave a README pointing at
      `survos/search-bundle`. The local clone at `~/tacman/ux-search` is in sync with
      `origin` on `1.x`, so removing the directory loses nothing.
- [ ] Leave the two upstream PRs ([#49](https://github.com/Mezcalito/ux-search/pull/49), #50)
      open — they still accurately describe upstream bugs and cost nothing to keep.

## References to Mezcalito that stay

Not everything matching the grep is wrong. Keep:

- **MIT copyright headers** — `(c) Mezcalito (https://www.mezcalito.fr)` on every imported
  file under `src/`, `tests/`, `config/`, and `translations/`. Required by the licence.
- **The README Credits section.**
- **Upstream issue provenance** — `mezcalito/ux-search#46` in `src/Adapter/Doctrine/` and
  `Twig/Components/Facet/AbstractFacet.php`, `#47` in `config/ux_search_services.php`, `#13`
  in `Twig/Components/Hits.php`. These point at real upstream issues explaining why the code
  is shaped as it is.
- **`bu/folio-bundle/SESSION.md`** — a dated session log. It records the path as it was at
  the time; rewriting history docs is churn.
- **`bu/storage-bundle/src/SurvosStorageBundle.php`** — the `Mezcalito\ImgproxyBundle`
  check is a different Mezcalito package entirely, unrelated to search.

## Verification

- [x] The grep returns nothing across all three apps (ignoring generated lock/reference files,
      an IDE cache, a stale Playwright log, and harvest's `.claude/worktrees/` copy).
- [x] All three boot: `cache:clear` succeeds, `lint:twig` passes on every touched template.
- [x] `@SurvosSearch` resolves the app override ahead of the bundle in each app.
- [x] zm's searches all still register; openfoto's `folio_fts` adapter config survives.
- [x] folio's `folio_row` registers again — it had been silently absent.
- [ ] **Not done: no page was rendered over HTTP.** openfoto's and zm's dev servers were not
      running and starting them unasked seemed worse than saying so. Load zm's `/search` and
      home page, and an openfoto folio search, before trusting this.

---

## Follow-ups this migration surfaced

**1. Duplicate `folio_row` search in zm.** `App\Search\FolioRowSearch` and
`Survos\FolioBundle\Search\FolioRowSearch` both register under that name;
`RegisterSearchPass` `array_combine()`s by name, so one silently shadows the other. The
bundle's currently wins, which is the better outcome — the app copy is 301 diff lines behind
(no `FolioFacetFieldResolver`, no config-driven `titleSortEnabled`/`defaultSort`, missing the
`build()` re-entrancy fix). zm's copy is probably deletable.

The collision was invisible until now because folio-bundle gated its own `FolioRowSearch` on
`class_exists(\Mezcalito\UxSearchBundle\Search\AbstractSearch::class)`, so the bundle's
version never registered at all. Worth considering whether `RegisterSearchPass` should throw
on a duplicate name instead of silently dropping one.

**2. The `ux-search` Stimulus controllers never bind — in any app.** RESOLVED in 2.24.7
(mono `327d88b7`); all four consumers updated 2026-08-16 — mediary, openfoto, zm, harvest.
Root cause was three violations of the rules in `bu/AGENTS.md`: no `symfony-ux` composer
keyword (so Flex's `PackageJsonSynchronizer` skipped the package entirely and deleted the
`assets/controllers.json` block), the bundle extended `AbstractSurvosBundle` instead of
`AbstractUxBundle` so the dev-time guard for exactly that mistake never ran, and the
templates hard-coded controller ids instead of using `survos_stimulus()`. Note the keyword
only takes effect once a release reaches Packagist — Flex reads `installed.json`, not a
`mono/link` symlink. Original diagnosis follows.

Found while migrating mediary, but it is a bundle-wide defect, not a mediary one. `templates/Layout.html.twig`
emits `data-controller: 'ux-search'`, `Facet/RefinementList.html.twig` emits
`'ux-search--refinement-list'`, `Facet/RangeSlider.html.twig` emits `'ux-search-range-slider'`.
StimulusBundle registers third-party UX controllers under `<scope>--<package>--<name>`, so
what actually lands in the generated controllers map is:

```
"survos--search-bundle--ux-search"
"survos--search-bundle--ux-search--refinement-list"
"survos--search-bundle--ux-search-range-slider"
```

Verified on mediary by fetching `/assets/@symfony/stimulus-bundle/controllers-*.js` off a
rendered `/media/search`. Nothing matches the bare identifiers, so `updateUrl` (the
pushState/history rewriting behind `enableUrlRewriting()`), `toggleFacetCollapse`, and the
range slider are all dead. Search still works because Live Component drives it server-side —
which is why this went unnoticed. Predates the absorption: the same mismatch existed under
`@tacman/ux-search` (`tacman--ux-search--ux-search`) and upstream `@mezcalito/…`.

openfoto, zm and harvest additionally have **no `assets/controllers.json` block at all** —
Flex's `PackageJsonSynchronizer` dropped it when `tacman/ux-search` was uninstalled and did
not re-add one for `survos/search-bundle`. So those three also lose the
`@survos/search-bundle/styles/ux-search.css` autoimport and render the search UI unstyled.
mediary's block was restored by hand in the same commit.

Fix is either the three template identifiers, or a `stimulus_controller()` call that resolves
the name properly. Both need the controllers.json block present in each app.

**3. `hitTemplate` should work without an app override.** openfoto and harvest both ship a
`Hits.html.twig` override whose only job is to call `survos_hit_template()` — the Twig
function search-bundle already provides, which reads
`HitTemplateSearchInterface::getHitTemplate()`. The bundle's own `Hits.html.twig` ignores it
and `json_encode`s the hit instead, so every app must copy the same override to get real
result cards. Teaching the default template to use the hit template when the search provides
one would delete that boilerplate from three apps.
