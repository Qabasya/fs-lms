<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Exam\ExamAudienceResolver;
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
	) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms exam audience', array( $this, 'audience' ) );
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
