<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Profile\NotificationType;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Profile\NotificationService;
use Inc\Services\Profile\PinnedNotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Закреплённые уведомления: «выполнено» вычисляется по данным домена, а не по прочитанности плитки.
 */
class PinnedNotificationServiceTest extends TestCase {

	private NotificationRepository $notifications;
	private UserManager $users;
	private PersonRepository $persons;
	private SubmissionRepository $submissions;
	private ExamEventRepository $events;
	private ExamParticipationRepository $participations;
	private ExamTime $time;
	private StudentRecordRepository $records;
	private PinnedNotificationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->notifications  = $this->createMock( NotificationRepository::class );
		$this->users          = $this->createMock( UserManager::class );
		$this->persons        = $this->createMock( PersonRepository::class );
		$this->submissions    = $this->createMock( SubmissionRepository::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->time           = $this->createMock( ExamTime::class );
		$this->records        = $this->createMock( StudentRecordRepository::class );
		$this->time->method( 'nowUtc' )->willReturn( '2026-10-15 12:00:00' );

		$client = $this->createMock( NotificationService::class );
		$client->method( 'toClientArray' )->willReturnCallback(
			static fn( NotificationDTO $n ): array => array( 'id' => $n->id, 'type' => $n->type->value )
		);

		$this->service = new PinnedNotificationService(
			$this->notifications,
			$client,
			$this->users,
			$this->persons,
			$this->submissions,
			$this->events,
			$this->participations,
			$this->time,
			$this->records
		);
	}

	private function asStudent( string $role = 'lms_student' ): void {
		$user        = new \WP_User();
		$user->roles = array( $role );
		$this->users->method( 'find' )->willReturn( $user );
		$this->persons->method( 'findByWpUserId' )->willReturn( new PersonDTO(
			id: 42, wpUserId: 5, lastName: 'П', firstName: 'И', middleName: null,
			birthDate: null, isStudent: true, school: null, grade: null,
			expelledAt: null, createdAt: '2026-01-01 00:00:00', updatedAt: '2026-01-01 00:00:00'
		) );
	}

	private function row( int $id, NotificationType $type, int $entityId, array $payload = array() ): NotificationDTO {
		return NotificationDTO::fromArray( array(
			'id'                => $id,
			'recipient_user_id' => 5,
			'type'              => $type->value,
			'entity_type'       => 'group_lesson',
			'entity_id'         => $entityId,
			'payload'           => wp_json_encode( $payload ),
			'url'               => '/x',
			'created_at'        => '2026-10-15 10:00:00',
		) );
	}

	private function ids( array $items ): array {
		return array_column( $items, 'id' );
	}

	public function test_only_students_get_pinned_notifications(): void {
		$this->asStudent( UserRole::FSParent->value );
		$this->notifications->expects( self::never() )->method( 'listByTypes' );

		self::assertSame( array(), $this->service->forUser( 5 ) );
	}

	public function test_deadline_stays_pinned_until_work_is_submitted(): void {
		$this->asStudent();
		$this->notifications->method( 'listByTypes' )->willReturn( array(
			$this->row( 2, NotificationType::DeadlineMissed, 100, array( 'work_id' => 50 ) ),
			$this->row( 1, NotificationType::DeadlineMissed, 100, array( 'work_id' => 51 ) ),
		) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array( (object) array( 'workId' => 51 ) ) );

		self::assertSame( array( 2 ), $this->ids( $this->service->forUser( 5 ) ) );
	}

	public function test_missed_deadline_supersedes_soon_for_same_work(): void {
		$this->asStudent();
		$this->notifications->method( 'listByTypes' )->willReturn( array(
			$this->row( 2, NotificationType::DeadlineMissed, 100, array( 'work_id' => 50 ) ),
			$this->row( 1, NotificationType::DeadlineSoon, 100, array( 'work_id' => 50 ) ),
		) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );

		self::assertSame( array( 2 ), $this->ids( $this->service->forUser( 5 ) ) );
	}

	public function test_deadline_notification_without_work_id_is_not_pinned(): void {
		$this->asStudent();
		$this->notifications->method( 'listByTypes' )->willReturn( array(
			$this->row( 1, NotificationType::DeadlineSoon, 100 ),
		) );

		self::assertSame( array(), $this->service->forUser( 5 ) );
	}

	public function test_exam_opening_is_done_when_event_not_found(): void {
		$this->asStudent();
		$this->notifications->method( 'listByTypes' )->willReturn( array(
			$this->row( 1, NotificationType::ExamRegistrationOpened, 9 ),
		) );
		$this->events->method( 'find' )->willReturn( null );

		self::assertSame( array(), $this->service->forUser( 5 ) );
	}

	public function test_trial_access_stays_pinned_while_trial_record_exists(): void {
		$this->asStudent();
		$this->notifications->method( 'listByTypes' )->willReturn( array( $this->row( 7, NotificationType::TrialAccess, 0 ) ) );
		$this->records->method( 'findTrialByStudent' )->with( 42 )->willReturn( array( (object) array( 'id' => 31 ) ) );

		self::assertSame( array( 7 ), $this->ids( $this->service->forUser( 5 ) ) );
	}

	public function test_trial_access_is_not_pinned_after_full_enrollment(): void {
		$this->asStudent();
		$this->notifications->method( 'listByTypes' )->willReturn( array( $this->row( 7, NotificationType::TrialAccess, 0 ) ) );
		$this->records->method( 'findTrialByStudent' )->willReturn( array() );

		self::assertSame( array(), $this->ids( $this->service->forUser( 5 ) ) );
	}

	public function test_trial_access_is_looked_up_beyond_the_default_age_window(): void {
		$this->asStudent();
		$this->notifications->expects( self::exactly( 2 ) )->method( 'listByTypes' )
			->willReturnCallback( function ( int $userId, array $types, int $days = 14 ): array {
				self::assertContains( $days, array( 14, 90 ) );
				return array();
			} );

		$this->service->forUser( 5 );
	}
}
