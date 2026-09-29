<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Task\ConditionCleanupService;
use WP_CLI;

/**
 * Class TaskConditionCleanupCommand
 *
 * WP-CLI команда чистки сохранённых условий заданий от пустых строк по краям.
 *
 * @package Inc\Cli
 *
 * ### Использование
 *
 * ```
 * wp fs-lms task clean-conditions [--dry-run]
 * ```
 *
 * В Docker-окружении проекта:
 * `docker compose run --rm wpcli wp fs-lms task clean-conditions --dry-run`
 *
 * Вывод условий нормализуется на лету, так что без команды ученики мусора уже
 * не видят; она правит сами данные — для экспорта, переноса предмета и редактора.
 */
class TaskConditionCleanupCommand implements ServiceInterface {

	public function __construct( private readonly ConditionCleanupService $service ) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms task clean-conditions', array( $this, 'cleanConditions' ) );
	}

	/**
	 * Срезает пустые строки и невидимые символы по краям условий заданий.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Только посчитать, ничего не записывая.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fs-lms task clean-conditions --dry-run
	 *     wp fs-lms task clean-conditions
	 *
	 * @param array $args       Позиционные аргументы (не используются)
	 * @param array $assoc_args Флаги команды
	 *
	 * @return void
	 */
	public function cleanConditions( array $args, array $assoc_args ): void {
		$dryRun = isset( $assoc_args['dry-run'] );
		$result = $this->service->run( $dryRun );

		if ( 0 === $result['updated'] ) {
			WP_CLI::success( sprintf( 'Просмотрено заданий: %d. Условия чистые — править нечего.', $result['scanned'] ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'%s: заданий — %d из %d, полей условия — %d.',
				$dryRun ? 'Найдено (ничего не записано)' : 'Очищено',
				$result['updated'],
				$result['scanned'],
				$result['fields']
			)
		);
	}
}
