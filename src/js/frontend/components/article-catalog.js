import { FilterSection } from './filter-section.js';
import { pluralRu }      from '../../common/plural.js';

/** Сколько секций (номеров заданий) показывать до подгрузки следующей порции. */
const SECTIONS_PER_PAGE = 5;

/** За сколько пикселей до сентинела начинать подгрузку — как на тренажёре. */
const LOAD_MARGIN = 320;

/**
 * Каталог учебника: фильтры сайдбара, поиск и подгрузка секций по прокрутке.
 *
 * Работает без сети — весь каталог предмета отрисован сервером, поэтому
 * фильтр только прячет карточки, а счётчики и сводки групп пересчитывает по
 * видимому срезу сам. Правила счёта те же, что на сервере у тренажёра
 * (PHP-зеркало — `Inc\Services\Subject\FilterGroupService`): опции группы
 * считаются под ОСТАЛЬНЫЕ фильтры, иначе выбор схлопнул бы её же список.
 *
 * Подгрузка тоже своя: догружать нечего, поэтому «страница» — это окно из
 * SECTIONS_PER_PAGE подходящих секций, которое растёт при подходе к сентинелу.
 *
 * @returns {void}
 */
export function initArticleCatalog() {
    const root = document.querySelector('.fs-articles-page');
    if (!root) return;

    const cards    = [...root.querySelectorAll('.js-article-card')];
    const sections = [...root.querySelectorAll('.js-articles-sec')];
    const empty    = root.querySelector('.js-articles-empty');
    const input    = root.querySelector('.js-search-input');
    const clears   = [...root.querySelectorAll('.js-filters-clear')];
    const sentinel = root.querySelector('.js-infinite-sentinel');
    const listEnd  = root.querySelector('.js-infinite-end');

    // Выбранные значения: таксономия → Set слагов терминов.
    const selected = new Map();
    let   query    = '';

    // Секции текущего среза и размер показанного окна.
    let matching = [];
    let shown    = SECTIONS_PER_PAGE;

    // Термины и текст карточки читаются один раз: разметка каталога не
    // меняется, а фильтр дёргается на каждый ввод.
    const index = new Map(cards.map(card => [card, {
        terms: parseTerms(card.dataset.terms || ''),
        text:  card.textContent.toLowerCase(),
    }]));

    const groups = [...root.querySelectorAll('.js-filter-sec')].map(el => ({
        el,
        key:     el.dataset.section,
        isType:  el.dataset.isType === '1',
        options: [...el.querySelectorAll('.js-filter-option')].map(btn => ({
            slug: btn.dataset.value,
            name: btn.querySelector('.filter-option-label')?.textContent.trim() ?? '',
        })),
        ui: new FilterSection(el, (key, value, isActive) => {
            if (!selected.has(key)) selected.set(key, new Set());

            const values = selected.get(key);
            if (isActive) {
                values.add(value);
            } else {
                values.delete(value);
            }

            apply();
        }),
    }));

    const apply = () => {
        let total = 0;

        matching = [];

        sections.forEach(section => {
            let visible = 0;

            section.querySelectorAll('.js-article-card').forEach(card => {
                const ok = matches(card, selected);
                card.hidden = !ok;
                if (ok) visible++;
            });

            if (visible > 0) matching.push(section);
            total += visible;

            const count = section.querySelector('.js-articles-count');
            if (count) count.textContent = `${visible} ${pluralRu(visible, 'статья', 'статьи', 'статей')}`;
        });

        // Новый срез — окно снова с начала списка.
        shown = SECTIONS_PER_PAGE;
        paginate();

        // Пустой учебник и пустая выборка — разные состояния: первое рисует
        // сервер, второе появляется только когда карточки вообще есть.
        if (empty) empty.hidden = total > 0 || cards.length === 0;

        const active = [...selected.values()].some(values => values.size > 0) || query !== '';
        clears.forEach(btn => { btn.disabled = !active; });

        refreshFacets();
    };

    /**
     * Показывает первые `shown` подходящих секций, прячет остальные и
     * переключает подпись под списком.
     *
     * @returns {void}
     */
    function paginate() {
        sections.forEach(section => { section.hidden = true; });
        matching.slice(0, shown).forEach(section => { section.hidden = false; });

        const more = matching.length > shown;

        if (sentinel) sentinel.hidden = !more;
        if (listEnd) listEnd.hidden = more || matching.length === 0;
    }

    /**
     * Открывает следующую порцию секций. Повторяет, пока сентинел остаётся в
     * зоне видимости: короткие секции могут не заполнить экран, а второй раз
     * наблюдатель на неизменившемся пересечении не сработает.
     *
     * @returns {void}
     */
    function loadMore() {
        while (matching.length > shown && sentinelInView()) {
            shown += SECTIONS_PER_PAGE;
            paginate();
        }
    }

    /**
     * Подошла ли прокрутка к сентинелу.
     *
     * @returns {boolean}
     */
    function sentinelInView() {
        if (!sentinel || sentinel.hidden) return false;

        return sentinel.getBoundingClientRect().top < window.innerHeight + LOAD_MARGIN;
    }

    /**
     * Пересчитывает счётчики, доступность опций и сводку каждой группы.
     *
     * @returns {void}
     */
    const refreshFacets = () => {
        groups.forEach(group => {
            const constraint = new Map([...selected].filter(([key]) => key !== group.key));
            const counts     = new Map();

            cards.forEach(card => {
                if (!matches(card, constraint)) return;

                (index.get(card).terms.get(group.key) ?? []).forEach(slug => {
                    counts.set(slug, (counts.get(slug) ?? 0) + 1);
                });
            });

            const chosen = selected.get(group.key) ?? new Set();
            const terms  = group.options.map(option => {
                const count = counts.get(option.slug) ?? 0;

                return {
                    slug:     option.slug,
                    name:     option.name,
                    count,
                    selected: chosen.has(option.slug),
                    // Выбранный термин виден и с нулём — иначе снять
                    // несовместимый выбор было бы нечем.
                    available: count > 0 || chosen.has(option.slug),
                };
            });

            const available = terms.filter(term => term.available);

            group.ui.apply({
                terms,
                summary:   summaryFor(available, group.isType),
                active:    chosen.size,
                available: available.length,
            });
        });
    };

    /**
     * Проходит ли карточка набор фильтров и поисковый запрос.
     *
     * @param {Element} card       - Карточка статьи.
     * @param {Map}     constraint - Фильтры: таксономия → Set слагов.
     *
     * @returns {boolean}
     */
    function matches(card, constraint) {
        const { terms, text } = index.get(card);

        for (const [taxonomy, values] of constraint) {
            if (!values.size) continue;

            const own = terms.get(taxonomy);
            if (!own || ![...values].some(value => own.has(value))) return false;
        }

        return !query || text.includes(query);
    }

    if (input) {
        input.addEventListener('input', () => {
            query = input.value.trim().toLowerCase();
            apply();
        });
    }

    if (sentinel) {
        const observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) loadMore();
        }, { rootMargin: `${LOAD_MARGIN}px 0px` });

        observer.observe(sentinel);
    }

    clears.forEach(btn => btn.addEventListener('click', () => {
        groups.forEach(group => group.ui.reset());
        selected.clear();
        query = '';

        if (input && input.value !== '') {
            input.value = '';
            // Событие input — чтобы search-box.js убрал крестик очистки;
            // оно же вызовет apply() своим обработчиком.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        apply();
    }));

    apply();
}

/**
 * Разбирает токены карточки (`taxonomy:slug`) в карту таксономий.
 *
 * @param {string} tokens - Значение data-terms.
 *
 * @returns {Map<string, Set<string>>}
 */
function parseTerms(tokens) {
    const map = new Map();

    tokens.split(' ').filter(Boolean).forEach(token => {
        const at  = token.indexOf(':');
        const tax = token.slice(0, at);

        if (!map.has(tax)) map.set(tax, new Set());
        map.get(tax).add(token.slice(at + 1));
    });

    return map;
}

/**
 * Сводка группы: годы — диапазоном, тип задания — со склонением.
 * PHP-зеркало — FilterGroupService::summary().
 *
 * @param {Array}   terms  - Доступные опции группы.
 * @param {boolean} isType - Группа типа задания.
 *
 * @returns {string}
 */
function summaryFor(terms, isType) {
    const count = terms.length;
    const years = terms.filter(term => /^\d{4}$/.test(term.name));

    if (count > 0 && years.length === count) {
        return yearSummary(terms.map(term => parseInt(term.name, 10)));
    }

    return isType
        ? `Все ${count} ${pluralRu(count, 'тип', 'типа', 'типов')}`
        : `Все ${count}`;
}

/**
 * Сводка группы годов: до трёх — перечислением, непрерывный ряд —
 * диапазоном, остальное — числом лет.
 *
 * @param {number[]} years - Годы доступных опций.
 *
 * @returns {string}
 */
function yearSummary(years) {
    const sorted = [...new Set(years)].sort((a, b) => a - b);
    const count  = sorted.length;
    const min    = sorted[0];
    const max    = sorted[count - 1];

    if (count <= 3) return sorted.join(', ');
    if (max - min + 1 === count) return `${min}—${max}`;

    return `${count} ${pluralRu(count, 'год', 'года', 'лет')}`;
}