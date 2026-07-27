<?php

declare( strict_types=1 );

namespace Unit\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Controllers\Subscribers\NotificationSubscriber;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Profile\NotificationService;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

class NotificationSubscriberTest extends TestCase {

	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $logEvents;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject         $notifications;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject        $submissions;
	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $attempts;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject       $groupLessons;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject                 $workManager;
	private PostManager&\PHPUnit\Framework\MockObject\MockObject                 $posts;
	private TemplateResolver&\PHPUnit\Framework\MockObject\MockObject            $templateResolver;
	private TaskCheckerRegistry&\PHPUnit\Framework\MockObject\MockObject         $checkerRegistry;
	private NotificationSubscriber $subscriber;

	protected function setUp(): void {
		parent::setUp();

		$this->logEvents        = $this->createMock( LogEventDispatcherInterface::class );
		$this->notifications    = $this->createMock( NotificationService::class );
		$this->submissions       = $this->createMock( SubmissionRepository::class );
		$this->attempts          = $this->createMock( AssessmentAttemptRepository::class );
		$this->groupLessons      = $this->createMock( GroupLessonRepository::class );
		$this->workManager       = $this->createMock( WorkManager::class );
		$this->posts             = $this->createMock( PostManager::class );
		$this->templateResolver  = $this->createMock( TemplateResolver::class );
		$this->checkerRegistry   = $this->createMock( TaskCheckerRegistry::class );

		$this->subscriber = new NotificationSubscriber(
			$this->logEvents,
			$this->notifications,
			$this->submissions,
			$this->attempts,
			$this->groupLessons,
			$this->workManager,
			$this->posts,
			$this->templateResolver,
			$this->checkerRegistry,
		);
	}

	private function submission( array $overrides = array() ): SubmissionDTO {
		return SubmissionDTO::fromArray( array_merge( array(
			'id'                 => 1,
			'student_person_id'  => 10,
			'group_lesson_id'    => 100,
			'work_id'            => 50,
			'work_type'          => 'homework',
			'task_id'            => null,
			'status'             => 'submitted',
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
		), $overrides ) );
	}

	private function lesson( array $overrides = array() ): GroupLessonDTO {
		$base = array(
			'id' => 100, 'group_id' => 5, 'lesson_id' => null, 'position' => 0,
			'kind' => 'group', 'status' => 'scheduled', 'visibility' => 'open',
		);
		return GroupLessonDTO::fromArray( array_merge( $base, $overrides ) );
	}

	public function test_register_subscribes_to_bus_events_and_recording_hook(): void {
		$this->logEvents->expects( $this->exactly( 4 ) )->method( 'subscribe' )->with(
			self::logicalOr(
				LogEvent::SubmissionGraded,
				LogEvent::SubmissionReturned,
				LogEvent::AttemptGraded,
				LogEvent::SubmissionMade,
			),
			$this->anything()
		);

		// add_action() — no-op стаб; сам вызов не должен падать (fs_lms_recording_attached).
		$this->subscriber->register();
	}

	public function test_submission_graded_pushes_fresh_to_student(): void {
		$this->submissions->method( 'find' )->with( 1 )->willReturn( $this->submission( array( 'status' => 'graded', 'score' => 4, 'max_score' => 5 ) ) );
		$this->notifications->method( 'studentUserId' )->with( 10 )->willReturn( 77 );
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonTopic' )->willReturn( 'Тема' );
		$this->notifications->method( 'groupName' )->willReturn( 'Группа' );
		$this->notifications->method( 'lessonWorkUrl' )->willReturn( '/group/?gid=5&gl=100' );

		$this->notifications->expects( $this->once() )
			->method( 'pushFresh' )
			->with( array( 77 ), NotificationType::WorkGraded, 'graded:1', $this->anything(), '/group/?gid=5&gl=100', 5, 'submission', 1 );

		$this->subscriber->handleSubmissionGraded( new LearningEvent(
			event: LogEvent::SubmissionGraded, actorUserId: 999, entityId: '1'
		) );
	}

	public function test_submission_graded_skips_when_student_has_no_account(): void {
		$this->submissions->method( 'find' )->willReturn( $this->submission() );
		$this->notifications->method( 'studentUserId' )->willReturn( null );

		$this->notifications->expects( $this->never() )->method( 'pushFresh' );

		$this->subscriber->handleSubmissionGraded( new LearningEvent(
			event: LogEvent::SubmissionGraded, actorUserId: 999, entityId: '1'
		) );
	}

	public function test_submission_returned_pushes_fresh_to_student(): void {
		$this->submissions->method( 'find' )->willReturn( $this->submission( array( 'status' => 'returned' ) ) );
		$this->notifications->method( 'studentUserId' )->willReturn( 77 );
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );

		$this->notifications->expects( $this->once() )
			->method( 'pushFresh' )
			->with( array( 77 ), NotificationType::WorkReturned, 'returned:1', $this->anything(), $this->anything(), 5, 'submission', 1 );

		$this->subscriber->handleSubmissionReturned( new LearningEvent(
			event: LogEvent::SubmissionReturned, actorUserId: 999, entityId: '1'
		) );
	}

	public function test_attempt_graded_pushes_student_and_guardians(): void {
		$this->attempts->method( 'find' )->with( 9 )->willReturn( AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => 3, 'student_person_id' => 10, 'group_id' => 5,
			'attempt_number' => 1, 'started_at' => '2026-01-01 00:00:00', 'deadline_at' => '2026-01-01 01:00:00',
			'status' => 'graded', 'total_score' => 8, 'max_score' => 10,
		) ) );
		$this->notifications->method( 'studentUserId' )->with( 10 )->willReturn( 77 );
		$this->notifications->method( 'guardianUserIds' )->with( 10 )->willReturn( array( 88 ) );

		$this->notifications->expects( $this->once() )
			->method( 'pushFresh' )
			->with( array( 77, 88 ), NotificationType::AttemptGraded, 'attempt:9', $this->anything(), $this->anything(), 5, 'assessment_attempt', 9 );

		$this->subscriber->handleAttemptGraded( new LearningEvent(
			event: LogEvent::AttemptGraded, actorUserId: 999, entityId: '9'
		) );
	}

	public function test_attempt_graded_skips_when_no_recipients(): void {
		$this->attempts->method( 'find' )->willReturn( AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => 3, 'student_person_id' => 10, 'group_id' => 5,
			'attempt_number' => 1, 'started_at' => '2026-01-01 00:00:00', 'deadline_at' => '2026-01-01 01:00:00',
			'status' => 'graded',
		) ) );
		$this->notifications->method( 'studentUserId' )->willReturn( null );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array() );

		$this->notifications->expects( $this->never() )->method( 'pushFresh' );

		$this->subscriber->handleAttemptGraded( new LearningEvent(
			event: LogEvent::AttemptGraded, actorUserId: 999, entityId: '9'
		) );
	}

	public function test_submission_made_notifies_teacher_when_batch_aggregate_pending_review(): void {
		$this->submissions->method( 'find' )->with( 1 )->willReturn(
			$this->submission( array( 'status' => 'pending_review', 'task_id' => null ) )
		);
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( 55 );

		$this->posts->expects( $this->never() )->method( 'get' ); // pending_review достаточно, шаблон не резолвится

		$this->notifications->expects( $this->once() )
			->method( 'push' )
			->with( array( 55 ), NotificationType::ReviewNeeded, 'review:1', $this->anything(), $this->anything(), 5, 'submission', 1 );

		$this->subscriber->handleSubmissionMade( new LearningEvent(
			event: LogEvent::SubmissionMade, actorUserId: 10, entityId: '1'
		) );
	}

	public function test_submission_made_notifies_teacher_when_single_path_task_has_no_checker(): void {
		$this->submissions->method( 'find' )->willReturn(
			$this->submission( array( 'status' => 'submitted', 'task_id' => 42 ) )
		);
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( 55 );

		$post = new \WP_Post( array( 'ID' => 42 ) );
		$this->posts->method( 'get' )->with( 42 )->willReturn( $post );
		$this->templateResolver->method( 'resolveEnum' )->with( $post )->willReturn( TaskTemplate::FileAnswer );
		$this->checkerRegistry->method( 'has' )->with( TaskTemplate::FileAnswer )->willReturn( false );

		$this->notifications->expects( $this->once() )->method( 'push' );

		$this->subscriber->handleSubmissionMade( new LearningEvent(
			event: LogEvent::SubmissionMade, actorUserId: 10, entityId: '1'
		) );
	}

	public function test_submission_made_does_not_notify_when_task_has_checker(): void {
		$this->submissions->method( 'find' )->willReturn(
			$this->submission( array( 'status' => 'submitted', 'task_id' => 42 ) )
		);

		$post = new \WP_Post( array( 'ID' => 42 ) );
		$this->posts->method( 'get' )->willReturn( $post );
		$this->templateResolver->method( 'resolveEnum' )->willReturn( TaskTemplate::Standard );
		$this->checkerRegistry->method( 'has' )->with( TaskTemplate::Standard )->willReturn( true );

		$this->notifications->expects( $this->never() )->method( 'push' );

		$this->subscriber->handleSubmissionMade( new LearningEvent(
			event: LogEvent::SubmissionMade, actorUserId: 10, entityId: '1'
		) );
	}

	public function test_submission_made_resolves_task_from_work_when_task_id_is_null(): void {
		$this->submissions->method( 'find' )->willReturn(
			$this->submission( array( 'status' => 'submitted', 'task_id' => null, 'work_id' => 50 ) )
		);
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( 55 );

		$this->workManager->method( 'get' )->with( 50 )->willReturn( new WorkDTO(
			id: 50, subjectKey: 'math', title: 'Работа', workType: WorkType::from( 'homework' ),
			itemIds: array( 77 ), instructions: '', authorId: 1, status: 'publish',
		) );
		$post = new \WP_Post( array( 'ID' => 77 ) );
		$this->posts->method( 'get' )->with( 77 )->willReturn( $post );
		$this->templateResolver->method( 'resolveEnum' )->willReturn( TaskTemplate::FileAnswer );
		$this->checkerRegistry->method( 'has' )->willReturn( false );

		$this->notifications->expects( $this->once() )->method( 'push' );

		$this->subscriber->handleSubmissionMade( new LearningEvent(
			event: LogEvent::SubmissionMade, actorUserId: 10, entityId: '1'
		) );
	}

	public function test_submission_made_skips_when_lesson_missing(): void {
		$this->submissions->method( 'find' )->willReturn( $this->submission( array( 'status' => 'pending_review' ) ) );
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->notifications->expects( $this->never() )->method( 'push' );

		$this->subscriber->handleSubmissionMade( new LearningEvent(
			event: LogEvent::SubmissionMade, actorUserId: 10, entityId: '1'
		) );
	}

	public function test_submission_made_skips_when_no_effective_teacher(): void {
		$this->submissions->method( 'find' )->willReturn( $this->submission( array( 'status' => 'pending_review' ) ) );
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonTeacherUserId' )->willReturn( null );

		$this->notifications->expects( $this->never() )->method( 'push' );

		$this->subscriber->handleSubmissionMade( new LearningEvent(
			event: LogEvent::SubmissionMade, actorUserId: 10, entityId: '1'
		) );
	}

	public function test_recording_attached_pushes_video_uploaded_to_lesson_students(): void {
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array( 21, 22 ) );
		$this->notifications->method( 'lessonTopic' )->willReturn( 'Тема' );
		$this->notifications->method( 'groupName' )->willReturn( 'Группа' );

		$this->notifications->expects( $this->once() )
			->method( 'push' )
			->with( array( 21, 22 ), NotificationType::VideoUploaded, 'video:100', $this->anything(), $this->anything(), 5, 'group_lesson', 100 );

		$this->subscriber->handleRecordingAttached( 100 );
	}

	public function test_recording_attached_skips_when_no_students(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'lessonStudentUserIds' )->willReturn( array() );

		$this->notifications->expects( $this->never() )->method( 'push' );

		$this->subscriber->handleRecordingAttached( 100 );
	}

	public function test_recording_attached_skips_when_lesson_missing(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->notifications->expects( $this->never() )->method( 'push' );

		$this->subscriber->handleRecordingAttached( 999 );
	}
}
