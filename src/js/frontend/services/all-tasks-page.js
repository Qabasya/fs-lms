import { AllTasksApi }    from './all-tasks-api.js';
import { FilterSection }  from '../components/filter-section.js';
import { buildTaskCard }  from '../components/task-card.js';
import { bindCardTabs }   from '../modules/card-tabs.js';

/**
 * AllTasksPage — оркестратор страницы «Все задания».
 *
 * Связывает AllTasksApi, FilterSection, buildTaskCard и bindCardTabs.
 * Управляет состоянием: активные фильтры, offset пагинации, флаг загрузки.
 */
export class AllTasksPage {
    constructor() {
        this._root = document.querySelector('.fs-all-tasks-page');
    }

    init() {
        if (!this._root) return;

        this._readConfig();
        this._bindRefs();
        this._api = new AllTasksApi(this._ajaxUrl, 'fetch_all_tasks', this._nonce, this._subjectKey);

        this._filters  = { search: '', taxonomies: {} };
        this._offset   = this._root.querySelectorAll('.js-task-cards .task-card-row').length;
        this._loading  = false;

        this._initFilterSections();
        this._initSearch();
        this._initClearBtns();
        this._initInfiniteScroll();
        this._bindTagFilters();

        bindCardTabs(this._cardsWrap);
    }

    // ── Config ────────────────────────────────────────────────

    _readConfig() {
        this._subjectKey = this._root.dataset.subjectKey;
        this._nonce      = this._root.dataset.nonce;
        this._ajaxUrl    = this._root.dataset.ajaxUrl;
        this._perPage    = parseInt(this._root.dataset.perPage, 10) || 10;
        this._total      = parseInt(this._root.dataset.total,   10) || 0;
        this._hasMore    = this._root.dataset.hasMore === '1';
    }

    _bindRefs() {
        this._cardsWrap   = this._root.querySelector('.js-task-cards');
        this._emptyState  = this._root.querySelector('.js-tasks-empty');
        this._sentinel    = this._root.querySelector('.js-infinite-sentinel');
        this._infiniteEnd = this._root.querySelector('.js-infinite-end');
        this._countEl     = this._root.querySelector('.js-results-count');
        this._clearBtns   = this._root.querySelectorAll('.js-filters-clear');
        this._sideClear   = this._root.querySelector('.filters-side-clear');
        this._metaClear   = this._root.querySelector('.results-meta .js-filters-clear');
        this._searchInput = this._root.querySelector('.js-search-input');
    }

    // ── Filter sections ───────────────────────────────────────

    _initFilterSections() {
        this._filterSections = [];

        this._root.querySelectorAll('.js-filter-sec').forEach(el => {
            const section = new FilterSection(el, (key, value, isActive) => {
                this._onFilterOptionToggled(key, value, isActive);
            });
            this._filterSections.push(section);
        });
    }

    _onFilterOptionToggled(taxonomy, value, isActive) {
        const current = this._filters.taxonomies[taxonomy] || [];

        if (isActive) {
            if (!current.includes(value)) current.push(value);
            this._filters.taxonomies[taxonomy] = current;
        } else {
            const next = current.filter(v => v !== value);
            if (next.length) {
                this._filters.taxonomies[taxonomy] = next;
            } else {
                delete this._filters.taxonomies[taxonomy];
            }
        }

        this._syncClearBtns();
        this._reload();
    }

    // ── Теги-фильтры на карточках ──────────────────────────────

    /**
     * Клик по чипу-тегу карточки (.js-tag-filter) переиспользует клик
     * соответствующей опции сайдбара — единый путь тоггла/бейджа/перезагрузки.
     * Делегирование на постоянном контейнере: работает и для AJAX-карточек.
     */
    _bindTagFilters() {
        if (!this._cardsWrap) return;

        this._cardsWrap.addEventListener('click', e => {
            const chip = e.target.closest('.js-tag-filter');
            if (!chip) return;

            const option = this._root.querySelector(
                `.js-filter-option[data-filter="${chip.dataset.filter}"][data-value="${chip.dataset.value}"]`
            );
            if (option) option.click();
        });
    }

    // ── Search ────────────────────────────────────────────────

    _initSearch() {
        if (!this._searchInput) return;
        let timer;
        this._searchInput.addEventListener('input', e => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                this._filters.search = e.target.value.trim();
                this._syncClearBtns();
                this._reload();
            }, 350);
        });
    }

    // ── Clear ─────────────────────────────────────────────────

    _initClearBtns() {
        this._clearBtns.forEach(btn => {
            btn.addEventListener('click', () => this._clearAll());
        });
    }

    _clearAll() {
        this._filters = { search: '', taxonomies: {} };
        if (this._searchInput) this._searchInput.value = '';
        this._filterSections.forEach(s => s.reset());
        this._syncClearBtns();
        this._reload();
    }

    _syncClearBtns() {
        // Кнопки сброса отражают ТОЛЬКО выбранные фильтры-таксономии, не поиск
        // (у поля поиска свой нативный ×). Сайдбарная — disabled, мета — hidden.
        const active = Object.keys(this._filters.taxonomies).length > 0;
        if (this._sideClear) this._sideClear.disabled = !active;
        if (this._metaClear) this._metaClear.hidden   = !active;
    }

    // ── Fetch / render ────────────────────────────────────────

    _reload() {
        this._offset = 0;
        this._hasMore = false;
        this._loadTasks(true);
    }

    _loadMore() {
        if (!this._hasMore || this._loading) return;
        this._loadTasks(false);
    }

    _loadTasks(reset) {
        if (this._loading) return;
        this._loading = true;

        this._api.fetch(this._filters, reset ? 0 : this._offset, this._perPage)
            .then(({ tasks, total, has_more }) => {
                this._total   = total;
                this._hasMore = has_more;

                if (reset) {
                    this._cardsWrap.innerHTML = '';
                    this._offset = 0;
                }

                tasks.forEach(task => {
                    this._cardsWrap.insertAdjacentHTML('beforeend', buildTaskCard(task));
                });

                this._offset += tasks.length;

                bindCardTabs(this._cardsWrap);
                this._updateCountEl(total);
                this._toggleEmpty(this._offset === 0);
                this._setSentinel(has_more);
            })
            .catch(err => console.error('[AllTasksPage]', err))
            .finally(() => { this._loading = false; });
    }

    // ── UI helpers ────────────────────────────────────────────

    _updateCountEl(n) {
        if (this._countEl) this._countEl.textContent = n;
    }

    _toggleEmpty(show) {
        if (this._emptyState) this._emptyState.hidden  = !show;
        if (this._cardsWrap)  this._cardsWrap.hidden   =  show;
    }

    _setSentinel(visible) {
        if (this._sentinel)    this._sentinel.hidden    = !visible;
        if (this._infiniteEnd) this._infiniteEnd.hidden =  visible;
    }

    // ── Infinite scroll ───────────────────────────────────────

    _initInfiniteScroll() {
        if (!this._sentinel) return;
        const io = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) this._loadMore();
        }, { rootMargin: '320px 0px' });
        io.observe(this._sentinel);
    }
}