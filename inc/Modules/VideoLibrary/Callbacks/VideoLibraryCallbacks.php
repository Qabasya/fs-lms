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
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class VideoLibraryCallbacks
 *
 * AJAX-обработчики ручной привязки записей (V9): списки unmatched/matched,
 * занятия группы на день, привязка/отвязка. Привязка идёт тем же путём, что
 * авто-матч V6 (`recording_url` + `held`) — через VideoRegistrationService.
 *
 * @package Inc\Modules\VideoLibrary\Callbacks
 */
class VideoLibraryCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly VideoRecordingRepository $recordings,
		private readonly VideoRegistrationService $registration,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly GroupsRepository         $groups,
		private readonly LessonManager            $lessons,
	) {
		parent::__construct();
	}

	/** Списки записей + группы для селекта привязки. */
	public function ajaxList(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$groups = array_map(
			static fn( object $g ): array => array(
				'id'   => (int) $g->id,
				'name' => (string) $g->name,
			),
			$this->groups->findAll()
		);

		$this->success( array(
			'unmatched' => array_map( fn( VideoRecordingDTO $r ): array => $this->recordingRow( $r ), $this->recordings->listUnmatched() ),
			'matched'   => array_map( fn( VideoRecordingDTO $r ): array => $this->recordingRow( $r ), $this->recordings->listMatched() ),
			'groups'    => $groups,
		) );
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

	/** @return array<string, mixed> */
	private function recordingRow( VideoRecordingDTO $recording ): array {
		$teacher = '';
		if ( null !== $recording->teacherUserId ) {
			$user    = get_userdata( $recording->teacherUserId );
			$teacher = false !== $user ? $user->user_login : "user #{$recording->teacherUserId}";
		}

		$lessonAt = null;
		if ( null !== $recording->groupLessonId ) {
			$lessonAt = $this->groupLessons->find( $recording->groupLessonId )?->scheduledAt;
		}

		return array(
			'id'                 => $recording->id,
			's3_key'             => $recording->s3Key,
			'group_slug'         => $recording->groupSlug,
			'group_id'           => $recording->groupId,
			'teacher'            => $teacher,
			'recorded_at'        => $recording->recordedAt,
			'size'               => size_format( $recording->sizeBytes ) ?: '0 B',
			'group_lesson_id'    => $recording->groupLessonId,
			'lesson_scheduled_at' => $lessonAt,
		);
	}
}
