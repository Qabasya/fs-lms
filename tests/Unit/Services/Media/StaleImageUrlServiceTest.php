<?php

declare( strict_types=1 );

namespace Unit\Services\Media;

use Inc\Services\Media\StaleImageUrlService;
use PHPUnit\Framework\TestCase;

/**
 * Оптимизатор заменил `…-scaled.png` на `.avif`, а условие задания хранит старый адрес: при выводе он заменяется на существующий файл.
 */
class StaleImageUrlServiceTest extends TestCase {

	private string $dir;
	private StaleImageUrlService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/fs-lms-img-' . uniqid();
		mkdir( $this->dir . '/2026/10', 0777, true );
		$GLOBALS['_fs_test_upload_dir']       = array( 'baseurl' => 'https://site.test/wp-content/uploads', 'basedir' => $this->dir );
		$GLOBALS['_fs_test_attachment_meta']  = array();
		$GLOBALS['_fs_test_attachment_urls']  = array();
		$this->service                        = new StaleImageUrlService();
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/2026/10/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir . '/2026/10' );
		rmdir( $this->dir . '/2026' );
		rmdir( $this->dir );
		unset( $GLOBALS['_fs_test_upload_dir'], $GLOBALS['_fs_test_attachment_meta'], $GLOBALS['_fs_test_attachment_urls'] );
		parent::tearDown();
	}

	private function touch( string $name ): void {
		file_put_contents( $this->dir . '/2026/10/' . $name, 'x' );
	}

	private function img( string $name, string $extra = '' ): string {
		return '<p><img class="alignnone wp-image-7 size-full"' . $extra . ' src="https://site.test/wp-content/uploads/2026/10/' . $name . '" alt="" width="2560" height="854" /></p>';
	}

	public function test_existing_file_is_untouched(): void {
		$this->touch( 'a-scaled.png' );
		$html = $this->img( 'a-scaled.png' );

		self::assertSame( $html, $this->service->repair( $html ) );
	}

	public function test_missing_png_gets_the_attachment_file(): void {
		$this->touch( 'a-scaled.avif' );
		$GLOBALS['_fs_test_attachment_meta'][7] = array( 'file' => '2026/10/a-scaled.avif', 'sizes' => array() );

		$out = $this->service->repair( $this->img( 'a-scaled.png' ) );

		self::assertStringContainsString( 'src="https://site.test/wp-content/uploads/2026/10/a-scaled.avif"', $out );
		self::assertStringContainsString( 'width="2560"', $out );
	}

	public function test_sized_copy_maps_to_the_same_size_of_the_attachment(): void {
		$this->touch( 'a-300x100.avif' );
		$this->touch( 'a-scaled.avif' );
		$GLOBALS['_fs_test_attachment_meta'][7] = array(
			'file'  => '2026/10/a-scaled.avif',
			'sizes' => array( 'medium' => array( 'file' => 'a-300x100.avif', 'width' => 300, 'height' => 100 ) ),
		);

		$out = $this->service->repair( $this->img( 'a-300x100.png' ) );

		self::assertStringContainsString( 'uploads/2026/10/a-300x100.avif', $out );
	}

	public function test_without_attachment_class_the_neighbour_avif_is_used(): void {
		$this->touch( 'b.avif' );
		$html = '<img src="https://site.test/wp-content/uploads/2026/10/b.png" alt="">';

		self::assertStringContainsString( 'uploads/2026/10/b.avif', $this->service->repair( $html ) );
	}

	public function test_scaled_falls_back_to_the_kept_original(): void {
		$this->touch( 'c.png' );
		$html = '<img src="https://site.test/wp-content/uploads/2026/10/c-scaled.png" alt="">';

		self::assertStringContainsString( 'uploads/2026/10/c.png', $this->service->repair( $html ) );
	}

	public function test_nothing_found_and_foreign_images_stay_as_is(): void {
		$missing = '<img src="https://site.test/wp-content/uploads/2026/10/gone.png" alt="">';
		$foreign = '<img src="https://other.test/x.png" alt="">';

		self::assertSame( $missing, $this->service->repair( $missing ) );
		self::assertSame( $foreign, $this->service->repair( $foreign ) );
	}

	public function test_stale_srcset_and_sizes_are_dropped_with_the_fix(): void {
		$this->touch( 'd.avif' );
		$html = '<img src="https://site.test/wp-content/uploads/2026/10/d.png" srcset="https://site.test/wp-content/uploads/2026/10/d-300x100.png 300w" sizes="(max-width: 300px) 100vw" alt="">';

		$out = $this->service->repair( $html );

		self::assertStringNotContainsString( 'srcset', $out );
		self::assertStringNotContainsString( 'sizes', $out );
	}

	public function test_cyrillic_names_are_decoded_and_encoded_back(): void {
		$this->touch( 'Задание-1-scaled.avif' );
		$html = '<img src="https://site.test/wp-content/uploads/2026/10/%D0%97%D0%B0%D0%B4%D0%B0%D0%BD%D0%B8%D0%B5-1-scaled.png" alt="">';

		self::assertStringContainsString( '%D0%97%D0%B0%D0%B4%D0%B0%D0%BD%D0%B8%D0%B5-1-scaled.avif', $this->service->repair( $html ) );
	}
}
