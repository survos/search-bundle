import { Controller } from '@hotwired/stimulus';
import instantsearch from 'instantsearch.js';
import { searchBox, hits, stats, pagination, refinementList, currentRefinements, clearRefinements, sortBy, rangeInput, configure } from 'instantsearch.js/es/widgets';
import { createEngine } from '@tacman1123/twig-browser';
import { installSymfonyTwigAPI } from '@tacman1123/twig-browser/adapters/symfony';

/** InstantSearch widgets with a Symfony backend and client-rendered Twig hits. */
export default class extends Controller {
    static targets = ['query', 'hits', 'stats', 'pagination', 'current', 'clear', 'sort', 'facet', 'range', 'error'];
    static values = { endpoint: String, name: String, template: String, sorts: Array, context: Object, rawJson: { type: Boolean, default: true } };

    async connect() {
        this.disposed = false;
        // objectID -> hit as returned by the endpoint, for the {} raw-document dialog.
        this.rawHits = new Map();
        if (this.rawJsonValue) this.installRawJsonDialog();
        try {
            const [routes, response] = await Promise.all([
                import('@survos/js-twig/generated/fos_routes.js'),
                fetch(this.templateValue, { credentials: 'same-origin' }),
            ]);
            if (!response.ok) throw new Error('Could not load the result template.');
            const source = await response.text();
            if (this.disposed) return;
            const engine = createEngine();
            installSymfonyTwigAPI(engine, { pathGenerator: routes.path });
            engine.compileBlock('hit', source);
            const searchClient = {
                search: async (requests) => {
                    const res = await fetch(this.endpointValue, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ requests }),
                    });
                    if (!res.ok) throw new Error('Search is temporarily unavailable. Please try again.');
                    this.errorTarget.hidden = true;
                    return res.json();
                },
            };
            this.search = instantsearch({ indexName: this.nameValue, searchClient, routing: true, insights: false });
            this.search.on('error', ({ error }) => this.showError(error));
            this.search.on('render', () => {
                for (const node of this.rangeTargets) {
                    const inputs = node.querySelectorAll('input');
                    if (inputs[0]) inputs[0].setAttribute('aria-label', `${node.dataset.label || node.dataset.field} minimum`);
                    if (inputs[1]) inputs[1].setAttribute('aria-label', `${node.dataset.label || node.dataset.field} maximum`);
                }
            });
            const widgets = [
                configure({ hitsPerPage: 24 }),
                searchBox({ container: this.queryTarget, placeholder: 'Search this collection…', showSubmit: false, searchAsYouType: true }),
                hits({ container: this.hitsTarget, escapeHTML: false, templates: {
                    item: (hit) => this.rawJsonButton(hit) + engine.renderBlock('hit', { hit, ...this.contextValue }),
                    empty: '<div class="bench-empty"><h2>No matches yet</h2><p>Try a shorter query or clear a filter.</p></div>',
                } }),
                stats({ container: this.statsTarget }),
                pagination({ container: this.paginationTarget, padding: 2, showFirst: false, showLast: false }),
                currentRefinements({ container: this.currentTarget }),
                clearRefinements({ container: this.clearTarget, templates: { resetLabel: 'Clear filters' } }),
                sortBy({ container: this.sortTarget, items: [{ value: this.nameValue, label: 'Relevance' }, ...this.sortsValue] }),
            ];
            for (const node of this.facetTargets) widgets.push(refinementList({ container: node, attribute: node.dataset.field, limit: 8, showMore: true, showMoreLimit: 100, sortBy: ['count:desc', 'name:asc'] }));
            for (const node of this.rangeTargets) widgets.push(rangeInput({ container: node, attribute: node.dataset.field, precision: 0 }));
            this.search.addWidgets(widgets);
            this.search.start();
        } catch (error) { this.showError(error); }
    }

    /** The indexed document is what explains a card: a missing image is usually a missing field. */
    installRawJsonDialog() {
        if (!document.getElementById('survos-search-raw-json-style')) {
            const style = document.createElement('style');
            style.id = 'survos-search-raw-json-style';
            style.textContent = '.ais-Hits-item{position:relative}'
                + '.survos-raw-json-btn{position:absolute;top:.25rem;right:.25rem;z-index:2;font:600 .75rem/1 ui-monospace,monospace;padding:.2rem .35rem;border:1px solid #0003;border-radius:.25rem;background:#fffc;color:#333;cursor:pointer;opacity:.55}'
                + '.survos-raw-json-btn:hover,.survos-raw-json-btn:focus-visible{opacity:1}'
                + '.survos-raw-json{width:min(56rem,calc(100vw - 2rem));max-height:85vh;padding:0;border:1px solid #0003;border-radius:.5rem}'
                + '.survos-raw-json header{display:flex;gap:.5rem;align-items:center;padding:.5rem .75rem;border-bottom:1px solid #0002;position:sticky;top:0;background:inherit}'
                + '.survos-raw-json header strong{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
                + '.survos-raw-json [data-body]{overflow:auto;max-height:calc(85vh - 3rem)}'
                + '.survos-raw-json pre{margin:0;padding:.75rem;font-size:.8rem;white-space:pre-wrap;word-break:break-word}';
            document.head.append(style);
        }
        this.rawDialog = document.createElement('dialog');
        this.rawDialog.className = 'survos-raw-json';
        this.rawDialog.innerHTML = '<header><strong></strong><button type="button" data-close aria-label="Close">✕</button></header><div data-body></div>';
        this.rawDialog.addEventListener('click', (event) => {
            if (event.target === this.rawDialog || event.target.closest('[data-close]')) this.rawDialog.close();
        });
        this.element.append(this.rawDialog);
        this.onRawJsonClick = async (event) => {
            const button = event.target.closest('.survos-raw-json-btn');
            if (!button || !this.element.contains(button)) return;
            event.preventDefault();
            event.stopPropagation();
            const hit = this.rawHits.get(button.dataset.objectId);
            if (!hit) return;
            this.rawDialog.querySelector('strong').textContent = `${this.nameValue} · ${button.dataset.objectId}`;
            const body = this.rawDialog.querySelector('[data-body]');
            body.textContent = 'Loading…';
            if (!this.rawDialog.open) this.rawDialog.showModal();
            body.replaceChildren(await this.jsonView(hit));
        };
        this.element.addEventListener('click', this.onRawJsonClick);
    }

    /** The json-viewer widget meili-bundle used; plain <pre> when the app has not installed it. */
    async jsonView(data) {
        this.jsonViewerReady ??= import('@andypf/json-viewer').then(() => true, () => false);
        if (await this.jsonViewerReady) {
            const viewer = document.createElement('andypf-json-viewer');
            Object.assign(viewer, { expanded: 1, indent: 2, showDataTypes: false, theme: 'monokai', showToolbar: true, showSize: true, showCopy: true, expandIconType: 'square' });
            viewer.data = data;
            return viewer;
        }
        const pre = document.createElement('pre');
        pre.textContent = JSON.stringify(data, null, 2);
        return pre;
    }

    rawJsonButton(hit) {
        if (!this.rawJsonValue) return '';
        // Drop InstantSearch's own bookkeeping (__position, __queryID) so the dialog shows only the response.
        const raw = Object.fromEntries(Object.entries(hit).filter(([key]) => !key.startsWith('__')));
        const id = String(hit.objectID);
        this.rawHits.set(id, raw);
        const attr = id.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        return `<button type="button" class="survos-raw-json-btn" data-object-id="${attr}" title="Show the indexed document">{}</button>`;
    }

    showError(error) {
        this.errorTarget.textContent = error.message;
        this.errorTarget.hidden = false;
    }

    disconnect() {
        this.disposed = true;
        this.search?.dispose();
        if (this.onRawJsonClick) this.element.removeEventListener('click', this.onRawJsonClick);
        this.rawDialog?.remove();
    }
}
