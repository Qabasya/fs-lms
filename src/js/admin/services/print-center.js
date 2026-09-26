/**
 * @module PrintCenter
 * @description Страница «Центр печати» (templates/admin/print-center.php):
 *              поиск ученика по ФИО → его зачисления с родителем → сборка
 *              документа по DOCX-шаблону и скачивание по одноразовой ссылке.
 *
 * @requires jQuery
 */

import '../_types.js';
import { showToast } from '../modules/toast.js';

const $ = jQuery;

/**
 * POST на admin-ajax с нонсом менеджера.
 *
 * @param {string} action Ключ из fs_lms_vars.ajax_actions.
 * @param {Object} data   Параметры запроса.
 * @return {Promise<Object>} Ответ вида { success, data }.
 */
function post( action, data = {} ) {
    return Promise.resolve( $.post( fs_lms_vars.ajaxurl, {
        action:   fs_lms_vars.ajax_actions[ action ],
        security: fs_lms_vars.nonces.manager,
        ...data,
    } ) );
}

/**
 * Скачивание по одноразовой ссылке: файл из AJAX-ответа браузер не сохранит.
 *
 * @param {string} url Ссылка на файл.
 */
function download( url ) {
    const a = document.createElement( 'a' );
    a.href = url;
    document.body.appendChild( a );
    a.click();
    a.remove();
}

export const PrintCenter = {

    _searchTimer: null,
    _searchSeq:   0,
    _studentId:   0,
    _records:     [],
    _results:     [],
    _active:      -1,

    init() {
        this.$form    = $( '#fs-print-center-form' );
        this.$input   = $( '#fs-print-student' );
        this.$list    = $( '#fs-print-student-list' );
        this.$hint    = this.$form.find( '[data-print-student-hint]' );
        this.$record  = $( '#fs-print-record' );
        this.$doc     = $( '#fs-print-document' );
        this.$submit  = this.$form.find( '[data-print-submit]' );
        this.$status  = this.$form.find( '[data-print-status]' );
        this.$inputs  = this.$form.find( '[data-print-inputs]' );

        this.bindSearch();
        this.bindForm();
        this.bindCopy();
        this.bindPrograms();
        this.syncButton();
    },

    // ───────────── Поиск ученика ─────────────

    bindSearch() {
        this.$input.on( 'input', () => {
            this.resetStudent();
            clearTimeout( this._searchTimer );
            this._searchTimer = setTimeout( () => this.search( String( this.$input.val() ).trim() ), 300 );
        } );

        this.$input.on( 'keydown', ( e ) => {
            if ( this.$list.prop( 'hidden' ) ) {
                return;
            }
            if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
                e.preventDefault();
                const step = 'ArrowDown' === e.key ? 1 : -1;
                this.highlight( ( this._active + step + this._results.length ) % this._results.length );
            } else if ( 'Enter' === e.key && this._active >= 0 ) {
                e.preventDefault();
                this.pick( this._results[ this._active ] );
            } else if ( 'Escape' === e.key ) {
                this.closeList();
            }
        } );

        this.$list.on( 'mousedown', '[data-index]', ( e ) => {
            e.preventDefault(); // не терять фокус поля до выбора
            this.pick( this._results[ Number( e.currentTarget.dataset.index ) ] );
        } );

        this.$input.on( 'blur', () => this.closeList() );
    },

    search( query ) {
        if ( query.length < 2 ) {
            this.closeList();
            return;
        }

        const seq = ++this._searchSeq;
        post( 'searchPrintStudents', { query } ).then( ( res ) => {
            if ( seq !== this._searchSeq ) {
                return; // пришёл ответ на устаревший запрос
            }
            this.renderList( res.success ? res.data : [] );
        } );
    },

    renderList( items ) {
        this._results = items;
        this._active  = -1;
        this.$list.empty();

        if ( ! items.length ) {
            this.$list.append( $( '<li class="fs-print-center__suggest-empty">' ).text( 'Ученики не найдены' ) );
        }

        items.forEach( ( item, i ) => {
            const $li = $( '<li class="fs-print-center__suggest-item" role="option">' )
                .attr( { 'data-index': i, id: `fs-print-student-opt-${ i }` } )
                .append( $( '<span class="fs-print-center__suggest-name">' ).text( item.name ) );
            if ( item.hint ) {
                $li.append( $( '<span class="fs-print-center__suggest-hint">' ).text( item.hint ) );
            }
            this.$list.append( $li );
        } );

        this.$list.prop( 'hidden', false );
        this.$input.attr( 'aria-expanded', 'true' );
    },

    highlight( index ) {
        this._active = index;
        const $items = this.$list.children( '[data-index]' );
        $items.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
        const $current = $items.eq( index ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
        this.$input.attr( 'aria-activedescendant', $current.attr( 'id' ) || '' );
        $current.get( 0 )?.scrollIntoView( { block: 'nearest' } );
    },

    closeList() {
        this.$list.prop( 'hidden', true );
        this.$input.attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
    },

    pick( item ) {
        if ( ! item ) {
            return;
        }
        this.closeList();
        this.$input.val( item.name );
        this._studentId = item.id;
        this.$hint.text( item.hint ).prop( 'hidden', ! item.hint );
        this.loadRecords();
    },

    resetStudent() {
        this._studentId = 0;
        this._records   = [];
        this.$hint.prop( 'hidden', true );
        this.$form.find( '[data-print-record-field], [data-print-parent-field]' ).prop( 'hidden', true );
        this.setStatus( '' );
        this.syncButton();
    },

    // ───────────── Зачисление и родитель ─────────────

    loadRecords() {
        const studentId = this._studentId;
        this.setStatus( 'Загрузка…' );

        post( 'getPrintStudentRecords', { student_id: studentId } ).then( ( res ) => {
            if ( studentId !== this._studentId ) {
                return;
            }
            this._records = res.success ? res.data : [];
            this.renderRecords();
        } );
    },

    renderRecords() {
        this.$record.empty();
        this._records.forEach( ( r ) => {
            this.$record.append( $( '<option>' ).val( r.id ).text( r.label ) );
        } );

        // Выбор — только когда действующих зачислений несколько; одно подставляется молча.
        const hasRecords = this._records.length > 0;
        this.$form.find( '[data-print-record-field]' ).prop( 'hidden', this._records.length < 2 );
        this.setStatus( hasRecords ? '' : 'У ученика нет зачислений — родитель не определён.' );
        this.renderParent();
    },

    renderParent() {
        const record = this.currentRecord();
        const $field = this.$form.find( '[data-print-parent-field]' );

        $field.prop( 'hidden', ! record );
        this.$form.find( '[data-print-parent]' )
            .text( record?.parent ? record.parent.name : 'Родитель в зачислении не указан' )
            .toggleClass( 'is-missing', ! record?.parent );

        this.syncButton();
    },

    currentRecord() {
        const id = Number( this.$record.val() );
        return this._records.find( ( r ) => r.id === id ) || null;
    },

    // ───────────── Форма и формирование ─────────────

    bindForm() {
        this.$record.on( 'change', () => this.renderParent() );

        this.$doc.on( 'change', () => {
            this.$submit.text( this.$doc.find( ':selected' ).data( 'buttonLabel' ) );
            this.syncInputs();
            this.syncButton();
        } );

        this.$inputs.on( 'input', '[data-print-input]', () => this.syncButton() );
        this.syncInputs();

        this.$form.on( 'submit', ( e ) => {
            e.preventDefault();
            this.generate();
        } );
    },

    /** Форме нужны данные со страницы (справка на вычет). */
    needsInput() {
        return '1' === String( this.$doc.find( ':selected' ).data( 'needsInput' ) );
    },

    syncInputs() {
        this.$inputs.prop( 'hidden', ! this.needsInput() );
    },

    /** Введённые данные формы: { tax_number, tax_year, tax_sum }. */
    inputValues() {
        const values = {};
        this.$inputs.find( '[data-print-input]' ).each( ( i, el ) => {
            values[ el.dataset.printInput ] = String( el.value ).trim();
        } );
        return values;
    },

    syncButton() {
        const record = this.currentRecord();
        const filled = ! this.needsInput() || Object.values( this.inputValues() ).every( ( v ) => '' !== v );
        const ready  = this._studentId > 0
            && record?.parent
            && filled
            && ! this.$doc.find( ':selected' ).prop( 'disabled' )
            && ! this.$submit.is( '[data-print-locked]' );

        this.$submit.prop( 'disabled', ! ready );
    },

    generate() {
        const record = this.currentRecord();
        if ( ! record ) {
            return;
        }

        this.$submit.prop( 'disabled', true );
        this.setStatus( 'Формирование…' );

        post( 'generatePrintDocument', {
            document:   this.$doc.val(),
            student_id: this._studentId,
            record_id:  record.id,
            ...( this.needsInput() ? this.inputValues() : {} ),
        } ).then( ( res ) => {
            if ( ! res.success ) {
                this.setStatus( '' );
                // error() отдаёт строку, fail() — объект { message }.
                showToast( res.data?.message || res.data || 'Не удалось сформировать документ.', 'error' );
                return;
            }

            download( res.data.url );
            this.setStatus( `Скачан файл «${ res.data.filename }».` );

            if ( res.data.empty?.length ) {
                showToast( `Не заполнены поля: ${ res.data.empty.join( '; ' ) }. Проверьте документ перед печатью.`, 'warning', 8000 );
            }
        } ).catch( ( xhr ) => {
            this.setStatus( '' );
            showToast( xhr?.responseJSON?.data?.message || xhr?.responseJSON?.data || 'Ошибка соединения.', 'error' );
        } ).finally( () => this.syncButton() );
    },

    setStatus( text ) {
        this.$status.text( text );
    },

    // ───────────── Программы и цены ─────────────

    bindPrograms() {
        const $rows = $( '[data-print-program]' );

        $rows.on( 'input', 'input', ( e ) => {
            $( e.delegateTarget ).find( '[data-print-program-save]' ).prop( 'disabled', false );
        } );

        $rows.on( 'click', '[data-print-program-save]', ( e ) => {
            const $row = $( e.delegateTarget );
            const $btn = $( e.currentTarget ).prop( 'disabled', true );

            post( 'savePrintProgram', {
                subject_key: $row.data( 'printProgram' ),
                program:     $row.find( '[data-field="program"]' ).val(),
                price:       $row.find( '[data-field="price"]' ).val(),
            } ).then( ( res ) => {
                if ( res.success ) {
                    showToast( 'Сохранено.', 'success' );
                } else {
                    $btn.prop( 'disabled', false );
                    showToast( res.data?.message || res.data || 'Не удалось сохранить.', 'error' );
                }
            } ).catch( () => {
                $btn.prop( 'disabled', false );
                showToast( 'Ошибка соединения.', 'error' );
            } );
        } );
    },

    // ───────────── Справочник полей ─────────────

    bindCopy() {
        $( '.fs-print-center' ).on( 'click', '[data-print-copy]', ( e ) => {
            const code = e.currentTarget.dataset.printCopy;
            navigator.clipboard?.writeText( code ).then(
                () => showToast( `Скопировано: ${ code }`, 'success' ),
                () => showToast( 'Не удалось скопировать.', 'error' )
            );
        } );
    },
};
