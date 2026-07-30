<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Services\Subject\Bundle\BundleArchive;
use Inc\Services\Subject\Bundle\BundleSchema;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Безопасность и целостность распаковки пакета.
 *
 * Архив — файл, загруженный пользователем, поэтому здесь проверяется именно
 * враждебный вход: выход за пределы каталога, посторонние файлы, подмена
 * содержимого и битый манифест.
 */
#[RequiresPhpExtension( 'zip' )]
class BundleArchiveTest extends TestCase {

	/** @var string[] Каталоги, созданные тестом */
	private array $tempDirs = array();

	protected function tearDown(): void {
		foreach ( $this->tempDirs as $dir ) {
			$this->removeDir( $dir );
		}
		$this->tempDirs = array();

		parent::tearDown();
	}

	public function test_reads_manifest_from_valid_archive(): void {
		$archive = $this->makeArchive( array(
			BundleSchema::MANIFEST => (string) json_encode( array( 'schema_version' => '1.0.0', 'subject' => array( 'key' => 'math' ) ) ),
			'media/7__photo.jpg'   => 'binary-content',
		) );

		$manifest = ( new BundleArchive() )->read( $archive, $this->makeDir() );

		self::assertSame( '1.0.0', $manifest['schema_version'] );
		self::assertSame( 'math', $manifest['subject']['key'] );
	}

	public function test_rejects_path_traversal_entry(): void {
		$archive = $this->makeArchive( array(
			BundleSchema::MANIFEST      => '{}',
			'media/../../../evil.php'   => '<?php echo 1;',
		) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/небезопасный путь/u' );

		( new BundleArchive() )->read( $archive, $this->makeDir() );
	}

	public function test_rejects_absolute_path_entry(): void {
		$archive = $this->makeArchive( array(
			BundleSchema::MANIFEST => '{}',
			'/etc/passwd'          => 'root:x:0:0',
		) );

		$this->expectException( RuntimeException::class );

		( new BundleArchive() )->read( $archive, $this->makeDir() );
	}

	public function test_rejects_files_outside_manifest_and_media(): void {
		$archive = $this->makeArchive( array(
			BundleSchema::MANIFEST => '{}',
			'shell.php'            => '<?php echo 1;',
		) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/посторонний файл/u' );

		( new BundleArchive() )->read( $archive, $this->makeDir() );
	}

	public function test_rejects_archive_without_manifest(): void {
		$archive = $this->makeArchive( array( 'media/1__a.jpg' => 'x' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/нет файла/u' );

		( new BundleArchive() )->read( $archive, $this->makeDir() );
	}

	public function test_rejects_corrupted_manifest_json(): void {
		$archive = $this->makeArchive( array( BundleSchema::MANIFEST => '{ this is not json' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/повреждён/u' );

		( new BundleArchive() )->read( $archive, $this->makeDir() );
	}

	public function test_rejects_non_zip_file(): void {
		$dir  = $this->makeDir();
		$fake = $dir . DIRECTORY_SEPARATOR . 'not-a-zip.zip';
		file_put_contents( $fake, 'просто текст' );

		$this->expectException( RuntimeException::class );

		( new BundleArchive() )->read( $fake, $this->makeDir() );
	}

	public function test_checksum_mismatch_stops_import(): void {
		$dir = $this->makeDir();
		mkdir( $dir . DIRECTORY_SEPARATOR . 'media' );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . '7__photo.jpg', 'подменённое содержимое' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/контрольная сумма/u' );

		( new BundleArchive() )->verifyChecksums(
			array( array( 'file' => 'media/7__photo.jpg', 'sha256' => hash( 'sha256', 'оригинал' ) ) ),
			$dir
		);
	}

	public function test_checksum_passes_for_intact_file(): void {
		$dir     = $this->makeDir();
		$content = 'оригинал';
		mkdir( $dir . DIRECTORY_SEPARATOR . 'media' );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . '7__photo.jpg', $content );

		( new BundleArchive() )->verifyChecksums(
			array( array( 'file' => 'media/7__photo.jpg', 'sha256' => hash( 'sha256', $content ) ) ),
			$dir
		);

		$this->expectNotToPerformAssertions();
	}

	public function test_missing_media_file_stops_import(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Архив неполный/u' );

		( new BundleArchive() )->verifyChecksums(
			array( array( 'file' => 'media/7__photo.jpg', 'sha256' => str_repeat( 'a', 64 ) ) ),
			$this->makeDir()
		);
	}

	/**
	 * Создаёт ZIP с заданными записями и возвращает путь к нему.
	 *
	 * @param array<string, string> $entries [имя внутри архива => содержимое]
	 *
	 * @return string
	 */
	private function makeArchive( array $entries ): string {
		$path = $this->makeDir() . DIRECTORY_SEPARATOR . 'bundle.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}

		$zip->close();

		return $path;
	}

	/**
	 * Создаёт уникальный временный каталог, удаляемый в tearDown.
	 *
	 * @return string
	 */
	private function makeDir(): string {
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs-lms-bundle-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );
		$this->tempDirs[] = $dir;

		return $dir;
	}

	/**
	 * Рекурсивно удаляет каталог.
	 *
	 * @param string $dir Путь
	 *
	 * @return void
	 */
	private function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $dir );
	}
}
