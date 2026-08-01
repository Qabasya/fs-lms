/**
 * FilterSection — управляет одной секцией фильтра в сайдбаре.
 *
 * Обязанности:
 *   - toggle открытие/закрытие тела секции
 *   - обновление badge с количеством активных значений
 *   - передача выбранного/снятого значения через коллбек onToggle
 *
 * Не знает ничего о логике фильтрации или AJAX.
 */
export class FilterSection {
    /**
     * @param {Element}  el         - Корневой DOM-элемент секции (.js-filter-sec).
     * @param {Function} onToggle   - Вызывается при клике на опцию: (key, value, isActive) => void.
     */
    constructor(el, onToggle) {
        this._el       = el;
        this._onToggle = onToggle;
        this._head     = el.querySelector('.filter-sec-head');
        this._body     = el.querySelector('.filter-sec-body');
        this._summary  = el.querySelector('.filter-sec-summary');
        // Бейдж мог быть отрисован сервером (фильтр пришёл в URL) — переиспользуем его,
        // иначе на первом же клике в шапке появился бы второй.
        this._badge    = el.querySelector('.filter-sec-badge');

        this._bindHead();
        this._bindOptions();
        this._refreshSummary();
    }

    /** Сбрасывает все активные опции внутри секции без вызова коллбека. */
    reset() {
        this._el.querySelectorAll('.js-filter-option.is-active').forEach(b => b.classList.remove('is-active'));
        this._updateBadge(0);
        this._refreshSummary();
    }

    /**
     * Применяет пересчитанную сервером группу: доступность опций, счётчики,
     * сводку и бейдж. Опции не пересоздаются — недоступные под текущий срез
     * скрываются атрибутом hidden и возвращаются, когда снова подходят.
     *
     * @param {Object} group - Группа фильтров из ответа сервера.
     * @returns {void}
     */
    apply(group) {
        const bySlug = new Map((group.terms || []).map(term => [term.slug, term]));

        this._el.querySelectorAll('.js-filter-option').forEach(btn => {
            const term = bySlug.get(btn.dataset.value);

            if (!term) {
                btn.hidden = true;
                return;
            }

            btn.hidden = !term.available;
            btn.classList.toggle('is-active', !!term.selected);

            const count = btn.querySelector('.filter-option-count');
            if (count) count.textContent = term.count;
        });

        if (this._summary) this._summary.textContent = group.summary || '';

        this._updateBadge(group.active || 0);
        this._refreshSummary();

        // Ни одной доступной опции — группа не применима к текущему срезу.
        this._el.hidden = !group.available;
    }

    _bindHead() {
        if (!this._head) return;
        this._head.addEventListener('click', () => {
            const open = this._body && !this._body.hidden;
            if (this._body) this._body.hidden = open;
            this._head.setAttribute('aria-expanded', String(!open));
            this._head.querySelector('.filter-sec-chev')?.classList.toggle('is-open', !open);
            this._refreshSummary();
        });
    }

    _bindOptions() {
        this._el.querySelectorAll('.js-filter-option').forEach(btn => {
            btn.addEventListener('click', () => {
                const isNowActive = btn.classList.toggle('is-active');
                const key         = btn.dataset.filter;
                const value       = btn.dataset.value;

                const activeCount = this._el.querySelectorAll('.js-filter-option.is-active').length;
                this._updateBadge(activeCount);
                this._refreshSummary();
                this._onToggle(key, value, isNowActive);
            });
        });
    }

    /** Сводка видна только когда секция свёрнута и ни одна опция не выбрана. */
    _refreshSummary() {
        if (!this._summary) return;
        const open   = this._body && !this._body.hidden;
        const active = this._el.querySelectorAll('.js-filter-option.is-active').length > 0;
        this._summary.hidden = open || active;
    }

    _updateBadge(count) {
        const right = this._head?.querySelector('.filter-sec-right');
        if (!right) return;

        if (!this._badge) {
            this._badge = document.createElement('span');
            this._badge.className = 'filter-sec-badge';
        }

        if (count > 0) {
            this._badge.textContent = count;
            right.prepend(this._badge);
        } else {
            this._badge.remove();
        }
    }
}