import { renderChapterRows, renderAttachmentRows } from './video-editor.js';
import { registerBlockButtons } from '../../../tinymce/editor-blocks.js';
import { registerLectureBlocks } from './lecture-blocks.js';

/**
 * inline-editor.js — тело инлайнового шага (text / video / broadcast):
 * TinyMCE/wp.editor с кнопками блоков и LaTeX, поля видео-шага, ссылка
 * трансляции. Вынесено из step-editor.js без изменения поведения: связки с
 * инстансом редактора передаются через `ctx` — `tinyState` (держатель id
 * активного TinyMCE, общий с destroyTiny), `scheduleSave`, `clearReviewFlag`.
 *
 * Таблица, блок кода и формула — общие с редактором задания кнопки
 * (`tinymce/editor-blocks.js`). Регистрируются прямо в `setup`, а не внешним
 * плагином: редактор шага поднимает `wp.editor.initialize()` из JS, и
 * `mce_external_plugins` до него не доезжает. Плагин `table` из комплекта
 * TinyMCE не используется — в сборке WordPress его нет, а с CDN он терялся
 * вместе с кнопкой.
 *
 * Делимитеры формулы здесь мэтджаксовые (`\(…\)` / `\[…\]`): шаг урока
 * рендерит MathJax, настроенный в `Core\Assets\BundleLoader`.
 */

/**
 * Разворачивает произвольно вложенные <div>/<span>-обёртки без семантики —
 * их приносит вставка из внешних редакторов (Google Colab, Jupyter export
 * и т.п.), а модель лекции держит только плоские блочные теги (p/h1-h4/
 * ul/ol/pre/blockquote/table/img — см. _step-text.scss). Строки кода,
 * скопированные построчно как отдельные `<div><code>…</code></div>`,
 * склеиваются в один `<pre><code>` — иначе каждая строка рендерится своим
 * блоком с рамкой.
 */
function cleanPastedNode( root ) {
	// Разворачивать div/span, пока не останется ни одного — глубина
	// исходной вложенности произвольна (у Colab бывает 4-5 уровней).
	let wrapper;
	while ( ( wrapper = root.querySelector( 'div, span' ) ) ) {
		while ( wrapper.firstChild ) {
			wrapper.parentNode.insertBefore( wrapper.firstChild, wrapper );
		}
		wrapper.remove();
	}

	// Соседние «голые» <code> на верхнем уровне (по одной строке кода на
	// исходный div) — склеить в один <pre><code>, разделив переносами.
	const children = Array.from( root.childNodes );
	let i = 0;
	while ( i < children.length ) {
		const node = children[ i ];
		if ( node.nodeType === 1 && 'CODE' === node.tagName ) {
			let j = i;
			const lines = [];
			while ( j < children.length && children[ j ].nodeType === 1 && 'CODE' === children[ j ].tagName ) {
				lines.push( children[ j ].textContent );
				j++;
			}
			const pre  = document.createElement( 'pre' );
			const code = document.createElement( 'code' );
			code.textContent = lines.join( '\n' );
			pre.appendChild( code );
			root.insertBefore( pre, children[ i ] );
			for ( let k = i; k < j; k++ ) {
				root.removeChild( children[ k ] );
			}
			i = j;
		} else {
			i++;
		}
	}
}

export function destroyTiny( tinyState ) {
	if ( tinyState.id ) {
		if ( window.wp?.editor ) {
			window.wp.editor.remove( tinyState.id );
		} else if ( window.tinymce?.get( tinyState.id ) ) {
			window.tinymce.get( tinyState.id ).remove();
		}
		tinyState.id = null;
	}
}

export function inlineEditor( ed, step, ctx ) {
	const { tinyState, scheduleSave, clearReviewFlag } = ctx;
	if ( 'text' === step.type ) {
		const tid = `fs-se-rte-${ Date.now() }`;
		tinyState.id = tid;
		ed.innerHTML ='<textarea id="' + tid + '" class="fs-cb-rte-target"></textarea>';
		ed.querySelector( '#' + tid ).value = step.payload.content || '';
		ed.classList.add( 'fs-rte-loading' ); // анти-флэш: снимется по событию init редактора

		function onEditorChange() {
			const mc = window.tinymce?.get( tid );
			step.payload.content = mc ? mc.getContent() : ( ed.querySelector( '#' + tid )?.value ?? '' );
			scheduleSave();
		}

		// Добавляет кнопки плагина в тулбар TinyMCE 4: блоки (таблица, код,
		// формула) — общие с редактором задания, остальное — своё.
		function setupButtons( editor ) {
			// Таблица и формула — общие с заданием; «Код» и «Изображение» — блоки
			// как в статье, со своими модалками (lecture-blocks.js).
			registerBlockButtons( editor, { latex: 'mathjax', code: false } );
			registerLectureBlocks( editor );

			editor.addButton( 'code_inline', {
				text   : '</>',
				tooltip: 'Инлайн-код',
				onclick() {
					editor.formatter.toggle( 'code_inline' );
				},
				onPostRender() {
					const btn = this;
					editor.on( 'NodeChange', () => btn.active( editor.formatter.match( 'code_inline' ) ) );
				},
			} );
			editor.on( 'init', () => {
				editor.formatter.register( 'code_inline', { inline: 'code' } );
				ed.classList.remove( 'fs-rte-loading' );
			} );
			// Медиатека целиком (файлы, видео, картинка «как есть»); картинку как в
			// статье — с размером и подписью — вставляет кнопка «Изображение».
			editor.addButton( 'fs_media', {
				icon   : 'dashicon dashicons-admin-media',
				tooltip: 'Добавить медиафайл',
				onclick() {
					window.wp?.media?.editor?.open( editor.id );
				},
			} );
			editor.on( 'NodeChange change', onEditorChange );
			editor.on( 'keyup paste cut', () => clearReviewFlag( step ) );
		}

		// TinyMCE вычищает класс у `<pre>` и `<table>` при переключении вкладок
		// «Визуально»/«Текст», а без него блок кода на фронте остаётся
		// неподсвеченным (`frontend/components/code-block.js` ищет именно класс).
		// У листинга класс и язык висят на `<code>`, у картинки — класс на `<figure>`:
		// их тоже бережём.
		const extendedElements = 'pre[class|id|style],code[class|data-lang],table[class|id|style],figure[class],figcaption';

		if ( window.wp?.editor ) {
			window.wp.editor.initialize( tid, {
				tinymce: {
					wpautop                : true,
					plugins                : 'charmap colorpicker fullscreen hr lists paste tabfocus textcolor wordpress wpautoresize wpeditimage wplink wptextpattern',
					toolbar1               : 'bold italic underline strikethrough code_inline | formatselect | forecolor | bullist numlist | blockquote hr | alignleft aligncenter alignright | link unlink | fs_media | removeformat | undo redo | fullscreen',
					toolbar2               : 'fs_table fs_code_block fs_image fs_formula | charmap',
					// Второй ряд WordPress прячет до нажатия «Показать/скрыть панель», а
					// этой кнопки у нас нет — без флага блоки было не достать вовсе.
					wordpress_adv_hidden   : false,
					height                 : 400,
					extended_valid_elements: extendedElements,
					paste_postprocess      : ( plugin, args ) => cleanPastedNode( args.node ),
					setup                  : setupButtons,
				},
				quicktags   : { buttons: 'strong,em,link,ul,ol,li,code,close' },
				mediaButtons: false,
			} );
		} else if ( window.tinymce ) {
			window.tinymce.init( {
				selector               : '#' + tid,
				toolbar                : 'bold italic underline strikethrough code_inline | formatselect | bullist numlist | blockquote hr | alignleft aligncenter alignright | link | charmap | fs_table fs_code_block fs_image fs_formula | removeformat | undo redo | fullscreen',
				menubar                : false,
				statusbar              : false,
				plugins                : 'link lists hr charmap fullscreen',
				height                 : 400,
				skin_url               : window.tinymce?.baseURL + '/skins/lightgray',
				extended_valid_elements: extendedElements,
				paste_postprocess      : ( plugin, args ) => cleanPastedNode( args.node ),
				setup                  : setupButtons,
			} );
		} else {
			const area = ed.querySelector( '#' + tid );
			area.setAttribute( 'style', 'display:none' );
			const div = document.createElement( 'div' );
			div.className = 'rte-area';
			div.contentEditable = 'true';
			div.innerHTML = step.payload.content || '';
			div.addEventListener( 'input', () => { step.payload.content = div.innerHTML; clearReviewFlag( step ); scheduleSave(); } );
			ed.appendChild( div );
		}
	} else if ( 'video' === step.type ) {
		ed.innerHTML = `
			<div class="field-row"><label>Ссылка на видео</label><input class="field-input" data-url placeholder="https://…mp4 (нативный плеер) или YouTube/VK/Rutube (встраивание)"></div>
			<div class="field-row"><label>Описание под видео</label><textarea class="field-input" data-desc placeholder="Краткое описание…"></textarea></div>
			<div class="field-row"><label>Таймкоды с главами</label>
				<div class="fs-cb-chapters" data-chapters></div>
				<button type="button" class="button" data-chapter-add>+ Глава</button>
			</div>
			<div class="field-row"><label>Вложения-конспекты (скачивание под плеером)</label>
				<div class="fs-cb-attachments" data-attach-list></div>
				<button type="button" class="button" data-attach-add>+ Файл из медиабиблиотеки</button>
			</div>`;
		const url  = ed.querySelector( '[data-url]' );
		const desc = ed.querySelector( '[data-desc]' );
		url.value  = step.payload.url || '';
		desc.value = step.payload.description || '';
		url.addEventListener( 'input', () => { step.payload.url = url.value; clearReviewFlag( step ); scheduleSave(); } );
		desc.addEventListener( 'input', () => { step.payload.description = desc.value; clearReviewFlag( step ); scheduleSave(); } );

		renderChapterRows( ed.querySelector( '[data-chapters]' ), step, scheduleSave );
		renderAttachmentRows( ed.querySelector( '[data-attach-list]' ), step, scheduleSave );

		ed.querySelector( '[data-chapter-add]' ).addEventListener( 'click', () => {
			step.payload.chapters = step.payload.chapters || [];
			step.payload.chapters.push( { t: 0, title: '' } );
			renderChapterRows( ed.querySelector( '[data-chapters]' ), step, scheduleSave );
			scheduleSave();
		} );

		ed.querySelector( '[data-attach-add]' ).addEventListener( 'click', () => {
			if ( ! window.wp?.media ) { return; }
			const frame = window.wp.media( { title: 'Вложения к видео', multiple: true } );
			frame.on( 'select', () => {
				const picked = frame.state().get( 'selection' ).toJSON().map( ( a ) => a.id );
				const ids    = ( step.payload.attachments || [] ).concat( picked );
				step.payload.attachments = ids.filter( ( v, i ) => ids.indexOf( v ) === i );
				renderAttachmentRows( ed.querySelector( '[data-attach-list]' ), step, scheduleSave );
				scheduleSave();
			} );
			frame.open();
		} );
	} else if ( 'broadcast' === step.type ) {
		ed.innerHTML = `
			<div class="field-row"><label>Ссылка на трансляцию (необязательно)</label><input class="field-input" data-stream-url placeholder="https://…"></div>
			<p class="field-hint">Адрес эфира на время занятия — Zoom, VK Видео, YouTube и т.п.
			Поле можно оставить пустым: после занятия шаг сам покажет запись, привязанную к занятию
			(в том числе загруженную в S3), а до неё — заглушку. Публикацию курса пустой шаг не блокирует.</p>`;
		const streamUrl = ed.querySelector( '[data-stream-url]' );
		streamUrl.value = step.payload.stream_url || '';
		streamUrl.addEventListener( 'input', () => {
			step.payload.stream_url = streamUrl.value;
			clearReviewFlag( step );
			scheduleSave();
		} );
	}
}
