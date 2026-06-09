import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const RestoreArchiveModal = {
    $modal:        null,
    _eventsBound:  false,
    _resolve:      null,
    _reject:       null,

    _setup() {
        if ( ! this.$modal || ! this.$modal.length ) {
            this.$modal = $( '#fs-restore-archive-modal' );
        }
        if ( ! this.$modal.length ) { return false; }

        if ( ! this._eventsBound ) {
            this._eventsBound = true;

            this.$modal.on( 'click', '#ram-confirm-btn', () => {
                const withParent = this.$modal.find( 'input[name="ram-mode"]:checked' ).val() === '1';
                const resolve = this._resolve;
                this._close();
                resolve?.( { withParent } );
            } );

            this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', () => {
                this._cancel();
            } );
        }
        return true;
    },

    init() {
        this._setup();
    },

    choose( hasParent = true ) {
        if ( ! this._setup() ) {
            return Promise.reject( new Error( '#fs-restore-archive-modal not found in DOM' ) );
        }

        return new Promise( ( resolve, reject ) => {
            this._resolve = resolve;
            this._reject  = reject;

            const $input = this.$modal.find( '#ram-mode-with-parent' );
            const $label = this.$modal.find( '#ram-with-parent-label' );
            const $text  = this.$modal.find( '#ram-with-parent-text' );

            if ( ! hasParent ) {
                $input.prop( 'disabled', true );
                $label.addClass( 'fs-radio-label--disabled' );
                $text.text( 'С данными родителя — нет данных в системе' );
                this.$modal.find( 'input[name="ram-mode"][value="0"]' ).prop( 'checked', true );
            } else {
                $input.prop( 'disabled', false );
                $label.removeClass( 'fs-radio-label--disabled' );
                $text.text( 'С данными родителя — заявка сразу готова к проверке' );
            }

            bindEsc( 'restore_archive', () => this._cancel() );
            openModal( this.$modal );
        } );
    },

    _cancel() {
        this._close();
        this._reject?.( 'cancel' );
    },

    _close() {
        unbindEsc( 'restore_archive' );
        closeModal( this.$modal );
        this._resolve = null;
        this._reject  = null;
    },
};
