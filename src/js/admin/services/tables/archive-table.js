const $ = jQuery;

const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

export const ArchiveTable = {

    _initialized: false,

    init() {
        if ( this._initialized ) return;
        if ( ! $( '.fs-lms-archive' ).length ) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'change', '#js-select-all-archive', ( e ) => {
            $( '.js-archive-cb' ).prop( 'checked', e.currentTarget.checked );
        } );

        $( document ).on( 'change', '.js-archive-cb', () => {
            const total   = $( '.js-archive-cb' ).length;
            const checked = $( '.js-archive-cb:checked' ).length;
            $( '#js-select-all-archive' ).prop( 'indeterminate', checked > 0 && checked < total );
            $( '#js-select-all-archive' ).prop( 'checked', checked === total );
        } );

        $( document ).on( 'click', '#js-archive-bulk-apply', () => this._applyBulk() );
    },

    _applyBulk() {
        if ( $( '#js-archive-bulk-action' ).val() !== 'export' ) return;

        const ids = [];
        $( '.js-archive-cb:checked' ).each( ( _, el ) => {
            const id = parseInt( $( el ).val(), 10 );
            if ( id ) ids.push( id );
        } );

        if ( ! ids.length ) return;

        $.post( AJAX_URL(), {
            action:   ACTIONS().exportArchive,
            ids:      ids,
            security: NONCES().manager,
        } ).done( ( r ) => {
            if ( r.success && r.data.url ) {
                const a = document.createElement( 'a' );
                a.href = r.data.url;
                document.body.appendChild( a );
                a.click();
                document.body.removeChild( a );
            }
        } );
    },
};
