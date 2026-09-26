/**
 * @module LectureCodeModal
 * @description Модалка блока «Код» шага «Лекция» — поля как у блока «Код» статьи:
 *              код как есть и язык плашки. Только UI: вставку в редактор делает
 *              `services/step-editors/lecture-blocks.js`.
 *
 * @requires jQuery
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';
import { bindTabIndent } from '../../common/utils.js';

const $ = jQuery;

/**
 * Языки плашки — те же, что у блока статьи (`ArticleBlockRenderer::LANGUAGES`).
 * Подсветка синтаксиса — только для Python, для остальных язык меняет подпись.
 */
export const LECTURE_CODE_LANGUAGES = [ 'Python', 'C++', 'C#', 'Java', 'Pascal', 'JavaScript', 'SQL', 'Текст' ];

export const LectureCodeModal = {

	_initialized: false,
	_resolve:     null,

	init() {
		this.$modal = $( '#fs-lms-lecture-code-modal' );
		if ( ! this.$modal.length || this._initialized ) {
			return;
		}
		this._initialized = true;

		this.$code   = $( '#fs-lms-lecture-code' );
		this.$lang   = $( '#fs-lms-lecture-code-lang' );
		this.$submit = this.$modal.find( '[data-lecture-block-submit]' );

		LECTURE_CODE_LANGUAGES.forEach( ( lang ) => this.$lang.append( $( '<option>' ).val( lang ).text( lang ) ) );
		bindTabIndent( this.$code[ 0 ] );

		this.$modal.on( 'click', '.js-modal-close, .fs-lms-modal-backdrop', () => this.close() );
		this.$submit.on( 'click', () => this._submit() );
	},

	/**
	 * @param {{ code?: string, lang?: string, editing?: boolean }} initial Значения полей.
	 * @return {Promise<{ code: string, lang: string }|null>} null — модалку закрыли.
	 */
	open( initial = {} ) {
		this.init();
		this.$code.val( initial.code || '' );
		this.$lang.val( LECTURE_CODE_LANGUAGES.includes( initial.lang ) ? initial.lang : LECTURE_CODE_LANGUAGES[ 0 ] );
		this.$submit.text( initial.editing ? 'Сохранить' : 'Вставить' );

		openModal( this.$modal );
		bindEsc( 'lecture_code', () => this.close() );
		this.$code.trigger( 'focus' );

		return new Promise( ( resolve ) => { this._resolve = resolve; } );
	},

	close( result = null ) {
		closeModal( this.$modal );
		unbindEsc( 'lecture_code' );
		this._resolve?.( result );
		this._resolve = null;
	},

	_submit() {
		const code = String( this.$code.val() ).replace( /\s+$/, '' );
		if ( ! code ) {
			this.$code.trigger( 'focus' );
			return;
		}
		this.close( { code, lang: String( this.$lang.val() ) } );
	},
};
