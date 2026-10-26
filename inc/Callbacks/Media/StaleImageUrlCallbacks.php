<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Media;

use Inc\Services\Media\StaleImageUrlService;

/**
 * Class StaleImageUrlCallbacks
 *
 * Фильтр `the_content`: адреса картинок с пропавшим файлом заменяются на существующие
 * ({@see StaleImageUrlService}). `the_content` проходят условия заданий, подсказки, статьи, лекции и разборы.
 *
 * @package Inc\Callbacks\Media
 */
class StaleImageUrlCallbacks {

	/**
	 * @param StaleImageUrlService $images Починка адресов картинок
	 */
	public function __construct(
		private readonly StaleImageUrlService $images,
	) {}

	/**
	 * @param mixed $content Содержимое, проходящее `the_content`
	 *
	 * @return mixed То же содержимое с исправленными адресами картинок
	 */
	public function filterContent( mixed $content ): mixed {
		return is_string( $content ) ? $this->images->repair( $content ) : $content;
	}
}
