<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamScoreService;
use Inc\Services\Exam\ExamStatsService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Статистика: показатели на фикстуре (10 участий), единица подсчёта — участие, шкалы не смешиваются, разбор по заданиям.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamStatsServiceTest extends TestCase {

	use ExamFixtures;

	private ExamEventRepository&MockObject $events;
	private ExamParticipationRepository&MockObject $participations;
	private ExamRegistrationRepository&MockObject $registrations;
	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject $answers;
	private ExamScoreService&MockObject $scores;
	private ExamAccessGuard&MockObject $guard;
	private ExamStatsService $service;

	/** @var array<int, array<string, mixed>> Итог по ID попытки для summarize(). */
	private array $summaries = array();
	/** @var array<int, list<array<string, mixed>>> Единицы по ID попытки для units(). */
	private array $units = array();
	/** @var array<int, ExamRegistrationDTO[]> История записей по ID участия. */
	private array $history = array();
	/** @var ExamParticipationDTO[] */
	private array $partList = array();
	/** @var AttemptDTO[] */
	private array $attemptList = array();

	protected function setUp(): void {
		parent::setUp();

		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers        = $this->createMock( AssessmentAnswerRepository::class );
		$this->scores         = $this->createMock( ExamScoreService::class );
		$this->guard          = $this->createMock( ExamAccessGuard::class );

		$this->guard->method( 'canManageEvent' )->willReturn( true );
		$this->events->method( 'findBySubjectKey' )->willReturn( array( $this->examEvent( array( 'status' => 'published' ) ) ) );
		$this->participations->method( 'findByEvent' )->willReturnCallback( fn (): array => $this->partList );
		$this->registrations->method( 'findByParticipation' )->willReturnCallback( fn ( int $id ): array => $this->history[ $id ] ?? array() );
		$this->attempts->method( 'listByParticipations' )->willReturnCallback( fn (): array => $this->attemptList );
		$this->scores->method( 'summarize' )->willReturnCallback( fn ( AttemptDTO $a ): array => $this->summaries[ $a->id ] );
		$this->scores->method( 'units' )->willReturnCallback( fn ( AttemptDTO $a ): array => $this->units[ $a->id ] ?? array() );

		$assessments = $this->createMock( AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( $this->assessment() );
		$scoringUnits = $this->createMock( ScoringUnits::class );
		$scoringUnits->method( 'keysFor' )->willReturn( array( 11 => 'n:1', 12 => 'n:14', 13 => 'n:26' ) );
		$formats = $this->createMock( ExamFormatRegistry::class );
		$formats->method( 'for' )->willReturn( null );
		$time = $this->createStub( ExamTime::class );

		$this->service = $this->makeService( $assessments, $scoringUnits, $formats, $time );
	}

	private function makeService( AssessmentManager $assessments, ScoringUnits $units, ExamFormatRegistry $formats, ExamTime $time, ?ExamScoreService $scores = null, ?ExamAccessGuard $guard = null ): ExamStatsService {
		return new ExamStatsService(
			$this->events, $this->createMock( ExamSessionRepository::class ), $this->participations, $this->registrations, $this->attempts, $this->answers,
			$scores ?? $this->scores, $guard ?? $this->guard, $units, $assessments, $formats, $time
		);
	}

	private function assessment(): AssessmentDTO {
		return new AssessmentDTO(
			id: 500, subjectKey: 'inf_ege', title: 'Вариант', taskIds: array( 11, 12, 13 ), timeLimit: 235, attemptsAllowed: 1, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: AssessmentKind::EgeComputer, taskPoints: array( 11 => 1.0, 12 => 1.0, 13 => 2.0 ),
			scoreMap: array(), taskNumbers: array(),
		);
	}

	private function part( int $id, string $audience = 'student' ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => $id, 'event_id' => 3, 'participant_id' => $id, 'audience' => $audience, 'transfer_allowed' => 0, 'version' => 1,
			'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function reg( int $id, int $participationId, string $status, int $session = 7 ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => $participationId, 'session_id' => $session, 'status' => $status,
			'active_slot' => 'confirmed' === $status ? 1 : null, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	private function attempt( int $id, int $participationId, string $status, int $registrationId ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => $id, 'assessment_id' => 500, 'student_person_id' => 1, 'attempt_number' => 1, 'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 13:55:00',
			'status' => $status, 'exam_participation_id' => $participationId, 'exam_registration_id' => $registrationId,
		) );
	}

	private function ege( int $primary, int $secondary, bool $pending = false ): array {
		return array(
			'direction' => 'ege', 'primary' => $primary, 'primary_max' => 29, 'secondary' => $pending ? null : $secondary, 'secondary_max' => 100,
			'grade' => null, 'grade_max' => null, 'pending' => $pending, 'final' => ! $pending,
		);
	}

	private function oge( int $primary, int $grade ): array {
		return array( 'direction' => 'oge', 'primary' => $primary, 'primary_max' => 19, 'secondary' => null, 'secondary_max' => null, 'grade' => $grade, 'grade_max' => 5, 'pending' => false, 'final' => true );
	}

	/**
	 * 10 участий: 1–5 сдали (оценены), 6 сдал и ждёт ручной проверки, 7 в процессе, 8–9 неявки, 10 записан без попытки.
	 */
	private function givenTen(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->partList[] = $this->part( $i );
		}
		foreach ( range( 1, 6 ) as $i ) {
			$this->history[ $i ]   = array( $this->reg( 100 + $i, $i, 'confirmed' ) );
			$this->attemptList[]   = $this->attempt( 200 + $i, $i, 'graded', 100 + $i );
			$this->summaries[ 200 + $i ] = $i <= 5 ? $this->ege( 10 + $i, 60 + $i ) : $this->ege( 0, 0, true );
		}
		$this->history[7]    = array( $this->reg( 107, 7, 'confirmed' ) );
		$this->attemptList[] = $this->attempt( 207, 7, 'in_progress', 107 );
		$this->history[8]    = array( $this->reg( 108, 8, 'missed' ) );
		$this->history[9]    = array( $this->reg( 109, 9, 'missed' ) );
		$this->history[10]   = array( $this->reg( 110, 10, 'confirmed' ) );
	}

	public function test_kpi_counts_on_fixture(): void {
		$this->givenTen();

		$kpi = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) )['kpi'];

		self::assertSame( 10, $kpi['registered'] );
		self::assertSame( 7, $kpi['started'] );
		self::assertSame( 6, $kpi['submitted'] );
		self::assertSame( 2, $kpi['missed'] );
		self::assertSame( 1, $kpi['pending_review'] );
		self::assertSame( 5, $kpi['sample'] );
	}

	public function test_averages_use_only_final_results(): void {
		$this->givenTen();

		$kpi = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) )['kpi'];

		self::assertSame( 13.0, $kpi['avg_primary'] ); // (11+12+13+14+15)/5, работа на проверке не входит
		self::assertSame( 63.0, $kpi['avg_secondary'] );
	}

	public function test_transferred_registration_counts_participation_once(): void {
		$this->partList      = array( $this->part( 1 ) );
		$this->history[1]    = array( $this->reg( 101, 1, 'transferred', 7 ), $this->reg( 102, 1, 'confirmed', 8 ) );

		$kpi = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) )['kpi'];

		self::assertSame( 1, $kpi['registered'] );
	}

	public function test_cancelled_registration_without_attempt_is_not_registered(): void {
		$this->partList   = array( $this->part( 1 ) );
		$this->history[1] = array( $this->reg( 101, 1, 'cancelled' ) );

		$kpi = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) )['kpi'];

		self::assertSame( 0, $kpi['registered'] );
		self::assertSame( 0, $kpi['missed'] );
	}

	public function test_ege_has_secondary_average_and_no_grade(): void {
		$this->givenTen();

		$result = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) );

		self::assertNotNull( $result['kpi']['avg_secondary'] );
		self::assertNull( $result['kpi']['avg_grade'] );
		self::assertSame( 'ege', $result['format']['direction'] );
	}

	public function test_oge_has_grade_average_and_no_secondary(): void {
		$this->partList      = array( $this->part( 1 ), $this->part( 2 ) );
		$this->history       = array( 1 => array( $this->reg( 101, 1, 'confirmed' ) ), 2 => array( $this->reg( 102, 2, 'confirmed' ) ) );
		$this->attemptList   = array( $this->attempt( 201, 1, 'graded', 101 ), $this->attempt( 202, 2, 'graded', 102 ) );
		$this->summaries     = array( 201 => $this->oge( 15, 4 ), 202 => $this->oge( 12, 3 ) );

		$kpi = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) )['kpi'];

		self::assertSame( 3.5, $kpi['avg_grade'] );
		self::assertNull( $kpi['avg_secondary'] );
		self::assertSame( 13.5, $kpi['avg_primary'] );
	}

	public function test_scales_are_not_mixed(): void {
		$this->partList    = array( $this->part( 1 ), $this->part( 2 ) );
		$this->history     = array( 1 => array( $this->reg( 101, 1, 'confirmed' ) ), 2 => array( $this->reg( 102, 2, 'confirmed' ) ) );
		$this->attemptList = array( $this->attempt( 201, 1, 'graded', 101 ), $this->attempt( 202, 2, 'graded', 102 ) );
		$this->summaries   = array( 201 => $this->ege( 20, 80 ), 202 => $this->oge( 12, 3 ) );

		$result = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) );

		self::assertSame( 1, $result['kpi']['sample'] );
		self::assertSame( 20.0, $result['kpi']['avg_primary'] );
		self::assertTrue( $result['format']['mixed'] );
	}

	public function test_zero_sample_gives_null_averages(): void {
		$this->partList   = array( $this->part( 1 ) );
		$this->history[1] = array( $this->reg( 101, 1, 'confirmed' ) );

		$result = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) );

		self::assertSame( 0, $result['kpi']['sample'] );
		self::assertNull( $result['kpi']['avg_primary'] );
		self::assertNull( $result['kpi']['avg_secondary'] );
		self::assertNull( $result['kpi']['avg_grade'] );
	}

	public function test_session_filter(): void {
		$this->partList    = array( $this->part( 1 ), $this->part( 2 ), $this->part( 3 ) );
		$this->history     = array(
			1 => array( $this->reg( 101, 1, 'confirmed', 7 ) ),
			2 => array( $this->reg( 102, 2, 'confirmed', 8 ) ),
			3 => array( $this->reg( 103, 3, 'missed', 8 ) ),
		);
		$this->attemptList = array( $this->attempt( 202, 2, 'graded', 102 ) );
		$this->summaries   = array( 202 => $this->ege( 10, 50 ) );

		$kpi = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3, 'session_id' => 8 ) )['kpi'];

		self::assertSame( 2, $kpi['registered'] );
		self::assertSame( 1, $kpi['missed'], 'Для неявки сеанс — сеанс пропущенной записи.' );
		self::assertSame( 1, $kpi['submitted'] );
	}

	public function test_audience_filter_separates_guests(): void {
		$this->partList    = array( $this->part( 1 ), $this->part( 2, 'guest' ) );
		$this->history     = array( 1 => array( $this->reg( 101, 1, 'confirmed' ) ), 2 => array( $this->reg( 102, 2, 'confirmed' ) ) );

		$guests   = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3, 'audience' => 'guest' ) )['kpi'];
		$students = $this->service->overview( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3, 'audience' => 'student' ) )['kpi'];

		self::assertSame( 1, $guests['registered'] );
		self::assertSame( 1, $students['registered'] );
	}

	public function test_denied_for_foreign_event(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$service = $this->makeService( $this->createMock( AssessmentManager::class ), $this->createMock( ScoringUnits::class ), $this->createMock( ExamFormatRegistry::class ), $this->createStub( ExamTime::class ), null, $guard );

		try {
			$service->overview( 99, array( 'subject_key' => 'inf_ege', 'event_id' => 3 ) );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	// ── Разбор по заданиям ────────────────────────────────────────────────────────────────────────

	/** @return list<array<string, mixed>> */
	private function unitList( string $n1, string $n14, string $n26, float $s26 = 2.0 ): array {
		return array(
			array( 'unit_key' => 'n:1', 'number' => '1', 'score' => 'correct' === $n1 ? 1.0 : 0.0, 'max' => 1.0, 'status' => $n1, 'anchor' => '' ),
			array( 'unit_key' => 'n:14', 'number' => '14', 'score' => 'correct' === $n14 ? 1.0 : 0.0, 'max' => 1.0, 'status' => $n14, 'anchor' => '' ),
			array( 'unit_key' => 'n:26', 'number' => '26', 'score' => $s26, 'max' => 2.0, 'status' => $n26, 'anchor' => '' ),
		);
	}

	private function byTaskOn( array $attempts ): array {
		return $this->service->byTask( $attempts, $this->examEvent() );
	}

	public function test_fixture_matches_manual_count(): void {
		$a = array( $this->attempt( 1, 1, 'graded', 1 ), $this->attempt( 2, 2, 'graded', 2 ), $this->attempt( 3, 3, 'submitted', 3 ) );
		$this->units = array(
			1 => $this->unitList( 'correct', 'incorrect', 'correct', 2.0 ),
			2 => $this->unitList( 'correct', 'unanswered', 'partial', 1.0 ),
			3 => $this->unitList( 'incorrect', 'correct', 'pending', 0.0 ),
		);

		$rows = array_column( $this->byTaskOn( $a ), null, 'number' );

		// №1: верно, верно, неверно.
		self::assertSame( array( 3, 2, 0, 1, 0, 0, 67 ), array( $rows['1']['total'], $rows['1']['full'], $rows['1']['partial'], $rows['1']['incorrect'], $rows['1']['unanswered'], $rows['1']['pending'], $rows['1']['full_share'] ) );
		// №14: неверно, пропуск, верно.
		self::assertSame( array( 3, 1, 1, 1, 0 ), array( $rows['14']['total'], $rows['14']['full'], $rows['14']['incorrect'], $rows['14']['unanswered'], $rows['14']['pending'] ) );
		// №26: полный, частичный, на проверке; средний балл — по двум проверенным.
		self::assertSame( array( 3, 1, 1, 1 ), array( $rows['26']['total'], $rows['26']['full'], $rows['26']['partial'], $rows['26']['pending'] ) );
		self::assertSame( 1.5, $rows['26']['avg_score'] );
	}

	public function test_by_task_shares_sum_to_total_for_every_unit(): void {
		$a = array( $this->attempt( 1, 1, 'graded', 1 ), $this->attempt( 2, 2, 'graded', 2 ), $this->attempt( 3, 3, 'submitted', 3 ) );
		$this->units = array( 1 => $this->unitList( 'correct', 'incorrect', 'correct' ), 2 => $this->unitList( 'partial', 'unanswered', 'partial', 1.0 ), 3 => $this->unitList( 'pending', 'correct', 'pending', 0.0 ) );

		foreach ( $this->byTaskOn( $a ) as $row ) {
			self::assertSame( $row['total'], $row['full'] + $row['partial'] + $row['incorrect'] + $row['unanswered'] + $row['pending'], 'Задание ' . $row['number'] );
		}
	}

	public function test_unit_26_has_max_two_and_partial_bucket(): void {
		$this->units = array( 1 => $this->unitList( 'correct', 'correct', 'partial', 1.0 ) );

		$rows = array_column( $this->byTaskOn( array( $this->attempt( 1, 1, 'graded', 1 ) ) ), null, 'number' );

		self::assertSame( 2.0, $rows['26']['max'] );
		self::assertSame( 1, $rows['26']['partial'] );
		self::assertSame( 0, $rows['26']['full'] );
	}

	public function test_pending_units_are_excluded_from_average(): void {
		$this->units = array(
			1 => $this->unitList( 'correct', 'correct', 'correct', 2.0 ),
			2 => $this->unitList( 'correct', 'correct', 'pending', 0.0 ),
		);

		$rows = array_column( $this->byTaskOn( array( $this->attempt( 1, 1, 'graded', 1 ), $this->attempt( 2, 2, 'submitted', 2 ) ) ), null, 'number' );

		self::assertSame( 2.0, $rows['26']['avg_score'] );
	}

	public function test_in_progress_attempts_are_ignored(): void {
		$this->units = array( 1 => $this->unitList( 'correct', 'correct', 'correct' ) );

		self::assertSame( array(), $this->byTaskOn( array( $this->attempt( 1, 1, 'in_progress', 1 ) ) ) );
	}

	public function test_repeated_numbers_form_one_unit(): void {
		// units() уже сводит задания одного номера в одну единицу — строка статистики одна на номер.
		$this->units = array( 1 => array(
			array( 'unit_key' => 'n:26', 'number' => '26', 'score' => 2.0, 'max' => 2.0, 'status' => 'correct', 'anchor' => '' ),
		) );

		$rows = $this->byTaskOn( array( $this->attempt( 1, 1, 'graded', 1 ) ) );

		self::assertCount( 1, $rows );
		self::assertSame( 1, $rows[0]['total'] );
	}

	public function test_oge_has_sixteen_units_with_manual_max(): void {
		$format = new \Inc\DTO\Exam\ExamFormatDTO(
			kind: AssessmentKind::OgeComputer, direction: \Inc\Enums\Exam\ExamDirection::Oge, unitCount: 16, primaryMax: 19, secondaryMax: null,
			gradeMax: 5, durationMinutes: 150, scale: array(), unitMaxScores: array( 13 => 2, 14 => 2, 15 => 2, 16 => 2 ),
		);
		$formats = $this->createMock( ExamFormatRegistry::class );
		$formats->method( 'for' )->willReturn( $format );
		$service = $this->makeService( $this->createMock( AssessmentManager::class ), $this->createMock( ScoringUnits::class ), $formats, $this->createStub( ExamTime::class ) );
		$assessments = $this->createMock( AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( $this->assessment() );
		$service = $this->makeService( $assessments, $this->createMock( ScoringUnits::class ), $formats, $this->createStub( ExamTime::class ) );
		$this->units = array( 1 => array( array( 'unit_key' => 'n:13', 'number' => '13', 'score' => 1.0, 'max' => 2.0, 'status' => 'partial', 'anchor' => '' ) ) );

		$rows = array_column( $service->byTask( array( $this->attempt( 1, 1, 'graded', 1 ) ), $this->examEvent() ), null, 'number' );

		self::assertCount( 16, $rows );
		self::assertSame( 2.0, $rows['13']['max'] );
		self::assertSame( 0, $rows['5']['total'] );
	}

	public function test_verdicts_are_derived_from_answers_like_the_review_screen(): void {
		// Настоящий ExamScoreService над сырыми ответами: №1 — верно, №14 — пропуск, №26 — частично (1 из 2).
		$assessments = $this->createMock( AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( $this->assessment() );
		$scoringUnits = $this->createMock( ScoringUnits::class );
		$scoringUnits->method( 'keysFor' )->willReturn( array( 11 => 'n:1', 12 => 'n:14', 13 => 'n:26' ) );
		$scoringUnits->method( 'totals' )->willReturnCallback( static fn ( $a, array $perTask ): array => array(
			'score' => array_sum( array_column( $perTask, 'score' ) ),
			'max'   => array_sum( array_column( $perTask, 'max' ) ),
		) );
		$real = new ExamScoreService( $this->createMock( ExamFormatRegistry::class ), $assessments, $this->answers, $scoringUnits, $this->createMock( \Inc\Services\Assessment\SecondaryScoreService::class ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			new AttemptAnswerDTO( 1, 1, 11, '5', true, 1.0, 1.0, null, null ),
			new AttemptAnswerDTO( 2, 1, 12, '', false, 0.0, 1.0, null, null ),
			new AttemptAnswerDTO( 3, 1, 13, 'x', false, 1.0, 2.0, null, null ),
		) );
		$service = $this->makeService( $assessments, $scoringUnits, $this->createMock( ExamFormatRegistry::class ), $this->createStub( ExamTime::class ), $real );

		$rows = array_column( $service->byTask( array( $this->attempt( 1, 1, 'graded', 1 ) ), $this->examEvent() ), null, 'number' );

		self::assertSame( 1, $rows['1']['full'] );
		self::assertSame( 1, $rows['14']['unanswered'] );
		self::assertSame( 1, $rows['26']['partial'] );
		self::assertSame( 2.0, $rows['26']['max'] );
	}
}
