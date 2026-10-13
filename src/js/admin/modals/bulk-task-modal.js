/**
 * @module BulkTaskModal
 * @description Окно «Массовое добавление заданий» конструктора работы: переключатель
 *              источника (приватный банк / публичные задачи), поиск и список заданий
 *              с чекбоксами. Список догружается по прокрутке; отметки переживают смену
 *              источника и поиска.
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

const BulkTaskModal = {
    /** @type {jQuery} */
    $modal: null,

    /** Повторный init() — no-op: модалку поднимает и автозагрузчик ui.js, и вызывающий. */
    _initialized: false,

    init() {
        if ( this._initialized ) { return; }
        this._initialized = true;
        this.$modal = $( '#fs-lms-bulk-task-modal' );
    },

    /**
     * Открывает окно и возвращает выбранные задания.
     *
     * @param {Object}   opts
     * @param {Function} opts.fetchFn    (query: string, scope: 'subject'|'public', page: number) => Promise<Object[]>
     *                                   Страница кандидатов `{id, title, type?, bundle_children?}`;
     *                                   пустая страница — конец списка.
     * @param {number[]} [opts.takenIds] ID заданий, которые уже стоят в работе: их строки
     *                                   показаны отмеченными и недоступными.
     * @returns {Promise<Object[]>} Выбранные элементы в порядке отметки; reject — окно закрыто без выбора.
     */
    open( { fetchFn, takenIds = [] } ) {
        this.init();
        if ( ! this.$modal.length ) { return Promise.reject( 'missing' ); }

        const $modal   = this.$modal;
        const $list    = $modal.find( '[data-bulk-list]' );
        const $search  = $modal.find( '[data-bulk-search]' );
        const $count   = $modal.find( '[data-bulk-count]' );
        const $confirm = $modal.find( '.fs-lms-modal-confirm' );
        const $sources = $modal.find( 'input[name="fs_bulk_task_source"]' );

        const taken    = new Set( takenIds );
        const selected = new Map(); // id → элемент кандидата; Map хранит порядок отметки
        let scope      = 'subject';
        let page       = 0;
        let hasMore    = true;
        let loading    = false;
        let requestId  = 0;         // отсекает ответы устаревших запросов
        let timer      = null;

        // Родитель связки 19–21 раскладывается в работе на подзадания — «уже в работе»
        // он тогда, когда в ней стоят все его части.
        const isTaken = ( item ) => ( Array.isArray( item.bundle_children ) && item.bundle_children.length
            ? item.bundle_children.every( ( child ) => taken.has( parseInt( child.id, 10 ) ) )
            : taken.has( parseInt( item.id, 10 ) ) );

        const updateCount = () => {
            $count.text( selected.size ? `Выбрано: ${ selected.size }` : 'Ничего не выбрано' );
            $confirm.prop( 'disabled', ! selected.size );
        };

        const setNote = ( text ) => {
            $list.find( '.fs-bulk-task__note' ).remove();
            if ( text ) { $list.append( `<div class="fs-bulk-task__note">${ esc( text ) }</div>` ); }
        };

        const renderRow = ( item ) => {
            const id       = parseInt( item.id, 10 );
            const disabled = isTaken( item );
            // В приватном банке вперемешку идут задачи банка и свои задания предмета —
            // метка различает их; у публичных задач источник один, метка была бы шумом.
            const origin   = disabled
                ? 'В работе'
                : ( 'public' === scope ? '' : ( 'problem' === item.type || 'bank' === item.source ? 'Банк' : 'Предмет' ) );

            const $row = $( `
                <label class="fs-bulk-task__row${ disabled ? ' is-taken' : '' }">
                    <span class="fs-bulk-task__title">${ esc( String( item.title || '(без названия)' ) ) }</span>
                    ${ origin ? `<span class="fs-bulk-task__origin">${ origin }</span>` : '' }
                    <input type="checkbox" class="fs-bulk-task__check">
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

            Promise.resolve( fetchFn( $search.val().trim(), scope, page + 1 ) )
                .then( ( items ) => {
                    if ( current !== requestId ) { return; }

                    loading = false;
                    page   += 1;
                    hasMore = items.length > 0;
                    setNote( '' );
                    items.forEach( ( item ) => $list.append( renderRow( item ) ) );

                    if ( ! $list.children().length ) { setNote( 'Задания не найдены' ); }

                    // Страница не заполнила окно — прокрутки нет и догрузка сама не случится.
                    if ( hasMore && $list[ 0 ].scrollHeight <= $list[ 0 ].clientHeight ) { loadPage(); }
                } )
                .catch( () => {
                    if ( current !== requestId ) { return; }

                    loading = false;
                    hasMore = false;
                    setNote( 'Не удалось загрузить задания' );
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
        $search.val( '' );
        $sources.filter( '[value="subject"]' ).prop( 'checked', true );
        updateCount();

        return new Promise( ( resolve, reject ) => {
            const close = () => {
                clearTimeout( timer );
                requestId += 1;
                unbindEsc( 'bulk_task' );
                $modal.find( '*' ).addBack().off( '.bulkTask' );
                closeModal( $modal, () => $list.empty() );
            };
            const cancel = () => { close(); reject( 'cancel' ); };

            $sources.on( 'change.bulkTask', ( e ) => { scope = e.currentTarget.value; reload(); } );
            $search.on( 'input.bulkTask', () => { clearTimeout( timer ); timer = setTimeout( reload, SEARCH_DELAY ); } );
            $list.on( 'scroll.bulkTask', () => {
                const el = $list[ 0 ];
                if ( el.scrollTop + el.clientHeight >= el.scrollHeight - SCROLL_THRESHOLD ) { loadPage(); }
            } );

            $confirm.on( 'click.bulkTask', () => { close(); resolve( Array.from( selected.values() ) ); } );
            $modal.find( '.fs-lms-modal-cancel, .fs-lms-modal-close, .fs-lms-modal-backdrop' ).on( 'click.bulkTask', cancel );
            bindEsc( 'bulk_task', cancel );

            openModal( $modal );
            reload();
            $search.trigger( 'focus' );
        } );
    },
};

export { BulkTaskModal };
