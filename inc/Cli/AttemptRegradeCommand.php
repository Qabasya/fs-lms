<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Assessment\AttemptRegradeService;
use WP_CLI;

/**
 * Class AttemptRegradeCommand
 *
 * WP-CLI: пересчёт автоматически проверяемых заданий сданных попыток станции (тот же обход, что делает
 * одноразовая миграция {@see \Inc\Migrations\AttemptRegradeMigration}, но за один запуск и с `--dry-run`).
 *
 * ```
 * wp fs-lms exam regrade --dry-run
 * wp fs-lms exam regrade
 * ```
 *
 * @package Inc\Cli
 */
class AttemptRegradeCommand implements ServiceInterface {

	private const BATCH = 100;

	public function __construct( private readonly AttemptRegradeService $service ) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms exam regrade', array( $this, 'regrade' ) );
	}

	/**
	 * Пересчитывает автоматически проверяемые задания сданных попыток КЕГЭ/ОГЭ.
	 *
	 * Оценки преподавателя и ручные задания с ответом не меняются.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Только посчитать, ничего не записывая.
	 *
	 * @param array $args       Позиционные аргументы (не используются)
	 * @param array $assoc_args Флаги команды
	 */
	public function regrade( array $args, array $assoc_args ): void {
		$dryRun   = isset( $assoc_args['dry-run'] );
		$lastId   = 0;
		$scanned  = 0;
		$attempts = 0;
		$answers  = 0;

		do {
			$result    = $this->service->runBatch( $lastId, self::BATCH, $dryRun );
			$lastId    = $result['last_id'];
			$scanned  += $result['scanned'];
			$attempts += $result['attempts'];
			$answers  += $result['answers'];
		} while ( $result['scanned'] > 0 );

		WP_CLI::success(
			sprintf(
				'%s: просмотрено попыток — %d, изменилось попыток — %d, заданий — %d.',
				$dryRun ? 'Пересчёт (ничего не записано)' : 'Пересчитано',
				$scanned,
				$attempts,
				$answers
			)
		);
	}
}
