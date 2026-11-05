<?php
/**
 * Video inventory and recording-link reset commands.
 *
 * @package FS_LMS
 */

declare(strict_types=1);

namespace Inc\Modules\VideoLibrary\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\VideoLibrary\Services\VideoInventoryExportService;
use Inc\Modules\VideoLibrary\Services\VideoRecordingResetService;
use WP_CLI;

/** Управляет инвентарём и отвязкой видеозаписей через WP-CLI. */
class VideoInventoryCommand implements ServiceInterface {
	/** Receives the inventory exporter. */
	public function __construct( private readonly VideoInventoryExportService $exporter, private readonly VideoRecordingResetService $reset ) {}

	/** Register only under WP-CLI, including when the runtime module is disabled. */
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'fs-lms video inventory', array( $this, 'export' ) );
			WP_CLI::add_command( 'fs-lms video reset-links', array( $this, 'resetLinks' ) );
		}
	}

	/**
	 * Очищает все ссылки и отвязывает видеозаписи, сохраняя отметки «видео не будет».
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Выполнить изменения; без флага только подсчёт.
	 */
	public function resetLinks( array $args, array $assocArgs ): void {
		try {
			$result = $this->reset->reset( ! isset( $assocArgs['apply'] ) );
			WP_CLI::success( sprintf( '%s: указателей занятий — %d, привязок реестра — %d.', isset( $assocArgs['apply'] ) ? 'Очищено' : 'Будет очищено', $result['lessons'], $result['recordings'] ) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Export video registry and lesson pointers without changing the database.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : New JSON file outside the site's public directory. Existing files are refused.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fs-lms video inventory /home/deploy/migration/lms-videos.json
	 *
	 * @param array $args Positional output path.
	 * @param array $assoc_args Named arguments (unused).
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		try {
			$this->exporter->export( (string) $args[0] );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		WP_CLI::success( 'Видеореестр и указатели выгружены. База данных не изменялась.' );
	}
}
