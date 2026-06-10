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
        this.$modal.find( '[data-tvm="full_name"]' ).text( data.full_name || empty );
        this.$modal.find( '[data-tvm="email"]' ).text( data.email || empty );

        const esc = ( str ) => $( '<div>' ).text( String( str ) ).html();
        const subjectsGroups = data.subjects_groups || [];

        if ( ! subjectsGroups.length ) {
            this.$modal.find( '[data-tvm="subjects"]' ).text( empty );
            this.$modal.find( '[data-tvm="groups"]' ).text( empty );
            return;
        }

        const subjectParts = [];
        const groupParts   = [];

        subjectsGroups.forEach( ( sg ) => {
            subjectParts.push( esc( sg.subject_name || '' ) );
            const groupLines = ( sg.groups || [] ).map( ( g ) => esc( g ) ).join( '<br>' );
            groupParts.push( groupLines || empty );
        } );

        this.$modal.find( '[data-tvm="subjects"]' ).html( subjectParts.join( '<br><br>' ) );
        this.$modal.find( '[data-tvm="groups"]' ).html( groupParts.join( '<br><br>' ) );
    },
};
