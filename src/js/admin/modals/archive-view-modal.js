import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';
import { RestoreArchiveModal } from './restore-archive-modal.js';
import { toggleButton, showNotice } from '../modules/utils.js';

const $ = jQuery;

export const ArchiveViewModal = {
    $modal:       null,
    _initialized: false,

    init() {
        if ( this._initialized ) { return; }

        this.$modal = $( '#fs-archive-view-modal' );
        if ( ! this.$modal.length ) { return; }

        RestoreArchiveModal.init();

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.arc', '.js-view-archive', ( e ) => {
            e.preventDefault();
            const raw = $( e.currentTarget ).closest( 'tr' ).data( 'enrollment' );
            if ( raw ) { this.open( raw ); }
        } );

        // Restore from table row (direct link, modal not open)
        $( document ).on( 'click.arc-restore', '.js-restore-from-archive', ( e ) => {
            e.preventDefault();
            const $btn     = $( e.currentTarget );
            const archiveId = $btn.data( 'archive-id' );
            const hasParent = parseInt( $btn.data( 'has-parent' ) ?? '0', 10 ) === 1;

            if ( ! archiveId ) { return; }

            // If triggered from inside the view modal, close it first
            if ( $btn.closest( '#fs-archive-view-modal' ).length ) {
                this.close();
            }

            this._requestRestore( archiveId, hasParent, $btn );
        } );

        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );
    },

    open( data ) {
        this._fill( data );

        const archiveId = data.archive_id ?? null;
        const hasParent = data.parent_person_id ? 1 : 0;

        this.$modal.find( '#avm-restore-btn' )
            .data( 'archive-id', archiveId )
            .attr( 'data-archive-id', archiveId ?? '' )
            .data( 'has-parent', hasParent )
            .attr( 'data-has-parent', hasParent );

        bindEsc( 'archive_view', () => this.close() );
        openModal( this.$modal );
    },

    close() {
        unbindEsc( 'archive_view' );
        closeModal( this.$modal );
    },

    _requestRestore( archiveId, hasParent, $triggerBtn = null ) {
        RestoreArchiveModal.choose( hasParent )
            .then( ( { withParent } ) => {
                this._doRestore( archiveId, withParent, $triggerBtn );
            } )
            .catch( () => {} );
    },

    _doRestore( archiveId, withParent, $triggerBtn = null ) {
        if ( $triggerBtn ) { toggleButton( $triggerBtn, true, '...' ); }

        const vars = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:      fs_lms_vars.ajax_actions.restoreFromArchive,
                archive_id:  archiveId,
                with_parent: withParent ? 1 : 0,
                security:    vars.nonces?.restoreFromArchive ?? '',
            },
            success: ( res ) => {
                if ( $triggerBtn ) { toggleButton( $triggerBtn, false ); }

                if ( ! res.success ) {
                    alert( res.data || 'Ошибка восстановления.' );
                    return;
                }

                const appId      = res.data?.id       ?? '';
                const joinUrl    = res.data?.join_url  ?? '';
                const parentName = res.data?.parent_name ?? '';

                let msg = `Заявка #${ appId } создана.`;
                if ( parentName ) {
                    msg += `\nРодитель: ${ parentName }.`;
                }
                if ( joinUrl ) {
                    msg += `\n\nJoin-ссылка:\n${ joinUrl }`;
                    navigator.clipboard?.writeText( joinUrl ).catch( () => {} );
                }

                alert( msg );
                location.reload();
            },
            error: () => {
                if ( $triggerBtn ) { toggleButton( $triggerBtn, false ); }
                alert( 'Сетевая ошибка.' );
            },
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
            s_last_name:       sd.last_name   ?? sd.full_name?.split( ' ' )[ 0 ] ?? '',
            s_first_name:      sd.first_name  ?? sd.full_name?.split( ' ' )[ 1 ] ?? '',
            s_middle_name:     sd.middle_name ?? sd.full_name?.split( ' ' )[ 2 ] ?? '',
            s_birth_date:      sd.birth_date  ?? '',
            s_email:           sd.email       ?? '',
            s_phone:           sd.phone       ?? '',
            s_school:          sd.school      ?? '',
            s_grade:           sd.grade       ?? '',
            s_doc_type:        sd.doc_type    ?? '',
            s_doc_number:      sd.doc_number  ?? '',
            s_inn:             sd.inn         ?? '',
            g_last_name:       gd.last_name       ?? gd.full_name?.split( ' ' )[ 0 ] ?? '',
            g_first_name:      gd.first_name      ?? gd.full_name?.split( ' ' )[ 1 ] ?? '',
            g_middle_name:     gd.middle_name     ?? gd.full_name?.split( ' ' )[ 2 ] ?? '',
            g_birth_date:      gd.birth_date      ?? '',
            g_email:           gd.email           ?? '',
            g_phone:           gd.phone           ?? '',
            g_doc_type:        gd.doc_type        ?? '',
            g_doc_number:      gd.doc_number      ?? '',
            g_doc_issued_by:   gd.doc_issued_by   ?? '',
            g_doc_issued_date: gd.doc_issued_date ?? '',
            g_inn:             gd.inn             ?? '',
            g_address:         gd.address         ?? '',
            contract_no:       d.contract_no       ?? '',
            contract_date:     d.contract_date     ?? '',
            order_no:          d.order_no          ?? '',
            order_date:        d.order_date        ?? '',
            subject:           d.subject           ?? '',
            group:             d.group             ?? '',
            status:            d.status_label      ?? '',
            terminated_at:     d.terminated_at     ?? '',
            terminated_reason: d.terminated_reason ?? '',
        };

        Object.entries( map ).forEach( ( [ key, value ] ) => {
            this.$modal.find( `[data-arc="${ key }"]` ).text( f( value ) );
        } );
    },
};
