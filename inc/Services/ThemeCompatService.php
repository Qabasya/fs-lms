<?php

declare(strict_types=1);

namespace Inc\Services;

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
			self::openHtmlSkeleton();
			block_template_part( 'header' );
		} else {
			get_header();
		}
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
		// block_template_part('header') вызывается ПОСЛЕ wp_head(), поэтому блоки,
		// использующие Interactivity API, регистрируют модуль слишком поздно —
		// importmap уже выведен. Принудительно ставим в очередь до wp_head().
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( '@wordpress/interactivity' );
		}
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
