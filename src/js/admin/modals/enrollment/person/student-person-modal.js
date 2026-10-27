/**
 * @module StudentPersonModal
 * @description UI-компонент модального окна для просмотра и редактирования данных студента.
 *              Вся общая логика — {@link createPersonModal}; студент добавляет отображение
 *              дополнительных зачислений (несколько предметов/групп): строки клонируются
 *              из скрытого шаблона и очищаются при сбросе модалки.
 *
 * @requires jQuery
 */

import { createPersonModal } from './person-modal-base.js';

const base = createPersonModal( {
    selector:   '#fs-student-person-modal',
    escNs:      'spm',
    regenClass: 'js-spm-regen-pwd',
    // Имя события отличается от родительского 'fs-lms:regenerate-password', чтобы избежать конфликтов.
    regenEvent: 'fs-lms:spm-regenerate-password',
} );

/**
 * UI-компонент модального окна управления данными студента.
 * Работает в связке с StudentPersonModalManager, который обрабатывает AJAX-запросы.
 */
export const StudentPersonModal = {
    ...base,

    /**
     * Сброс: дополнительно убирает строки зачислений — при следующем открытии для другого
     * студента не должны отображаться зачисления предыдущего.
     */
    reset() {
        this.$el.find( '.js-spm-extra-enrollment-area' ).empty();
        base.reset.call( this );
    },

    /**
     * Добавление строки дополнительного зачисления в модалку.
     * Используется для отображения нескольких зачислений студента (если он учится на нескольких предметах).
     *
     * ПАТТЕРН: Клонирование шаблона (Template Cloning).
     * Вместо генерации HTML через шаблонные строки, мы клонируем скрытый шаблон из DOM,
     * заполняем его данными и добавляем в контейнер. Это более надежно и поддерживает
     * сложную структуру HTML с валидацией, стилями и событиями.
     *
     * @param {Object} data - Объект с данными зачисления.
     * @param {string} [data.contract_no] - Номер договора.
     * @param {string} [data.subject] - Название предмета.
     * @param {string} [data.group] - Название группы.
     * @param {string} [data.schedule] - Расписание занятий.
     */
    addEnrollmentRow( data ) {
        // КЛОНИРОВАНИЕ ШАБЛОНА:
        // Находим скрытый шаблон в DOM и клонируем его.
        // .clone() создает глубокую копию элемента со всеми дочерними элементами и атрибутами.
        // Шаблон находится внутри .js-spm-extra-enrollment-template, чтобы его можно было
        // легко найти и чтобы он не отображался на странице (обычно скрыт через CSS).
        const $tpl = this.$el.find( '.js-spm-extra-enrollment-template .js-spm-extra-enrollment-block' ).clone();

        // ЗАПОЛНЕНИЕ КЛОНИРОВАННОГО ШАБЛОНА:
        // Итерируем по всем ключам объекта data и заполняем соответствующие поля.
        // Поля ищутся по data-атрибуту data-enr-field="ключ".
        Object.entries( data ).forEach( ( [ key, val ] ) => {
            // Проверка val != null (нестрогое равенство) пропускает null и undefined,
            // но разрешает 0, false и пустую строку.
            // String(val) гарантирует, что значение будет строкой (защита от типов).
            $tpl.find( `[data-enr-field="${ key }"]` ).val( val != null ? String( val ) : '' );
        } );

        // ДОБАВЛЕНИЕ В КОНТЕЙНЕР:
        // Добавляем заполненный клон в область для дополнительных зачислений.
        // Теперь он станет видимым (если шаблон был скрыт, а клон — нет).
        this.$el.find( '.js-spm-extra-enrollment-area' ).append( $tpl );
    },
};
