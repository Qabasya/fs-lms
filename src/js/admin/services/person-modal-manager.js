import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;
const REVEAL_MS     = 30000;
const STUDENT_MODAL = '#fs-student-person-modal';
const PARENT_MODAL  = '#fs-parent-person-modal';
const BOTH_MODALS   = STUDENT_MODAL + ',' + PARENT_MODAL;

export const PersonModalManager = {
    $student:     null,
    $parent:      null,
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        this.$student = $( STUDENT_MODAL );
        this.$parent  = $( PARENT_MODAL );
        if ( ! this.$student.length && ! this.$parent.length ) return;
        this._initialized = true;
        this._bindEvents();
    },

    // ── Events ──────────────────────────────────────────────────────────────

    _bindEvents() {
        // Открытие из таблицы
        $( document ).on( 'click.pmm', '.js-view-person', ( e ) => {
            e.preventDefault();
            const $el = $( e.currentTarget );
            const type = $el.data( 'personType' );
            if ( type === 'student' && this.$student.length ) {
                this._open( this.$student, $el.data() );
            } else if ( type === 'parent' && this.$parent.length ) {
                this._open( this.$parent, $el.data() );
            }
        } );

        // Закрытие — backdrop, кнопки закрытия
        $( document ).on( 'click.pmm_close', BOTH_MODALS + ' .fs-lms-modal-backdrop,' + BOTH_MODALS + ' .js-pmm-close,' + BOTH_MODALS + ' .js-modal-close', ( e ) => {
            e.preventDefault();
            this._close( $( e.currentTarget ).closest( '.fs-lms-modal' ) );
        } );

        // Reveal PII (делегирование от document для надёжности)
        $( document ).on( 'click.pmm_reveal', BOTH_MODALS + ' .js-pii-reveal', ( e ) => {
            e.preventDefault();
            const $modal   = $( e.currentTarget ).closest( '.fs-lms-modal' );
            const personId = parseInt( $modal.data( 'personId' ), 10 ) || 0;
            const wpUserId = parseInt( $modal.data( 'wpUserId' ), 10 ) || 0;
            this._reveal( $( e.currentTarget ), personId, wpUserId );
        } );

        // Режим редактирования
        $( document ).on( 'click.pmm_edit',   BOTH_MODALS + ' .js-pmm-edit',   ( e ) => { e.preventDefault(); this._enterEdit( $( e.currentTarget ).closest( '.fs-lms-modal' ) ); } );
        $( document ).on( 'click.pmm_cancel', BOTH_MODALS + ' .js-pmm-cancel', ( e ) => { e.preventDefault(); this._exitEdit(  $( e.currentTarget ).closest( '.fs-lms-modal' ) ); } );
        $( document ).on( 'click.pmm_save',   BOTH_MODALS + ' .js-pmm-save',   ( e ) => { e.preventDefault(); this._saveEdit(  $( e.currentTarget ).closest( '.fs-lms-modal' ) ); } );

        // Действия
        $( document ).on( 'click.pmm_export', BOTH_MODALS + ' .js-pmm-export', ( e ) => { e.preventDefault(); this._exportPii( $( e.currentTarget ).closest( '.fs-lms-modal' ) ); } );
        $( document ).on( 'click.pmm_delete', BOTH_MODALS + ' .js-pmm-delete', ( e ) => { e.preventDefault(); this._deletePii( $( e.currentTarget ).closest( '.fs-lms-modal' ) ); } );
    },

    // ── Open / Close ────────────────────────────────────────────────────────

    _open( $modal, data ) {
        $modal.data( 'personId', data.personId || 0 );
        $modal.data( 'wpUserId', data.wpUserId || 0 );

        // Статические поля из data-атрибутов кнопки
        $modal.find( '.fs-lms-modal-title' ).text( data.displayName || '—' );
        $modal.find( '[data-val="display_name"]' ).text( data.displayName || '—' );
        $modal.find( '[data-val="email"]' ).text( data.email || '—' );
        $modal.find( '[data-val="relation_type"]' ).text( data.relationType || '—' );

        this._resetReveal( $modal );
        this._exitEdit( $modal );
        this._loadData( $modal, data.personId || 0 );

        bindEsc( 'pmm', () => this._close( $modal ) );
        openModal( $modal );
    },

    _close( $modal ) {
        unbindEsc( 'pmm' );
        closeModal( $modal, () => {
            this._resetReveal( $modal );
            this._exitEdit( $modal );
            $modal.removeData( 'personId' ).removeData( 'wpUserId' );
        } );
    },

    // ── Data loading ─────────────────────────────────────────────────────────

    _loadData( $modal, personId ) {
        if ( ! personId ) return;

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.getPersonData,
            person_id: personId,
            security:  fs_lms_applications_vars.nonces.manager,
        } )
        .done( ( res ) => {
            if ( ! res.success ) return;
            const d   = res.data;
            const enr = d.enrollments && d.enrollments[0];

            if ( enr ) {
                $modal.find( '[data-val="contract_no"]' ).text( enr.contract_no || '—' );
                $modal.find( '[data-val="subject"]'     ).text( enr.subject_name || '—' );
                $modal.find( '[data-val="group"]'       ).text( enr.group_title || '—' );
                $modal.find( '[data-val="school"]'      ).text( enr.school || '—' );
                $modal.find( '[data-val="grade"]'       ).text( enr.grade || '—' );
                $modal.find( '[data-val="birth_date"]'  ).text( enr.birth_date || '—' );
                $modal.find( '[data-val="guardian_birth_date"]' ).text( enr.guardian_birth_date || '—' );
                $modal.find( '[data-val="child_doc_number"]'    ).text( enr.child_doc_number || '—' );
                $modal.find( '[data-val="child_inn"]'           ).text( enr.child_inn || '—' );
                $modal.find( '[data-val="child_birth_date"]'    ).text( enr.child_birth_date || '—' );

                // Телефон зависит от роли
                const phone = d.type === 'parent' ? enr.guardian_phone : enr.student_phone;
                $modal.find( '[data-val="phone"]' ).text( phone || '—' );
            }

            const rep = d.representatives && d.representatives[0];
            if ( rep ) $modal.find( '[data-val="guardian_name"]' ).text( rep.name || '—' );

            const dep = d.dependents && d.dependents[0];
            if ( dep ) {
                $modal.find( '[data-val="dependent_name"]' ).text( dep.name || '—' );
                $modal.find( '[data-val="relation_type"]' ).text( dep.type_label || '—' );
            }

            if ( d.wp_user_id ) $modal.data( 'wpUserId', d.wp_user_id );
        } );
    },

    // ── Reveal ───────────────────────────────────────────────────────────────

    _reveal( $btn, personId, wpUserId ) {
        if ( $btn.prop( 'disabled' ) ) return;

        const $wrap = $btn.closest( '.fs-pii-field' );
        const field  = $btn.data( 'field' );

        $btn.prop( 'disabled', true ).text( '…' );

        if ( field === 'login' || field === 'password' ) {
            this._revealCredentials( $wrap, $btn, field, wpUserId );
        } else {
            this._revealPiiField( $wrap, $btn, field, personId );
        }
    },

    _revealPiiField( $wrap, $btn, field, personId ) {
        if ( ! personId ) { this._resetBtn( $wrap, $btn ); return; }

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.revealPiiField,
            person_id: personId,
            field:     field,
            reason:    'admin_userlist_view',
            security:  fs_lms_applications_vars.nonces.revealPii,
        } )
        .done( ( res ) => {
            if ( ! res.success ) { this._resetBtn( $wrap, $btn ); return; }
            this._showRevealed( $wrap, $btn, res.data.value || '(пусто)' );
        } )
        .fail( () => this._resetBtn( $wrap, $btn ) );
    },

    _revealCredentials( $wrap, $btn, field, wpUserId ) {
        if ( ! wpUserId ) { this._resetBtn( $wrap, $btn ); return; }

        $.post( fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.revealUserCredentials,
            user_id:  wpUserId,
            security: fs_lms_applications_vars.nonces.revealPii,
        } )
        .done( ( res ) => {
            if ( ! res.success ) { this._resetBtn( $wrap, $btn ); return; }
            const value = field === 'login' ? ( res.data.login || '—' ) : ( res.data.password || '—' );
            this._showRevealed( $wrap, $btn, value );
        } )
        .fail( () => this._resetBtn( $wrap, $btn ) );
    },

    _showRevealed( $wrap, $btn, value ) {
        $wrap.find( '.fs-pii-field__masked' ).hide();
        $wrap.find( '.fs-pii-field__revealed' ).text( value ).show();
        $btn.html( '<span class="dashicons dashicons-hidden"></span> Скрыть' ).prop( 'disabled', false );

        const timer = setTimeout( () => this._resetBtn( $wrap, $btn ), REVEAL_MS );
        $btn.data( 'reveal-timer', timer )
            .off( 'click.hide' )
            .on( 'click.hide', ( ev ) => {
                ev.preventDefault();
                ev.stopPropagation();
                clearTimeout( timer );
                this._resetBtn( $wrap, $btn );
            } );
    },

    _resetBtn( $wrap, $btn ) {
        clearTimeout( $btn.data( 'reveal-timer' ) );
        $wrap.find( '.fs-pii-field__revealed' ).hide().text( '' );
        $wrap.find( '.fs-pii-field__masked' ).show();
        $btn.off( 'click.hide' )
            .html( '<span class="dashicons dashicons-visibility"></span> Показать' )
            .prop( 'disabled', false );
    },

    _resetReveal( $modal ) {
        $modal.find( '.fs-pii-field' ).each( ( _, el ) => {
            this._resetBtn( $( el ), $( el ).find( '.js-pii-reveal' ) );
        } );
    },

    // ── Edit mode ─────────────────────────────────────────────────────────────

    _enterEdit( $modal ) {
        $modal.find( '.pmm-view' ).prop( 'hidden', true );
        $modal.find( '.pmm-edit' ).prop( 'hidden', false );
        $modal.find( '.js-pmm-edit, .js-pmm-export, .js-pmm-delete, .js-pmm-close' ).prop( 'hidden', true );
        $modal.find( '.js-pmm-save, .js-pmm-cancel' ).prop( 'hidden', false );
    },

    _exitEdit( $modal ) {
        $modal.find( '.pmm-view' ).prop( 'hidden', false );
        $modal.find( '.pmm-edit' ).prop( 'hidden', true );
        $modal.find( '.pmm-edit input' ).val( '' );
        $modal.find( '.js-pmm-edit, .js-pmm-export, .js-pmm-delete, .js-pmm-close' ).prop( 'hidden', false );
        $modal.find( '.js-pmm-save, .js-pmm-cancel' ).prop( 'hidden', true );
    },

    _saveEdit( $modal ) {
        const personId = parseInt( $modal.data( 'personId' ), 10 ) || 0;
        if ( ! personId ) return;

        const payload = {
            action:    fs_lms_vars.ajax_actions.updatePerson,
            security:  fs_lms_applications_vars.nonces.updatePerson,
            person_id: personId,
        };

        // Отправляем только непустые поля
        $modal.find( '.pmm-edit input[name]' ).each( ( _, el ) => {
            if ( el.value.trim() ) payload[ el.name ] = el.value.trim();
        } );

        $.post( fs_lms_vars.ajaxurl, payload )
        .done( ( res ) => {
            if ( ! res.success ) return;
            this._exitEdit( $modal );
            this._loadData( $modal, personId );
        } );
    },

    // ── Actions ───────────────────────────────────────────────────────────────

    _exportPii( $modal ) {
        const personId = parseInt( $modal.data( 'personId' ), 10 ) || 0;
        if ( ! personId ) return;

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.exportPii,
            person_id: personId,
            security:  fs_lms_applications_vars.nonces.exportPii,
        } ).done( ( res ) => {
            if ( res.success && res.data.download_url ) window.location.href = res.data.download_url;
        } );
    },

    _deletePii( $modal ) {
        const personId = parseInt( $modal.data( 'personId' ), 10 ) || 0;
        if ( ! personId ) return;
        if ( ! confirm( 'Удалить персональные данные? Физическое удаление через 30 дней.' ) ) return;

        $.post( fs_lms_vars.ajaxurl, {
            action:    fs_lms_vars.ajax_actions.requestPiiDeletion,
            person_id: personId,
            security:  fs_lms_applications_vars.nonces.deletePii,
        } ).done( ( res ) => {
            if ( res.success ) this._close( $modal );
        } );
    },
};
