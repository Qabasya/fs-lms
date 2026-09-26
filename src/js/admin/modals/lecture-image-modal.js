/**
 * @module LectureImageModal
 * @description Модалка блока «Изображение» шага «Лекция» — поля как у блока
 *              «Изображение» статьи: картинка из медиатеки, размер, ширина, подпись.
 *              Только UI: разметку и вставку в редактор делает
 *              `services/step-editors/lecture-blocks.js`.
 *
 *              Размеры — из самого вложения (`attachment.sizes` медиатеки), а не
 *              из списка зарегистрированных: у картинки меньше «large» такого
 *              размера просто нет, и предлагать его незачем.
 *
 * @requires jQuery
 * @requires wp.media
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/** Размер по умолчанию — как у блока статьи (`ImageSizeOptions::DEFAULT`). */
const DEFAULT_SIZE = 'large';

/** Подписи стандартных размеров WordPress. */
const SIZE_LABELS = {
	thumbnail: 'Миниатюра',
	medium:    'Средний',
	large:     'Большой',
	full:      'Оригинал',
};

/**
 * Класс на <body>, пока открыта медиатека: она должна встать поверх нашей
 * модалки (её z-index ниже), стили — в `admin/components/_lecture-blocks.scss`.
 */
const MEDIA_ABOVE_CLASS = 'fs-media-above-modal';

export const LectureImageModal = {

	_initialized: false,
	_resolve:     null,
	_attachment:  null,
	_frame:       null,

	init() {
		this.$modal = $( '#fs-lms-lecture-image-modal' );
		if ( ! this.$modal.length || this._initialized ) {
			return;
		}
		this._initialized = true;

		this.$preview = this.$modal.find( '[data-lecture-image-preview]' );
		this.$pick    = this.$modal.find( '[data-lecture-image-pick]' );
		this.$clear   = this.$modal.find( '[data-lecture-image-clear]' );
		this.$size    = $( '#fs-lms-lecture-image-size' );
		this.$width   = $( '#fs-lms-lecture-image-width' );
		this.$caption = $( '#fs-lms-lecture-image-caption' );
		this.$submit  = this.$modal.find( '[data-lecture-block-submit]' );

		this.$modal.on( 'click', '.js-modal-close, .fs-lms-modal-backdrop', () => this.close() );
		this.$pick.on( 'click', () => this._openMedia() );
		this.$clear.on( 'click', () => this._setAttachment( null ) );
		this.$submit.on( 'click', () => this._submit() );
	},

	/**
	 * @param {{ attachment?: Object|null, size?: string, width?: number, caption?: string, editing?: boolean }} initial
	 *        `attachment` — данные вложения медиатеки (`toJSON()`), если картинку правят.
	 * @return {Promise<{ attachment: Object, size: string, width: number, caption: string }|null>}
	 *         null — модалку закрыли.
	 */
	open( initial = {} ) {
		this.init();
		this._setAttachment( initial.attachment || null, initial.size );
		this.$width.val( initial.width || '' );
		this.$caption.val( initial.caption || '' );
		this.$submit.text( initial.editing ? 'Сохранить' : 'Вставить' );

		openModal( this.$modal );
		bindEsc( 'lecture_image', () => this.close() );

		// Новая картинка — сразу в медиатеку: без неё заполнять остальное незачем.
		if ( ! initial.attachment ) {
			this._openMedia();
		}

		return new Promise( ( resolve ) => { this._resolve = resolve; } );
	},

	close( result = null ) {
		closeModal( this.$modal );
		unbindEsc( 'lecture_image' );
		this._resolve?.( result );
		this._resolve = null;
	},

	_openMedia() {
		if ( ! window.wp?.media ) {
			return;
		}

		if ( ! this._frame ) {
			this._frame = window.wp.media( {
				title:    'Выберите изображение',
				library:  { type: 'image' },
				multiple: false,
				button:   { text: 'Выбрать' },
			} );
			this._frame.on( 'select', () => {
				const picked = this._frame.state().get( 'selection' ).first();
				if ( picked ) {
					this._setAttachment( picked.toJSON(), this.$size.val() );
				}
			} );
			this._frame.on( 'open', () => document.body.classList.add( MEDIA_ABOVE_CLASS ) );
			this._frame.on( 'close', () => document.body.classList.remove( MEDIA_ABOVE_CLASS ) );
		}

		this._frame.open();
	},

	/**
	 * @param {Object|null} attachment Вложение медиатеки (`toJSON()`), null — убрать.
	 * @param {string}      [size]     Размер, который выбрать в списке.
	 */
	_setAttachment( attachment, size ) {
		this._attachment = attachment;
		const has = !! attachment;

		this.$preview.prop( 'hidden', ! has ).attr( 'src', has ? this._sizeOf( 'medium' ).url : '' );
		this.$pick.text( has ? 'Заменить' : 'Выбрать изображение' );
		this.$clear.prop( 'hidden', ! has );
		this.$submit.prop( 'disabled', ! has );

		this.$size.empty().prop( 'disabled', ! has );
		if ( ! has ) {
			return;
		}

		const sizes = attachment.sizes || {};
		Object.keys( sizes ).forEach( ( slug ) => {
			const s = sizes[ slug ];
			this.$size.append( $( '<option>' ).val( slug ).text( `${ SIZE_LABELS[ slug ] || slug } (${ s.width }×${ s.height })` ) );
		} );

		const wanted = size && sizes[ size ] ? size : ( sizes[ DEFAULT_SIZE ] ? DEFAULT_SIZE : 'full' );
		this.$size.val( wanted );
	},

	/** Данные размера вложения с откатом на оригинал. */
	_sizeOf( slug ) {
		const a = this._attachment;
		return ( a.sizes && a.sizes[ slug ] ) || { url: a.url, width: a.width, height: a.height };
	},

	_submit() {
		if ( ! this._attachment ) {
			return;
		}

		this.close( {
			attachment: this._attachment,
			size:       String( this.$size.val() || 'full' ),
			width:      Math.max( 0, parseInt( this.$width.val(), 10 ) || 0 ),
			caption:    String( this.$caption.val() ).trim(),
		} );
	},
};
