# RefinementList Component

The `RefinementList` component allows users to filter search results based on facet values (terms). It displays checkbox options with result counts, local sorting when there are multiple values, and a search input only when the facet has enough values to show the "show more" control.

## Usage

```twig
<twig:Survos:Search:Facet:RefinementList property="brand" />
```

The property is automatically determined when using the generic Facet component:
```twig
{% for facet in search.facets %}
    <twig:Survos:Search:Facet :property="facet.property" />
{% endfor %}
```

## Available Variables

| Variable                        | Type                  | Description                                         |
|---------------------------------|-----------------------|-----------------------------------------------------|
| `property`                      | string                | The facet property name (e.g., "brand", "category") |
| `label`                         | string                | The display label for the facet                     |
| `distribution`                  | FacetTermDistribution | Object containing facet values and counts           |
| `distribution.values`           | array                 | Array of facet values with their counts             |
| `distribution.isChecked(value)` | bool                  | Whether a value is currently selected               |
| `limit`                         | int                   | Number of items to display before "show more"       |
| `searchable`                    | bool                  | Whether to show a local search input when values exceed `limit` |
| `valueType`                     | ?string               | Value type for sorting: `string`, `number`, `date`, or auto-detected |
| `collapsible`                   | bool                  | Whether the header title and chevron collapse the facet body |
| `collapsed`                     | bool                  | Whether the facet body starts collapsed                         |
| `attributes`                    | ComponentAttributes   | HTML attributes for the container                   |

## Blocks Available

| Block Name  | Description                                                              |
|-------------|--------------------------------------------------------------------------|
| `label`     | Facet title/legend - override to customize the facet heading             |
| `sort`      | Sort dropdown - override to customize local facet value sorting          |
| `search`    | Search input - override to customize the value search field              |
| `list`      | List of facet options - override to change checkbox structure or styling |
| `show_more` | "Show more" button - override to customize the limit toggle              |

## Default Layout

```twig
{% set valuesCount = distribution.values|length %}
{% set hasMultipleValues = valuesCount > 1 %}
{% set hasShowMore = valuesCount > this.limit %}
<fieldset {{ attributes.defaults({
    class: 'ux-search-facet ux-search-refinement-list',
    'data-skip-morph': true,
    'data-controller': 'ux-search--refinement-list',
    'data-ux-search--refinement-list-limit-value': this.limit,
    'data-ux-search--refinement-list-value-type-value': this.valueType|default('auto'),
    'data-ux-search-facet-collapsed': (this.collapsible and this.collapsed) ? 'true' : 'false',
    'data-ux-search-facet-expand-label': 'facet.expand'|trans({'%facet%': label}, domain='survos_search'),
    'data-ux-search-facet-collapse-label': 'facet.collapse'|trans({'%facet%': label}, domain='survos_search'),
    'data-ux-search--refinement-list-show-more-label-value': 'show_more'|trans(domain='survos_search'),
    'data-ux-search--refinement-list-show-less-label-value': 'show_less'|trans(domain='survos_search')
}) }}>
    <legend class="ux-search-facet__title ux-search-refinement-list__title ux-search-refinement-list__header d-flex align-items-center justify-content-between gap-2">
        <span class="ux-search-refinement-list__title-group d-inline-flex align-items-center gap-1 min-w-0">
            <span
                class="ux-search-refinement-list__title-text"
                {% if this.collapsible %}
                    role="button"
                    tabindex="0"
                    aria-expanded="{{ this.collapsed ? 'false' : 'true' }}"
                    aria-controls="{{ property }}-facet-panel"
                    data-ux-search-facet-toggle
                    data-action="click->ux-search#toggleFacetCollapse"
                {% endif %}
            >{% block label %}{{ label }}{% endblock %}</span>
            {% if this.collapsible %}
                <button
                    class="ux-search-refinement-list__collapse btn btn-icon btn-sm btn-ghost-secondary"
                    type="button"
                    aria-expanded="{{ this.collapsed ? 'false' : 'true' }}"
                    aria-controls="{{ property }}-facet-panel"
                    data-ux-search-facet-toggle
                    data-action="ux-search#toggleFacetCollapse"
                >
                    {# Chevron icon #}
                    <span class="ux-search-sr-only" data-ux-search-facet-label>
                        {{ (this.collapsed ? 'facet.expand' : 'facet.collapse')|trans({'%facet%': label}, domain='survos_search') }}
                    </span>
                </button>
            {% endif %}
        </span>
        {% if hasMultipleValues %}
            {% block sort %}
                <div class="ux-search-refinement-list__sort dropdown">
                    <select class="ux-search-sr-only" data-ux-search--refinement-list-target="sort">
                        <option value="count_desc">{{ 'facet.sort_options.count_desc'|trans(domain='survos_search') }}</option>
                        <option value="count_asc">{{ 'facet.sort_options.count_asc'|trans(domain='survos_search') }}</option>
                        {# Value sort options are shown based on valueType: string, number, or date. #}
                    </select>
                    <button class="ux-search-refinement-list__sort-toggle btn btn-action btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        {# Selected sort icon #}
                    </button>
                    <div class="ux-search-refinement-list__sort-menu dropdown-menu dropdown-menu-end">
                        {# Icon + label sort choices #}
                    </div>
                </div>
            {% endblock %}
        {% endif %}
    </legend>

    <div
        class="ux-search-refinement-list__panel"
        id="{{ property }}-facet-panel"
        data-ux-search-facet-panel
        {% if this.collapsible and this.collapsed %}hidden{% endif %}
    >
    {% if this.searchable and hasShowMore %}
        {% block search %}
            <div class="ux-search-refinement-list__search">
                <label class="ux-search-sr-only" for="{{ property }}-facet-search">
                    {{ 'facet.search'|trans({'%facet%': label}, domain='survos_search') }}
                </label>
                <input
                    class="ux-search-refinement-list__search-input ux-search-input form-control form-control-sm"
                    type="search"
                    id="{{ property }}-facet-search"
                    placeholder="{{ 'facet.search_placeholder'|trans(domain='survos_search') }}"
                    autocomplete="off"
                    data-ux-search--refinement-list-target="searchInput"
                    data-action="input->ux-search--refinement-list#search"
                >
            </div>
        {% endblock %}
    {% endif %}

    {% block list %}
        <ul class="ux-search-refinement-list__list" data-ux-search--refinement-list-target="list">
            {%- for key,value in distribution.values %}
                <li
                    class="ux-search-refinement-list__item{{ loop.index > this.limit ? ' ux-search-refinement-list__item--exceed-limit' }}"
                    data-ux-search--refinement-list-target="item"
                >
                    <input
                        class="ux-search-refinement-list__input"
                        value="{{ key }}"
                        type="checkbox"
                        id="{{ property }}-{{ key }}"
                        {%- if distribution.isChecked(key) %} checked {% endif -%}
                        data-action="live#action"
                        data-live-action-param="toggleFacetTerm"
                        data-live-property-param="{{ property }}"
                        data-live-value-param="{{ key }}"
                    >
                    <label class="ux-search-refinement-list__label" for="{{ property }}-{{ key }}">
                        <span class="ux-search-refinement-list__label-text" data-ux-search--refinement-list-target="label">{{ key }}</span>
                        <span class="ux-search-refinement-list__count" data-ux-search--refinement-list-target="count">{{ value }}</span>
                    </label>
                </li>
            {% endfor -%}
        </ul>
    {% endblock %}

    {% if hasShowMore %}
        {% block show_more %}
            <button
                class="ux-search-refinement-list__show-more"
                type="button"
                data-ux-search--refinement-list-target="toggle"
                data-action="ux-search--refinement-list#toggleShowMore"
            >
                {{ 'show_more'|trans(domain='survos_search') }}
            </button>
        {% endblock %}
    {% endif %}
</div>
</fieldset>
```

## Default HTML Output

```html
<fieldset class="ux-search-facet ux-search-refinement-list" data-skip-morph="" data-controller="ux-search--refinement-list" data-ux-search--refinement-list-limit-value="2" data-ux-search--refinement-list-show-more-label-value="Show more" data-ux-search--refinement-list-show-less-label-value="Show less">
    <legend class="ux-search-facet__title ux-search-refinement-list__title">Type</legend>
    <ul class="ux-search-refinement-list__list">
        <li class="ux-search-refinement-list__item">
            <input class="ux-search-refinement-list__input" value="Trend cases" type="checkbox" id="type-Trend cases" data-action="live#action" data-live-action-param="toggleFacetTerm" data-live-property-param="type" data-live-value-param="Trend cases">
            <label class="ux-search-refinement-list__label" for="type-Trend cases">
                <span class="ux-search-refinement-list__label-text">Trend cases</span>
                <span class="ux-search-refinement-list__count">537</span>
            </label>
        </li>
        <li class="ux-search-refinement-list__item">
            <input class="ux-search-refinement-list__input" value="Ult protection cases" type="checkbox" id="type-Ult protection cases" data-action="live#action" data-live-action-param="toggleFacetTerm" data-live-property-param="type" data-live-value-param="Ult protection cases">
            <label class="ux-search-refinement-list__label" for="type-Ult protection cases">
                <span class="ux-search-refinement-list__label-text">Ult protection cases</span>
                <span class="ux-search-refinement-list__count">289</span>
            </label>
        </li>
        // ...
    </ul>
    <button class="ux-search-refinement-list__show-more" type="button" data-ux-search--refinement-list-target="toggle" data-action="ux-search--refinement-list#toggleShowMore">Show more</button>
</fieldset>
```

## Configuration

Configure facet behavior in your Search class:

```php
use Survos\SearchBundle\Search\AbstractSearch;

#[AsSearch('products')]
class ProductSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        $this
            ->addFacet('brand', 'Brand')
            ->addFacet('category', 'Category')
            ->addFacet('color', 'Color', null, ['limit' => 10]) // Show max 10 values
            ->addFacet('size', 'Size', null, ['limit' => 5])
            ->addFacet('year', 'Year', null, ['valueType' => 'date'])
            ->addFacet('rating', 'Rating', null, ['valueType' => 'number'])
            ->addFacet('tag', 'Tag', null, ['searchable' => false]); // Hide value search
    }
}
```

**Options:**
- `limit` - Number of facet values to display before "show more" (default: defined in component)
- `searchable` - Show a local search input when values exceed `limit` (default: `true`)
- `valueType` - Controls the value-specific sort labels and comparison. Use `string`, `number`, or `date`; omit it to auto-detect from rendered values.
- `collapsible` - Make the header title and chevron collapse the facet body (default: `true`)
- `collapsed` - Render the facet body collapsed initially (default: `false`)

Control visibility:
- 1 value: no search, no sort
- 2+ values without "show more": sort only
- Values exceeding `limit`: sort and search

## Styling

Default classes:
- `.ux-search-facet` - Shared facet container class
- `.ux-search-refinement-list` - Main fieldset element
- `.ux-search-refinement-list__title` - Facet title/legend
- `.ux-search-refinement-list__header` - Facet title and sort dropdown row
- `.ux-search-refinement-list__title-group` - Facet title and chevron group
- `.ux-search-refinement-list__title-text` - Facet title text
- `.ux-search-refinement-list__collapse` - Collapse/expand chevron button
- `.ux-search-refinement-list__panel` - Collapsible facet body
- `.ux-search-refinement-list__sort` - Local sort dropdown
- `.ux-search-refinement-list__search` - Search input wrapper
- `.ux-search-refinement-list__search-input` - Local value search field
- `.ux-search-refinement-list__list` - List container
- `.ux-search-refinement-list__item` - Individual facet option
- `.ux-search-refinement-list__item--exceed-limit` - Hidden items beyond limit
- `.ux-search-refinement-list__item--hidden` - Items hidden by the local search query
- `.ux-search-refinement-list__input` - Checkbox input
- `.ux-search-refinement-list__label` - Label wrapper
- `.ux-search-refinement-list__label-text` - Value text
- `.ux-search-refinement-list__count` - Result count
- `.ux-search-refinement-list__show-more` - Show more/less button

## Related Components

- [RangeSlider](RangeSlider.md) - For numeric range filtering
- [RangeInput](RangeInput.md) - For manual range input
- [CurrentRefinements](../CurrentRefinements.md) - Display active filters
- [ClearRefinements](../ClearRefinements.md) - Clear all filters
- [Layout](../Layout.md) - Root container
