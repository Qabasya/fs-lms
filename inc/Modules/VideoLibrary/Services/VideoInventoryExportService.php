<?php
/**
 * Private video inventory file export.
 *
 * @package FS_LMS
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exclusive creation with restrictive umask is required for private local CLI exports.

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Modules\VideoLibrary\Repositories\VideoInventoryRepository;
use RuntimeException;

/** Writes a new private snapshot, leaving the database and existing files untouched. */
class VideoInventoryExportService {
	/** Receives the read-only inventory repository. */
	public function __construct( private readonly VideoInventoryRepository $repository ) {}

	/**
	 * Write registry and lesson identifiers to a new JSON file.
	 *
	 * @param string $path New output file, preferably outside the document root.
	 * @return void
	 * @throws RuntimeException If the new output cannot be written.
	 * @throws \JsonException If the snapshot cannot be encoded.
	 * @throws \Throwable Propagates failures after closing and removing incomplete output.
	 */
	public function export( string $path ): void {
		$json = wp_json_encode( $this->repository->snapshot(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
		$mask = umask( 0077 );
		try {
			$handle = @fopen( $path, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create failure is checked and reported below.
		} finally {
			umask( $mask );
		}
		if ( false === $handle ) {
			throw new RuntimeException( 'Не удалось создать новый файл отчёта. Существующий файл не перезаписывается.' );
		}
		try {
			$offset = 0;
			$length = strlen( $json );
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $json, $offset ) );
				if ( false === $written || 0 === $written ) {
					throw new RuntimeException( 'Не удалось записать отчёт полностью.' );
				}
				$offset += $written;
			}
		} catch ( \Throwable $e ) {
			fclose( $handle );
			wp_delete_file( $path );
			throw $e;
		}
		fclose( $handle );
	}
}
