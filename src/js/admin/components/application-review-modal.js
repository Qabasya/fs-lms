import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ApplicationReviewModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,

    $form: null,
    $saveBtn: null,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-review-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    _cacheElements() {
        this.$form    = this.$modal.find( 'form' );
        this.$saveBtn = $( '#review-modal-save-btn' );
    },

    _bindEvents() {
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );

        this.$modal.on( 'click', '.fs-editable-field__btn', ( e ) => {
            e.preventDefault();
            this._toggleField( $( e.currentTarget ).closest( '.fs-editable-field' ) );
        } );

        this.$form.on( 'submit.fs', ( e ) => {
            e.preventDefault();
            const data = this._collectFormData();
            this._saveCallbacks.forEach( cb => cb( data ) );
        } );
    },

    _toggleAccordion( $header ) {
        const isOpen     = $header.attr( 'aria-expanded' ) === 'true';
        const bodyId     = $header.attr( 'aria-controls' );
        const $body      = $( '#' + bodyId );

        // Close all sections
        this.$modal.find( '.fs-modal-accordion__header' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).prop( 'hidden', true );

        // Open clicked section if it was closed
        if ( ! isOpen ) {
            $header.attr( 'aria-expanded', 'true' );
            $body.prop( 'hidden', false );
        }
    },

    _toggleField( $field ) {
        const $display  = $field.find( '.fs-editable-field__display' );
        const $input    = $field.find( 'input, select' );
        const $icon     = $field.find( '.dashicons' );
        const isEditing = ! $input[0].hidden;

        if ( isEditing ) {
            $display[0].hidden = false;
            $input[0].hidden   = true;
            $icon.removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } else {
            $display[0].hidden = true;
            $input[0].hidden   = false;
            $input.trigger( 'focus' );
            $icon.removeClass( 'dashicons-edit' ).addClass( 'dashicons-undo' );
        }
    },

    onSave( callback ) {
        if ( typeof callback === 'function' ) {
            this._saveCallbacks.push( callback );
        }
    },

    open( data ) {
        $( '#review-modal-id' ).text( '#' + data.id );
        this.$form.find( '[name="application_id"]' ).val( data.id );

        const fields = [
            'student_last_name', 'student_first_name', 'student_middle_name',
            'student_birth_date', 'student_doc_type', 'student_doc_number', 'student_inn',
            'parent_last_name', 'parent_first_name', 'parent_middle_name',
            'parent_birth_date', 'relation_type',
            'parent_email', 'parent_phone',
            'parent_doc_type', 'parent_doc_number', 'parent_doc_issued_by', 'parent_doc_issued_date',
            'parent_inn', 'parent_address',
        ];

        fields.forEach( field => {
            const $field   = this.$modal.find( `.fs-editable-field[data-field="${ field }"]` );
            const value    = data[ field ] ?? '';
            const $display = $field.find( '.fs-editable-field__display' );
            const $input   = $field.find( 'input, select' );

            $display.text( value || '—' );
            $input.val( String( value ) );
            $display[0].hidden = false;
            $input[0].hidden   = true;
            $field.find( '.dashicons' ).removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } );

        // Reset accordion: first section open, rest closed
        this.$modal.find( '.fs-modal-accordion__header' ).first().attr( 'aria-expanded', 'true' );
        this.$modal.find( '.fs-modal-accordion__header' ).not( ':first' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).first().prop( 'hidden', false );
        this.$modal.find( '.fs-modal-accordion__body' ).not( ':first' ).prop( 'hidden', true );

        openModal( this.$modal );
        bindEsc( 'application_review', () => this.close() );
    },

    close() {
        closeModal( this.$modal );
        unbindEsc( 'application_review' );
    },

    setSaveState( loading ) {
        this.$saveBtn
            .prop( 'disabled', loading )
            .text( loading ? 'Сохранение...' : 'Сохранить' );
    },

    _collectFormData() {
        const data = { application_id: this.$form.find( '[name="application_id"]' ).val() };
        const fields = [
            'student_last_name', 'student_first_name', 'student_middle_name',
            'student_birth_date', 'student_doc_type', 'student_doc_number', 'student_inn',
            'parent_last_name', 'parent_first_name', 'parent_middle_name',
            'parent_birth_date', 'relation_type',
            'parent_email', 'parent_phone',
            'parent_doc_type', 'parent_doc_number', 'parent_doc_issued_by', 'parent_doc_issued_date',
            'parent_inn', 'parent_address',
        ];
        fields.forEach( field => {
            data[ field ] = this.$form.find( `[name="${ field }"]` ).val() ?? '';
        } );
        return data;
    },
};