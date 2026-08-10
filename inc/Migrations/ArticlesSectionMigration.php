<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Settings\OptionName;
use Inc\Enums\Wp\ShortCode;
use Inc\Enums\Wp\SubjectPageType;
use Inc\Shared\PluginLogger;

/**
 * Class ArticlesSectionMigration
 *
 * Одноразовая data-миграция: раздел «Учебник» переехал с адреса
 * `/{key}/textbook/` на `/{key}/articles/` (значение кейса
 * {@see SubjectPageType::Articles}).
 *
 * ### Что чинит
 *
 * Значение кейса — это одновременно слаг страницы, ключ в опции
 * `fs_lms_subject_pages` и часть тега шорткода. Без миграции на живой
 * установке рвётся всё сразу: `SubjectPagesRepository::getId()` не находит
 * страницу по новому ключу (ссылки «Учебник» пропадают из крошек, сайдбара и
 * навигации статьи), `SubjectPagesService::ensureForSubject()` не находит её и
 * по пути — и заводит вторую страницу, а на старой остаётся шорткод
 * `[fs_lms_subject_textbook]`, которого больше не существует.
 *
 * ### Почему отдельный класс, а не Migration_1_0_0
 *
 * Та выполняется только при (ре)активации плагина и на установках с уже
 * проставленной `fs_lms_schema_version` не запускается вовсе — а переехать
 * обязаны именно они. Паттерн — {@see BroadcastStepMigration}: гейт
 * собственной опцией, вызов из {@see \Inc\Init::run()} на обычной загрузке.
 *
 * Идемпотентна: после переноса ключа `textbook` в опции нет, страниц со
 * старым слагом не остаётся, повторный проход ничего не находит.
 *
 * @package Inc\Migrations
 */
class ArticlesSectionMigration {

	/** Опция-гейт (значение = версия выполненной миграции). */
	private const VERSION_OPTION = 'fs_lms_articles_section_migration';

	/** Версия миграции. */
	private const VERSION = '1';

	/** Прежнее значение кейса — ключ опции и слаг страницы. */
	private const LEGACY_SECTION = 'textbook';

	/** Прежний тег шорткода раздела. */
	private const LEGACY_SHORTCODE = 'fs_lms_subject_textbook';

	/**
	 * Выполняет миграцию один раз. Вызывать на обычной загрузке.
	 *
	 * @return void
	 */
	public function ensure(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		$page_ids = $this->migratePageIndex();

		foreach ( $page_ids as $page_id ) {
			$this->migratePage( $page_id );
		}

		if ( ! empty( $page_ids ) ) {
			PluginLogger::warning(
				'ArticlesSectionMigration',
				'Раздел учебника переведён на адрес /articles/',
				array( 'pages' => $page_ids )
			);

			// Слаг раздела сменился — без сброса правил старые адреса статей
			// остаются в кеше rewrite, а новые не резолвятся.
			$this->scheduleRewriteFlush();
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Переименовывает ключ раздела в опции `fs_lms_subject_pages`.
	 *
	 * @return int[] ID перенесённых страниц.
	 */
	private function migratePageIndex(): array {
		$pages = get_option( OptionName::SubjectPages->value, array() );

		if ( ! is_array( $pages ) ) {
			return array();
		}

		$section  = SubjectPageType::Articles->value;
		$page_ids = array();
		$changed  = false;

		foreach ( $pages as $subject_key => $page_map ) {
			if ( ! is_array( $page_map ) || ! isset( $page_map[ self::LEGACY_SECTION ] ) ) {
				continue;
			}

			$legacy_id = (int) $page_map[ self::LEGACY_SECTION ];
			unset( $pages[ $subject_key ][ self::LEGACY_SECTION ] );
			$changed = true;

			// Новый ключ уже есть (раздел успели пересоздать) — старую запись
			// просто забываем: перетирать живую страницу нельзя.
			if ( isset( $page_map[ $section ] ) ) {
				continue;
			}

			$pages[ $subject_key ][ $section ] = $legacy_id;

			if ( $legacy_id > 0 ) {
				$page_ids[] = $legacy_id;
			}
		}

		if ( $changed ) {
			update_option( OptionName::SubjectPages->value, $pages );
		}

		return $page_ids;
	}

	/**
	 * Приводит саму страницу к новому разделу: слаг и тег шорткода.
	 *
	 * Слаг трогаем, только если он прежний: страницу могли переименовать
	 * вручную, и её адрес уже разошёлся по ссылкам.
	 *
	 * @param int $page_id ID страницы раздела.
	 *
	 * @return void
	 */
	private function migratePage( int $page_id ): void {
		global $wpdb;

		$page = $wpdb->get_row(
			$wpdb->prepare( "SELECT post_name, post_content FROM {$wpdb->posts} WHERE ID = %d", $page_id )
		);

		if ( null === $page ) {
			return;
		}

		$update = array();

		if ( self::LEGACY_SECTION === $page->post_name ) {
			$update['post_name'] = SubjectPageType::Articles->value;
		}

		if ( str_contains( (string) $page->post_content, self::LEGACY_SHORTCODE ) ) {
			$update['post_content'] = str_replace(
				self::LEGACY_SHORTCODE,
				ShortCode::SubjectArticles->value,
				(string) $page->post_content
			);
		}

		if ( empty( $update ) ) {
			return;
		}

		$wpdb->update( $wpdb->posts, $update, array( 'ID' => $page_id ) );
		clean_post_cache( $page_id );
	}

	/**
	 * Сбрасывает правила перезаписи в конце запроса.
	 *
	 * Прямой вызов здесь бесполезен: миграция идёт из `Init::run()`, где CPT
	 * предметов ещё не зарегистрированы, и правила пересобрались бы без их
	 * адресов.
	 *
	 * @return void
	 */
	private function scheduleRewriteFlush(): void {
		add_action( 'wp_loaded', static fn() => flush_rewrite_rules( false ), 99 );
	}
}