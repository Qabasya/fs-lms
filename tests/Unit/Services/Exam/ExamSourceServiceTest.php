<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamSourceService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Источники приглашений: класс строго соответствует направлению проведения, ссылка выдаётся один раз, перевыпуск заменяет ключ.
 * Открытый ключ существует только в ответе выдачи и перевыпуска.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamSourceServiceTest extends TestCase {

	use ExamFixtures;

	private const ACTOR  = 10;
	private const EVENT  = 3;
	private const SOURCE = 14;
	private const KEY    = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

	private ExamSourceRepository&MockObject $sources;
	private ExamEventRepository&MockObject $events;
	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamAccessGuard&MockObject $guard;
	private ExamAccessTokenService&MockObject $tokens;
	private LogEventDispatcherInterface&MockObject $log;
	private ExamSourceService $service;
	private \Inc\Services\Exam\GuestSessionService&\PHPUnit\Framework\MockObject\MockObject $guestSessions;
	private object $db;
	private \wpdb $originalWpdb;

	/** @var list<array{id: int, data: array<string, mixed>, version: int}> */
	private array $updates = array();
	/** @var array<string, mixed> */
	private array $inserted = array();
	private AssessmentKind $kind = AssessmentKind::EgeComputer;
	private ExamDirection $direction = ExamDirection::Ege;
	private ?int $defaultAssessment = 500;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$this->originalWpdb           = $GLOBALS['wpdb'];
		$this->db                     = new class() extends \wpdb {
			/** @var string[] */
			public array $log = array();

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $this->db;

		$this->sources      = $this->createMock( ExamSourceRepository::class );
		$this->events       = $this->createMock( ExamEventRepository::class );
		$this->applications = $this->createMock( ExamGuestApplicationRepository::class );
		$this->guard        = $this->createMock( ExamAccessGuard::class );
		$this->tokens       = $this->createMock( ExamAccessTokenService::class );
		$this->guestSessions = $this->createMock( \Inc\Services\Exam\GuestSessionService::class );
		$this->log          = $this->createMock( LogEventDispatcherInterface::class );

		$this->guard->method( 'canManageEventGuests' )->willReturn( true );
		$this->events->method( 'find' )->willReturnCallback( fn () => $this->examEvent( array(
			'status' => 'published', 'guest_registration_enabled' => '1', 'default_assessment_id' => null === $this->defaultAssessment ? null : (string) $this->defaultAssessment,
			'registration_closes_at' => '2026-03-09 15:00:00',
		) ) );
		$this->sources->method( 'find' )->willReturnCallback( fn (): ExamSourceDTO => $this->source() );
		$this->sources->method( 'findForUpdate' )->willReturnCallback( function (): ExamSourceDTO {
			$this->db->log[] = 'source-lock';
			return $this->source();
		} );
		$this->sources->method( 'insert' )->willReturnCallback( function ( array $row ): int {
			$this->inserted = $row;
			return self::SOURCE;
		} );
		$this->sources->method( 'update' )->willReturnCallback( function ( int $id, array $data, int $version ): bool {
			$this->updates[] = array( 'id' => $id, 'data' => $data, 'version' => $version );
			return true;
		} );

		$this->service = $this->makeService();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->originalWpdb;
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	private function makeService( ?ExamAccessGuard $guard = null ): ExamSourceService {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( static fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? '2026-03-01 07:00:00' : '2026-03-01 10:00:00' );

		$assessments = $this->createMock( AssessmentManager::class );
		$assessments->method( 'get' )->willReturnCallback( fn (): AssessmentDTO => new AssessmentDTO(
			id: 500, subjectKey: 'inf_ege', title: 'Вариант', taskIds: array(), timeLimit: 235, attemptsAllowed: 1, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: $this->kind, taskPoints: array(), scoreMap: array()
		) );
		$formats = $this->createMock( ExamFormatRegistry::class );
		$formats->method( 'for' )->willReturnCallback( fn (): ExamFormatDTO => new ExamFormatDTO(
			kind: $this->kind, direction: $this->direction, unitCount: 27, primaryMax: 29, secondaryMax: 100, gradeMax: 0, durationMinutes: 235, scale: array(), unitMaxScores: array()
		) );

		return new ExamSourceService(
			$this->sources, $this->events, $this->applications, $guard ?? $this->guard, $this->tokens, $formats, $assessments, $this->log, new ExamTime( $clock ), $this->guestSessions
		);
	}

	/** @param array<string, mixed> $override */
	private function source( array $override = array() ): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array_merge( array(
			'id' => (string) self::SOURCE, 'event_id' => (string) self::EVENT, 'school_key' => null, 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5',
			'grade' => '11', 'teacher_name' => 'Иванова И. И.', 'label' => 'Школа 5, 11 класс', 'is_active' => '1', 'key_generation' => '0', 'key_revoked_at' => null,
			'created_by_user_id' => '10', 'version' => '2', 'created_at' => '2026-02-20 10:00:00', 'updated_at' => '2026-02-20 10:00:00',
		), $override ) );
	}

	/** @return array<string, mixed> */
	private function input( array $override = array() ): array {
		return array_merge( array( 'school_name' => '  Школа   5 ', 'teacher_name' => 'Иванова И. И.', 'grade' => 11 ), $override );
	}

	private function assertRefusal( ErrorCode $code, callable $call, ?string $message = null ): void {
		try {
			$call();
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( $code, $e->errorCode );
			if ( null !== $message ) {
				self::assertSame( $message, $e->getMessage() );
			}
		}
	}

	// ---- создание и правка ---------------------------------------------------------------------------------------------------------

	public function test_save_creates_source_with_normalized_school_and_audit_event(): void {
		$this->log->expects( self::once() )->method( 'dispatch' )->with(
			LogEvent::ExamSourceCreated,
			self::callback( static fn ( EntityChangedEvent $e ): bool => OperationType::Create === $e->operation && EntityType::ExamSource === $e->entityType && self::SOURCE === $e->entityId && self::ACTOR === $e->actorUserId )
		);

		$this->service->save( self::ACTOR, self::EVENT, $this->input(), null, null );

		self::assertSame( 'Школа 5', $this->inserted['school_name'], 'Края обрезаны, пробелы схлопнуты.' );
		self::assertSame( 'школа 5', $this->inserted['school_name_normalized'] );
		self::assertSame( 11, $this->inserted['grade'] );
		self::assertSame( self::ACTOR, $this->inserted['created_by_user_id'] );
		self::assertSame( self::EVENT, $this->inserted['event_id'] );
	}

	public function test_grade_must_match_event_direction(): void {
		// Проведение ЕГЭ (11 класс): источник 9 класса отклоняется.
		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'grade' => 9 ) ), null, null ), 'Класс не соответствует направлению проведения.' );
		self::assertSame( array(), $this->inserted );
	}

	public function test_oge_event_takes_grade_nine(): void {
		$this->kind      = AssessmentKind::OgeComputer;
		$this->direction = ExamDirection::Oge;

		$this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'grade' => 9 ) ), null, null );
		self::assertSame( 9, $this->inserted['grade'] );

		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'grade' => 11 ) ), null, null ), 'Класс не соответствует направлению проведения.' );
	}

	public function test_grade_other_than_9_or_11_rejected(): void {
		foreach ( array( 0, 8, 10, 12 ) as $grade ) {
			$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'grade' => $grade ) ), null, null ), 'Класс — 9 или 11.' );
		}
	}

	public function test_school_and_teacher_are_required(): void {
		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'school_name' => '  ' ) ), null, null ), 'Укажите школу.' );
		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'teacher_name' => '' ) ), null, null ), 'Укажите ФИО преподавателя.' );
	}

	public function test_event_without_main_variant_cannot_get_a_source(): void {
		$this->defaultAssessment = null;

		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input(), null, null ), 'Выберите основной вариант проведения: от него зависит класс.' );
	}

	public function test_editing_source_does_not_touch_existing_applications(): void {
		$this->applications->expects( self::never() )->method( 'update' );
		$this->applications->expects( self::never() )->method( 'insert' );

		$this->service->save( self::ACTOR, self::EVENT, $this->input( array( 'teacher_name' => 'Петрова П. П.' ) ), self::SOURCE, 2 );

		self::assertSame( 'Петрова П. П.', $this->updates[0]['data']['teacher_name'] );
		self::assertSame( 2, $this->updates[0]['version'] );
	}

	public function test_edit_with_stale_version_fails(): void {
		$this->assertRefusal( ErrorCode::ExamStale, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input(), self::SOURCE, 1 ) );
		self::assertSame( array(), $this->updates );
	}

	public function test_edit_without_version_fails_as_stale(): void {
		$this->assertRefusal( ErrorCode::ExamStale, fn () => $this->service->save( self::ACTOR, self::EVENT, $this->input(), self::SOURCE, null ) );
	}

	public function test_source_of_another_event_cannot_be_edited_through_this_one(): void {
		$sources = $this->createMock( ExamSourceRepository::class );
		$sources->method( 'findForUpdate' )->willReturn( $this->source( array( 'event_id' => '99' ) ) );
		$this->sources = $sources;

		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $this->makeService()->save( self::ACTOR, self::EVENT, $this->input(), self::SOURCE, 2 ) );
	}

	// ---- ссылки --------------------------------------------------------------------------------------------------------------------

	public function test_issue_link_returns_url_with_key_and_bumps_generation(): void {
		$this->tokens->method( 'hasActive' )->willReturn( false );
		$this->tokens->expects( self::once() )->method( 'issue' )->with( ExamTokenPurpose::Invitation, self::SOURCE, self::ACTOR, '2026-03-09 15:00:00' )->willReturn( self::KEY );
		$this->sources->expects( self::once() )->method( 'bumpGeneration' )->with( self::SOURCE )->willReturn( 1 );
		$this->sources->expects( self::once() )->method( 'setKeyRevokedAt' )->with( self::SOURCE, null );

		$url = $this->service->issueLink( self::ACTOR, self::SOURCE );

		self::assertSame( 'http://example.com/exam-signup/?k=' . self::KEY, $url );
		self::assertSame( array( 'START TRANSACTION', 'source-lock', 'COMMIT' ), $this->db->log );
	}

	public function test_issue_link_twice_is_rejected_use_reissue(): void {
		$this->tokens->method( 'hasActive' )->willReturn( true );
		$this->tokens->expects( self::never() )->method( 'issue' );
		$this->sources->expects( self::never() )->method( 'bumpGeneration' );

		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->service->issueLink( self::ACTOR, self::SOURCE ), 'Ссылка уже создана: чтобы заменить её, перевыпустите.' );
	}

	public function test_reissue_revokes_old_key_and_writes_audit_event(): void {
		// Прежний ключ отзывается внутри issue(); поколение растёт; отметка отзыва снимается.
		$this->tokens->expects( self::once() )->method( 'issue' )->willReturn( self::KEY );
		$this->sources->expects( self::once() )->method( 'bumpGeneration' )->with( self::SOURCE );
		$this->sources->expects( self::once() )->method( 'setKeyRevokedAt' )->with( self::SOURCE, null );
		$this->log->expects( self::once() )->method( 'dispatch' )->with(
			LogEvent::ExamSourceUpdated,
			self::callback( static fn ( EntityChangedEvent $e ): bool => self::ACTOR === $e->actorUserId && self::SOURCE === $e->entityId && str_contains( (string) $e->oldLabel, 'перевыпущена' ) )
		);

		$url = $this->service->reissueLink( self::ACTOR, self::SOURCE );

		self::assertStringContainsString( '?k=' . self::KEY, $url );
	}

	public function test_audit_event_never_contains_the_key(): void {
		$captured = null;
		$this->tokens->method( 'issue' )->willReturn( self::KEY );
		$this->log->method( 'dispatch' )->willReturnCallback( function ( LogEvent $event, EntityChangedEvent $payload ) use ( &$captured ): void {
			$captured = $payload;
		} );

		$this->service->reissueLink( self::ACTOR, self::SOURCE );

		self::assertNotNull( $captured );
		self::assertStringNotContainsString( self::KEY, (string) json_encode( (array) $captured ) );
	}

	public function test_revoke_sets_key_revoked_at(): void {
		$this->tokens->expects( self::once() )->method( 'revoke' )->with( ExamTokenPurpose::Invitation, self::SOURCE )->willReturn( 1 );
		$this->sources->expects( self::once() )->method( 'setKeyRevokedAt' )->with( self::SOURCE, '2026-03-01 07:00:00' );
		$this->tokens->expects( self::never() )->method( 'issue' );
		$this->applications->expects( self::never() )->method( 'update' );

		$this->service->revokeLink( self::ACTOR, self::SOURCE );
	}

	public function test_set_active_updates_flag_under_lock(): void {
		$this->service->setActive( self::ACTOR, self::SOURCE, false );

		self::assertSame( array( 'is_active' => 0 ), $this->updates[0]['data'] );
		self::assertSame( 2, $this->updates[0]['version'] );
	}

	public function test_set_active_to_same_value_writes_nothing_but_audit(): void {
		$this->service->setActive( self::ACTOR, self::SOURCE, true );

		self::assertSame( array(), $this->updates );
	}

	// ---- права ---------------------------------------------------------------------------------------------------------------------

	public function test_requires_manage_exam_guests_and_event_scope(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEventGuests' )->willReturn( false );
		$this->sources->expects( self::never() )->method( 'insert' );
		$this->tokens->expects( self::never() )->method( 'issue' );
		$service = $this->makeService( $guard );

		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $service->save( 99, self::EVENT, $this->input(), null, null ) );
		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $service->list( 99, self::EVENT ) );
		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $service->issueLink( 99, self::SOURCE ) );
		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $service->reissueLink( 99, self::SOURCE ) );
		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $service->revokeLink( 99, self::SOURCE ) );
		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $service->setActive( 99, self::SOURCE, false ) );
	}

	public function test_unknown_source_is_refused(): void {
		$sources = $this->createMock( ExamSourceRepository::class );
		$sources->method( 'find' )->willReturn( null );
		$this->sources = $sources;

		$this->assertRefusal( ErrorCode::ExamAccess, fn () => $this->makeService()->issueLink( self::ACTOR, 404 ), 'Источник не найден.' );
	}

	// ---- список --------------------------------------------------------------------------------------------------------------------

	public function test_list_has_no_key_or_hash_and_reports_link_state(): void {
		$this->sources->method( 'listByEvent' )->willReturn( array( $this->source( array( 'key_generation' => '3' ) ) ) );
		$this->tokens->method( 'hasActive' )->willReturn( true );
		$this->applications->method( 'countHeldBySource' )->with( self::SOURCE )->willReturn( 2 );

		$rows = $this->service->list( self::ACTOR, self::EVENT );

		self::assertSame( array( 'id', 'school_name', 'teacher_name', 'grade', 'is_active', 'has_link', 'generation', 'active_holds', 'version' ), array_keys( $rows[0] ) );
		self::assertTrue( $rows[0]['has_link'] );
		self::assertSame( 3, $rows[0]['generation'] );
		self::assertSame( 2, $rows[0]['active_holds'] );
	}
}
