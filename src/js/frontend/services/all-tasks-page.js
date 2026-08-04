import { AllTasksApi }    from './all-tasks-api.js';
import { FilterSection }  from '../components/filter-section.js';
import { buildTaskCard }  from '../components/task-card.js';
import { bindAnswerToggle } from '../modules/answer-toggle.js';
import { initTaskConditions } from '../modules/task-condition.js';
import { renderSidebarArticles } from '../components/sidebar-articles.js';
import { pluralRu }       from '../../common/plural.js';

/**
 * AllTasksPage — оркестратор страницы «Все задания».
 *
 * Связывает AllTasksApi, FilterSection, buildTaskCard и bindAnswerToggle.
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

        // Состояние берём из разметки: фильтры могли прийти в URL (клик по тегу
        // на странице задания) и уже отрисованы сервером как активные опции.
        this._filters  = { search: '', taxonomies: this._readSelectedFilters() };
        this._offset   = this._root.querySelectorAll('.js-task-cards .task-card-row').length;
        this._loading  = false;

        this._initFilterSections();
        this._initSearch();
        this._initClearBtns();
        this._initInfiniteScroll();
        this._bindTagFilters();

        bindAnswerToggle(this._cardsWrap);
        initTaskConditions(this._cardsWrap);
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
        this._nounEl      = this._root.querySelector('.js-results-noun');
        this._clearBtns   = this._root.querySelectorAll('.js-filters-clear');
        this._sideClear   = this._root.querySelector('.filters-side-clear');
        this._searchInput = this._root.querySelector('.js-search-input');
        this._articles    = this._root.querySelector('.js-articles-block');
    }

    /**
     * Собирает активные опции сайдбара (SSR-разметка) в карту состояния
     * {таксономия: [слаги]} — без неё выбор из URL потерялся бы при первом же
     * запросе к серверу.
     *
     * @returns {Object<string, string[]>}
     */
    _readSelectedFilters() {
        const selected = {};

        this._root.querySelectorAll('.js-filter-option.is-active').forEach(btn => {
            const key   = btn.dataset.filter;
            const value = btn.dataset.value;
            if (!key || !value) return;

            (selected[key] = selected[key] || []).push(value);
        });

        return selected;
    }

    // ── Filter sections ───────────────────────────────────────

    _initFilterSections() {
        this._filterSections = [];
        this._sectionByTax   = {};

        this._root.querySelectorAll('.js-filter-sec').forEach(el => {
            const section = new FilterSection(el, (key, value, isActive) => {
                this._onFilterOptionToggled(key, value, isActive);
            });
            this._filterSections.push(section);
            this._sectionByTax[el.dataset.section] = section;
        });
    }

    /**
     * Раскладывает пересчитанные сервером фасеты по секциям сайдбара: секции,
     * которых нет в ответе, к текущему срезу неприменимы — скрываем целиком.
     *
     * @param {Object[]} groups - Группы фильтров из ответа сервера.
     * @returns {void}
     */
    _applyFilterGroups(groups) {
        const byTax = new Map(groups.map(group => [group.taxonomy, group]));

        Object.entries(this._sectionByTax).forEach(([taxonomy, section]) => {
            const group = byTax.get(taxonomy);
            section.apply(group || { terms: [], summary: '', active: 0, available: 0 });
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
        this._syncUrl();
        this._reload();
    }

    /**
     * Держит адрес страницы в соответствии с выбранными фильтрами
     * (`filters[<taxonomy>][]=<term>` — тот же формат, что понимает SSR).
     * Благодаря этому обновление страницы сохраняет выбор, а снятый фильтр
     * не возвращается из старой ссылки.
     *
     * @returns {void}
     */
    _syncUrl() {
        const params = new URLSearchParams(window.location.search);

        [...params.keys()]
            .filter(key => key.startsWith('filters['))
            .forEach(key => params.delete(key));

        Object.entries(this._filters.taxonomies).forEach(([taxonomy, terms]) => {
            terms.forEach(term => params.append(`filters[${taxonomy}][]`, term));
        });

        const query = params.toString();
        window.history.replaceState({}, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
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
        this._syncUrl();
        this._reload();
    }

    _syncClearBtns() {
        // Кнопка сброса отражает ТОЛЬКО выбранные фильтры-таксономии, не поиск
        // (у поля поиска свой нативный ×).
        const active = Object.keys(this._filters.taxonomies).length > 0;
        if (this._sideClear) this._sideClear.disabled = !active;
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
            .then(({ tasks, total, has_more, articles, filters }) => {
                this._total   = total;
                this._hasMore = has_more;

                if (reset) {
                    this._cardsWrap.innerHTML = '';
                    this._offset = 0;
                    // Подборка статей зависит от выбранных типов задания —
                    // сервер присылает её только при перезагрузке списка.
                    if (articles) renderSidebarArticles(this._articles, articles);
                    // Фасеты: оставляем в сайдбаре только те варианты, что ещё
                    // встречаются в текущем срезе.
                    if (filters) this._applyFilterGroups(filters);
                }

                tasks.forEach(task => {
                    this._cardsWrap.insertAdjacentHTML('beforeend', buildTaskCard(task));
                });

                this._offset += tasks.length;

                bindAnswerToggle(this._cardsWrap);
                initTaskConditions(this._cardsWrap);
                this._updateCountEl(total);
                this._toggleEmpty(this._offset === 0);
                this._setSentinel(has_more);
            })
            .catch(err => console.error('[AllTasksPage]', err))
            .finally(() => { this._loading = false; });
    }

    // ── UI helpers ────────────────────────────────────────────

    // Число и форму слова обновляем вместе: «1 задание» / «3 задания» / «12 заданий».
    _updateCountEl(n) {
        if (this._countEl) this._countEl.textContent = n;
        if (this._nounEl)  this._nounEl.textContent  = pluralRu(Number(n) || 0, 'задание', 'задания', 'заданий');
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