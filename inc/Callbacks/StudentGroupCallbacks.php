<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Enums\WeekDay;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\StudentGroupService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class StudentGroupCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly StudentGroupService  $group_service,
		private readonly EnrollmentRepository $enrollmentRepository,
		private readonly PersonRepository     $personRepository,
	) {
		parent::__construct();
	}

	public function ajaxSaveStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		$title      = $this->requireText( 'title', error: 'Название группы обязательно для заполнения.' );
		$period_id  = $this->requireKey( 'period_id', error: 'Необходимо указать учебный период.' );
		$subject_id = $this->requireKey( 'subject_id', error: 'Необходимо указать предмет.' );
		$teacher_id = $this->requireInt( 'teacher_id', error: 'Необходимо выбрать преподавателя.' );

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

		$group_dto = $this->group_service->createGroup( $title, $period_id, $subject_id, $teacher_id, $schedule );

		$this->respond(
			result: $group_dto ? array( 'group' => $group_dto->toArray() ) : false,
			error_msg: 'Не удалось создать группу. Возможно, группа с таким названием в этом периоде уже существует.',
			success_msg: 'Группа успешно создана.'
		);
	}

	public function ajaxGetStudentsByGroup(): void {
		$this->authorize( Nonce::Manager );

		$group_key = $this->requireKey( 'group_id', error: 'ID группы не указан.' );

		$enrollments = $this->enrollmentRepository->findActiveByGroupKey( $group_key );

		$students = array();
		foreach ( $enrollments as $enr ) {
			$person = $this->personRepository->find( $enr->studentPersonId );
			$wpUser = $person?->wpUserId ? get_userdata( $person->wpUserId ) : null;
			$students[] = array(
				'id'   => $enr->studentPersonId,
				'name' => $wpUser ? $wpUser->display_name : ( $person?->fullName ?: "Person #{$enr->studentPersonId}" ),
			);
		}

		$this->success( $students );
	}

	public function ajaxDeleteStudentGroup(): void {
		$this->authorize( Nonce::Manager );

		$id = $this->requireKey( 'id', error: 'Идентификатор группы не указан.' );

		$deleted = $this->group_service->deleteGroup( $id );

		$this->respond(
			result: $deleted ? array( 'id' => $id ) : false,
			error_msg: 'Ошибка удаления. Группа не найдена или уже удалена.',
			success_msg: 'Группа успешно удалена.'
		);
	}
}
