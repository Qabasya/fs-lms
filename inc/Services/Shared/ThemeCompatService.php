<?php

declare(strict_types=1);

namespace Inc\Services\Shared;

/**
 * Class ThemeCompatService
 *
 * Совместимость с классическими и блочными (FSE) темами WordPress.
 *
 * Блочные темы не имеют header.php / footer.php, поэтому get_header() /
 * get_footer() выдают Deprecated-предупреждение. Этот класс определяет тип
 * темы и вызывает нужный API: для блочных — block_template_part() + ручной
 * HTML-скелет, для классических — стандартные get_header() / get_footer().
 */
class ThemeCompatService {

	/**
	 * Выводит открывающий HTML-скелет и шапку сайта.
	 */
	public static function header(): void {
		if ( self::isBlockTheme() ) {
			// Шапка рендерится в буфер ДО вывода <head>: блоки на рендере
			// регистрируют свои script-модули, а WP при блочной теме печатает
			// import map в wp_head (см. WP_Script_Modules::add_hooks). Отрендерить
			// шапку после — значит выпустить, например, модуль блока навигации без
			// записи в import map, и браузер падает на «@wordpress/interactivity».
			$header = self::renderTemplatePart( 'header' );
			self::openHtmlSkeleton();
			echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			get_header();
		}
	}

	/**
	 * Рендерит часть блочного шаблона в строку.
	 *
	 * @param string $part Имя части шаблона (`header`, `footer`).
	 */
	private static function renderTemplatePart( string $part ): string {
		ob_start();
		block_template_part( $part );

		return (string) ob_get_clean();
	}

	/**
	 * Выводит подвал сайта и закрывающий HTML-скелет.
	 */
	public static function footer(): void {
		if ( self::isBlockTheme() ) {
			block_template_part( 'footer' );
			wp_footer();
			echo '</body></html>';
		} else {
			get_footer();
		}
	}

	/**
	 * Возвращает true если активная тема является блочной (FSE).
	 */
	private static function isBlockTheme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme()
			&& function_exists( 'block_template_part' );
	}

	/**
	 * Выводит DOCTYPE, <head> и открывающий <body> для блочных тем.
	 */
	private static function openHtmlSkeleton(): void {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<?php wp_head(); ?>
		</head>
		<body <?php body_class(); ?>>
		<?php
		if ( function_exists( 'wp_body_open' ) ) {
			wp_body_open();
		}
	}
}
