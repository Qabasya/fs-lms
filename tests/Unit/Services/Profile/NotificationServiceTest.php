<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\EffectiveTeacherResolver;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase {

	private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject   $notifications;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject  $studentRecords;
	private PersonRepository&\PHPUnit\Framework\MockObject\MockObject         $personRepository;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject         $groups;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject            $lessons;
	private EffectiveTeacherResolver&\PHPUnit\Framework\MockObject\MockObject $effectiveTeacher;
	private NotificationService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_actions'] = array();

		$this->notifications   = $this->createMock( NotificationRepository::class );
		$this->studentRecords  = $this->createMock( StudentRecordRepository::class );
		$this->personRepository = $this->createMock( PersonRepository::class );
		$this->groups           = $this->createMock( GroupsRepository::class );
		$this->lessons          = $this->createMock( LessonManager::class );
		$this->effectiveTeacher = $this->createMock( EffectiveTeacherResolver::class );

		$this->service = new NotificationService(
			$this->notifications,
			$this->studentRecords,
			$this->personRepository,
			$this->groups,
			$this->lessons,
			$this->effectiveTeacher,
		);
	}

	private function person( int $id, ?int $wpUserId ): PersonDTO {
		return PersonDTO::fromArray( array(
			'id'         => $id,
			'wp_user_id' => $wpUserId,
			'last_name'  => 'Фамилия',
			'first_name' => 'Имя',
			'is_student' => false,
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	private function record( int $studentId, int $parentId, int $groupId = 1 ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id'                  => 1,
			'student_person_id'   => $studentId,
			'parent_person_id'    => $parentId,
			'group_id'            => $groupId,
			'snapshot_last_name'  => 'Фамилия',
			'snapshot_first_name' => 'Имя',
			'status'              => 'active',
			'enrolled_at'         => '2026-01-01 00:00:00',
			'created_at'          => '2026-01-01 00:00:00',
			'updated_at'          => '2026-01-01 00:00:00',
		) );
	}

	private function lesson( array $overrides = array() ): GroupLessonDTO {
		$base = array(
			'id'                 => 100,
			'group_id'           => 5,
			'lesson_id'          => null,
			'position'           => 0,
			'extra_work_ids'     => null,
			'scheduled_at'       => null,
			'ends_at'            => null,
			'is_pinned'          => 0,
			'teacher_user_id'    => null,
			'visibility'         => 'open',
			'opened_at'          => null,
			'homework_due_at'    => null,
			'allow_late'         => 1,
			'recording_url'      => null,
			'created_by_user_id' => null,
			'updated_by_user_id' => null,
			'kind'               => 'group',
			'status'             => 'scheduled',
			'student_person_id'  => null,
		);
		return GroupLessonDTO::fromArray( array_merge( $base, $overrides ) );
	}

	public function test_push_inserts_for_each_unique_valid_recipient_and_dispatches_hook_only_for_inserted(): void {
		$this->notifications->method( 'insertIgnore' )->willReturnMap( array(
			array( 7, 'work_graded', 'graded:1', array(), '', null, null, null, true ),
			array( 8, 'work_graded', 'graded:1', array(), '', null, null, null, false ),
		) );

		// Дубли и 0/отрицательные id — отфильтровываются перед вставкой.
		$this->service->push( array( 7, 7, 8, 0, -1 ), NotificationType::WorkGraded, 'graded:1' );

		self::assertCount( 1, $GLOBALS['_fs_test_actions'] );
		self::assertSame( 'fs_lms_notification_created', $GLOBALS['_fs_test_actions'][0]['hook'] );
		self::assertSame( array( 7, 'work_graded', array() ), $GLOBALS['_fs_test_actions'][0]['args'] );
	}

	public function test_push_fresh_retracts_before_pushing(): void {
		$calls = array();
		$this->notifications->method( 'deleteByDedupe' )->willReturnCallback(
			function () use ( &$calls ): void { $calls[] = 'retract'; }
		);
		$this->notifications->method( 'insertIgnore' )->willReturnCallback(
			function () use ( &$calls ): bool { $calls[] = 'push'; return true; }
		);

		$this->service->pushFresh( array( 7 ), NotificationType::WorkGraded, 'graded:1' );

		self::assertSame( array( 'retract', 'push' ), $calls );
	}

	public function test_retract_delegates_to_repository_with_filtered_ids(): void {
		$this->notifications->expects( $this->once() )
			->method( 'deleteByDedupe' )
			->with( array( 7, 8 ), 'att:1:2' );

		$this->service->retract( array( 7, 7, 8, 0 ), 'att:1:2' );
	}

	public function test_student_user_id_returns_wp_user_id(): void {
		$this->personRepository->method( 'find' )->with( 10 )->willReturn( $this->person( 10, 55 ) );

		self::assertSame( 55, $this->service->studentUserId( 10 ) );
	}

	public function test_student_user_id_returns_null_when_person_not_found(): void {
		$this->personRepository->method( 'find' )->willReturn( null );

		self::assertNull( $this->service->studentUserId( 10 ) );
	}

	public function test_guardian_user_ids_dedupes_across_active_records(): void {
		$this->studentRecords->method( 'findActiveByStudent' )->with( 10 )->willReturn( array(
			$this->record( 10, 20 ),
			$this->record( 10, 20 ), // тот же родитель во второй активной записи
		) );
		$this->personRepository->method( 'findByIds' )->with( array( 20 ) )->willReturn( array(
			20 => $this->person( 20, 99 ),
		) );

		self::assertSame( array( 99 ), $this->service->guardianUserIds( 10 ) );
	}

	public function test_guardian_user_ids_skips_person_without_wp_account(): void {
		$this->studentRecords->method( 'findActiveByStudent' )->willReturn( array( $this->record( 10, 20 ) ) );
		$this->personRepository->method( 'findByIds' )->willReturn( array( 20 => $this->person( 20, null ) ) );

		self::assertSame( array(), $this->service->guardianUserIds( 10 ) );
	}

	public function test_group_student_user_ids_collects_all_active_students(): void {
		$this->studentRecords->method( 'findActiveByGroupId' )->with( 5 )->willReturn( array(
			$this->record( 10, 20 ),
			$this->record( 11, 20 ),
		) );
		$this->personRepository->method( 'findByIds' )->with( array( 10, 11 ) )->willReturn( array(
			10 => $this->person( 10, 101 ),
			11 => $this->person( 11, 102 ),
		) );

		self::assertSame( array( 101, 102 ), $this->service->groupStudentUserIds( 5 ) );
	}

	public function test_lesson_student_user_ids_individual_returns_single_student(): void {
		$this->personRepository->method( 'find' )->with( 30 )->willReturn( $this->person( 30, 77 ) );

		$lesson = $this->lesson( array( 'kind' => 'individual', 'student_person_id' => 30 ) );

		self::assertSame( array( 77 ), $this->service->lessonStudentUserIds( $lesson ) );
	}

	public function test_lesson_student_user_ids_group_delegates_to_group_students(): void {
		$this->studentRecords->method( 'findActiveByGroupId' )->with( 5 )->willReturn( array( $this->record( 10, 20 ) ) );
		$this->personRepository->method( 'findByIds' )->willReturn( array( 10 => $this->person( 10, 101 ) ) );

		self::assertSame( array( 101 ), $this->service->lessonStudentUserIds( $this->lesson() ) );
	}

	public function test_lesson_teacher_user_id_delegates_to_effective_teacher_resolver(): void {
		$lesson = $this->lesson();
		$this->effectiveTeacher->expects( $this->once() )
			->method( 'forLesson' )
			->with( $lesson )
			->willReturn( 42 );

		self::assertSame( 42, $this->service->lessonTeacherUserId( $lesson ) );
	}

	public function test_lesson_topic_prefers_lesson_title_over_label(): void {
		$lesson = $this->lesson( array( 'lesson_id' => 7, 'label' => 'Запасной ярлык' ) );
		$this->lessons->method( 'get' )->with( 7 )->willReturn( new \Inc\DTO\Course\LessonDTO(
			id: 7, subjectKey: 'math', topic: 'Квадратные уравнения', steps: array(), authorId: 1, status: 'publish'
		) );

		self::assertSame( 'Квадратные уравнения', $this->service->lessonTopic( $lesson ) );
	}

	public function test_lesson_topic_falls_back_to_label_without_lesson_id(): void {
		$lesson = $this->lesson( array( 'lesson_id' => null, 'label' => 'Резервное занятие' ) );

		self::assertSame( 'Резервное занятие', $this->service->lessonTopic( $lesson ) );
	}

	public function test_group_name_reads_from_groups_repository(): void {
		$this->groups->method( 'findById' )->with( 5 )->willReturn( (object) array( 'id' => 5, 'name' => 'ОГЭ-1' ) );

		self::assertSame( 'ОГЭ-1', $this->service->groupName( 5 ) );
	}

	public function test_group_name_empty_when_group_not_found(): void {
		$this->groups->method( 'findById' )->willReturn( null );

		self::assertSame( '', $this->service->groupName( 999 ) );
	}

	public function test_student_snapshot_name_matches_record_in_given_group(): void {
		$this->studentRecords->method( 'findActiveByStudent' )->with( 10 )->willReturn( array(
			StudentRecordDTO::fromArray( array(
				'id' => 1, 'student_person_id' => 10, 'parent_person_id' => 20, 'group_id' => 5,
				'snapshot_last_name' => 'Иванов', 'snapshot_first_name' => 'Иван',
				'status' => 'active', 'enrolled_at' => '2026-01-01 00:00:00',
				'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			) ),
			StudentRecordDTO::fromArray( array(
				'id' => 2, 'student_person_id' => 10, 'parent_person_id' => 20, 'group_id' => 6,
				'snapshot_last_name' => 'Другая', 'snapshot_first_name' => 'Фамилия',
				'status' => 'active', 'enrolled_at' => '2026-01-01 00:00:00',
				'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			) ),
		) );

		self::assertSame( 'Иванов Иван', $this->service->studentSnapshotName( 10, 5 ) );
	}

	public function test_student_snapshot_name_empty_without_matching_group(): void {
		$this->studentRecords->method( 'findActiveByStudent' )->willReturn( array() );

		self::assertSame( '', $this->service->studentSnapshotName( 10, 5 ) );
	}

	public function test_lesson_work_url_appends_step_when_resolvable(): void {
		$lesson = $this->lesson( array( 'lesson_id' => 7 ) );
		$this->lessons->method( 'get' )->with( 7 )->willReturn( new \Inc\DTO\Course\LessonDTO(
			id: 7,
			subjectKey: 'math',
			topic: 'Тема',
			steps: array( new \Inc\DTO\Course\StepDTO(
				key: 'step-3',
				type: \Inc\Enums\Course\StepType::Work,
				payload: array( 'ref' => 55 ),
			) ),
			authorId: 1,
			status: 'publish',
		) );

		$url = $this->service->lessonWorkUrl( $lesson, 55 );

		self::assertStringContainsString( 'gid=5', $url );
		self::assertStringContainsString( 'gl=100', $url );
		self::assertStringContainsString( 'step=step-3', $url );
	}

	public function test_lesson_work_url_without_step_when_lesson_has_no_content(): void {
		$lesson = $this->lesson( array( 'lesson_id' => null ) );

		$url = $this->service->lessonWorkUrl( $lesson, 55 );

		self::assertStringContainsString( 'gid=5', $url );
		self::assertStringNotContainsString( 'step=', $url );
	}

	public function test_to_client_array_renders_work_graded_body_with_score(): void {
		$dto = NotificationDTO::fromArray( array(
			'id'                => 1,
			'recipient_user_id' => 7,
			'type'              => 'work_graded',
			'group_id'          => null,
			'entity_type'       => null,
			'entity_id'         => null,
			'payload'           => wp_json_encode( array( 'topic' => 'Задача 3', 'group_name' => 'ОГЭ-1', 'score' => 4, 'max_score' => 5 ) ),
			'url'               => '/group/?gid=5&gl=100',
			'created_at'        => '2026-07-27 10:00:00',
			'seen_at'           => null,
			'read_at'           => null,
		) );

		$out = $this->service->toClientArray( $dto );

		self::assertSame( 'work_graded', $out['type'] );
		self::assertSame( 'ok', $out['tone'] );
		self::assertSame( 'Работа проверена', $out['title'] );
		self::assertStringContainsString( 'Задача 3', $out['body'] );
		self::assertStringContainsString( '4', $out['body'] );
		self::assertStringContainsString( '5', $out['body'] );
		self::assertTrue( $out['unread'] );
	}

	public function test_to_client_array_renders_review_needed_body_with_student_name(): void {
		$dto = NotificationDTO::fromArray( array(
			'id'                => 2,
			'recipient_user_id' => 9,
			'type'              => 'review_needed',
			'group_id'          => null,
			'entity_type'       => null,
			'entity_id'         => null,
			'payload'           => wp_json_encode( array( 'student_name' => 'Иванов Иван', 'topic' => 'Эссе' ) ),
			'url'               => '/profile/?screen=summary',
			'created_at'        => '2026-07-27 10:00:00',
			'seen_at'           => '2026-07-27 11:00:00',
			'read_at'           => null,
		) );

		$out = $this->service->toClientArray( $dto );

		self::assertSame( 'warn', $out['tone'] );
		self::assertStringContainsString( 'Иванов Иван', $out['body'] );
		self::assertStringContainsString( 'Эссе', $out['body'] );
		self::assertTrue( $out['unread'] );
	}
}
