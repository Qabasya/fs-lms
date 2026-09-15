<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Controllers;

use Inc\Modules\ArticleBlocks\Config\ArticleBlocksConfig;

/**
 * Class ArticleBlocksSettingsController
 *
 * Карточка модуля на панели модулей (`fs_lms_dashboard_modules`) и тумблер
 * (`fs_lms_module_toggle_article_blocks`). Своей секции настроек у модуля нет.
 *
 * @package Inc\Modules\ArticleBlocks\Controllers
 */
class ArticleBlocksSettingsController {

	/** ID модуля на панели: хвост хука тумблера. */
	private const MODULE_ID = 'article_blocks';

	public function __construct(
		private readonly ArticleBlocksConfig $config,
	) {}

	public function register(): void {
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_' . self::MODULE_ID, array( $this, 'onToggle' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$modules[] = array(
			'id'           => self::MODULE_ID,
			'title'        => 'Блоки статей для WPBakery',
			'description'  => 'Элементы «Код», «Таблица», «Изображение» и «Заголовок» в WPBakery и кнопка «Код» в текстовом блоке статей. Выключение убирает только элементы из редактора: уже вставленные блоки на страницах статей продолжают отображаться.',
			'enabled'      => $this->config->isEnabled(),
			'const_locked' => defined( ArticleBlocksConfig::TOGGLE_CONSTANT ),
			'const_key'    => ArticleBlocksConfig::TOGGLE_CONSTANT,
		);

		return $modules;
	}

	public function onToggle( bool $enabled ): void {
		$this->config->save( array( 'enabled' => $enabled ) );
	}
}
