import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ParentPersonModal = {
    $el: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        this.$el = $( '#fs-parent-person-modal' );
        if ( ! this.$el.length ) return;
        this._initialized = true;
        this._bindUiEvents();
    },

    _bindUiEvents() {
        this.$el.on( 'click', '.fs-lms-modal-backdrop, .js-modal-close, .js-pmm-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );
    },

    open() {
        bindEsc( 'ppm', () => this.close() );
        openModal( this.$el );
    },

    close() {
        unbindEsc( 'ppm' );
        closeModal( this.$el, () => this.reset() );
    },

    reset() {
        this.$el.find( '.fs-person-field' ).val( '' );
        this.$el.find( '.fs-lms-modal-title' ).text( '' );
        this.$el.find( '.js-reveal-all' ).text( 'Показать данные' ).prop( 'disabled', false );
        this.setEditing( false );
        this.$el.removeData( 'personId' ).removeData( 'wpUserId' );
    },

    setPersonId( id ) { this.$el.data( 'personId', id ); },
    setWpUserId( id ) { this.$el.data( 'wpUserId', id ); },
    getPersonId() { return parseInt( this.$el.data( 'personId' ), 10 ) || 0; },
    getWpUserId() { return parseInt( this.$el.data( 'wpUserId' ), 10 ) || 0; },

    fill( data ) {
        if ( 'display_name' in data ) {
            this.$el.find( '.fs-lms-modal-title' ).text( data.display_name || '' );
        }
        Object.entries( data ).forEach( ( [ key, val ] ) => {
            this.$el.find( `[data-field="${ key }"]` ).val( val != null ? String( val ) : '' );
        } );
    },

    fillRevealed( data ) {
        Object.entries( data ).forEach( ( [ key, val ] ) => {
            if ( val ) this.$el.find( `[data-field="${ key }"]` ).val( String( val ) );
        } );
        this.$el.find( '.js-reveal-all' ).text( 'Данные раскрыты' ).prop( 'disabled', true );
    },

    setEditing( editing ) {
        this.$el.find( '.fs-person-field' ).prop( 'readonly', ! editing );
        this.$el.find( '.js-pmm-edit' ).prop( 'hidden', editing );
        if ( editing ) {
            this.$el.find( '.fs-lms-modal-footer' ).append(
                '<button type="button" class="button button-primary js-pmm-save">Сохранить</button>' +
                '<button type="button" class="button js-pmm-cancel">Отмена</button>'
            );
        } else {
            this.$el.find( '.js-pmm-save, .js-pmm-cancel' ).remove();
        }
    },

    getEditData() {
        const data = {};
        this.$el.find( '.fs-person-field[data-field]' ).each( ( _, el ) => {
            const key = el.dataset.field;
            if ( key && el.value.trim() ) data[ key ] = el.value.trim();
        } );
        return data;
    },
};
