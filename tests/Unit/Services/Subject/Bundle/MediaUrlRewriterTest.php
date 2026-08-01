<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Services\Subject\Bundle\MediaIdMap;
use Inc\Services\Subject\Bundle\MediaUrlRewriter;
use PHPUnit\Framework\TestCase;

/**
 * Подмена ссылок на медиа при импорте: URL и wp-image-{id} источника → свои.
 */
class MediaUrlRewriterTest extends TestCase {

	private const string SRC = 'https://source.example/wp-content/uploads';
	private const string DST = 'https://target.example/wp-content/uploads';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_fs_test_attachment_urls']      = array( 700 => self::DST . '/2026/08/scheme.png' );
		$GLOBALS['_fs_test_attachment_size_urls'] = array(
			700 => array( 'medium' => self::DST . '/2026/08/scheme-300x183.png' ),
		);
	}

	/**
	 * Раздел media[] одного вложения, залитого как ID 700.
	 *
	 * @return array{0: array, 1: MediaIdMap}
	 */
	private function bundle(): array {
		$map = new MediaIdMap();
		$map->bind( MediaIdMap::exportId( 143 ), 700 );

		$media = array(
			array(
				'export_id'   => MediaIdMap::exportId( 143 ),
				'source_id'   => 143,
				'source_urls' => array(
					'full'   => self::SRC . '/2026/07/scheme.png',
					'medium' => self::SRC . '/2026/07/scheme-300x183.png',
				),
			),
		);

		return array( $media, $map );
	}

	public function test_rewrites_inline_image_in_condition(): void {
		[ $media, $map ] = $this->bundle();

		$rewriter = new MediaUrlRewriter();

		$post = $rewriter->rewritePost(
			array(
				'meta' => array(
					'task_condition' => '<img class="alignnone wp-image-143" src="' . self::SRC . '/2026/07/scheme-300x183.png">',
				),
			),
			$rewriter->buildMap( $media, $map )
		);

		$this->assertSame(
			'<img class="alignnone wp-image-700" src="' . self::DST . '/2026/08/scheme-300x183.png">',
			$post['meta']['task_condition']
		);
	}

	public function test_rewrites_link_field_and_post_content(): void {
		[ $media, $map ] = $this->bundle();

		$rewriter = new MediaUrlRewriter();

		$post = $rewriter->rewritePost(
			array(
				'meta'         => array( 'file_primary' => self::SRC . '/2026/07/scheme.png' ),
				'post_content' => 'см. ' . self::SRC . '/2026/07/scheme.png',
			),
			$rewriter->buildMap( $media, $map )
		);

		$this->assertSame( self::DST . '/2026/08/scheme.png', $post['meta']['file_primary'] );
		$this->assertSame( 'см. ' . self::DST . '/2026/08/scheme.png', $post['post_content'] );
	}

	public function test_rewrites_nested_meta_structures(): void {
		[ $media, $map ] = $this->bundle();

		$rewriter = new MediaUrlRewriter();

		$post = $rewriter->rewritePost(
			array( 'meta' => array( 'steps' => array( array( 'payload' => array( 'html' => '<img src="' . self::SRC . '/2026/07/scheme.png">' ) ) ) ) ),
			$rewriter->buildMap( $media, $map )
		);

		$this->assertSame(
			'<img src="' . self::DST . '/2026/08/scheme.png">',
			$post['meta']['steps'][0]['payload']['html']
		);
	}

	public function test_falls_back_to_full_url_when_size_missing(): void {
		// На целевом сайте нет размера medium — ссылка ведёт на оригинал,
		// картинка крупнее ожидаемой лучше битой.
		$GLOBALS['_fs_test_attachment_size_urls'] = array();

		[ $media, $map ] = $this->bundle();

		$rewriter = new MediaUrlRewriter();

		$post = $rewriter->rewritePost(
			array( 'meta' => array( 'task_condition' => '<img src="' . self::SRC . '/2026/07/scheme-300x183.png">' ) ),
			$rewriter->buildMap( $media, $map )
		);

		$this->assertSame(
			'<img src="' . self::DST . '/2026/08/scheme.png">',
			$post['meta']['task_condition']
		);
	}

	public function test_leaves_text_untouched_when_file_not_in_package(): void {
		// Вложение не залилось (битый файл в архиве) — ссылку не трогаем:
		// текст условия важнее, чем формально чистая ссылка.
		$rewriter = new MediaUrlRewriter();
		$original = '<img src="' . self::SRC . '/2026/07/scheme.png">';

		$post = $rewriter->rewritePost(
			array( 'meta' => array( 'task_condition' => $original ) ),
			$rewriter->buildMap( $this->bundle()[0], new MediaIdMap() )
		);

		$this->assertSame( $original, $post['meta']['task_condition'] );
	}

	public function test_old_package_without_source_urls_is_harmless(): void {
		// Пакет формата 1.0.0: source_id/source_urls нет — замен просто нет.
		$map = new MediaIdMap();
		$map->bind( MediaIdMap::exportId( 143 ), 700 );

		$rewriter = new MediaUrlRewriter();

		$this->assertSame(
			array(),
			$rewriter->buildMap( array( array( 'export_id' => MediaIdMap::exportId( 143 ) ) ), $map )
		);
	}
}
