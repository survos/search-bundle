import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

class controller extends Controller {
    async initialize() {
        this.component = await getComponent(this.element);
    }
    connect() {
        window.addEventListener('ux-search:url:update', this.handleHistoryUpdate);
    }
    handleHistoryUpdate = (event) => {
        const customEvent = event;
        this.updateUrl(customEvent.detail.url);
    };
    async updateFacetRange(event) {
        const { property, rangeMin, rangeMax } = event.params;
        const form = event.currentTarget;
        const { min, max } = this.getRangeValues(form, property, parseFloat(rangeMin), parseFloat(rangeMax));
        await this.component.action('updateFacetRange', { property, min, max });
    }
    toggleFacetCollapse(event) {
        if (event instanceof KeyboardEvent && event.key === ' ') {
            event.preventDefault();
        }
        const trigger = event.currentTarget;
        const facet = trigger?.closest('[data-ux-search-facet-collapsed]');
        if (!facet) {
            return;
        }
        const isCollapsed = facet.dataset.uxSearchFacetCollapsed === 'true';
        const isNextCollapsed = !isCollapsed;
        facet.dataset.uxSearchFacetCollapsed = isNextCollapsed ? 'true' : 'false';
        const panel = facet.querySelector('[data-ux-search-facet-panel]');
        if (panel) {
            panel.hidden = isNextCollapsed;
        }
        facet.querySelectorAll('[data-ux-search-facet-toggle]').forEach((toggle) => {
            toggle.setAttribute('aria-expanded', isNextCollapsed ? 'false' : 'true');
        });
        const label = facet.querySelector('[data-ux-search-facet-label]');
        if (label) {
            label.textContent = isNextCollapsed
                ? facet.dataset.uxSearchFacetExpandLabel ?? ''
                : facet.dataset.uxSearchFacetCollapseLabel ?? '';
        }
    }
    getRangeValues(form, property, rangeMin, rangeMax) {
        const data = new FormData(form);
        const getValue = (suffix) => {
            const value = data.get(`${property}-${suffix}`);
            return value?.length ? Number(value) : null;
        };
        const min = getValue('min');
        const max = getValue('max');
        return {
            min: typeof min === 'number' ? (min > rangeMin ? min : null) : null,
            max: typeof max === 'number' ? (max < rangeMax ? max : null) : null,
        };
    }
    updateUrl(url) {
        Promise.resolve().then(() => {
            const target = new URL(url, window.location.href);
            const relative = target.pathname + target.search + target.hash;
            const current = window.location.pathname + window.location.search + window.location.hash;
            if (current !== relative) {
                history.replaceState(history.state, '', relative);
            }
        });
    }
    disconnect() {
        window.removeEventListener('ux-search:url:update', this.handleHistoryUpdate);
    }
}

export { controller as default };
