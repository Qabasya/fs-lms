<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Enrollment;

use DomainException;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\Enrollment\EnrollmentAccountsService;
use Inc\Services\Enrollment\TrialAccessService;
use Inc\Services\Person\PersonService;
use Inc\Services\Profile\NotificationService;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Продление временного доступа: срок заявки сдвигается, плитка «Временный доступ» держится на ключе физлица. */
class TrialAccessServiceTest extends TestCase {

	private ApplicationRepository&MockObject $applications;
	private StudentRecordRepository&MockObject $records;
	private LogEventDispatcherInterface&MockObject $logEvents;
	private TrialAccessService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->applications = $this->createMock( ApplicationRepository::class );
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->logEvents    = $this->createMock( LogEventDispatcherInterface::class );
		$clock              = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-20 12:00:00' );

		$this->service = new TrialAccessService(
			$this->applications,
			$this->records,
			$this->createMock( GroupsRepository::class ),
			$this->createMock( PersonService::class ),
			$this->createMock( PersonRepository::class ),
			$this->createMock( PersonDocumentsRepository::class ),
			$this->createMock( EnrollmentAccountsService::class ),
			$this->createMock( UserManager::class ),
			$this->createMock( PasswordGeneratorService::class ),
			$this->createMock( PiiCryptoService::class ),
			$this->createMock( JoinCodeService::class ),
			$clock,
			$this->logEvents,
			$this->createMock( NotificationService::class ),
		);
	}

	private function application( string $status, ?string $expiresAt ): ApplicationDTO {
		return ApplicationDTO::fromArray( array(
			'id' => 7, 'student_person_id' => 9005, 'status' => $status, 'created_at' => '2026-10-01 00:00:00',
			'updated_at' => '2026-10-01 00:00:00', 'expires_at' => $expiresAt,
		) );
	}

	private function trialRecord(): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array( 'id' => 31, 'student_person_id' => 9005, 'group_id' => 4, 'status' => 'active', 'is_trial' => 1 ) );
	}

	public function test_extend_adds_a_week_to_the_current_expiry(): void {
		$this->applications->method( 'find' )->willReturn( $this->application( 'pending_parent', '2026-10-25 00:00:00' ) );
		$this->records->method( 'findTrialByStudent' )->willReturn( array( $this->trialRecord() ) );

		$this->applications->expects( self::once() )->method( 'update' )
			->with( 7, array( 'expires_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-10-20 12:00:00' ) )
			->willReturn( true );
		$this->logEvents->expects( self::once() )->method( 'dispatch' )->with( LogEvent::TrialAccessExtended, self::anything() );

		self::assertSame( '2026-11-01 00:00:00', $this->service->extend( 7, 1 ) );
	}

	public function test_extend_counts_from_now_when_expiry_is_already_behind(): void {
		$this->applications->method( 'find' )->willReturn( $this->application( 'ready_for_review', '2026-10-01 00:00:00' ) );
		$this->records->method( 'findTrialByStudent' )->willReturn( array( $this->trialRecord() ) );

		self::assertSame( '2026-10-27 12:00:00', $this->service->extend( 7, 1 ) );
	}

	public function test_extend_is_refused_without_trial_access(): void {
		$this->applications->method( 'find' )->willReturn( $this->application( 'pending_parent', '2026-10-25 00:00:00' ) );
		$this->records->method( 'findTrialByStudent' )->willReturn( array() );
		$this->applications->expects( self::never() )->method( 'update' );

		$this->expectException( DomainException::class );
		$this->service->extend( 7, 1 );
	}

	public function test_extend_is_refused_for_a_closed_application(): void {
		$this->applications->method( 'find' )->willReturn( $this->application( 'expired', '2026-10-01 00:00:00' ) );
		$this->applications->expects( self::never() )->method( 'update' );

		$this->expectException( DomainException::class );
		$this->service->extend( 7, 1 );
	}

	public function test_notice_key_is_per_person(): void {
		self::assertSame( 'trial_access:9005', TrialAccessService::noticeKey( 9005 ) );
	}
}
