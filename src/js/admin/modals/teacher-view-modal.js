import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const TeacherViewModal = {
    $modal: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-teacher-view-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.tvm', '.js-view-teacher', ( e ) => {
            e.preventDefault();
            const data = $( e.currentTarget ).closest( 'tr' ).data( 'teacher' );
            if ( data ) this.open( data );
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );
    },

    open( data ) {
        this._fill( data );
        bindEsc( 'teacher_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'teacher_view' );
        closeModal( this.$modal );
    },

    _fill( data ) {
        const empty = '—';
        const fields = {
            full_name: data.full_name || empty,
            email:     data.email     || empty,
            subjects:  data.subjects  || empty,
            groups:    data.groups    || empty,
        };

        Object.entries( fields ).forEach( ( [ key, value ] ) => {
            this.$modal.find( `[data-tvm="${ key }"]` ).text( value );
        } );
    },
};
