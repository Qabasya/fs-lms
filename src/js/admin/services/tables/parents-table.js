import { PiiExportService } from '../pii-export-service.js';

const $ = jQuery;

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

        // Предупреждение о ПД и выбор режима паролей — в общем сервисе (A2).
        PiiExportService.exportParents( ids );
    },
};
