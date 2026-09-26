/**
 * @fileoverview Кнопки блоков для TinyMCE: таблица, блок кода с подсветкой,
 * формула LaTeX. Общие для двух поверхностей, где автор набирает контент:
 * метабокс задания (внешний плагин `task-editor-buttons.js`) и редактор шага
 * «Лекция» в конструкторе урока (`admin/services/step-editors/inline-editor.js`).
 *
 * Собственные кнопки, а не плагин `table` из комплекта TinyMCE: в сборке
 * WordPress его нет (`wp-includes/js/tinymce/plugins/` содержит charmap, lists,
 * paste и т.д., но не table), а тянуть его с CDN значит потерять кнопку везде,
 * где до CDN не достучались. Да он и не нужен: по постановке требуется разовая
 * вставка данных, скопированных из Excel, а не панель работы с таблицей.
 *
 * Разметка совпадает с тем, что ждёт фронт: `<pre class="fs-code-highlight">`
 * разбирает `frontend/components/code-block.js` (подсветка строится на клиенте,
 * в сохранённом HTML остаётся чистый текст); лекция вставляет вместо него
 * листинг `<pre><code class="js-code" data-lang>` — тот же, что блок «Код»
 * статьи, с плашкой языка, копированием и номерами строк, таблицу с классом
 * `fs-table-center` центрирует общий SCSS (`shared/_content-tables.scss`).
 *
 * Делимитеры формулы у поверхностей разные, и это не косметика: условие задания
 * разбирает QuickLaTeX (`$…$` / `$$…$$` на странице с `[latexpage]`, который
 * дописывает `Services\Task\LatexPageShortcodeService`), а шаг урока — MathJax,
 * настроенный в `Core\Assets\BundleLoader` на `\(…\)` / `\[…\]`.
 */

/** Класс блока кода — тот же, что читает подсветка на фронте. */
const CODE_CLASS = 'fs-code-highlight';

/**
 * Класс листинга — как у блока «Код» статьи (`ArticleBlockRenderer::code()`):
 * `code-block.js` разворачивает его в редактор с плашкой языка, копированием
 * и номерами строк.
 */
const LISTING_CLASS = 'js-code';

/**
 * Языки плашки листинга — те же, что у блока статьи
 * (`ArticleBlockRenderer::LANGUAGES`). Подсветка — только для Python.
 */
const LISTING_LANGUAGES = [ 'Python', 'C++', 'C#', 'Java', 'Pascal', 'JavaScript', 'SQL', 'Текст' ];

/** Отступ по Tab в поле кода — как в редакторе блока статьи. */
const TAB_INDENT = '    ';

/** Класс таблицы, которой SCSS центрирует ячейки (вместо инлайновых стилей). */
const TABLE_CLASS = 'fs-table-center';

const DEFAULT_COLS = 3;
const DEFAULT_ROWS = 2;

/** Обёртки формулы: ключ — движок, который её разбирает на фронте. */
const LATEX_WRAPS = {
	quicklatex: { inline: [ '$', '$' ], block: [ '$$', '$$' ] },
	mathjax   : { inline: [ '\\(', '\\)' ], block: [ '\\[', '\\]' ] },
};

/**
 * Разбирает данные, скопированные из таблицы Excel/Google Sheets:
 * строки переводами строк, ячейки — табами.
 *
 * @param {string} raw Содержимое textarea.
 * @returns {string[][]} Матрица значений; пустой массив, если данных нет.
 */
function parseGrid( raw ) {
	const text = String( raw || '' ).replace( /\r\n?/g, '\n' ).replace( /\n+$/, '' );

	if ( ! text.trim() ) {
		return [];
	}

	return text.split( '\n' ).map( ( line ) => line.split( '\t' ) );
}

/**
 * Дополняет матрицу до заданного размера и обрезает лишнее, чтобы строки
 * таблицы всегда были одной длины (иначе вёрстка едет).
 *
 * @param {string[][]} grid Матрица из parseGrid().
 * @param {number}     cols Нужное число столбцов.
 * @param {number}     rows Нужное число строк (вместе с шапкой).
 * @returns {string[][]}
 */
function fitGrid( grid, cols, rows ) {
	const out = [];

	for ( let r = 0; r < rows; r++ ) {
		const row = [];
		for ( let c = 0; c < cols; c++ ) {
			row.push( ( grid[ r ] && grid[ r ][ c ] ) ? String( grid[ r ][ c ] ).trim() : '' );
		}
		out.push( row );
	}

	return out;
}

/**
 * HTML таблицы. Первая строка — шапка: в кодовых таблицах условия это
 * почти всегда заголовки (буквы, разряды, обозначения).
 *
 * @param {Object}     editor     Экземпляр TinyMCE (нужен для экранирования).
 * @param {string[][]} grid       Матрица значений.
 * @param {boolean}    withHeader Первую строку сделать шапкой.
 * @returns {string}
 */
function tableHtml( editor, grid, withHeader ) {
	const cell = ( tag, value ) =>
		'<' + tag + '>' + ( value ? editor.dom.encode( value ) : '&nbsp;' ) + '</' + tag + '>';
	let head = '';
	let body = '';

	grid.forEach( ( row, index ) => {
		const isHead = withHeader && 0 === index;
		const tag    = isHead ? 'th' : 'td';
		const cells  = row.map( ( value ) => cell( tag, value ) ).join( '' );

		if ( isHead ) {
			head += '<tr>' + cells + '</tr>';
		} else {
			body += '<tr>' + cells + '</tr>';
		}
	} );

	return '<table class="' + TABLE_CLASS + '">'
		+ ( head ? '<thead>' + head + '</thead>' : '' )
		+ '<tbody>' + body + '</tbody>'
		+ '</table><p>&nbsp;</p>';
}

/** Клампит размер: 1 — иначе таблицы не выйдет, 30 — верхняя вменяемая планка. */
function clampSize( value, fallback ) {
	let n = parseInt( value, 10 );

	if ( ! n || n < 1 ) {
		n = fallback;
	}

	return Math.min( 30, n );
}

function openTableDialog( editor ) {
	editor.windowManager.open( {
		title: 'Вставить таблицу',
		width: 520,
		height: 360,
		body:  [
			{ type: 'textbox', name: 'cols', label: 'Столбцов', value: String( DEFAULT_COLS ) },
			{ type: 'textbox', name: 'rows', label: 'Строк (вместе с шапкой)', value: String( DEFAULT_ROWS ) },
			{ type: 'checkbox', name: 'header', label: 'Первая строка — шапка', checked: true },
			{
				type:        'textbox',
				name:        'data',
				label:       'Данные',
				multiline:   true,
				minHeight:   140,
				placeholder: 'Вставьте ячейки из Excel — столбцы разделяются табуляцией',
			},
		],
		onsubmit( e ) {
			const grid = parseGrid( e.data.data );
			// Размеры вставки важнее введённых вручную: автор скопировал
			// готовую таблицу, и подрезать её по дефолтным 3×2 нельзя.
			const cols = grid.length
				? Math.max.apply( null, grid.map( ( row ) => row.length ) )
				: clampSize( e.data.cols, DEFAULT_COLS );
			const rows = grid.length || clampSize( e.data.rows, DEFAULT_ROWS );

			editor.insertContent( tableHtml(
				editor,
				fitGrid( grid, clampSize( cols, DEFAULT_COLS ), clampSize( rows, DEFAULT_ROWS ) ),
				Boolean( e.data.header )
			) );
		},
	} );
}

/**
 * Блок кода под курсором (для правки уже вставленного), либо null.
 *
 * @param {Object} editor Экземпляр TinyMCE.
 * @returns {HTMLElement|null}
 */
function currentCodeBlock( editor ) {
	const pre = editor.dom.getParent( editor.selection.getNode(), 'pre' );
	return pre && ( editor.dom.hasClass( pre, CODE_CLASS ) || pre.querySelector( 'code.' + LISTING_CLASS ) ) ? pre : null;
}

/**
 * Tab в многострочном поле диалога вставляет отступ, а не уводит фокус.
 *
 * @param {HTMLTextAreaElement} area Поле кода.
 */
function bindTabIndent( area ) {
	area.addEventListener( 'keydown', ( e ) => {
		if ( 'Tab' !== e.key || e.shiftKey ) {
			return;
		}
		e.preventDefault();
		const { selectionStart: from, selectionEnd: to, value } = area;
		area.value = value.slice( 0, from ) + TAB_INDENT + value.slice( to );
		area.selectionStart = area.selectionEnd = from + TAB_INDENT.length;
	} );
}

/**
 * Диалог блока кода.
 *
 * `listing` — листинг как в статье (язык, шапка, номера строк); иначе — простой
 * блок с подсветкой, который уместен внутри текста условия задания.
 *
 * @param {Object}  editor  Экземпляр TinyMCE.
 * @param {boolean} listing Вставлять листинг.
 */
function openCodeDialog( editor, listing ) {
	// Курсор в уже вставленном блоке — правим его; иначе выделенный текст —
	// обычный сценарий: автор вставил код абзацем, выделил и нажал кнопку.
	const existing = currentCodeBlock( editor );
	const lang     = existing?.querySelector( 'code' )?.dataset.lang || LISTING_LANGUAGES[ 0 ];
	const initial  = existing ? existing.textContent : editor.selection.getContent( { format: 'text' } );

	const body = [
		{
			type:        'textbox',
			name:        'code',
			multiline:   true,
			minHeight:   260,
			value:       initial || '',
			placeholder: listing ? 'Вставьте код как есть — Tab добавляет отступ' : 'Код на Python',
		},
	];
	if ( listing ) {
		body.unshift( {
			type:   'listbox',
			name:   'lang',
			label:  'Язык',
			value:  LISTING_LANGUAGES.includes( lang ) ? lang : LISTING_LANGUAGES[ 0 ],
			values: LISTING_LANGUAGES.map( ( l ) => ( { text: l, value: l } ) ),
		} );
	}

	const win = editor.windowManager.open( {
		title: existing ? 'Изменить код' : 'Вставить код',
		width: 640,
		height: listing ? 460 : 420,
		body,
		onsubmit( e ) {
			const code = String( e.data.code || '' ).replace( /\s+$/, '' );
			if ( ! code ) {
				return;
			}
			const encoded = editor.dom.encode( code );
			const html    = listing
				? '<pre><code class="' + LISTING_CLASS + '" data-lang="' + editor.dom.encode( e.data.lang ) + '">' + encoded + '</code></pre>'
				: '<pre class="' + CODE_CLASS + '">' + encoded + '</pre>';

			if ( existing ) {
				editor.undoManager.transact( () => editor.dom.setOuterHTML( existing, html ) );
				editor.nodeChanged();
				return;
			}
			editor.insertContent( html + '<p>&nbsp;</p>' );
		},
	} );

	const area = win?.find( '#code' )[ 0 ]?.getEl();
	if ( area ) {
		bindTabIndent( area );
	}
}

function openFormulaDialog( editor, wraps ) {
	const selected = editor.selection.getContent( { format: 'text' } );

	editor.windowManager.open( {
		title: 'Вставить формулу',
		width: 560,
		height: 260,
		body:  [
			{
				type:        'textbox',
				name:        'formula',
				label:       'LaTeX',
				multiline:   true,
				minHeight:   110,
				value:       selected || '',
				placeholder: '\\frac{a}{b} = \\sqrt{c}',
			},
			{ type: 'checkbox', name: 'inline', label: 'В строку с текстом', checked: false },
		],
		onsubmit( e ) {
			const formula = String( e.data.formula || '' ).trim();

			if ( ! formula ) {
				return;
			}

			// Шорткод `[latexpage]` сюда не пишется: он относится к странице
			// целиком и дописывается один раз при сохранении условия (PHP).
			const wrap = e.data.inline ? wraps.inline : wraps.block;

			editor.insertContent( editor.dom.encode( wrap[ 0 ] + formula + wrap[ 1 ] ) );
		},
	} );
}

/**
 * Вешает кнопки «Таблица», «Код» и «Формула» на панель редактора.
 *
 * @param {Object}  editor        Экземпляр TinyMCE.
 * @param {Object}  [options]
 * @param {string}  [options.latex='quicklatex'] Движок формул: `quicklatex` или `mathjax`.
 * @param {string}  [options.code='highlight']   Блок кода: `highlight` — простая подсветка
 *                                                (условие задания), `listing` — листинг как
 *                                                в статье, с языком и номерами строк (лекция).
 * @returns {void}
 */
export function registerBlockButtons( editor, options ) {
	const wraps   = LATEX_WRAPS[ ( options && options.latex ) || 'quicklatex' ] || LATEX_WRAPS.quicklatex;
	const listing = 'listing' === ( options && options.code );

	editor.addButton( 'fs_table', {
		icon:    'table',
		tooltip: 'Вставить таблицу',
		onclick() {
			openTableDialog( editor );
		},
	} );

	editor.addButton( 'fs_code_block', {
		icon:    'code',
		tooltip: listing ? 'Вставить листинг кода' : 'Вставить код с подсветкой',
		onclick() {
			openCodeDialog( editor, listing );
		},
	} );

	editor.addButton( 'fs_formula', {
		text:    '\\(x\\)',
		tooltip: 'Вставить формулу (LaTeX)',
		onclick() {
			openFormulaDialog( editor, wraps );
		},
	} );
}
