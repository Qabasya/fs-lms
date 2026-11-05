<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Course\WorkPointsRecalculationService;
use WP_CLI;

/** Однократный пересчёт баллов работ по сохранённым вердиктам. */
class WorkPointsRecalculateCommand implements ServiceInterface {
	public function __construct( private readonly WorkPointsRecalculationService $service ) {}

	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'fs-lms work recalculate-points', array( $this, 'run' ) );
		}
	}

	/**
	 * Только практика, самостоятельные и домашние работы; ответы не проверяются заново.
	 * Запускать при остановленной записи сдач и проверок.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Записать изменения. Без флага только подсчёт.
	 */
	public function run( array $args, array $assocArgs ): void {
		$after = 0;
		$scanned = 0;
		$changed = 0;
		$skipped = 0;
		$dryRun = ! isset( $assocArgs['apply'] );
		do {
			$batch = $this->service->runBatch( $after, 100, $dryRun );
			$after = $batch['last_id'];
			$scanned += $batch['scanned'];
			$changed += $batch['changed'];
			$skipped += $batch['skipped'];
		} while ( $batch['scanned'] > 0 );
		WP_CLI::success( sprintf( '%s: просмотрено %d, изменено %d, пропущено %d.', $dryRun ? 'Предварительный расчёт' : 'Пересчёт', $scanned, $changed, $skipped ) );
	}
}
