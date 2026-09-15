<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Controllers;

use Inc\Modules\ArticleBlocks\Enums\ArticleBlock;
use Inc\Modules\ArticleBlocks\Services\ArticleBlockRenderer;
use Inc\Services\Subject\Bundle\ShortcodeMediaRefs;

/**
 * Class ArticleBlocksShortcodeController
 *
 * Вывод блоков статьи и их учёт при переносе предмета.
 *
 * Регистрируется всегда — и при выключенном модуле, и без WPBakery: шорткоды уже лежат
 * в контенте опубликованных статей, и выключение редактора не должно превращать их в текст.
 *
 * @package Inc\Modules\ArticleBlocks\Controllers
 */
class ArticleBlocksShortcodeController {

	public function __construct(
		private readonly ArticleBlockRenderer $renderer,
	) {}

	public function register(): void {
		foreach ( ArticleBlock::cases() as $block ) {
			add_shortcode(
				$block->value,
				fn( mixed $atts ): string => $this->renderer->render( $block, $atts )
			);
		}

		// Картинка блока лежит ID в атрибуте — пакет переноса предмета должен её найти и переписать.
		add_filter( ShortcodeMediaRefs::FILTER, array( $this, 'addMediaAttributes' ) );
	}

	/**
	 * @param array<string, string[]> $map Карта «шорткод → атрибуты с ID вложений»
	 *
	 * @return array<string, string[]>
	 */
	public function addMediaAttributes( array $map ): array {
		$map[ ArticleBlock::Image->value ] = array( 'image' );

		return $map;
	}
}
