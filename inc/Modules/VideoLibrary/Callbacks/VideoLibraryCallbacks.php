<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\LessonManager;
use Inc\Modules\VideoLibrary\Services\VideoRegistrationService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class VideoLibraryCallbacks
 *
 * AJAX-обработчики ручной привязки записей (V9): занятия группы на день,
 * привязка/отвязка. Привязка идёт тем же путём, что авто-матч V6
 * (`recording_url` + `held`) — через VideoRegistrationService.
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
}
