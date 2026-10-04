<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\System\CronController;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Exam\ExamTickLock;
use Inc\Services\Exam\ExamTickService;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use WP_CLI;

/**
 * WP-CLI команды для экзаменов.
 *
 * @package Inc\Cli
 */
class ExamCommand implements ServiceInterface {

	public function __construct(
		private readonly ExamAudienceResolver     $audience,
		private readonly StudentRecordRepository  $records,
		private readonly GroupsRepository         $groups,
		private readonly ExamTickLock             $tickLock,
		private readonly ExamTickService          $ticks,
	) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms exam audience', array( $this, 'audience' ) );
		WP_CLI::add_command( 'fs-lms exam tick', array( $this, 'tick' ) );
	}

	/**
	 * Выполнить минутный тик экзаменов вручную: автоистечение попыток и неявки либо освобождение истёкших броней гостей.
	 *
	 * Тот же код и та же блокировка, что у cron: второй одновременный запуск не выполняется.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Какой тик запускать.
	 * ---
	 * default: auto-expire
	 * options:
	 *   - auto-expire
	 *   - hold-release
	 * ---
	 *
	 * [--at=<unix>]
	 * : Подождать до этого момента (секунды Unix) и только потом запустить тик — для стенда start-vs-missed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fs-lms exam tick
	 *     wp fs-lms exam tick --name=auto-expire
	 *     wp fs-lms exam tick --name=hold-release
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function tick( array $args, array $assoc_args ): void {
		$name   = (string) ( $assoc_args['name'] ?? 'auto-expire' );
		$result = null;

		// Стенд: все процессы загружают WordPress заранее и стартуют в одну секунду.
		$at = (float) ( $assoc_args['at'] ?? 0 );
		if ( $at > microtime( true ) ) {
			usleep( (int) ( ( $at - microtime( true ) ) * 1_000_000 ) );
		}

		if ( 'hold-release' === $name ) {
			$lock = CronController::EXAM_HOLD_RELEASE_LOCK;
			$run  = function () use ( &$result ): void {
				$result = sprintf( 'Освобождено броней: %d.', $this->ticks->releaseHolds() );
			};
		} elseif ( 'auto-expire' === $name ) {
			$lock = CronController::EXAM_AUTO_EXPIRE_LOCK;
			$run  = function () use ( &$result ): void {
				$counts = $this->ticks->autoExpireTick();
				$result = sprintf( 'Завершено попыток: %d, проставлено неявок: %d.', $counts['expired'], $counts['missed'] );
			};
		} else {
			WP_CLI::error( sprintf( 'Неизвестный тик «%s»: допустимы auto-expire и hold-release.', $name ) );
			return;
		}

		if ( ! $this->tickLock->run( $lock, $run ) ) {
			WP_CLI::warning( 'Тик уже выполняется.' );
			return;
		}

		if ( null === $result ) {
			// Исключение тика поглощено ExamTickLock (cron не должен падать) и записано в журнал.
			WP_CLI::error( 'Тик завершился с ошибкой — подробности в журнале.' );
		}

		WP_CLI::success( $result );
	}

	/**
	 * Вывести аудиторию предмета (активные ученики групп).
	 *
	 * ## OPTIONS
	 *
	 * <subject_key>
	 * : Ключ предмета (например, inf_ege)
	 *
	 * [--format=<table|count>]
	 * : Формат вывода (table или count)
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fs-lms exam audience inf_ege
	 *     wp fs-lms exam audience inf_oge --format=count
	 *
	 * @param array $args       Позиционные аргументы
	 * @param array $assoc_args Именованные аргументы
	 */
	public function audience( array $args, array $assoc_args ): void {
		$subjectKey = $args[0] ?? '';
		$format = $assoc_args['format'] ?? 'table';

		$studentIds = $this->audience->studentPersonIds( $subjectKey );

		if ( empty( $studentIds ) ) {
			if ( 0 === count( $this->groups->findBySubjectKey( $subjectKey ) ) ) {
				WP_CLI::warning( 'Групп предмета нет.' );
			}

			if ( 'count' === $format ) {
				WP_CLI::line( '0' );
			} else {
				WP_CLI::line( 'Всего: 0' );
			}
			return;
		}

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $studentIds ) );
			return;
		}

		// Таблица: person_id, ФИО, группы
		$rows = array();
		foreach ( $studentIds as $personId ) {
			$records = $this->records->findActiveByStudent( $personId );

			$fullName = '';
			$groups = array();

			foreach ( $records as $record ) {
				if ( empty( $fullName ) ) {
					$fullName = trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName );
				}

				$group = $this->groups->findById( $record->groupId );
				if ( null !== $group && null === $group->deleted_at ) {
					$groups[] = $group->name;
				}
			}

			$rows[] = array(
				'person_id' => $personId,
				'full_name' => $fullName,
				'groups'    => implode( ', ', array_unique( $groups ) ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'person_id', 'full_name', 'groups' ) );

		WP_CLI::line( 'Всего: ' . count( $studentIds ) );
	}
}
