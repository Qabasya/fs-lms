<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Settings\TableName;

/**
 * Class AssessmentAnswerUniqueMigration
 *
 * Одноразовая схемная миграция: уникальный ключ `(attempt_id, task_id)` на
 * таблице ответов попытки плюс вычистка дублей, накопившихся до него.
 *
 * ### Зачем ключ
 *
 * `AssessmentAnswerRepository::upsert()` раньше решал «вставить или обновить»
 * на стороне PHP: сперва SELECT, потом INSERT либо UPDATE. Автосохранение
 * страницы экзамена шлёт ответ по вводу, по уходу из поля и по кнопке —
 * два таких запроса приходят почти одновременно, оба видят пустоту и делают
 * по INSERT. В таблице оказываются две строки на одну пару, а дальше
 * `findByAttemptAndTask()` (`LIMIT 1` без сортировки) берёт произвольную из
 * них: ответ то виден, то нет. Ключ делает такую пару невозможной, а
 * репозиторий — атомарным (`INSERT … ON DUPLICATE KEY UPDATE`).
 *
 * ### Почему отдельный класс, а не `Migration_1_0_0::up()`
 *
 * `MigrationRunner::run()` вызывается только из `register_activation_hook`, и
 * его гейт `version_compare('1.0.0', $current, '>')` на установках с уже
 * проставленным `fs_lms_schema_version = 1.0.0` даёт false — DDL оттуда туда
 * не доедет. А это ровно те инсталляции, где дубли и лежат. В `up()` ключ
 * добавлен только для новых установок (см. секцию 19).
 *
 * Идемпотентна: гейт — собственная опция; при уже стоящем ключе повторный
 * `ensure()` — дешёвый option-read (паттерн {@see BroadcastStepMigration}).
 *
 * @package Inc\Migrations
 */
class AssessmentAnswerUniqueMigration {

	/** Опция-гейт (совпадение версии = миграция выполнена). */
	private const VERSION_OPTION = 'fs_lms_assessment_answer_unique';

	/** Версия миграции. */
	private const VERSION = '1';

	/** Имя уникального ключа. */
	private const INDEX = 'attempt_task';

	/**
	 * Выполняет миграцию один раз (version-gated). Вызывать на обычной загрузке.
	 *
	 * @return void
	 */
	public function ensure(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;
		$table = TableName::AssessmentAnswers->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}

		if ( ! $this->hasIndex( $table ) ) {
			$this->dropDuplicates( $table );
			$this->addIndex( $table );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Сносит лишние строки пары `(attempt_id, task_id)`, оставляя последнюю.
	 *
	 * Последнюю, а не первую: строки-дубли появлялись гонкой двух INSERT, и
	 * дальше `UPDATE … WHERE attempt_id AND task_id` правил их все разом — то
	 * есть содержимое у них одинаковое, а свежесть выше у большего id.
	 *
	 * @param string $table Имя таблицы с префиксом.
	 *
	 * @return void
	 */
	private function dropDuplicates( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE `dup` FROM `$table` AS `dup`
			 INNER JOIN (
				SELECT attempt_id, task_id, MAX(id) AS keep_id
				FROM `$table`
				GROUP BY attempt_id, task_id
				HAVING COUNT(*) > 1
			 ) AS `keep`
			 ON `dup`.attempt_id = `keep`.attempt_id
			 AND `dup`.task_id = `keep`.task_id
			 AND `dup`.id <> `keep`.keep_id"
		);
	}

	/**
	 * @param string $table Имя таблицы с префиксом.
	 *
	 * @return void
	 */
	private function addIndex( string $table ): void {
		global $wpdb;

		$index = self::INDEX;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE `$table` ADD UNIQUE KEY `$index` (`attempt_id`, `task_id`)" );
	}

	/**
	 * @param string $table Имя таблицы с префиксом.
	 *
	 * @return bool
	 */
	private function hasIndex( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `$table` WHERE Key_name = %s", self::INDEX ) );
	}
}
