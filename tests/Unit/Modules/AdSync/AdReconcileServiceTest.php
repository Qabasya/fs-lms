<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\UserManager;
use Inc\Modules\AdSync\Services\AdReconcileService;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class AdReconcileServiceTest extends TestCase {

	private function person( int $wpUserId ): PersonDTO {
		return new PersonDTO(
			id: 42, wpUserId: $wpUserId, lastName: 'П', firstName: 'И', middleName: null,
			birthDate: null, isStudent: true, school: null, grade: null,
			expelledAt: null, createdAt: '2026-01-01 00:00:00', updatedAt: '2026-01-01 00:00:00'
		);
	}

	private function wpUser( string $login ): \WP_User {
		$u = new \WP_User();
		$u->user_login = $login;
		return $u;
	}

	private function appWith( string $username ): ApplicationDTO {
		return ApplicationDTO::fromArray( array(
			'id'               => 5,
			'status'           => 'pending_parent',
			'created_at'       => '2026-01-01 00:00:00',
			'updated_at'       => '2026-01-01 00:00:00',
			'student_data_enc' => 'ENC',
		) );
	}

	public function test_collects_active_records_and_live_applications(): void {
		$records = $this->createMock( StudentRecordRepository::class );
		$records->method( 'allActiveStudentPersonIds' )->willReturn( array( 42 ) );

		$persons = $this->createMock( PersonRepository::class );
		$persons->method( 'find' )->willReturn( $this->person( 99 ) );

		$users = $this->createMock( UserManager::class );
		$users->method( 'find' )->willReturn( $this->wpUser( 'i.petrov' ) );

		$apps = $this->createMock( ApplicationRepository::class );
		$apps->method( 'findByStatuses' )->willReturn( array( $this->appWith( 'a.sidorov' ) ) );

		$crypto = $this->createMock( PiiCryptoService::class );
		$crypto->method( 'decrypt' )->willReturn( (string) json_encode( array( 'username' => 'a.sidorov' ) ) );

		$service = new AdReconcileService( $records, $apps, $persons, $users, $crypto );
		$list    = $service->activeUsernames();

		sort( $list );
		self::assertSame( array( 'a.sidorov', 'i.petrov' ), $list );
	}

	public function test_deduplicates_usernames(): void {
		$records = $this->createMock( StudentRecordRepository::class );
		$records->method( 'allActiveStudentPersonIds' )->willReturn( array( 42 ) );

		$persons = $this->createMock( PersonRepository::class );
		$persons->method( 'find' )->willReturn( $this->person( 99 ) );

		$users = $this->createMock( UserManager::class );
		$users->method( 'find' )->willReturn( $this->wpUser( 'dup.user' ) );

		$apps = $this->createMock( ApplicationRepository::class );
		$apps->method( 'findByStatuses' )->willReturn( array( $this->appWith( 'dup.user' ) ) );

		$crypto = $this->createMock( PiiCryptoService::class );
		$crypto->method( 'decrypt' )->willReturn( (string) json_encode( array( 'username' => 'dup.user' ) ) );

		$service = new AdReconcileService( $records, $apps, $persons, $users, $crypto );

		self::assertSame( array( 'dup.user' ), $service->activeUsernames() );
	}

	public function test_skips_person_without_wp_user(): void {
		$records = $this->createMock( StudentRecordRepository::class );
		$records->method( 'allActiveStudentPersonIds' )->willReturn( array( 42 ) );

		$persons = $this->createMock( PersonRepository::class );
		$persons->method( 'find' )->willReturn( $this->person( 0 ) ); // нет привязки к WP-юзеру

		$apps = $this->createMock( ApplicationRepository::class );
		$apps->method( 'findByStatuses' )->willReturn( array() );

		$service = new AdReconcileService(
			$records, $apps, $persons,
			$this->createMock( UserManager::class ),
			$this->createMock( PiiCryptoService::class ),
		);

		self::assertSame( array(), $service->activeUsernames() );
	}
}
