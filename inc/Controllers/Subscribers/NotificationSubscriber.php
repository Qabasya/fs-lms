<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Profile\NotificationService;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;

/**
 * Class NotificationSubscriber
 *
 * Событийные продюсеры in-app уведомлений: слушает шину {@see LogEventDispatcherInterface}
 * (оценка/возврат сдачи, оценка попытки, сдача работы) и WP-хук `fs_lms_recording_attached`
 * (единая точка всех 3 путей привязки записи занятия — {@see \Inc\Modules\VideoLibrary\Services\VideoRegistrationService},
 * {@see \Inc\Callbacks\Course\ProgramCallbacks}, {@see \Inc\Modules\VideoLibrary\Callbacks\VideoLibraryCallbacks}).
 *
 * ### `review_needed` — фильтр «ручной части»
 *
 * `SubmissionMade` фигурирует и в single- (`SubmissionService::submit()`), и в batch-пути
 * (`submitBatch()`); оба теперь дисптчат `entityId` = id самой (агрегатной) сдачи, поэтому
 * резолвится единообразно через {@see SubmissionRepository::find()}. Batch-путь уже пишет
 * статус `pending_review`, если хоть одна задача без авто-чекера ({@see self::needsReview()}
 * проверяет это первым, дешёвым условием); single-путь всегда пишет `submitted` независимо
 * от шаблона задачи — для него дополнительно резолвится шаблон конкретной задачи
 * (`task_id` сдачи, а для не-per-task сдачи — первая задача работы) через
 * {@see TaskCheckerRegistry::has()} (тот же паттерн, что и {@see \Inc\Services\Course\OpenCourseValidator}).
 *
 * @package Inc\Controllers\Subscribers
 */
class NotificationSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly NotificationService         $notifications,
		private readonly SubmissionRepository        $submissions,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly WorkManager                 $workManager,
		private readonly PostManager                 $posts,
		private readonly TemplateResolver             $templateResolver,
		private readonly TaskCheckerRegistry           $checkerRegistry,
	) {}

	public function register(): void {
		$this->logEvents->subscribe( LogEvent::SubmissionGraded,   array( $this, 'handleSubmissionGraded' ) );
		$this->logEvents->subscribe( LogEvent::SubmissionReturned, array( $this, 'handleSubmissionReturned' ) );
		$this->logEvents->subscribe( LogEvent::AttemptGraded,      array( $this, 'handleAttemptGraded' ) );
		$this->logEvents->subscribe( LogEvent::SubmissionMade,     array( $this, 'handleSubmissionMade' ) );

		add_action( 'fs_lms_recording_attached', array( $this, 'handleRecordingAttached' ) );
	}

	/** Работа проверена — ученику (переоценка заменяет старую непрочитанную плитку свежей). */
	public function handleSubmissionGraded( LearningEvent $event ): void {
		$sub = $this->submissions->find( (int) $event->entityId );
		if ( null === $sub ) {
			return;
		}

		$studentUserId = $this->notifications->studentUserId( $sub->studentPersonId );
		if ( null === $studentUserId ) {
			return;
		}

		$lesson = $this->groupLessons->find( $sub->groupLessonId );

		$this->notifications->pushFresh(
			array( $studentUserId ),
			NotificationType::WorkGraded,
			"graded:{$sub->id}",
			array(
				'topic'      => $lesson ? $this->notifications->lessonTopic( $lesson ) : '',
				'group_name' => $lesson ? $this->notifications->groupName( $lesson->groupId ) : '',
				'score'      => $sub->score,
				'max_score'  => $sub->maxScore,
			),
			$lesson ? $this->notifications->lessonWorkUrl( $lesson, $sub->workId ) : '',
			$lesson?->groupId,
			'submission',
			$sub->id
		);
	}

	/** Работа возвращена на доработку — ученику. */
	public function handleSubmissionReturned( LearningEvent $event ): void {
		$sub = $this->submissions->find( (int) $event->entityId );
		if ( null === $sub ) {
			return;
		}

		$studentUserId = $this->notifications->studentUserId( $sub->studentPersonId );
		if ( null === $studentUserId ) {
			return;
		}

		$lesson = $this->groupLessons->find( $sub->groupLessonId );

		$this->notifications->pushFresh(
			array( $studentUserId ),
			NotificationType::WorkReturned,
			"returned:{$sub->id}",
			array(
				'topic'      => $lesson ? $this->notifications->lessonTopic( $lesson ) : '',
				'group_name' => $lesson ? $this->notifications->groupName( $lesson->groupId ) : '',
			),
			$lesson ? $this->notifications->lessonWorkUrl( $lesson, $sub->workId ) : '',
			$lesson?->groupId,
			'submission',
			$sub->id
		);
	}

	/** Экзамен проверен — ученику и родителю (критичное уведомление). */
	public function handleAttemptGraded( LearningEvent $event ): void {
		$attempt = $this->attempts->find( (int) $event->entityId );
		if ( null === $attempt ) {
			return;
		}

		$studentUserId = $this->notifications->studentUserId( $attempt->studentPersonId );
		$recipients    = array_merge(
			null !== $studentUserId ? array( $studentUserId ) : array(),
			$this->notifications->guardianUserIds( $attempt->studentPersonId )
		);
		if ( empty( $recipients ) ) {
			return;
		}

		$this->notifications->pushFresh(
			$recipients,
			NotificationType::AttemptGraded,
			"attempt:{$attempt->id}",
			array(
				'score'     => $attempt->totalScore,
				'max_score' => $attempt->maxScore,
			),
			(string) add_query_arg( array( 'screen' => 'learner-grades' ), PageRoutes::UserProfile->url() ),
			$attempt->groupId,
			'assessment_attempt',
			$attempt->id
		);
	}

	/** Сдана работа — учителю, только если есть часть без автопроверки. */
	public function handleSubmissionMade( LearningEvent $event ): void {
		$sub = $this->submissions->find( (int) $event->entityId );
		if ( null === $sub || ! $this->needsReview( $sub ) ) {
			return;
		}

		$lesson = $this->groupLessons->find( $sub->groupLessonId );
		if ( null === $lesson ) {
			return;
		}

		$teacherUserId = $this->notifications->lessonTeacherUserId( $lesson );
		if ( null === $teacherUserId ) {
			return;
		}

		$this->notifications->push(
			array( $teacherUserId ),
			NotificationType::ReviewNeeded,
			"review:{$sub->id}",
			array(
				'student_name' => $this->notifications->studentSnapshotName( $sub->studentPersonId, $lesson->groupId ),
				'topic'        => $this->notifications->lessonTopic( $lesson ),
				'group_name'   => $this->notifications->groupName( $lesson->groupId ),
			),
			(string) add_query_arg( array( 'screen' => 'summary' ), PageRoutes::UserProfile->url() ),
			$lesson->groupId,
			'submission',
			$sub->id
		);
	}

	/** Появилась запись занятия — ученикам занятия (идемпотентно: dedupe по занятию покрывает все 3 пути привязки). */
	public function handleRecordingAttached( int $groupLessonId ): void {
		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( null === $lesson ) {
			return;
		}

		$recipients = $this->notifications->lessonStudentUserIds( $lesson );
		if ( empty( $recipients ) ) {
			return;
		}

		$this->notifications->push(
			$recipients,
			NotificationType::VideoUploaded,
			"video:{$groupLessonId}",
			array(
				'topic'      => $this->notifications->lessonTopic( $lesson ),
				'group_name' => $this->notifications->groupName( $lesson->groupId ),
			),
			PageRoutes::GroupCockpit->lessonUrl( $lesson->groupId, $lesson->id ),
			$lesson->groupId,
			'group_lesson',
			$groupLessonId
		);
	}

	/**
	 * true, если сдача требует ручной проверки: агрегат batch-пути уже помечен
	 * `pending_review` (была хоть одна задача без чекера), либо (single-путь,
	 * статус которого всегда `submitted`) конкретная задача сдачи не имеет
	 * зарегистрированного авто-чекера.
	 */
	private function needsReview( SubmissionDTO $sub ): bool {
		if ( SubmissionStatus::PendingReview === $sub->status ) {
			return true;
		}

		$taskId = $sub->taskId ?? $this->firstTaskIdOfWork( $sub->workId );
		if ( null === $taskId ) {
			return false;
		}

		$post = $this->posts->get( $taskId );
		if ( null === $post ) {
			return false;
		}

		return ! $this->checkerRegistry->has( $this->templateResolver->resolveEnum( $post ) );
	}

	/** Первая задача работы — для сдач без явного task_id (работа = один task). */
	private function firstTaskIdOfWork( int $workId ): ?int {
		return $this->workManager->get( $workId )?->itemIds[0] ?? null;
	}
}
