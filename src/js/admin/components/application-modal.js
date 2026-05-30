import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ApplicationModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,

    $form: null,
    $saveBtn: null,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    _cacheElements() {
        this.$form    = this.$modal.find( 'form' );
        this.$saveBtn = $( '#app-modal-save-btn' );
    },

    _bindEvents() {
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
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

    _toggleField( $field ) {
        const $display = $field.find( '.fs-editable-field__display' );
        const $input   = $field.find( 'input, select' );
        const $icon    = $field.find( '.dashicons' );
        const isEditing = ! $input[0].hidden;

        if ( isEditing ) {
            $display[0].hidden = false;
            $input[0].hidden = true;
            $icon.removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } else {
            $display[0].hidden = true;
            $input[0].hidden = false;
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
        $( '#app-modal-id' ).text( '#' + data.id );
        this.$form.find( '[name="application_id"]' ).val( data.id );

        const fields = [ 'last_name', 'first_name', 'middle_name', 'birth_date', 'email', 'phone', 'school', 'grade' ];
        fields.forEach( field => {
            const $field   = this.$modal.find( `.fs-editable-field[data-field="${ field }"]` );
            const value    = data[ field ] ?? '';
            const $display = $field.find( '.fs-editable-field__display' );
            const $input   = $field.find( 'input, select' );

            $display.text( value || '—' );
            $input.val( String( value ) );

            $display[0].hidden = false;
            $input[0].hidden = true;
            $field.find( '.dashicons' ).removeClass( 'dashicons-undo' ).addClass( 'dashicons-edit' );
        } );

        openModal( this.$modal );
        bindEsc( 'application', () => this.close() );
    },

    close() {
        closeModal( this.$modal );
        unbindEsc( 'application' );
    },

    setSaveState( loading ) {
        this.$saveBtn
            .prop( 'disabled', loading )
            .text( loading ? 'Сохранение...' : 'Сохранить' );
    },

    _collectFormData() {
        const data = { application_id: this.$form.find( '[name="application_id"]' ).val() };
        const fields = [ 'last_name', 'first_name', 'middle_name', 'birth_date', 'email', 'phone', 'school', 'grade' ];
        fields.forEach( field => {
            data[ field ] = this.$form.find( `[name="${ field }"]` ).val() ?? '';
        } );
        return data;
    },
};