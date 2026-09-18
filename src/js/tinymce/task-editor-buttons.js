/**
 * @fileoverview Плагин панели TinyMCE в редакторе задания: регистрирует в
 * `tinymce.PluginManager` кнопки таблицы, блока кода и формулы.
 *
 * Это НЕ модуль admin-бандла: файл собирается отдельной точкой входа и
 * подключается самим редактором как внешний плагин (`mce_external_plugins`),
 * то есть исполняется внутри TinyMCE, а не на странице. Отсюда и стиль —
 * самоисполняемая регистрация, без экспортов.
 *
 * Сами кнопки и их диалоги — в `editor-blocks.js`: та же тройка стоит в
 * редакторе шага «Лекция», где TinyMCE поднимает `wp.editor.initialize()` и
 * внешние плагины не подключаются.
 *
 * Делимитеры формулы здесь квиклатексные (`$…$` / `$$…$$`): условие задания
 * разбирает QuickLaTeX.
 */

/* global tinymce */

import { registerBlockButtons } from './editor-blocks.js';

( function () {
	'use strict';

	if ( 'undefined' === typeof tinymce ) {
		return;
	}

	tinymce.PluginManager.add( 'fs_task_blocks', function ( editor ) {
		registerBlockButtons( editor, { latex: 'quicklatex' } );
	} );
}() );
