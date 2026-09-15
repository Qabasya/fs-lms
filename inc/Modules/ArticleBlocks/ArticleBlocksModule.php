<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\ArticleBlocks\Config\ArticleBlocksConfig;
use Inc\Modules\ArticleBlocks\Controllers\ArticleBlocksEditorController;
use Inc\Modules\ArticleBlocks\Controllers\ArticleBlocksSettingsController;
use Inc\Modules\ArticleBlocks\Controllers\ArticleBlocksShortcodeController;

/**
 * Class ArticleBlocksModule
 *
 * Опциональный модуль — блоки статьи «Код», «Таблица», «Изображение», «Заголовок» и «Задание» для WPBakery.
 *
 * Блоки — шорткоды с семантической разметкой, которую страница статьи (`ArticleContentService`)
 * уже умеет оформлять: ядро о модуле не знает. С ядром модуль связан двумя швами:
 * фильтром переноса предмета `fs_lms_bundle_shortcode_media_attrs` (картинка блока) и панелью
 * модулей (`fs_lms_dashboard_modules`).
 *
 * Выключение устроено в две части: шорткоды — данные статей и регистрируются всегда;
 * редактор (элементы WPBakery, кнопка «Код») — только при включённом модуле.
 * Уровни — см. {@see ArticleBlocksConfig}.
 *
 * @package Inc\Modules\ArticleBlocks
 */
class ArticleBlocksModule implements ServiceInterface {

	public function __construct(
		private readonly ArticleBlocksShortcodeController $shortcodes,
		private readonly ArticleBlocksSettingsController  $settings,
		private readonly ArticleBlocksEditorController    $editor,
		private readonly ArticleBlocksConfig              $config,
	) {}

	public function register(): void {
		$this->shortcodes->register();
		$this->settings->register();

		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$this->editor->register();
	}
}
