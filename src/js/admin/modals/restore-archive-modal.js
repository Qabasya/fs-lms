import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const RestoreArchiveModal = {
    $modal:       null,
    _initialized: false,
    _resolve:     null,
    _reject:      null,

    init() {
        if ( this._initialized ) { return; }

        this.$modal = $( '#fs-restore-archive-modal' );
        if ( ! this.$modal.length ) { return; }

        this._initialized = true;
        this._bindEvents();
    },

    /**
     * Показывает модалку и возвращает Promise с {withParent: bool}.
     * Скрывает опцию "с данными родителя" если hasParent = false.
     */
    choose( hasParent = true ) {
        return new Promise( ( resolve, reject ) => {
            this._resolve = resolve;
            this._reject  = reject;

            const $opt = this.$modal.find( '#ram-mode-with-parent' );
            $opt.prop( 'disabled', ! hasParent );
            if ( ! hasParent ) {
                $opt.text( $opt.text() + ' (нет данных)' );
                this.$modal.find( '#ram-mode-select' ).val( '0' );
            } else {
                $opt.text( 'С данными родителя — заявка сразу готова к проверке' );
            }

            bindEsc( 'restore_archive', () => this._cancel() );
            openModal( this.$modal );
        } );
    },

    _bindEvents() {
        this.$modal.on( 'click', '#ram-confirm-btn', () => {
            const withParent = parseInt( this.$modal.find( '#ram-mode-select' ).val(), 10 ) === 1;
            this._close();
            this._resolve?.( { withParent } );
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close', () => {
            this._cancel();
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
