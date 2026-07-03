<?php

declare( strict_types=1 );

namespace Unit\Managers\Wp;

use Inc\Managers\Wp\MediaManager;
use PHPUnit\Framework\TestCase;

/**
 * T13.2: лимит 20 МБ + расширенный whitelist (webp/heic/pptx/py). Покрывает
 * только валидацию до `media_handle_upload()` — реальная WP-загрузка требует
 * ABSPATH/медиа-стабов, которых у тестового окружения нет.
 */
class MediaManagerTest extends TestCase {

	private MediaManager $manager;
	/** @var list<string> */
	private array $tmpFiles = array();

	protected function setUp(): void {
		parent::setUp();
		$this->manager = new MediaManager();
	}

	protected function tearDown(): void {
		foreach ( $this->tmpFiles as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tmpFiles = array();
		$_FILES         = array();
		parent::tearDown();
	}

	private function tmpFileWithContent( string $content ): string {
		$path             = tempnam( sys_get_temp_dir(), 'fslms_media_' );
		file_put_contents( $path, $content );
		$this->tmpFiles[] = $path;
		return $path;
	}

	public function test_throws_when_file_key_missing_from_request(): void {
		$_FILES = array();

		$this->expectException( \RuntimeException::class );
		$this->manager->uploadFromRequest( 'answer_file' );
	}

	public function test_throws_when_upload_error_present(): void {
		$_FILES['answer_file'] = array(
			'error'    => UPLOAD_ERR_INI_SIZE,
			'tmp_name' => '',
			'size'     => 0,
		);

		$this->expectException( \RuntimeException::class );
		$this->manager->uploadFromRequest( 'answer_file' );
	}

	public function test_throws_when_file_exceeds_20mb_limit(): void {
		$path = $this->tmpFileWithContent( 'x' );
		$_FILES['answer_file'] = array(
			'error'    => UPLOAD_ERR_OK,
			'tmp_name' => $path,
			'size'     => 21 * 1024 * 1024,
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '20 МБ' );
		$this->manager->uploadFromRequest( 'answer_file' );
	}

	public function test_throws_when_mime_type_not_in_whitelist(): void {
		// ZIP-сигнатура — не входит ни в один разрешённый тип.
		$path = $this->tmpFileWithContent( "PK\x03\x04" . str_repeat( "\0", 20 ) );
		$_FILES['answer_file'] = array(
			'error'    => UPLOAD_ERR_OK,
			'tmp_name' => $path,
			'size'     => 24,
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Недопустимый тип файла.' );
		$this->manager->uploadFromRequest( 'answer_file' );
	}
}
