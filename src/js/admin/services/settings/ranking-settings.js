import '../../_types.js';

const $ = jQuery;

/** Веса рейтинга: сохраняется только форма своей карточки. */
export const RankingSettings = {
    init() {
        const $form = $( '#fs-ranking-settings-form' );
        if ( ! $form.length || $form.data( 'rankingBound' ) ) {
            return;
        }
        $form.data( 'rankingBound', true ).on( 'submit', ( event ) => {
            event.preventDefault();
            this.save( $form );
        } );
    },

    save( $form ) {
        if ( $form.data( 'saving' ) || ! $form[ 0 ].reportValidity() ) {
            return;
        }
        const $status = $form.find( '.js-ranking-status' );
        const $buttons = $form.find( 'button' );
        const settings = {};
        $form.find( '[data-ranking-field]' ).each( ( _, field ) => {
            settings[ field.name ] = field.value;
        } );
        $form.data( 'saving', true ).attr( 'aria-busy', 'true' );
        $status.text( 'Сохранение…' ).removeClass( 'fs-config-status--ok fs-config-status--err' );
        $buttons.prop( 'disabled', true );
        $.post( fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.saveRankingSettings,
            security: fs_lms_vars.nonces.config,
            settings,
        } )
            .done( ( result ) => {
                if ( result.success ) {
                    $status.text( 'Сохранено.' ).addClass( 'fs-config-status--ok' );
                } else {
                    $status.text( this.errorMessage( result ) ).addClass( 'fs-config-status--err' );
                }
            } )
            .fail( ( xhr ) => {
                $status.text( this.errorMessage( xhr.responseJSON, 'Ошибка сети. Повторите сохранение.' ) ).addClass( 'fs-config-status--err' );
            } )
            .always( () => {
                $buttons.prop( 'disabled', false );
                $form.data( 'saving', false ).removeAttr( 'aria-busy' );
            } );
    },

    errorMessage( response, fallback = 'Не удалось сохранить настройки.' ) {
        const data = response && response.data;
        return typeof data === 'string' ? data : ( data && data.message ) || fallback;
    },
};
