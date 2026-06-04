const $ = jQuery;

const AlertModal = {
    /** @type {JQuery|null} */
    $modal: null,

    init() {
        this.$modal = $( '#fs-lms-alert-modal' );
    },

    /**
     * @param {string} message
     * @param {string} [title='Ошибка']
     * @returns {Promise<void>}
     */
    show( message, title = 'Ошибка' ) {
        if ( ! this.$modal?.length ) {
            // eslint-disable-next-line no-alert
            alert( message );
            return Promise.resolve();
        }

        this.$modal.find( '.fs-lms-modal-title' ).text( title );
        this.$modal.find( '.fs-lms-modal-message' ).text( message );

        this.$modal.removeClass( 'hidden' );
        void this.$modal[ 0 ].offsetHeight;
        this.$modal.addClass( 'active' );

        return new Promise( ( resolve ) => {
            const close = () => {
                $( document ).off( 'keydown.alert_modal' );
                this.$modal.find( '.fs-lms-alert-modal-ok' ).off( 'click.alert' );
                this.$modal.removeClass( 'active' );
                setTimeout( () => this.$modal.addClass( 'hidden' ), 200 );
                resolve();
            };

            this.$modal.find( '.fs-lms-alert-modal-ok' )
                .off( 'click.alert' )
                .on( 'click.alert', close );

            $( document ).off( 'keydown.alert_modal' ).on( 'keydown.alert_modal', ( e ) => {
                if ( e.key === 'Escape' || e.key === 'Enter' ) {
                    close();
                }
            } );
        } );
    },
};

export { AlertModal };