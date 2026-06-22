import '../_types.js';
import { showToast } from '../modules/toast.js';

/* global jQuery, fs_lms_vars */
const $ = jQuery;

/**
 * TaskTemplateType — авто-сохранение типа шаблона задачи (банк `fs_lms_problems`).
 *
 * При смене визуального шаблона в селекторе «Тип шаблона» (сайдбар) тип сразу
 * сохраняется AJAX-ом, затем экран редактирования перезагружается — метабокс
 * полей перерисовывается под новый тип (`MetaBoxController` → `TemplateResolver`).
 */
export const TaskTemplateType = {

	init() {
		const $select = $( '.fs-lms-template-select' );
		if ( ! $select.length ) {
			return;
		}
		$select.on( 'change', this.onChange );
	},

	onChange() {
		const $select = $( this );
		const postId  = parseInt( $( '#post_ID' ).val(), 10 ) || 0;
		const nonce   = $( '#fs_lms_meta_nonce' ).val() || '';
		const type    = String( $select.val() || '' );

		if ( ! postId || ! nonce || ! type ) {
			return;
		}

		$select.prop( 'disabled', true );

		$.post( fs_lms_vars.ajaxurl, {
			action:        fs_lms_vars.ajax_actions.setTaskTemplateType,
			security:      nonce,
			post_id:       postId,
			template_type: type,
		} ).done( ( resp ) => {
			if ( resp && resp.success ) {
				// Переход на edit-экран этого поста: надёжно для auto-draft (новая задача)
				// и для существующих — там это просто перезагрузка.
				window.location.href = `post.php?post=${ postId }&action=edit`;
			} else {
				showToast( ( resp && resp.data ) || 'Не удалось сохранить тип', 'error' );
				$select.prop( 'disabled', false );
			}
		} ).fail( () => {
			showToast( 'Ошибка сети', 'error' );
			$select.prop( 'disabled', false );
		} );
	},
};