<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Log\LogArchiveService;
use WP_CLI;

/**
 * Class LogArchiveCommand
 *
 * WP-CLI: возврат строк журналов из месячного ZIP-архива («Журналы» → «Архив журналов»).
 *
 * ### Использование
 *
 * ```
 * wp fs-lms logs import fs-lms-logs-before-2026-10-01.zip
 * ```
 *
 * Строки с уже занятым `id` пропускаются, поэтому повторный запуск безопасен.
 *
 * @package Inc\Cli
 */
class LogArchiveCommand implements ServiceInterface {

	/**
	 * @param LogArchiveService $archive Месячный архив журналов
	 */
	public function __construct(
		private readonly LogArchiveService $archive,
	) {}

	/**
	 * Регистрирует команду, если плагин запущен под WP-CLI.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms logs import', array( $this, 'import' ) );
	}

	/**
	 * Возвращает записи журналов из ZIP-архива в таблицы.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Путь к ZIP, скачанному на странице «Журналы».
	 *
	 * @param string[]              $args      Позиционные аргументы
	 * @param array<string, string> $assocArgs Именованные аргументы
	 */
	public function import( array $args, array $assocArgs ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_file( $file ) ) {
			WP_CLI::error( 'Укажите существующий ZIP: wp fs-lms logs import <file>' );
		}

		try {
			$restored = $this->archive->restore( $file );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( $restored as $channel => $count ) {
			WP_CLI::log( sprintf( '%s: восстановлено %d', $channel, $count ) );
		}
		WP_CLI::success( 'Готово.' );
	}
}
