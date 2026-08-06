/**
 * @fileoverview Счётчик символов в метабоксе краткого описания статьи.
 *
 * @module ArticleDescription
 * @description Показывает, сколько из отведённых символов уже занято. Сам предел
 *              держит атрибут maxlength (и обрезка на сервере) — модуль только
 *              отражает состояние, ничего не блокируя.
 */

const $ = jQuery;

export const ArticleDescription = {

    /** @type {boolean} Защита от повторной инициализации */
    _initialized: false,

    /**
     * Навешивает пересчёт на поле описания.
     *
     * @returns {void}
     */
    init() {
        if (this._initialized) return;

        const $root = $('.js-article-description');
        if (!$root.length) return;

        this._initialized = true;

        $root.on('input.fs', '.js-article-description__input', (e) => {
            const $input = $(e.currentTarget);
            // [...string] считает символы, а не UTF-16-единицы: эмодзи и составные
            // символы не должны раздувать счётчик вдвое против серверного mb_substr().
            const length = [...$input.val()].length;

            $input.closest('.js-article-description')
                .find('.js-article-description__counter')
                .text(String(length));
        });
    },
};
