<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskEditorButtonsController
 *
 * Кнопки «Таблица», «Код» и «Формула» на панели TinyMCE в редакторе задания.
 *
 * @package Inc\Controllers\Task
 *
 * Плагин панели — самостоятельный файл (`assets/js/task-editor-buttons.min.js`,
 * исходник `src/js/tinymce/task-editor-buttons.js`), а не модуль admin-бандла:
 * TinyMCE грузит внешние плагины сам, по URL из `mce_external_plugins`.
 *
 * Кнопки вешаются только на экраны заданий — публичные `{key}_tasks` и общий
 * банк `fs_lms_problems`. На статьях и страницах панель остаётся прежней: у
 * статей свой набор блоков (модуль ArticleBlocks).
 */
class TaskEditorButtonsController extends BaseController implements ServiceInterface {

	/** Идентификатор плагина — совпадает с именем в `tinymce.PluginManager.add()`. */
	private const PLUGIN = 'fs_task_blocks';

	/** Собранный файл плагина (билд-выход gulp). */
	private const SCRIPT = 'assets/js/task-editor-buttons.min.js';

	/** Кнопки в порядке их появления на панели. */
	private const BUTTONS = array( 'fs_table', 'fs_code_block', 'fs_formula' );

	public function register(): void {
		add_filter( 'mce_external_plugins', array( $this, 'registerPlugin' ) );
		add_filter( 'mce_buttons', array( $this, 'addButtons' ) );
		add_filter( 'tiny_mce_before_init', array( $this, 'allowCodeBlockClass' ) );
	}

	/**
	 * Подключает плагин панели на экранах заданий.
	 *
	 * @param array<string, string> $plugins Внешние плагины TinyMCE
	 *
	 * @return array<string, string>
	 */
	public function registerPlugin( array $plugins ): array {
		if ( ! $this->isTaskScreen() ) {
			return $plugins;
		}

		$path = $this->path( self::SCRIPT );
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version;

		$plugins[ self::PLUGIN ] = add_query_arg( 'ver', $ver, $this->url( self::SCRIPT ) );

		return $plugins;
	}

	/**
	 * Добавляет кнопки после «Курсива» — рядом с остальным форматированием.
	 *
	 * @param string[] $buttons Кнопки первой строки панели
	 *
	 * @return string[]
	 */
	public function addButtons( array $buttons ): array {
		if ( ! $this->isTaskScreen() ) {
			return $buttons;
		}

		$missing = array_values( array_diff( self::BUTTONS, $buttons ) );

		if ( array() === $missing ) {
			return $buttons;
		}

		$position = array_search( 'italic', $buttons, true );

		if ( false === $position ) {
			return array_merge( $buttons, $missing );
		}

		array_splice( $buttons, (int) $position + 1, 0, $missing );

		return $buttons;
	}

	/**
	 * Разрешает TinyMCE хранить класс у `<pre>`.
	 *
	 * Без этого редактор вычищает `class="fs-code-highlight"` при переключении
	 * вкладок «Визуально» / «Текст», и блок кода на фронте остаётся неподсвеченным
	 * (подсветку вешает `frontend/components/code-block.js` именно по этому классу).
	 *
	 * @param array<string, mixed> $settings Настройки инициализации TinyMCE
	 *
	 * @return array<string, mixed>
	 */
	public function allowCodeBlockClass( array $settings ): array {
		if ( ! $this->isTaskScreen() ) {
			return $settings;
		}

		$existing = isset( $settings['extended_valid_elements'] ) ? (string) $settings['extended_valid_elements'] : '';
		$rule     = 'pre[class|id|style],table[class|id|style]';

		$settings['extended_valid_elements'] = '' === $existing ? $rule : $existing . ',' . $rule;

		return $settings;
	}

	/**
	 * Экран редактирования задания: публичная задача предмета или запись общего
	 * банка. `get_current_screen()` доступен не всегда (ранние хуки, REST) —
	 * в этом случае кнопок не добавляем.
	 */
	private function isTaskScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return false;
		}

		$postType = (string) $screen->post_type;

		return PostTypeResolver::isTaskPostType( $postType ) || PostTypeResolver::problems() === $postType;
	}
}
