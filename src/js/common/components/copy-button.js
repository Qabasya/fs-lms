const $ = jQuery;

const BTN_HTML =
    '<button type="button" class="fs-copy-field__btn" aria-label="Копировать">' +
        '<span class="dashicons dashicons-clipboard fs-copy-field__icon"></span>' +
        '<span class="fs-copy-field__label">Скопировано</span>' +
    '</button>';

export const CopyButton = {
    /**
     * Оборачивает все input.js-copy-target в .fs-copy-field и добавляет кнопку.
     * Безопасно вызывать повторно после динамической вставки контента.
     */
    inject() {
        $( 'input.js-copy-target' ).each( ( _, el ) => {
            if ( $( el ).closest( '.fs-copy-field' ).length ) return;
            $( el ).wrap( '<div class="fs-copy-field"></div>' );
            $( el ).after( BTN_HTML );
        } );
    },

    init() {
        this.inject();

        $( document ).on( 'click', '.fs-copy-field__btn', ( e ) => {
            const $btn   = $( e.currentTarget );
            const $input = $btn.prev( 'input' );
            if ( ! $input.length ) return;

            const text = $input.val().trim();
            if ( ! text ) return;

            navigator.clipboard.writeText( text )
                .then( ()  => this._succeed( $btn ) )
                .catch( () => {} );
        } );
    },

    _succeed( $btn ) {
        if ( $btn.hasClass( 'is-copied' ) ) return;

        $btn.addClass( 'is-copied' )
            .find( '.fs-copy-field__icon' )
            .removeClass( 'dashicons-clipboard' )
            .addClass( 'dashicons-yes' );

        setTimeout( () => {
            $btn.removeClass( 'is-copied' )
                .find( '.fs-copy-field__icon' )
                .removeClass( 'dashicons-yes' )
                .addClass( 'dashicons-clipboard' );
        }, 2000 );
    },
};
