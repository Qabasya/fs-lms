<?php

declare( strict_types=1 );

namespace Inc\Shared;

/**
 * Class SafeHtml
 *
 * Статическая обёртка над `wp_kses_post()` — единственная точка вызова во всём
 * плагине (сохранение условий/подсказок задания, вывод в шаблонах и API-сервисах).
 *
 * `wp_kses_post()` не входит в список разрешённых протоколов `data:` и у
 * `<img src="data:image/png;base64,...">` вырезает ровно схему `data:`, оставляя
 * `src="image/png;base64,..."` — браузер трактует это как относительный путь и
 * шлёт 404/403 вместо картинки (вставка изображения через буфер обмена в TinyMCE).
 * Метод возвращает префикс обратно для уже отфильтрованных `<img>` с безопасными
 * mime-типами картинок; остальные протоколы (например, `data:` в `href`)
 * по-прежнему запрещены — регулярка трогает только `img src`.
 *
 * @package Inc\Shared
 */
class SafeHtml {

	/**
	 * @param string $html Сырой HTML (условие/подсказка задания, инструкция, консент и т.п.)
	 *
	 * @return string Отфильтрованный `wp_kses_post()` HTML с восстановленными data-URI картинками
	 */
	public static function post( string $html ): string {
		return self::restoreDataImageUris( wp_kses_post( $html ) );
	}

	private static function restoreDataImageUris( string $html ): string {
		return (string) preg_replace(
			'/(<img\b[^>]*\ssrc=["\'])(image\/(?:png|jpe?g|gif|webp);base64,)/i',
			'$1data:$2',
			$html
		);
	}
}
