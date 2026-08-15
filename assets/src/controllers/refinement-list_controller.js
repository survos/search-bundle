import { Controller } from '@hotwired/stimulus';

class default_1 extends Controller {
    static values = {
        limit: Number,
        isShowingMore: {
            type: Boolean,
            default: false,
        },
        showMoreLabel: String,
        showLessLabel: String,
        isSearching: {
            type: Boolean,
            default: false,
        },
        valueType: {
            type: String,
            default: 'auto',
        },
    };
    static targets = [
        'toggle',
        'searchInput',
        'list',
        'item',
        'label',
        'count',
        'sort',
        'sortOption',
        'sortChoice',
        'sortToggle',
        'sortIcon',
        'noResults',
    ];
    mutationObserver;
    sortOptionsFrame = null;
    initialize() {
        this.mutationObserver = new MutationObserver(this.handleMutation);
    }
    connect() {
        this.mutationObserver.observe(this.element, {
            childList: true,
        });
        this.configureSortOptions();
        this.sort();
    }
    handleMutation = () => {
        this.updateToggleLabel();
    };
    sortTargetConnected() {
        this.scheduleSortOptionsConfiguration();
    }
    sortOptionTargetConnected() {
        this.scheduleSortOptionsConfiguration();
    }
    sortChoiceTargetConnected() {
        this.scheduleSortOptionsConfiguration();
    }
    labelTargetConnected() {
        this.scheduleSortOptionsConfiguration();
    }
    isShowingMoreValueChanged() {
        this.updateToggleLabel();
    }
    toggleShowMore() {
        this.isShowingMoreValue = !this.isShowingMoreValue;
    }
    search() {
        if (!this.hasSearchInputTarget)
            return;
        const query = this.normalize(this.searchInputTarget.value);
        this.isSearchingValue = query.length > 0;
        let matchingItems = 0;
        this.itemTargets.forEach((item, index) => {
            const label = this.labelTargets[index]?.textContent ?? '';
            const isMatching = this.normalize(label).includes(query);
            if (isMatching) {
                matchingItems++;
            }
            item.classList.toggle('ux-search-refinement-list__item--hidden', !isMatching);
        });
        this.updateNoResults(this.isSearchingValue && matchingItems === 0);
    }
    selectSort(event) {
        if (!this.hasSortTarget)
            return;
        const choice = event.currentTarget;
        const value = choice.dataset.sortValue;
        if (!value)
            return;
        this.sortTarget.value = value;
        this.sort();
    }
    sort() {
        if (!this.hasSortTarget)
            return;
        const sortValue = this.sortTarget.value;
        const valueType = this.getValueType();
        const items = [...this.itemTargets];
        items.sort((itemA, itemB) => {
            const indexA = this.itemTargets.indexOf(itemA);
            const indexB = this.itemTargets.indexOf(itemB);
            if ('count_asc' === sortValue || 'count_desc' === sortValue) {
                const countA = this.getCount(indexA);
                const countB = this.getCount(indexB);
                return 'count_asc' === sortValue ? countA - countB : countB - countA;
            }
            return this.compareValues(this.getLabel(indexA), this.getLabel(indexB), valueType, sortValue.endsWith('_desc'));
        });
        items.forEach((item) => this.listTarget.append(item));
        this.updateLimitedItems();
        this.search();
        this.updateSortToggle();
    }
    updateToggleLabel() {
        if (!this.hasToggleTarget)
            return;
        this.toggleTarget.innerHTML = this.isShowingMoreValue ? this.showLessLabelValue : this.showMoreLabelValue;
    }
    updateNoResults(isVisible) {
        if (!this.hasNoResultsTarget)
            return;
        this.noResultsTarget.hidden = !isVisible;
    }
    configureSortOptions() {
        if (!this.hasSortTarget)
            return;
        const valueType = this.getValueType();
        this.sortOptionTargets.forEach((option) => {
            option.hidden = option.dataset.valueType !== valueType;
        });
        this.sortChoiceTargets.forEach((choice) => {
            const choiceValueType = choice.dataset.valueType;
            const isHidden = !!choiceValueType && choiceValueType !== valueType;
            choice.hidden = isHidden;
            choice.classList.toggle('d-none', isHidden);
            choice.setAttribute('aria-hidden', isHidden ? 'true' : 'false');
        });
        if (this.sortTarget.selectedOptions[0]?.hidden) {
            this.sortTarget.value = 'count_desc';
        }
        this.updateSortToggle();
    }
    updateSortToggle() {
        if (!this.hasSortTarget || !this.hasSortToggleTarget)
            return;
        const selectedValue = this.sortTarget.value;
        const selectedChoice = this.sortChoiceTargets.find((choice) => choice.dataset.sortValue === selectedValue);
        const selectedLabel = selectedChoice?.dataset.sortLabel ?? this.sortTarget.selectedOptions[0]?.textContent?.trim() ?? '';
        const selectedIcon = selectedChoice?.querySelector('[data-sort-icon]');
        this.sortToggleTarget.title = selectedLabel;
        this.sortToggleTarget.setAttribute('aria-label', selectedLabel);
        this.sortChoiceTargets.forEach((choice) => {
            const isSelected = choice.dataset.sortValue === selectedValue;
            choice.classList.toggle('active', isSelected);
            choice.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
        if (this.hasSortIconTarget && selectedIcon) {
            this.sortIconTarget.innerHTML = selectedIcon.innerHTML;
        }
    }
    scheduleSortOptionsConfiguration() {
        if (this.sortOptionsFrame !== null)
            return;
        this.sortOptionsFrame = window.requestAnimationFrame(() => {
            this.sortOptionsFrame = null;
            this.configureSortOptions();
        });
    }
    updateLimitedItems() {
        this.itemTargets.forEach((item, index) => {
            item.classList.toggle('ux-search-refinement-list__item--exceed-limit', index >= this.limitValue);
        });
    }
    getValueType() {
        const configuredValueType = this.normalize(this.valueTypeValue || 'auto');
        if (['date', 'number', 'string'].includes(configuredValueType)) {
            return configuredValueType;
        }
        return this.guessValueType();
    }
    guessValueType() {
        const labels = this.labelTargets.map((label) => (label.textContent ?? '').trim()).filter((label) => label.length > 0);
        if (labels.length > 0 && labels.every((label) => /^\d{4}$/.test(label))) {
            return 'date';
        }
        if (labels.length > 0 && labels.every((label) => this.parseNumber(label) !== null)) {
            return 'number';
        }
        if (labels.length > 0 && labels.every((label) => this.parseDate(label) !== null)) {
            return 'date';
        }
        return 'string';
    }
    compareValues(valueA, valueB, valueType, descending) {
        let comparison;
        if ('date' === valueType) {
            comparison = (this.parseDate(valueA) ?? 0) - (this.parseDate(valueB) ?? 0);
        }
        else if ('number' === valueType) {
            comparison = (this.parseNumber(valueA) ?? 0) - (this.parseNumber(valueB) ?? 0);
        }
        else {
            comparison = valueA.localeCompare(valueB, undefined, { numeric: true, sensitivity: 'base' });
        }
        return descending ? comparison * -1 : comparison;
    }
    getLabel(index) {
        return this.labelTargets[index]?.textContent?.trim() ?? '';
    }
    getCount(index) {
        return Number.parseFloat((this.countTargets[index]?.textContent ?? '').replace(/[^\d.-]/g, '')) || 0;
    }
    parseNumber(value) {
        const normalizedValue = value.trim().replace(/,/g, '');
        if (!/^-?\d+(\.\d+)?$/.test(normalizedValue)) {
            return null;
        }
        return Number.parseFloat(normalizedValue);
    }
    parseDate(value) {
        const normalizedValue = value.trim();
        if (/^\d{4}$/.test(normalizedValue)) {
            return Date.UTC(Number.parseInt(normalizedValue, 10), 0, 1);
        }
        const timestamp = Date.parse(normalizedValue);
        return Number.isNaN(timestamp) ? null : timestamp;
    }
    normalize(value) {
        return value.trim().toLocaleLowerCase();
    }
    disconnect() {
        if (this.sortOptionsFrame !== null) {
            window.cancelAnimationFrame(this.sortOptionsFrame);
            this.sortOptionsFrame = null;
        }
        this.mutationObserver.disconnect();
    }
}

export { default_1 as default };
