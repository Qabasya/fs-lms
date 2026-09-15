<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Config;

use Inc\Modules\Shared\ModuleConfig;

/**
 * Class ArticleBlocksConfig
 *
 * Конфигурация модуля «Блоки статей». Модуль владеет СВОЕЙ опцией `fs_lms_article_blocks` —
 * ядро о ней не знает.
 *
 * Тумблер выключает только редактор (элементы WPBakery и кнопку «Код»). Шорткоды блоков
 * отрисовываются всегда: это данные опубликованных статей.
 *
 * Уровни выключения:
 *  1) тумблер на панели модулей (опция `fs_lms_article_blocks.enabled`, по умолчанию включён —
 *     настраивать модулю нечего);
 *  2) константа `FS_LMS_ARTICLE_BLOCKS` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/ArticleBlocks/` + строки в `Init::getServices()`
 *     (статьи с блоками модуля после этого покажут шорткоды текстом).
 *
 * @package Inc\Modules\ArticleBlocks\Config
 */
class ArticleBlocksConfig extends ModuleConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_article_blocks';

	/** Константа wp-config, перекрывающая тумблер. */
	public const TOGGLE_CONSTANT = 'FS_LMS_ARTICLE_BLOCKS';

	private const DEFAULTS = array(
		'enabled' => true,
	);

	protected function option(): string {
		return self::OPTION;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return self::DEFAULTS;
	}

	protected function toggleConstant(): ?string {
		return self::TOGGLE_CONSTANT;
	}
}
