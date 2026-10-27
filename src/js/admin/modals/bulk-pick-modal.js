/**
 * @module BulkPickModal
 * @description Общее окно множественного выбора из каталога: поиск, список с чекбоксами,
 *              необязательные переключатель источника и выбор предмета. Им пользуются
 *              «Массовое добавление заданий» конструктора работы и (дальше) добавление уроков
 *              в курс — различия задаёт конфиг вызова, а не копия окна. Список догружается
 *              по прокрутке; отметки переживают смену источника, предмета и поиска.
 *
 *              В сеть модалка не ходит: страницы списка отдаёт `fetchFn` вызывающего
 *              (см. slot-builder.js), сама она только рисует и собирает выбор.
 *
 * @requires jQuery
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';
import { escapeHtml as esc } from '../modules/utils.js';

const $ = jQuery;

/** Пауза после ввода в поиск перед запросом, мс. */
const SEARCH_DELAY = 300;

/** За сколько пикселей до конца списка запрашивать следующую страницу. */
const SCROLL_THRESHOLD = 80;

const BulkPickModal = {
    /** @type {jQuery} */
    $modal: null,

    /** Повторный init() — no-op: модалку поднимает и автозагрузчик ui.js, и вызывающий. */
    _initialized: false,

    init() {
        if ( this._initialized ) { return; }
        this._initialized = true;
        this.$modal = $( '#fs-lms-bulk-pick-modal' );
    },

    /**
     * Открывает окно и возвращает выбранные элементы.
     *
     * @param {Object}   opts
     * @param {Function} opts.fetchFn    (query: string, source: string, subject: string, page: number) => Promise<Object[]>
     *                                   Страница кандидатов `{id, title, …}`; пустая страница — конец списка.
     * @param {string}   [opts.title]           Заголовок окна.
     * @param {string}   [opts.confirmText]     Подпись кнопки подтверждения. Функция `(subject) => string` —
     *                                          подпись зависит от выбранного предмета (добавить / скопировать).
     * @param {string}   [opts.searchPlaceholder]
     * @param {string}   [opts.emptyText]       Текст пустого списка.
     * @param {string}   [opts.takenLabel]      Метка уже добавленных элементов.
     * @param {{value: string, label: string}[]} [opts.sources] Источники (радио); меньше двух — переключателя нет.
     * @param {{key: string, name: string}[]}    [opts.subjects] Предметы (выпадающий список); меньше двух — списка нет.
     * @param {string}   [opts.subject]         Предмет, выбранный при открытии (по умолчанию первый из `subjects`).
     * @param {number[]} [opts.takenIds]        ID, которые уже добавлены: их строки отмечены и недоступны.
     * @param {Function} [opts.isTaken]         (item, takenSet) => boolean — своё правило «уже добавлено».
     * @param {Function} [opts.originLabel]     (item, { source, subject }) => string — метка происхождения в строке.
     * @returns {Promise<Object[]>} Выбранные элементы в порядке отметки; reject — окно закрыто без выбора.
     */
    open( {
        fetchFn,
        title             = 'Выбор',
        confirmText       = 'Добавить',
        searchPlaceholder = 'Поиск по названию…',
        emptyText         = 'Ничего не найдено',
        takenLabel        = 'Уже добавлено',
        sources           = [],
        subjects          = [],
        subject           = '',
        takenIds          = [],
        isTaken           = ( item, takenSet ) => takenSet.has( parseInt( item.id, 10 ) ),
        originLabel       = () => '',
    } ) {
        this.init();
        if ( ! this.$modal.length ) { return Promise.reject( 'missing' ); }

        const $modal    = this.$modal;
        const $list     = $modal.find( '[data-bulk-list]' );
        const $search   = $modal.find( '[data-bulk-search]' );
        const $count    = $modal.find( '[data-bulk-count]' );
        const $confirm  = $modal.find( '.fs-lms-modal-confirm' );
        const $sources  = $modal.find( '[data-bulk-sources]' );
        const $subject  = $modal.find( '[data-bulk-subject]' );

        const taken    = new Set( takenIds );
        const selected = new Map(); // id → элемент кандидата; Map хранит порядок отметки
        let source     = sources[ 0 ]?.value ?? '';
        let subjectKey = subject || subjects[ 0 ]?.key || '';
        let page       = 0;
        let hasMore    = true;
        let loading    = false;
        let requestId  = 0;         // отсекает ответы устаревших запросов
        let timer      = null;

        const confirmLabel = () => ( 'function' === typeof confirmText ? confirmText( subjectKey ) : confirmText );

        const updateCount = () => {
            $count.text( selected.size ? `Выбрано: ${ selected.size }` : 'Ничего не выбрано' );
            $confirm.prop( 'disabled', ! selected.size ).text( confirmLabel() );
        };

        const setNote = ( text ) => {
            $list.find( '.fs-bulk-pick__note' ).remove();
            if ( text ) { $list.append( `<div class="fs-bulk-pick__note">${ esc( text ) }</div>` ); }
        };

        const renderRow = ( item ) => {
            const id       = parseInt( item.id, 10 );
            const disabled = isTaken( item, taken );
            const origin   = disabled ? takenLabel : originLabel( item, { source, subject: subjectKey } );

            const $row = $( `
                <label class="fs-bulk-pick__row${ disabled ? ' is-taken' : '' }">
                    <span class="fs-bulk-pick__title">${ esc( String( item.title || '(без названия)' ) ) }</span>
                    ${ origin ? `<span class="fs-bulk-pick__origin">${ esc( origin ) }</span>` : '' }
                    <input type="checkbox" class="fs-bulk-pick__check">
                </label>` );

            $row.find( 'input' )
                .prop( { checked: disabled || selected.has( id ), disabled } )
                .on( 'change', ( e ) => {
                    if ( e.currentTarget.checked ) { selected.set( id, item ); } else { selected.delete( id ); }
                    updateCount();
                } );

            return $row;
        };

        const loadPage = () => {
            if ( loading || ! hasMore ) { return; }

            loading = true;
            const current = ++requestId;
            setNote( 'Загрузка…' );

            Promise.resolve( fetchFn( $search.val().trim(), source, subjectKey, page + 1 ) )
                .then( ( items ) => {
                    if ( current !== requestId ) { return; }

                    loading = false;
                    page   += 1;
                    hasMore = items.length > 0;
                    setNote( '' );
                    items.forEach( ( item ) => $list.append( renderRow( item ) ) );

                    if ( ! $list.children().length ) { setNote( emptyText ); }

                    // Страница не заполнила окно — прокрутки нет и догрузка сама не случится.
                    if ( hasMore && $list[ 0 ].scrollHeight <= $list[ 0 ].clientHeight ) { loadPage(); }
                } )
                .catch( () => {
                    if ( current !== requestId ) { return; }

                    loading = false;
                    hasMore = false;
                    setNote( 'Не удалось загрузить список' );
                } );
        };

        const reload = () => {
            requestId += 1; // ответ запроса, ушедшего до смены фильтра, больше не нужен
            loading    = false;
            page       = 0;
            hasMore    = true;
            $list.empty().scrollTop( 0 );
            loadPage();
        };

        // Состояние прошлого открытия
        $modal.find( '.fs-lms-modal-title' ).text( title );
        $search.val( '' ).attr( 'placeholder', searchPlaceholder );

        $sources.empty().prop( 'hidden', sources.length < 2 );
        sources.forEach( ( s, i ) => $sources.append( `
            <label class="fs-bulk-pick__source">
                <input type="radio" name="fs_bulk_pick_source" value="${ esc( s.value ) }"${ 0 === i ? ' checked' : '' }>
                ${ esc( s.label ) }
            </label>` ) );

        $subject.empty().prop( 'hidden', subjects.length < 2 );
        subjects.forEach( ( s ) => $subject.append(
            $( '<option>' ).val( s.key ).text( s.name ).prop( 'selected', s.key === subjectKey )
        ) );

        updateCount();

        return new Promise( ( resolve, reject ) => {
            const close = () => {
                clearTimeout( timer );
                requestId += 1;
                unbindEsc( 'bulk_pick' );
                $modal.find( '*' ).addBack().off( '.bulkPick' );
                closeModal( $modal, () => $list.empty() );
            };
            const cancel = () => { close(); reject( 'cancel' ); };

            $sources.on( 'change.bulkPick', 'input', ( e ) => { source = e.currentTarget.value; reload(); } );
            $subject.on( 'change.bulkPick', ( e ) => { subjectKey = e.currentTarget.value; updateCount(); reload(); } );
            $search.on( 'input.bulkPick', () => { clearTimeout( timer ); timer = setTimeout( reload, SEARCH_DELAY ); } );
            $list.on( 'scroll.bulkPick', () => {
                const el = $list[ 0 ];
                if ( el.scrollTop + el.clientHeight >= el.scrollHeight - SCROLL_THRESHOLD ) { loadPage(); }
            } );

            $confirm.on( 'click.bulkPick', () => { close(); resolve( Array.from( selected.values() ) ); } );
            $modal.find( '.fs-lms-modal-cancel, .fs-lms-modal-close, .fs-lms-modal-backdrop' ).on( 'click.bulkPick', cancel );
            bindEsc( 'bulk_pick', cancel );

            openModal( $modal );
            reload();
            $search.trigger( 'focus' );
        } );
    },
};

export { BulkPickModal };
