import { LectureCodeModal } from '../../modals/lecture-code-modal.js';
import { LectureImageModal } from '../../modals/lecture-image-modal.js';

/**
 * lecture-blocks.js — кнопки «Код» и «Изображение» редактора шага «Лекция».
 *
 * Те же блоки, что у статьи (модуль ArticleBlocks), с теми же полями в модалках,
 * но в лекцию вставляется готовая HTML-разметка, а не шорткод: лекцию правят в
 * визуальном редакторе, где шорткод выглядел бы строкой текста, а плеер не
 * должен зависеть от того, включён ли модуль статей.
 *
 * Разметка совпадает с тем, что выводит блок статьи (`ArticleBlockRenderer`):
 *  - код — `<pre><code class="js-code" data-lang>`: плеер разворачивает его в
 *    листинг с плашкой языка, копированием и номерами строк (`code-block.js`);
 *  - картинка — `<figure><img class="wp-image-{id} size-{размер}"><figcaption>`.
 *
 * Курсор внутри уже вставленного блока — кнопка открывает его на правку.
 */

/** Класс листинга — как у блока «Код» статьи. */
const LISTING_CLASS = 'js-code';

/** Класс обёртки картинки — по нему блок узнаётся при правке. */
const FIGURE_CLASS = 'fs-lecture-figure';

/** Маркер «ширина задана вручную»: сама ширина — атрибут width картинки. */
const CUSTOM_WIDTH_CLASS = 'fs-img-custom-width';

/**
 * Регистрирует кнопки `fs_code_block` и `fs_image` на панели TinyMCE.
 *
 * @param {Object} editor Экземпляр TinyMCE.
 */
export function registerLectureBlocks( editor ) {
	editor.addButton( 'fs_code_block', {
		icon:    'code',
		tooltip: 'Код',
		onclick: () => editCode( editor ),
	} );

	editor.addButton( 'fs_image', {
		icon:    'image',
		tooltip: 'Изображение',
		onclick: () => editImage( editor ),
	} );
}

// ── Код ───────────────────────────────────────────────────────────────────

/** Листинг под курсором (`<pre>` с `code.js-code` либо простой блок кода) или null. */
function currentCode( editor ) {
	return editor.dom.getParent( editor.selection.getNode(), 'pre' );
}

function editCode( editor ) {
	const pre      = currentCode( editor );
	const bookmark = editor.selection.getBookmark();
	const initial  = pre
		? { code: pre.textContent, lang: pre.querySelector( 'code' )?.dataset.lang, editing: true }
		// Выделенный текст — обычный сценарий: код вставили абзацем, выделили, нажали кнопку.
		: { code: editor.selection.getContent( { format: 'text' } ) };

	LectureCodeModal.open( initial ).then( ( result ) => {
		if ( ! result ) {
			return;
		}
		const html = '<pre><code class="' + LISTING_CLASS + '" data-lang="' + editor.dom.encode( result.lang ) + '">'
			+ editor.dom.encode( result.code ) + '</code></pre>';

		replaceOrInsert( editor, pre, bookmark, html );
	} );
}

// ── Изображение ──────────────────────────────────────────────────────────

/** Картинка под курсором: её `<figure>` (или сам `<img>`) и данные для модалки. */
function currentImage( editor ) {
	const node   = editor.selection.getNode();
	const figure = editor.dom.getParent( node, 'figure.' + FIGURE_CLASS );
	const img    = figure ? figure.querySelector( 'img' ) : ( 'IMG' === node.nodeName ? node : null );
	if ( ! img ) {
		return null;
	}

	const id = parseInt( ( img.className.match( /wp-image-(\d+)/ ) || [] )[ 1 ], 10 );
	if ( ! id ) {
		return null;
	}

	return {
		node:    figure || img,
		id,
		size:    ( img.className.match( /size-([\w-]+)/ ) || [] )[ 1 ] || '',
		width:   img.classList.contains( CUSTOM_WIDTH_CLASS ) ? parseInt( img.getAttribute( 'width' ), 10 ) || 0 : 0,
		caption: figure?.querySelector( 'figcaption' )?.textContent || '',
	};
}

function editImage( editor ) {
	const current  = currentImage( editor );
	const bookmark = editor.selection.getBookmark();

	// Правка — подтягиваем вложение из медиатеки: размеры нужны модалке для списка.
	const attachment = current && window.wp?.media
		? fetchAttachment( current.id )
		: Promise.resolve( null );

	attachment.then( ( data ) => LectureImageModal.open( {
		attachment: data,
		size:       current?.size,
		width:      current?.width,
		caption:    current?.caption,
		editing:    !! current,
	} ) ).then( ( result ) => {
		if ( result ) {
			replaceOrInsert( editor, current?.node || null, bookmark, imageHtml( editor, result ) );
		}
	} );
}

/** Вложение медиатеки по ID (`toJSON()` модели) или null, если его нет. */
function fetchAttachment( id ) {
	const model = window.wp.media.attachment( id );

	return Promise.resolve( model.fetch() ).then( () => model.toJSON(), () => null );
}

/**
 * Разметка картинки. Ширина задана — берём наименьший размер не уже неё
 * (иначе оригинал) и пропорционально пересчитываем высоту, как блок статьи.
 */
function imageHtml( editor, { attachment, size, width, caption } ) {
	const sizes = attachment.sizes || {};
	const full  = { url: attachment.url, width: attachment.width, height: attachment.height };

	let slug = sizes[ size ] ? size : 'full';
	let src  = sizes[ slug ] || full;
	let w    = src.width;
	let h    = src.height;

	if ( width > 0 ) {
		const fit = Object.entries( sizes )
			.filter( ( [ , s ] ) => s.width >= width )
			.sort( ( a, b ) => a[ 1 ].width - b[ 1 ].width )[ 0 ];
		[ slug, src ] = fit || [ 'full', full ];
		w = width;
		h = Math.round( src.height * width / src.width );
	}

	const attrs = [
		'src="' + editor.dom.encode( src.url ) + '"',
		'width="' + w + '"',
		'height="' + h + '"',
		'alt="' + editor.dom.encode( attachment.alt || '' ) + '"',
		'class="wp-image-' + attachment.id + ' size-' + slug + ( width > 0 ? ' ' + CUSTOM_WIDTH_CLASS : '' ) + '"',
	];

	return '<figure class="' + FIGURE_CLASS + '"><img ' + attrs.join( ' ' ) + '>'
		+ ( caption ? '<figcaption>' + editor.dom.encode( caption ) + '</figcaption>' : '' )
		+ '</figure>';
}

// ── Общее ─────────────────────────────────────────────────────────────────

/**
 * Правка — заменяет блок на месте; новый блок — вставляет туда, где был курсор
 * до открытия модалки (фокус уходил в модалку, выделение восстанавливаем).
 */
function replaceOrInsert( editor, existing, bookmark, html ) {
	editor.focus();

	if ( existing && editor.getBody().contains( existing ) ) {
		editor.undoManager.transact( () => editor.dom.setOuterHTML( existing, html ) );
		editor.nodeChanged();
		return;
	}

	editor.selection.moveToBookmark( bookmark );
	editor.insertContent( html + '<p>&nbsp;</p>' );
}
