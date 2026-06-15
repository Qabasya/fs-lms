const $ = jQuery;

const NONCES   = () => fs_lms_applications_vars.nonces;
const ACTIONS  = () => fs_lms_vars.ajax_actions;
const AJAX_URL = () => fs_lms_vars.ajaxurl;

export const ParentsTable = {

    _initialized: false,

    init() {
        if ( this._initialized ) return;
        if ( ! $( '.fs-lms-parents' ).length ) return;
        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'change', '#js-select-all-parents', ( e ) => {
            $( '.js-parent-cb' ).prop( 'checked', e.currentTarget.checked );
        } );

        $( document ).on( 'change', '.js-parent-cb', () => {
            const total   = $( '.js-parent-cb' ).length;
            const checked = $( '.js-parent-cb:checked' ).length;
            $( '#js-select-all-parents' ).prop( 'indeterminate', checked > 0 && checked < total );
            $( '#js-select-all-parents' ).prop( 'checked', checked === total );
        } );

        $( document ).on( 'click', '#js-parents-bulk-apply', () => this._applyBulkExport() );
    },

    _applyBulkExport() {
        const ids = [];
        $( '.js-parent-cb:checked' ).each( ( _, el ) => {
            const personId = parseInt(
                $( el ).closest( 'tr' ).find( '.js-export-person' ).data( 'personId' ), 10
            );
            if ( personId ) ids.push( personId );
        } );

        if ( ! ids.length ) return;

        $.post( AJAX_URL(), {
            action:   ACTIONS().exportParents,
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
