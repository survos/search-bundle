# Consumer migration after the ux-search absorption

**Status:** mono done; the three apps not started.
**Delete this file when the apps are migrated and the package is retired.**

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

## To do — the three apps

Re-run the grep rather than trusting any list:

```bash
grep -rni 'mezcalito\|tacman/ux-search' ~/sites/{zm,openfoto,harvest} \
  | grep -vE '/(vendor|var|node_modules|\.idea)/'
```

**zm**
- [ ] `composer.json` — drop `tacman/ux-search: ^1.0.10`; also carries a `repositories`
      entry for `github.com/survos/search-bundle.git`
- [ ] `config/packages/mezcalito_ux_search.yaml` — rename file and config root; its
      `default:` reads `%env(MEZCALITO_UX_SEARCH_DEFAULT_DSN)%`
- [ ] `.env` lines 74–75 — the `MEZCALITO_UX_SEARCH_DEFAULT_DSN=doctrine://default` var and
      the comment above it
- [ ] `src/Search/DatasetArtifactSearch.php` (5 imports), `src/Search/FolioRowSearch.php` (4)
- [ ] `templates/bundles/MezcalitoUxSearchBundle/{Hits.html.twig,Facet/RefinementList.html.twig}`
      → `SurvosSearchBundle/`. `RefinementList` uses the parent-template form
      `{% extends '@!MezcalitoUxSearch/Facet/RefinementList.html.twig' %}`; `Hits` uses
      `domain='mezcalito_ux_search'`
- [ ] `templates/search/index.html.twig`, `templates/home/index.html.twig` — `<twig:Mezcalito:UxSearch:Layout>`
- [ ] `docs/landing-page-plan.md`, `docs/command/survosSearchCreate.md` — prose references

**openfoto** — has template overrides too, contrary to an earlier draft of this file:
- [ ] `composer.json` — drop `tacman/ux-search: ^1.0.10`
- [ ] `config/bundles.php:37` — remove the `MezcalitoUxSearchBundle` registration
- [ ] `config/packages/mezcalito_ux_search.yaml`
- [ ] `templates/bundles/MezcalitoUxSearchBundle/Hits.html.twig` — also uses
      `domain='mezcalito_ux_search'`

**harvest** — lightest:
- [ ] `composer.json` — drop `tacman/ux-search: ^1.0`

**Regenerated, don't hand-edit:** `config/reference.php` in zm and openfoto (psalm config
types, includes `MezcalitoUxSearchConfig`), every `composer.lock`, and
`openfoto/.idea/commandlinetools/*.xml` (IDE cache).

No app references `@mezcalito/ux-search` in `importmap.php` or `assets/controllers.json` —
the Stimulus side is contained inside the bundle.

## Retire the package

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

- [ ] The grep above returns nothing across the three apps.
- [ ] zm's `/search` and home page render facets, pagination, sort, and the offcanvas.
- [ ] openfoto search still works — it's the reference app.
- [ ] folio's `folio_row` search registers (the `class_exists` guard was silently failing)
      and `folio/search.html.twig` renders.
