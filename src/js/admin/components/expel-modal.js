import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ExpelModal = {
    $modal:       null,
    _confirmCbs:  [],
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-expel-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        this.$modal.on(
            'click',
            '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close',
            ( e ) => { e.preventDefault(); this.close(); }
        );

        $( '#fs-expel-form' ).on( 'submit.fs', ( e ) => {
            e.preventDefault();
            this._confirmCbs.forEach( cb => cb( this._collectFormData() ) );
        } );
    },

    onConfirm( callback ) {
        if ( typeof callback === 'function' ) this._confirmCbs.push( callback );
    },

    open( studentId, studentName ) {
        if ( ! this._initialized ) return;

        this.$modal.find( 'input[name="student_id"]' ).val( studentId );
        this.$modal.find( '.fs-expel-student-name' ).text( studentName ? `Студент: ${ studentName }` : '' );
        this.$modal.find( '#expel-reason' ).val( '' );
        this._setSaving( false );

        openModal( this.$modal );
        bindEsc( 'expel', () => this.close() );
    },

    close() {
        closeModal( this.$modal );
        unbindEsc( 'expel' );
    },

    setSaving( loading ) {
        this._setSaving( loading );
    },

    _setSaving( loading ) {
        this.$modal.find( '.js-expel-confirm' )
            .prop( 'disabled', loading )
            .text( loading ? 'Отчисление...' : 'Отчислить' );
    },

    _collectFormData() {
        return {
            student_id: this.$modal.find( 'input[name="student_id"]' ).val(),
            reason:     $( '#expel-reason' ).val().trim(),
        };
    },
};
