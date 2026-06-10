import { ArchiveViewModal } from '../modals/archive-view-modal.js';
import { RestoreArchiveModal } from '../modals/restore-archive-modal.js';
import { toggleButton, showNotice } from '../modules/utils.js';

const $ = jQuery;

export const ArchiveViewModalManager = {
    init() {
        ArchiveViewModal.init();
        RestoreArchiveModal.init();
        this._bindEvents();
    },

    _bindEvents() {
        $( document ).on( 'click.arc-restore', '.js-restore-from-archive', ( e ) => {
            e.preventDefault();
            const $btn      = $( e.currentTarget );
            const archiveId = $btn.data( 'archive-id' );
            const hasParent = parseInt( $btn.data( 'has-parent' ) ?? '0', 10 ) === 1;

            if ( ! archiveId ) { return; }

            RestoreArchiveModal.choose( hasParent )
                .then( ( { withParent } ) => this._doRestore( archiveId, withParent, $btn ) )
                .catch( ( err ) => { if ( err !== 'cancel' ) { showNotice( String( err ), 'error', $( '.fs-lms-archive' ) ); } } );
        } );
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
                    showNotice( res.data || 'Ошибка восстановления.', 'error', $( '.fs-lms-archive' ) );
                    return;
                }

                const appId      = res.data?.id         ?? '';
                const joinUrl    = res.data?.join_url    ?? '';
                const parentName = res.data?.parent_name ?? '';

                let msg = `Заявка #${ appId } создана.`;
                if ( parentName ) { msg += ` Родитель: ${ parentName }.`; }
                if ( joinUrl ) { navigator.clipboard?.writeText( joinUrl ).catch( () => {} ); }

                showNotice( msg, 'success', $( '.fs-lms-archive' ), { autoDismiss: true, autoDismissDelay: 2000 } );
                setTimeout( () => location.reload(), 2000 );
            },
            error: () => {
                if ( $triggerBtn ) { toggleButton( $triggerBtn, false ); }
                showNotice( 'Сетевая ошибка.', 'error', $( '.fs-lms-archive' ) );
            },
        } );
    },
};
