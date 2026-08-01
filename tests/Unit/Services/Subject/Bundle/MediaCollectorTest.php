<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Services\Subject\Bundle\MediaCollector;
use PHPUnit\Framework\TestCase;

/**
 * Поиск вложений в записях пакета: по ключам меты и по ссылкам внутри текста.
 */
class MediaCollectorTest extends TestCase {

	private const string BASE = 'https://source.example/wp-content/uploads';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_fs_test_upload_dir']          = array( 'baseurl' => self::BASE );
		$GLOBALS['_fs_test_post_types']          = array();
		$GLOBALS['_fs_test_url_to_attachment']   = array();
		$GLOBALS['_fs_test_attachment_urls']     = array();
		$GLOBALS['_fs_test_attachment_meta']     = array();
		$GLOBALS['_fs_test_attachment_size_urls'] = array();
	}

	// ── Ключи меты (прежнее поведение) ───────────────────────────────

	public function test_collects_ids_from_attachment_keys(): void {
		$posts = array(
			array( 'meta' => array( 'task_materials' => array( 'attachment_ids' => array( 7, 9 ) ) ) ),
			array( 'meta' => array( 'task_audio' => array( 'attachment_id' => 12 ) ) ),
		);

		$this->assertSame( array( 7, 9, 12 ), ( new MediaCollector() )->collectIds( $posts ) );
	}

	// ── Картинка условия ─────────────────────────────────────────────

	public function test_collects_attachment_behind_inline_image(): void {
		$GLOBALS['_fs_test_post_types'][143] = 'attachment';

		$condition = '<p>Дано:</p><img class="alignnone wp-image-143" '
			. 'src="' . self::BASE . '/2026/07/scheme-300x183.png" alt="">';

		$ids = ( new MediaCollector() )->collectIds( array( array( 'meta' => array( 'task_condition' => $condition ) ) ) );

		$this->assertSame( array( 143 ), $ids );
	}

	public function test_resolves_preview_url_to_original_attachment(): void {
		// Класса wp-image- нет — остаётся только URL превью.
		$GLOBALS['_fs_test_post_types'][143]                                        = 'attachment';
		$GLOBALS['_fs_test_url_to_attachment'][ self::BASE . '/2026/07/scheme.png' ] = 143;

		$condition = '<img src="' . self::BASE . '/2026/07/scheme-300x183.png">';

		$ids = ( new MediaCollector() )->collectIds( array( array( 'meta' => array( 'task_condition' => $condition ) ) ) );

		$this->assertSame( array( 143 ), $ids );
	}

	// ── URL-поля файлов (LinkField) ──────────────────────────────────

	public function test_collects_attachment_behind_link_field(): void {
		$GLOBALS['_fs_test_post_types'][55]                                          = 'attachment';
		$GLOBALS['_fs_test_url_to_attachment'][ self::BASE . '/2026/07/27001_A.txt' ] = 55;

		$posts = array( array( 'meta' => array( 'file_primary' => self::BASE . '/2026/07/27001_A.txt' ) ) );

		$this->assertSame( array( 55 ), ( new MediaCollector() )->collectIds( $posts ) );
	}

	public function test_scans_post_content_too(): void {
		$GLOBALS['_fs_test_post_types'][21] = 'attachment';

		$posts = array( array( 'post_content' => '<img class="wp-image-21" src="' . self::BASE . '/a.png">' ) );

		$this->assertSame( array( 21 ), ( new MediaCollector() )->collectIds( $posts ) );
	}

	// ── Чего собирать не надо ────────────────────────────────────────

	public function test_ignores_foreign_domain_links(): void {
		$posts = array( array( 'meta' => array( 'task_condition' => '<img src="https://other.example/img/pic.png">' ) ) );

		$collector = new MediaCollector();

		$this->assertSame( array(), $collector->collectIds( $posts ) );
		$this->assertSame( array(), $collector->unresolvedUrls() );
	}

	public function test_ignores_number_that_is_not_an_attachment(): void {
		// wp-image-999 приехало из чужого HTML: записи с таким ID либо нет,
		// либо это не вложение — в пакет она попасть не должна.
		$GLOBALS['_fs_test_post_types'][999] = 'inf_ege_tasks';

		$posts = array( array( 'meta' => array( 'task_condition' => '<img class="wp-image-999" src="x">' ) ) );

		$this->assertSame( array(), ( new MediaCollector() )->collectIds( $posts ) );
	}

	public function test_reports_own_uploads_link_without_attachment(): void {
		$posts = array( array( 'meta' => array( 'file_primary' => self::BASE . '/2026/07/deleted.txt' ) ) );

		$collector = new MediaCollector();
		$collector->collectIds( $posts );

		$warnings = $collector->unresolvedUrls();

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'deleted.txt', $warnings[0] );
	}

	// ── Манифест ─────────────────────────────────────────────────────

	public function test_describe_exports_source_id_and_urls(): void {
		$path = tempnam( sys_get_temp_dir(), 'fs-media-' );
		file_put_contents( $path, 'payload' );

		$GLOBALS['_fs_test_attached_files'][143]  = $path;
		$GLOBALS['_fs_test_attachment_urls'][143] = self::BASE . '/2026/07/scheme.png';
		$GLOBALS['_fs_test_attachment_meta'][143] = array( 'sizes' => array( 'medium' => array() ) );

		$GLOBALS['_fs_test_attachment_size_urls'][143] = array(
			'medium' => self::BASE . '/2026/07/scheme-300x183.png',
		);

		$result = ( new MediaCollector() )->describe( array( 143 ) );

		$this->assertSame( 143, $result['manifest'][0]['source_id'] );
		$this->assertSame(
			array(
				'full'   => self::BASE . '/2026/07/scheme.png',
				'medium' => self::BASE . '/2026/07/scheme-300x183.png',
			),
			$result['manifest'][0]['source_urls']
		);

		unlink( $path );
	}
}
