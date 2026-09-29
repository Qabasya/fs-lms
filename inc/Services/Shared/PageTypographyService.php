<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

/**
 * Class PageTypographyService
 *
 * Типографика всей публичной страницы: HTML, который уходит браузеру, проходит
 * через {@see NbspTypographer} — предлоги и союзы не висят в конце строки ни в
 * контенте темы (главная, курсы, контакты), ни на страницах плагина.
 *
 * Только обычные страницы фронта: админка, AJAX, REST, RSS, robots, embed и
 * ответы не-HTML (XML-карты сайта, JSON) не трогаются. Ошибка обработки не
 * должна стоить страницы — при пустом результате уходит исходный HTML.
 *
 * @package Inc\Services\Shared
 */
class PageTypographyService {

	public function __construct(
		private readonly NbspTypographer $typographer,
	) {}

	/** Хук `template_redirect`: буфер на весь вывод шаблона. */
	public function start(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_embed() || is_trackback() ) {
			return;
		}

		ob_start( array( $this, 'filter' ) );
	}

	/**
	 * Колбэк буфера: обработанная страница.
	 *
	 * @param string $html Вывод страницы.
	 */
	public function filter( string $html ): string {
		if ( ! $this->isHtmlPage( $html ) ) {
			return $html;
		}

		$result = $this->typographer->html( $html );

		return '' !== $result ? $result : $html;
	}

	/** Ответ — HTML-страница, а не XML/JSON с тем же хуком. */
	private function isHtmlPage( string $html ): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}

		return false !== stripos( substr( $html, 0, 1024 ), '<html' );
	}
}
