<?php
/**
 * Read-only video inventory command.
 *
 * @package FS_LMS
 */

declare(strict_types=1);

namespace Inc\Modules\VideoLibrary\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\VideoLibrary\Services\VideoInventoryExportService;
use WP_CLI;

/** Exports migration inventory without requiring DNS, S3 or a browser session. */
class VideoInventoryCommand implements ServiceInterface {
	/** Receives the inventory exporter. */
	public function __construct( private readonly VideoInventoryExportService $exporter ) {}

	/** Register only under WP-CLI, including when the runtime module is disabled. */
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'fs-lms video inventory', array( $this, 'export' ) );
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
