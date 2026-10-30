<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Woo\WooCommerceManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestPublicSignupService;
use Inc\Services\Exam\GuestSignupViewService;
use Inc\Services\Person\ConsentService;
use Inc\Services\Security\FormGuardService;
use Inc\Services\Shared\CenterContactsService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/** Форма гостевой записи не предлагает сеанс, на который запись уже закрыта (день экзамена). */
class GuestSignupSessionCutoffTest extends TestCase {

	use ExamFixtures;

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	/** @return list<int> ID сеансов, показанных гостю в момент `$nowUtc` */
	private function offered( string $nowUtc ): array {
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';

		$sessions = $this->createMock( ExamSessionRepository::class );
		$sessions->method( 'findByEvent' )->willReturn( array(
			$this->examSession( array( 'id' => '1', 'scheduled_at' => '2026-10-30 08:00:00', 'planned_end_at' => '2026-10-30 12:00:00' ) ), // сегодня 11:00
			$this->examSession( array( 'id' => '2', 'scheduled_at' => '2026-10-31 08:00:00', 'planned_end_at' => '2026-10-31 12:00:00' ) ), // завтра
		) );
		$rooms = $this->createMock( RoomRepository::class );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( $nowUtc );

		$service = new GuestSignupViewService(
			$this->createMock( ExamEventRepository::class ),
			$sessions,
			$rooms,
			$this->createMock( AssessmentManager::class ),
			$this->createMock( ExamFormatRegistry::class ),
			$this->createMock( WooCommerceManager::class ),
			$this->createMock( PluginConfig::class ),
			$this->createMock( ConsentService::class ),
			$this->createMock( CenterContactsService::class ),
			$this->createMock( FormGuardService::class ),
			new ExamTime( $clock ),
			$this->createMock( PostManager::class ),
			$this->createMock( GuestPublicSignupService::class ),
		);

		$method = new \ReflectionMethod( $service, 'sessionCards' );
		$event  = $this->examEvent();

		return array_column( $method->invoke( $service, $event, $nowUtc, ExamSourceDTO::fromArray( array(
			'id' => '14', 'event_id' => '3', 'school_key' => 's', 'school_name' => 'Ш', 'school_name_normalized' => 'ш', 'grade' => '11', 'teacher_name' => 'Т',
			'label' => 'Ш · 11', 'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1',
			'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) ), 'id' );
	}

	public function test_day_before_both_sessions_are_offered(): void {
		self::assertSame( array( 1, 2 ), $this->offered( '2026-10-29 20:00:00' ) ); // 23:00 по Москве 29.10
	}

	public function test_from_local_midnight_of_exam_day_that_session_disappears(): void {
		self::assertSame( array( 2 ), $this->offered( '2026-10-29 21:00:00' ) ); // 00:00 по Москве 30.10
	}
}
