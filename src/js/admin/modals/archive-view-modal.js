import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ArchiveViewModal = {
    $modal: null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-archive-view-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.arc', '.js-view-archive', ( e ) => {
            e.preventDefault();
            const $tr  = $( e.currentTarget ).closest( 'tr' );
            const raw  = $tr.data( 'enrollment' );
            if ( raw ) { this.open( raw ); }
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );

        this.$modal.on( 'click', '.js-restore-from-archive', ( e ) => {
            e.preventDefault();
            const archiveId = $( e.currentTarget ).data( 'archive-id' );
            if ( ! archiveId ) {
                alert( 'ID архивной записи не найден.' );
                return;
            }
            this._restoreFromArchive( archiveId );
        } );
    },

    open( data ) {
        this._fill( data );
        const archiveId = data.archive_id ?? null;
        this.$modal.find( '#avm-restore-btn' ).data( 'archive-id', archiveId ).attr( 'data-archive-id', archiveId ?? '' );
        bindEsc( 'archive_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'archive_view' );
        closeModal( this.$modal );
    },

    _restoreFromArchive( archiveId ) {
        const vars = window.fs_lms_applications_vars ?? {};
        $.ajax( {
            url:    window.ajaxurl,
            method: 'POST',
            data:   {
                action:     'restore_from_archive',
                archive_id: archiveId,
                security:   vars.nonces?.restoreFromArchive ?? '',
            },
            success: ( res ) => {
                if ( ! res.success ) {
                    alert( res.data || 'Ошибка восстановления.' );
                    return;
                }
                const joinUrl = res.data?.join_url ?? '';
                const appId   = res.data?.id ?? '';
                this.close();
                if ( joinUrl ) {
                    navigator.clipboard?.writeText( joinUrl )
                        .catch( () => prompt( 'Скопируйте ссылку:', joinUrl ) );
                    alert( `Заявка #${ appId } создана. Ссылка join скопирована в буфер.` );
                } else {
                    alert( `Заявка #${ appId } создана.` );
                }
                location.reload();
            },
            error: () => alert( 'Сетевая ошибка.' ),
        } );
    },

    _toggleAccordion( $header ) {
        const $body  = $( '#' + $header.attr( 'aria-controls' ) );
        const isOpen = $header.attr( 'aria-expanded' ) === 'true';

        this.$modal.find( '.fs-modal-accordion__header' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).prop( 'hidden', true );

        if ( ! isOpen ) {
            $header.attr( 'aria-expanded', 'true' );
            $body.prop( 'hidden', false );
        }
    },

    _fill( d ) {
        const empty = '—';
        const f     = ( v ) => v || empty;

        const sd = d.student  ?? {};
        const gd = d.guardian ?? {};

        const map = {
            s_last_name:        sd.last_name   ?? sd.full_name?.split( ' ' )[ 0 ] ?? '',
            s_first_name:       sd.first_name  ?? sd.full_name?.split( ' ' )[ 1 ] ?? '',
            s_middle_name:      sd.middle_name ?? sd.full_name?.split( ' ' )[ 2 ] ?? '',
            s_birth_date:       sd.birth_date  ?? '',
            s_email:            sd.email       ?? '',
            s_phone:            sd.phone       ?? '',
            s_school:           sd.school      ?? '',
            s_grade:            sd.grade       ?? '',
            s_doc_type:         sd.doc_type    ?? '',
            s_doc_number:       sd.doc_number  ?? '',
            s_inn:              sd.inn         ?? '',

            g_last_name:        gd.last_name       ?? gd.full_name?.split( ' ' )[ 0 ] ?? '',
            g_first_name:       gd.first_name      ?? gd.full_name?.split( ' ' )[ 1 ] ?? '',
            g_middle_name:      gd.middle_name     ?? gd.full_name?.split( ' ' )[ 2 ] ?? '',
            g_birth_date:       gd.birth_date      ?? '',
            g_relation_type:    gd.relation_type   ?? '',
            g_email:            gd.email           ?? '',
            g_phone:            gd.phone           ?? '',
            g_doc_type:         gd.doc_type        ?? '',
            g_doc_number:       gd.doc_number      ?? '',
            g_doc_issued_by:    gd.doc_issued_by   ?? '',
            g_doc_issued_date:  gd.doc_issued_date ?? '',
            g_inn:              gd.inn             ?? '',
            g_address:          gd.address         ?? '',

            contract_no:        d.contract_no        ?? '',
            contract_date:      d.contract_date      ?? '',
            order_no:           d.order_no           ?? '',
            order_date:         d.order_date         ?? '',
            subject:            d.subject            ?? '',
            group:              d.group              ?? '',
            status:             d.status_label       ?? '',
            terminated_at:      d.terminated_at      ?? '',
            terminated_reason:  d.terminated_reason  ?? '',
        };

        Object.entries( map ).forEach( ( [ key, value ] ) => {
            this.$modal.find( `[data-arc="${ key }"]` ).text( f( value ) );
        } );
    },
};
