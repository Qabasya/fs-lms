<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\LessonManager;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use Inc\Modules\VideoLibrary\Services\VideoRegistrationService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class VideoLibraryCallbacks
 *
 * AJAX-обработчики ручной привязки записей: админские (V9, ниже — `Nonce::Config`+
 * `Capability::Admin`) и преподавательские (Этап 3 — `Nonce::TeachLesson`+
 * `Capability::ManageLmsTeaching`, только своя группа занятия). Привязка в обоих
 * случаях идёт тем же путём, что авто-матч V6 (`recording_url` + `held`) —
 * через VideoRegistrationService.
 *
 * @package Inc\Modules\VideoLibrary\Callbacks
 */
class VideoLibraryCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly VideoRegistrationService $registration,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly LessonManager            $lessons,
		private readonly VideoRecordingRepository $recordings,
		private readonly GroupAccessGuard         $guard,
	) {
		parent::__construct();
	}

	/** Занятия группы на календарный день (кандидаты ручной привязки). */
	public function ajaxLessons(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$groupId = $this->requireInt( 'group_id' );
		$day     = $this->requireText( 'day' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$this->error( 'Неверный формат даты (ожидается ГГГГ-ММ-ДД).' );
		}

		$lessons = array();
		foreach ( $this->groupLessons->listByGroupAndDay( $groupId, $day ) as $lesson ) {
			$topic = null !== $lesson->lessonId ? ( $this->lessons->get( $lesson->lessonId )?->topic ?? '' ) : '';

			$lessons[] = array(
				'id'           => $lesson->id,
				'scheduled_at' => $lesson->scheduledAt,
				'ends_at'      => $lesson->endsAt,
				'kind'         => $lesson->kind,
				'status'       => $lesson->status,
				'title'        => $lesson->label ?? ( '' !== $topic ? $topic : "Занятие #{$lesson->id}" ),
			);
		}

		$this->success( array( 'lessons' => $lessons ) );
	}

	/** Ручная привязка записи к занятию (тот же путь, что авто-матч: recording_url + held). */
	public function ajaxAttach(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$recordingId   = $this->requireInt( 'recording_id' );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );

		if ( ! $this->registration->attachManually( $recordingId, $groupLessonId ) ) {
			$this->error( 'Запись или занятие не найдены.' );
		}

		$this->success( array( 'message' => 'Запись привязана к занятию.' ) );
	}

	/** Отвязка записи (возврат в unmatched; статус занятия не откатывается). */
	public function ajaxDetach(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$recordingId = $this->requireInt( 'recording_id' );

		if ( ! $this->registration->detachManually( $recordingId ) ) {
			$this->error( 'Запись не найдена.' );
		}

		$this->success( array( 'message' => 'Запись отвязана.' ) );
	}

	/**
	 * Записи занятия своей группы для тич-панели broadcast-шага (Этап 3, будущая
	 * поверхность — Этап 4): текущая привязка + непривязанные кандидаты той же группы.
	 * Params: group_lesson_id
	 */
	public function ajaxTeacherListRecordings(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$row           = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, get_current_user_id() ) ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$this->success( array(
			'current'    => array_map( array( $this, 'recordingToArray' ), $this->recordings->listByGroupLesson( $groupLessonId ) ),
			'candidates' => array_map( array( $this, 'recordingToArray' ), $this->recordings->listByGroup( $row->groupId ) ),
		) );
	}

	/**
	 * Ручная привязка записи преподавателем — тот же путь, что у админа (V9),
	 * но только в пределах своей группы (запись обязана быть из той же группы).
	 * Params: group_lesson_id, recording_id
	 */
	public function ajaxTeacherAttachRecording(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$recordingId   = $this->requireInt( 'recording_id' );

		$row = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, get_current_user_id() ) ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$recording = $this->recordings->find( $recordingId );
		if ( null === $recording || $recording->groupId !== $row->groupId ) {
			$this->error( 'Запись не найдена.' );
			return;
		}

		if ( ! $this->registration->attachManually( $recordingId, $groupLessonId ) ) {
			$this->error( 'Запись или занятие не найдены.' );
			return;
		}

		$this->success( array( 'message' => 'Запись привязана к занятию.' ) );
	}

	/**
	 * Ручная отвязка преподавателем — в пределах своей группы.
	 * Params: group_lesson_id, recording_id
	 */
	public function ajaxTeacherDetachRecording(): void {
		$this->authorize( Nonce::TeachLesson, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$recordingId   = $this->requireInt( 'recording_id' );

		$row = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || ! $this->guard->canManage( $row->groupId, get_current_user_id() ) ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		$recording = $this->recordings->find( $recordingId );
		if ( null === $recording || $recording->groupId !== $row->groupId ) {
			$this->error( 'Запись не найдена.' );
			return;
		}

		if ( ! $this->registration->detachManually( $recordingId ) ) {
			$this->error( 'Запись не найдена.' );
			return;
		}

		$this->success( array( 'message' => 'Запись отвязана.' ) );
	}

	/** @return array<string, mixed> */
	private function recordingToArray( VideoRecordingDTO $recording ): array {
		return array(
			'id'           => $recording->id,
			's3_key'       => $recording->s3Key,
			'recorded_at'  => $recording->recordedAt,
			'duration_sec' => $recording->durationSec,
			'status'       => $recording->status,
		);
	}
}
