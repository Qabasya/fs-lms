<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Task\TaskFileSchemeService;
use WP_CLI;

/**
 * Class TaskFileSchemeCommand
 *
 * WP-CLI команда перевода ссылок на файлы заданий с `http://` на схему сайта.
 *
 * @package Inc\Cli
 *
 * ### Использование
 *
 * ```
 * wp fs-lms task fix-file-urls [--dry-run]
 * ```
 *
 * В Docker-окружении проекта:
 * `docker compose run --rm wpcli wp fs-lms task fix-file-urls --dry-run`
 *
 * ### Если доступа к WP-CLI нет
 *
 * Команда — не единственный способ: те же ссылки правит одноразовая миграция
 * {@see \Inc\Migrations\TaskFileSchemeMigration}, которая срабатывает сама при
 * первой загрузке после обновления плагина. Команда нужна, чтобы прогнать
 * правку повторно (например, после импорта старого пакета предмета) или
 * посмотреть объём работ через `--dry-run`, ничего не меняя.
 */
class TaskFileSchemeCommand implements ServiceInterface {

	public function __construct( private readonly TaskFileSchemeService $service ) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms task fix-file-urls', array( $this, 'fixFileUrls' ) );
	}

	/**
	 * Переводит ссылки на файлы заданий на схему сайта (http:// → https://).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Только посчитать, ничего не записывая.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fs-lms task fix-file-urls --dry-run
	 *     wp fs-lms task fix-file-urls
	 *
	 * @param array $args       Позиционные аргументы (не используются)
	 * @param array $assoc_args Флаги команды
	 *
	 * @return void
	 */
	public function fixFileUrls( array $args, array $assoc_args ): void {
		$dryRun = isset( $assoc_args['dry-run'] );
		$result = $this->service->migrate( $dryRun );

		if ( 0 === $result['updated'] ) {
			WP_CLI::success( 'Ссылок со старой схемой не найдено — править нечего.' );
			return;
		}

		$message = sprintf(
			'%s: заданий — %d, ссылок — %d.',
			$dryRun ? 'Найдено (ничего не записано)' : 'Обновлено',
			$result['updated'],
			$result['links']
		);

		WP_CLI::success( $message );
	}
}
