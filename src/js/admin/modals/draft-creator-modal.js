import '../_types.js';
import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * DraftCreatorModal — лёгкая модалка «создать черновик» для работы/урока из конструктора.
 * Открывается через DraftCreatorModal.open(config) из ref-selector.js.
 */
export const DraftCreatorModal = {

	_initialized: false,
	_config:      null,
	_submitting:  false,

	$modal:    null,
	$title:    null,
	$workType: null,
	$submit:   null,

	init() {
		this.$modal = $( '#fs-lms-draft-creator-modal' );
		if ( ! this.$modal.length || this._initialized ) {
			return;
		}
		this._initialized = true;

		this.$title    = $( '#fs-lms-draft-title' );
		this.$workType = $( '#fs-lms-draft-work-type' );
		this.$submit   = $( '#fs-lms-draft-submit' );

		this.$modal.on( 'click', '.js-modal-close', () => this.close() );
		this.$modal.on( 'click', '.fs-lms-modal-backdrop', () => this.close() );
		this.$submit.on( 'click', () => this._submit() );
		this.$title.on( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				this._submit();
			}
		} );
	},

	/**
	 * @param {{ refType: 'work'|'lesson'|'problem'|'task'|'assessment'|'material', $field: jQuery, onCreated: function(int, string): void }} config
	 */
	open( config ) {
		this._config     = config;
		this._submitting = false;

		const isWork = config.refType === 'work';
		const titles = {
			work:       'Создать работу',
			lesson:     'Создать урок',
			problem:    'Создать задачу',
			task:       'Создать задачу',
			assessment: 'Создать контрольную',
			material:   'Создать материал',
		};
		this.$modal.find( '.fs-lms-draft-work-type-row' ).prop( 'hidden', ! isWork );
		this.$modal.find( '#fs-lms-draft-creator-title' ).text( titles[ config.refType ] || 'Создать' );
		this.$title.val( '' );

		openModal( this.$modal );
		bindEsc( 'draft_creator', () => this.close() );
		this.$title.trigger( 'focus' );
	},

	close() {
		closeModal( this.$modal );
		unbindEsc( 'draft_creator' );
	},

	_submit() {
		if ( this._submitting || ! this._config ) {
			return;
		}

		const title = this.$title.val().trim();
		if ( ! title ) {
			this.$title.trigger( 'focus' );
			return;
		}

		const { refType, $field, onCreated } = this._config;
		const subject = String( $field.data( 'subject' ) );

		const actionMap = {
			work:       fs_lms_vars.ajax_actions.createWorkDraft,
			lesson:     fs_lms_vars.ajax_actions.createLessonDraft,
			problem:    fs_lms_vars.ajax_actions.createProblemDraft,
			task:       fs_lms_vars.ajax_actions.createTaskDraft,
			assessment: fs_lms_vars.ajax_actions.createAssessmentDraft,
			material:   fs_lms_vars.ajax_actions.createArticleDraft,
		};
		const nonceMap = {
			work:       fs_lms_vars.nonces.authorWork,
			lesson:     fs_lms_vars.nonces.authorLesson,
			problem:    fs_lms_vars.nonces.authorWork,
			task:       fs_lms_vars.nonces.authorLesson,
			assessment: fs_lms_vars.nonces.authorLesson,
			material:   fs_lms_vars.nonces.authorLesson,
		};
		const action = actionMap[ refType ];
		const nonce  = nonceMap[ refType ];

		const data = { action, security: nonce, subject_key: subject, title };
		if ( refType === 'work' ) {
			data.work_type = this.$workType.val();
		}

		this._submitting = true;
		this.$submit.prop( 'disabled', true ).text( 'Создание…' );

		$.post( fs_lms_vars.ajaxurl, data )
			.done( ( resp ) => {
				if ( resp && resp.success ) {
					onCreated( resp.data.id, resp.data.title );
					this.close();
				}
			} )
			.always( () => {
				this._submitting = false;
				this.$submit.prop( 'disabled', false ).text( 'Создать' );
			} );
	},
};
