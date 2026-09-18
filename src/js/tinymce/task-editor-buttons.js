/**
 * @fileoverview Кнопки панели TinyMCE в редакторе задания: таблица, блок кода
 * с подсветкой и формула LaTeX.
 *
 * Это НЕ модуль admin-бандла: файл собирается отдельной точкой входа и
 * подключается самим редактором как внешний плагин (`mce_external_plugins`),
 * то есть исполняется внутри TinyMCE, а не на странице. Отсюда и стиль —
 * самоисполняемая регистрация в `tinymce.PluginManager`, без экспортов.
 *
 * Кнопка таблицы — своя, а не плагин `table` из комплекта TinyMCE: в сборке
 * WordPress его нет (`wp-includes/js/tinymce/plugins/` содержит charmap, lists,
 * paste и т.д., но не table). Да он и не нужен: по постановке требуется разовая
 * вставка данных, скопированных из Excel, а не панель работы с таблицей.
 *
 * Разметка блоков совпадает с тем, что ждёт фронт:
 * `<pre class="fs-code-highlight">` разбирает `frontend/components/code-block.js`
 * (подсветка строится на клиенте, в сохранённом HTML остаётся чистый текст),
 * таблицу с классом `fs-table-center` центрирует общий SCSS.
 */

/* global tinymce */

( function () {
	'use strict';

	if ( 'undefined' === typeof tinymce ) {
		return;
	}

	/** Класс блока кода — тот же, что читает подсветка на фронте. */
	var CODE_CLASS = 'fs-code-highlight';

	/** Класс таблицы, которую центрирует SCSS (вместо инлайновых стилей). */
	var TABLE_CLASS = 'fs-table-center';

	var DEFAULT_COLS = 3;
	var DEFAULT_ROWS = 2;

	/**
	 * Разбирает данные, скопированные из таблицы Excel/Google Sheets:
	 * строки переводами строк, ячейки — табами.
	 *
	 * @param {string} raw Содержимое textarea.
	 * @returns {string[][]} Матрица значений; пустой массив, если данных нет.
	 */
	function parseGrid( raw ) {
		var text = String( raw || '' ).replace( /\r\n?/g, '\n' ).replace( /\n+$/, '' );

		if ( ! text.trim() ) {
			return [];
		}

		return text.split( '\n' ).map( function ( line ) {
			return line.split( '\t' );
		} );
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
		var out = [];
		var r;
		var c;
		var row;

		for ( r = 0; r < rows; r++ ) {
			row = [];
			for ( c = 0; c < cols; c++ ) {
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
	 * @param {Object} editor Экземпляр TinyMCE (нужен для экранирования).
	 * @param {string[][]} grid Матрица значений.
	 * @param {boolean} withHeader Первую строку сделать шапкой.
	 * @returns {string}
	 */
	function tableHtml( editor, grid, withHeader ) {
		var cell = function ( tag, value ) {
			return '<' + tag + '>' + ( value ? editor.dom.encode( value ) : '&nbsp;' ) + '</' + tag + '>';
		};
		var head = '';
		var body = '';

		grid.forEach( function ( row, index ) {
			var isHead = withHeader && 0 === index;
			var tag    = isHead ? 'th' : 'td';
			var cells  = row.map( function ( value ) {
				return cell( tag, value );
			} ).join( '' );

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
		var n = parseInt( value, 10 );

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
			onsubmit: function ( e ) {
				var grid = parseGrid( e.data.data );
				// Размеры вставки важнее введённых вручную: автор скопировал
				// готовую таблицу, и подрезать её по дефолтным 3×2 нельзя.
				var cols = grid.length ? Math.max.apply( null, grid.map( function ( row ) {
					return row.length;
				} ) ) : clampSize( e.data.cols, DEFAULT_COLS );
				var rows = grid.length || clampSize( e.data.rows, DEFAULT_ROWS );

				editor.insertContent( tableHtml(
					editor,
					fitGrid( grid, clampSize( cols, DEFAULT_COLS ), clampSize( rows, DEFAULT_ROWS ) ),
					Boolean( e.data.header )
				) );
			},
		} );
	}

	function openCodeDialog( editor ) {
		// Выделенный текст — обычный сценарий: автор вставил код абзацем,
		// выделил и нажал кнопку. Подставляем его в поле как заготовку.
		var selected = editor.selection.getContent( { format: 'text' } );

		editor.windowManager.open( {
			title: 'Вставить код',
			width: 640,
			height: 420,
			body:  [
				{
					type:        'textbox',
					name:        'code',
					multiline:   true,
					minHeight:   260,
					value:       selected || '',
					placeholder: 'Код на Python',
				},
			],
			onsubmit: function ( e ) {
				var code = String( e.data.code || '' ).replace( /\s+$/, '' );

				if ( ! code ) {
					return;
				}

				editor.insertContent(
					'<pre class="' + CODE_CLASS + '">' + editor.dom.encode( code ) + '</pre><p>&nbsp;</p>'
				);
			},
		} );
	}

	function openFormulaDialog( editor ) {
		var selected = editor.selection.getContent( { format: 'text' } );

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
			onsubmit: function ( e ) {
				var formula = String( e.data.formula || '' ).trim();

				if ( ! formula ) {
					return;
				}

				// Обёртка `$$…$$` — то, что разбирает QuickLaTeX. Шорткод
				// `[latexpage]` сюда не пишется: он относится к странице целиком
				// и дописывается один раз при сохранении условия (PHP).
				var wrapped = e.data.inline ? '$' + formula + '$' : '$$' + formula + '$$';

				editor.insertContent( editor.dom.encode( wrapped ) );
			},
		} );
	}

	tinymce.PluginManager.add( 'fs_task_blocks', function ( editor ) {
		editor.addButton( 'fs_table', {
			icon:    'table',
			tooltip: 'Вставить таблицу',
			onclick: function () {
				openTableDialog( editor );
			},
		} );

		editor.addButton( 'fs_code_block', {
			icon:    'code',
			tooltip: 'Вставить код с подсветкой',
			onclick: function () {
				openCodeDialog( editor );
			},
		} );

		editor.addButton( 'fs_formula', {
			text:    '\\(x\\)',
			tooltip: 'Вставить формулу (LaTeX)',
			onclick: function () {
				openFormulaDialog( editor );
			},
		} );
	} );
}() );
