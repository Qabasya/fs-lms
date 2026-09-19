<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Services\Task\TaskFileSchemeService;

/**
 * Class TaskFileSchemeMigration
 *
 * Разовый перевод ссылок на файлы заданий с `http://` на схему сайта.
 *
 * @package Inc\Migrations
 *
 * ### Почему не в MigrationRunner
 *
 * `Migration_1_0_*` — про схему БД, и на установках с уже проставленным
 * `fs_lms_schema_version` старые версии не перезапускаются. Здесь правятся
 * ДАННЫЕ (`fs_lms_meta` заданий), поэтому миграция самодостаточна и
 * version-gated собственной опцией — как схемы модулей (`VideoSchema`).
 *
 * ### Почему автоматически, а не командой
 *
 * WP-CLI есть не на всяком хостинге. Команда {@see \Inc\Cli\TaskFileSchemeCommand}
 * остаётся для повторного прогона и `--dry-run`, а рядовая установка чинится
 * сама при первой загрузке после обновления.
 *
 * Гейт дешёвый: одно чтение опции на запрос, и только при несовпадении версии
 * выполняется запрос к `postmeta`.
 */
class TaskFileSchemeMigration {

	private const VERSION_OPTION = 'fs_lms_task_file_scheme_version';
	private const VERSION        = '1';

	public function __construct( private readonly TaskFileSchemeService $service ) {}

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->service->migrate();

		// Версия пишется в любом случае, даже если править было нечего:
		// иначе запрос к postmeta повторялся бы на каждой загрузке.
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
