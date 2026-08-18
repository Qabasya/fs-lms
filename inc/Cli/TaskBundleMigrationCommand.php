<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\DTO\Task\TaskBundleReferenceChangeDTO;
use Inc\Services\Task\TaskBundleMigrationPlanner;
use WP_CLI;

/**
 * Class TaskBundleMigrationCommand
 *
 * WP-CLI: перевод существующего банка связок 19-21 (`triple_task`) на модель
 * parent+children (см. .docs/Tasks.md, §4).
 *
 * @package Inc\Cli
 *
 * ### Что делает
 *
 * 1. Материализация — находит все `triple_task`-посты (предметные задания + банк)
 *    и досоздаёт/синхронизирует их 3 children. Идемпотентно и не разрушительно
 *    (upsert по уже сохранённым id) — выполняется **даже под `--dry-run`**, чтобы
 *    план второй фазы был построен по реальным id children, а не по догадке.
 * 2. Переезд ссылок — находит Work/Assessment, ссылающиеся на parent-id связки
 *    в itemIds/taskIds, и заменяет один id на 3 id children (баллы — по 1 на
 *    каждого). **Необратимо** переписывает уже опубликованные работы/экзамены —
 *    единственная фаза, которую реально блокирует `--dry-run`.
 *
 * Исторические строки `task_attempts`/`assessment_answers` по старому
 * parent-`task_id` не трогает — они остаются архивом прошлых сдач.
 *
 * ### Использование
 *
 * ```
 * wp fs-lms task-bundle migrate --dry-run
 * wp fs-lms task-bundle migrate
 * ```
 *
 * В Docker-окружении проекта:
 * `docker compose run --rm wpcli wp fs-lms task-bundle migrate --dry-run`
 */
class TaskBundleMigrationCommand implements ServiceInterface {

	public function __construct(
		private readonly TaskBundleMigrationPlanner $planner,
	) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms task-bundle migrate', array( $this, 'migrate' ) );
	}

	/**
	 * Материализует children связок и переносит на них ссылки Work/Assessment.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Материализовать children (это безопасно и обратимо не требуется), но
	 * только показать план переезда ссылок Work/Assessment, ничего в них не меняя.
	 *
	 * @param string[]              $args      Позиционные аргументы (не используются).
	 * @param array<string, string> $assocArgs Именованные аргументы.
	 *
	 * @return void
	 */
	public function migrate( array $args, array $assocArgs ): void {
		$dryRun = isset( $assocArgs['dry-run'] );

		$parentIds = $this->planner->findBundleParents();
		if ( array() === $parentIds ) {
			WP_CLI::success( 'Связок 19-21 в банке нет — переносить нечего.' );
			return;
		}

		WP_CLI::log( sprintf( 'Найдено связок (triple_task): %d.', count( $parentIds ) ) );

		$parentToChildren = $this->planner->materialize( $parentIds );
		WP_CLI::log( 'Children материализованы (идемпотентно, выполняется и под --dry-run).' );

		$changes = $this->planner->planReferenceUpdates( $parentToChildren );

		if ( array() === $changes ) {
			WP_CLI::success( 'Ссылающихся на связки Work/Assessment не найдено — переносить нечего.' );
			return;
		}

		WP_CLI::log( sprintf( 'Work/Assessment под переезд ссылок: %d.', count( $changes ) ) );
		WP_CLI\Utils\format_items(
			'table',
			array_map( static fn( TaskBundleReferenceChangeDTO $change ): array => $change->toRow(), $changes ),
			array( 'ID', 'тип', 'заголовок', 'было', 'станет' )
		);

		if ( $dryRun ) {
			WP_CLI::success( 'План готов. Повторите без --dry-run для применения.' );
			return;
		}

		$result = $this->planner->applyReferenceUpdates( $changes );

		if ( array() !== $result['failed'] ) {
			WP_CLI::warning( sprintf(
				'Не удалось обновить %d записей — гард дублей отклонил результат ' .
				'(в itemIds/taskIds уже были повторяющиеся id ДО миграции, независимо от связки). ' .
				'Разберите вручную: %s.',
				count( $result['failed'] ),
				implode( ', ', array_map( static fn( TaskBundleReferenceChangeDTO $c ): string => (string) $c->post_id, $result['failed'] ) )
			) );
		}

		WP_CLI::success( sprintf( 'Ссылки обновлены: %d.', count( $result['applied'] ) ) );
	}
}
