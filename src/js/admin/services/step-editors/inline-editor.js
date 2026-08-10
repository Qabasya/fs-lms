import { renderChapterRows, renderAttachmentRows } from './video-editor.js';

/**
 * inline-editor.js — тело инлайнового шага (text / video / broadcast):
 * TinyMCE/wp.editor с кнопками LaTeX, поля видео-шага, ссылка трансляции.
 * Вынесено из step-editor.js без изменения поведения: связки с инстансом
 * редактора передаются через `ctx` — `tinyState` (держатель id активного
 * TinyMCE, общий с destroyTiny), `scheduleSave`, `clearReviewFlag`.
 */

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

		// Добавляет кнопки LaTeX в тулбар TinyMCE 4.
		// Кнопки оборачивают выделение (или вставляют placeholder) в \(...\) / \[...\].
		function setupLatexButtons( editor ) {
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
			editor.addButton( 'latex_inline', {
				text    : '\\(…\\)',
				tooltip : 'Инлайн-формула LaTeX',
				onclick() {
					const sel = editor.selection.getContent( { format: 'text' } ).trim();
					editor.selection.setContent( '\\(' + ( sel || '  ' ) + '\\)' );
				},
			} );
			editor.addButton( 'latex_block', {
				text    : '\\[…\\]',
				tooltip : 'Блочная формула LaTeX',
				onclick() {
					const sel = editor.selection.getContent( { format: 'text' } ).trim();
					editor.selection.setContent( '\\[' + ( sel || '  ' ) + '\\]' );
				},
			} );
			editor.addButton( 'fs_media', {
				icon   : 'image',
				tooltip: 'Добавить медиафайл',
				onclick() {
					window.wp?.media?.editor?.open( editor.id );
				},
			} );
			editor.on( 'NodeChange change', onEditorChange );
			editor.on( 'keyup paste cut', () => clearReviewFlag( step ) );
		}

		const cdnBase = 'https://cdn.jsdelivr.net/npm/tinymce@4.9.11/plugins';
		const externalPlugins = {
			table        : cdnBase + '/table/plugin.min.js',
			searchreplace: cdnBase + '/searchreplace/plugin.min.js',
			anchor       : cdnBase + '/anchor/plugin.min.js',
		};

		if ( window.wp?.editor ) {
			window.wp.editor.initialize( tid, {
				tinymce: {
					wpautop          : true,
					plugins          : 'charmap colorpicker fullscreen hr lists paste tabfocus textcolor wordpress wpautoresize wpeditimage wplink wptextpattern',
					external_plugins : externalPlugins,
					toolbar1         : 'bold italic underline strikethrough code_inline | formatselect | forecolor | bullist numlist | blockquote hr | alignleft aligncenter alignright | link unlink | fs_media | table | removeformat | undo redo | fullscreen',
					toolbar2         : 'charmap | anchor searchreplace | latex_inline latex_block',
					height           : 400,
					setup            : setupLatexButtons,
				},
				quicktags   : { buttons: 'strong,em,link,ul,ol,li,code,close' },
				mediaButtons: false,
			} );
		} else if ( window.tinymce ) {
			window.tinymce.init( {
				selector         : '#' + tid,
				external_plugins : externalPlugins,
				toolbar          : 'bold italic underline strikethrough code_inline | formatselect | bullist numlist | blockquote hr | alignleft aligncenter alignright | link | charmap | table | anchor searchreplace | removeformat | undo redo | fullscreen | latex_inline latex_block',
				menubar          : false,
				statusbar        : false,
				plugins          : 'link lists hr charmap fullscreen',
				height           : 400,
				skin_url         : window.tinymce?.baseURL + '/skins/lightgray',
				setup            : setupLatexButtons,
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
			<div class="field-row"><label>Ссылка на трансляцию</label><input class="field-input" data-stream-url placeholder="https://…"></div>
			<p class="field-hint">После занятия сюда автоматически привяжется запись.</p>`;
		const streamUrl = ed.querySelector( '[data-stream-url]' );
		streamUrl.value = step.payload.stream_url || '';
		streamUrl.addEventListener( 'input', () => {
			step.payload.stream_url = streamUrl.value;
			clearReviewFlag( step );
			scheduleSave();
		} );
	}
}
