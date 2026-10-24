<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Exam\ExamUnregisteredService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Незаписавшиеся ученики направления: отменившие и не записывавшиеся — в списке, записанные — нет.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamUnregisteredServiceTest extends TestCase {

	use ExamFixtures;

	public function test_denied_without_event_access(): void {
		$service = $this->service( false, array(), array() );

		$this->expectException( CodedException::class );
		$service->rows( 10, 7 );
	}

	public function test_lists_only_students_without_active_registration(): void {
		$service = $this->service( true, array( 1, 2, 3 ), array( 1 => 'confirmed', 2 => 'cancelled' ) );

		$rows = $service->rows( 10, 7 );

		self::assertSame( array( 2, 3 ), array_column( $rows, 'person_id' ) );
	}

	public function test_export_keeps_only_selected_unregistered(): void {
		$service = $this->service( true, array( 1, 2, 3 ), array( 1 => 'confirmed' ) );

		$rows = $service->exportRows( 10, 7, array( 3, 1 ) );

		self::assertCount( 1, $rows );
		self::assertSame( 'Не записан', $rows[0]['status'] );
	}

	/** @param list<int> $audience @param array<int, string> $registrations person_id → статус записи */
	private function service( bool $canManage, array $audience, array $registrations ): ExamUnregisteredService {
		$sessions = $this->createMock( ExamSessionRepository::class );
		$sessions->method( 'find' )->willReturn( $this->examSession() );
		$events = $this->createMock( ExamEventRepository::class );
		$events->method( 'find' )->willReturn( $this->examEvent() );
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( $canManage );
		$resolver = $this->createMock( ExamAudienceResolver::class );
		$resolver->method( 'studentPersonIds' )->willReturn( $audience );

		$participations = $this->createMock( ExamParticipationRepository::class );
		$participations->method( 'findByEventAndPerson' )->willReturnCallback(
			function ( int $eventId, int $personId ) use ( $registrations ) {
				if ( ! isset( $registrations[ $personId ] ) ) {
					return null;
				}
				return ExamParticipationDTO::fromArray( array( 'id' => $personId, 'event_id' => $eventId, 'participant_id' => $personId, 'audience' => 'student', 'created_at' => '2026-03-01 00:00:00' ) );
			}
		);
		$registrationRepo = $this->createMock( ExamRegistrationRepository::class );
		$registrationRepo->method( 'findByParticipation' )->willReturnCallback(
			function ( int $id ) use ( $registrations ) {
				return array_map(
					static fn ( string $status ): ExamRegistrationDTO => ExamRegistrationDTO::fromArray( array( 'id' => 1, 'participation_id' => $id, 'session_id' => 7, 'status' => $status, 'created_at' => '2026-03-01 00:00:00' ) ),
					array( $registrations[ $id ] )
				);
			}
		);
		$records = $this->createMock( StudentRecordRepository::class );
		$records->method( 'findActiveByStudentFirst' )->willReturnCallback(
			fn ( int $id ) => new \Inc\DTO\Enrollment\StudentRecordDTO(
				$id, $id, 0, 5, 'Фамилия' . $id, 'Имя', null, 'Школа', '9', null, null, null, null,
				\Inc\Enums\Enrollment\EnrollmentStatus::Active, '2026-01-01', null, null, null, null, '', ''
			)
		);
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findById' )->willReturn( (object) array( 'name' => 'КЕГЭ-1' ) );

		return new ExamUnregisteredService( $sessions, $events, $guard, $resolver, $participations, $registrationRepo, $records, $groups );
	}
}
