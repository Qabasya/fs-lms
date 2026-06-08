<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Enums\WeekDay;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\SlugGenerator;

class StudentGroupCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;
	use SlugGenerator;

	public function __construct(
		private readonly GroupsRepository       $groupsRepository,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly PersonRepository       $personRepository,
	) {
		parent::__construct();
	}

	public function ajaxSaveStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		$title              = $this->requireText( 'title', error: 'Название группы обязательно для заполнения.' );
		$academic_period_id = $this->requireKey( 'period_id', error: 'Необходимо указать учебный период.' );
		$subject_key        = $this->requireKey( 'subject_id', error: 'Необходимо указать предмет.' );
		$teacher_id         = $this->sanitizeInt( 'teacher_id' ) ?: null;

		$schedule_json = $this->sanitizeText( 'schedule_json' );
		$raw_entries   = is_string( $schedule_json ) ? json_decode( wp_unslash( $schedule_json ), true ) : null;
		$schedule      = array();

		if ( is_array( $raw_entries ) ) {
			foreach ( $raw_entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$day = WeekDay::tryFrom( sanitize_key( (string) ( $entry['day'] ?? '' ) ) );
				if ( $day === null ) {
					continue;
				}
				$schedule[] = array(
					'day'   => $day->value,
					'start' => sanitize_text_field( (string) ( $entry['start'] ?? '' ) ),
					'end'   => sanitize_text_field( (string) ( $entry['end']   ?? '' ) ),
				);
			}
		}

		$group_id = $this->slugify( $title, 'group' ) . '_' . $this->slugify( $academic_period_id );

		if ( null !== $this->groupsRepository->findByGroupId( $group_id ) ) {
			$this->error( 'Группа с таким названием в этом периоде уже существует.' );
		}

		$id = $this->groupsRepository->create( array(
			'group_id'           => $group_id,
			'subject_key'        => $subject_key,
			'academic_period_id' => $academic_period_id,
			'name'               => $title,
			'teacher_id'         => $teacher_id,
			'schedule'           => (string) wp_json_encode( $schedule ),
		) );

		if ( ! $id ) {
			$this->error( 'Не удалось создать группу. Возможно, группа с таким названием в этом периоде уже существует.' );
		}

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	public function ajaxGetStudentsByGroup(): void {
		$this->authorize( Nonce::Manager );

		$group_id = $this->sanitizeInt( 'group_id' );

		$records = $this->studentRecordRepository->findActiveByGroupId( $group_id );

		$students = array();
		foreach ( $records as $record ) {
			$person = $this->personRepository->find( $record->studentPersonId );
			$wpUser = $person?->wpUserId ? get_userdata( $person->wpUserId ) : null;
			$students[] = array(
				'id'   => $record->studentPersonId,
				'name' => $wpUser ? $wpUser->display_name : ( $person?->fullName() ?: "Person #{$record->studentPersonId}" ),
			);
		}

		$this->success( $students );
	}

	public function ajaxDeleteStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		$id = $this->sanitizeInt( 'id' );

		if ( ! $id ) {
			$this->error( 'Идентификатор группы не указан.' );
		}

		$deleted = $this->groupsRepository->delete( $id );

		if ( ! $deleted ) {
			$this->error( 'Ошибка удаления. Группа не найдена или уже удалена.' );
		}

		$this->success( array( 'id' => $id ) );
	}
}
