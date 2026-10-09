<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Services\Assessment\ArchiveTaskNumber;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Services\Assessment\SecondaryScoreService;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamScoreService;
use PHPUnit\Framework\TestCase;

class ExamScoreServiceTest extends TestCase {

	private const ASSESSMENT_ID = 5;

	/** @var AssessmentManager&\PHPUnit\Framework\MockObject\MockObject */
	private AssessmentManager $assessments;

	/** @var AssessmentAnswerRepository&\PHPUnit\Framework\MockObject\MockObject */
	private AssessmentAnswerRepository $answers;

	private ExamScoreService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_filter_returns'] = array();

		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->answers     = $this->createMock( AssessmentAnswerRepository::class );
		$terms             = $this->createMock( TermManager::class );
		$terms->method( 'getPostTerms' )->willReturn( array() );

		$this->service = new ExamScoreService(
			new ExamFormatRegistry(),
			$this->assessments,
			$this->answers,
			new ScoringUnits( $terms, new ArchiveTaskNumber() ),
			new SecondaryScoreService(),
		);
	}

	private function assessment( AssessmentKind $kind, array $taskNumbers = array() ): AssessmentDTO {
		return new AssessmentDTO(
			id: self::ASSESSMENT_ID, subjectKey: 'inf', title: 'Вариант', taskIds: array_keys( $taskNumbers ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(), taskNumbers: $taskNumbers,
		);
	}

	private function attempt( float $total, float $max ): AttemptDTO {
		return new AttemptDTO(
			id: 11, assessmentId: self::ASSESSMENT_ID, studentPersonId: 3, groupId: null, attemptNumber: 1,
			startedAt: '2026-03-10 10:00:00', deadlineAt: '2026-03-10 13:55:00', submittedAt: '2026-03-10 12:00:00',
			status: AttemptStatus::Graded, totalScore: $total, maxScore: $max, gradedByUserId: null,
			createdAt: '2026-03-10 10:00:00', updatedAt: '2026-03-10 12:00:00', examParticipationId: 7, examRegistrationId: 2,
		);
	}

	/** @param array<string, mixed>|null $snapshot Снимок варианта; null — без снимка. */
	private function event( ?array $snapshot ): ExamEventDTO {
		return ExamEventDTO::fromArray( array(
			'id' => 1, 'subject_key' => 'inf', 'title' => 'Экзамен', 'owner_user_id' => 1, 'status' => 'published',
			'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'guest_registration_enabled' => 0, 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			'variant_snapshot' => null === $snapshot ? null : json_encode( array( (string) self::ASSESSMENT_ID => $snapshot ) ),
		) );
	}

	/** @return array<string, mixed> */
	private function egeSnapshot(): array {
		return array(
			'kind' => 'ege_computer', 'primary_max' => 29, 'secondary_max' => 100,
			'scale' => array( 0 => 0, 18 => 72, 22 => 83, 29 => 100 ),
		);
	}

	/** @return array<string, mixed> */
	private function ogeSnapshot(): array {
		return array(
			'kind' => 'oge_computer', 'primary_max' => 21, 'secondary_max' => null,
			'scale' => array( 0 => 2, 8 => 3, 15 => 4, 19 => 5 ),
		);
	}

	private function formatDto( AssessmentKind $kind, array $scale ): ExamFormatDTO {
		return new ExamFormatDTO(
			kind: $kind, direction: AssessmentKind::OgeComputer === $kind ? ExamDirection::Oge : ExamDirection::Ege,
			unitCount: 27, primaryMax: AssessmentKind::OgeComputer === $kind ? 21 : 29,
			secondaryMax: AssessmentKind::OgeComputer === $kind ? null : 100, gradeMax: AssessmentKind::OgeComputer === $kind ? 5 : 0,
			durationMinutes: 235, scale: $scale, unitMaxScores: array(),
		);
	}

	public function test_kege_18_primary_is_72_secondary(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );

		$result = $this->service->summarize( $this->attempt( 18.0, 29.0 ), $this->event( $this->egeSnapshot() ) );

		self::assertSame( 18, $result['primary'] );
		self::assertSame( 72, $result['secondary'] );
		self::assertSame( 'ege', $result['direction'] );
	}

	public function test_kege_22_primary_is_83_secondary(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );

		self::assertSame( 83, $this->service->summarize( $this->attempt( 22.0, 29.0 ), $this->event( $this->egeSnapshot() ) )['secondary'] );
	}

	public function test_kege_max_is_29_and_100(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );

		$result = $this->service->summarize( $this->attempt( 29.0, 29.0 ), $this->event( $this->egeSnapshot() ) );

		self::assertSame( 29, $result['primary_max'] );
		self::assertSame( 100, $result['secondary_max'] );
		self::assertSame( 100, $result['secondary'] );
		self::assertNull( $result['grade'] );
		self::assertTrue( $result['final'] );
	}

	public function test_kege_task_26_and_27_have_max_2(): void {
		$assessment = $this->assessment( AssessmentKind::EgeComputer, array( 26 => '26', 27 => '27' ) );
		$this->assessments->method( 'get' )->willReturn( $assessment );

		$units = $this->service->units( $this->attempt( 4.0, 4.0 ), array(
			array( 'task_id' => 26, 'unit_key' => 'n:26', 'number' => '26', 'anchor' => 'a26', 'verdict' => 'correct', 'score' => 2.0, 'max_score' => 2.0 ),
			array( 'task_id' => 27, 'unit_key' => 'n:27', 'number' => '27', 'anchor' => 'a27', 'verdict' => 'correct', 'score' => 2.0, 'max_score' => 2.0 ),
		) );

		self::assertCount( 2, $units );
		self::assertSame( 2.0, $units[0]['max'] );
		self::assertSame( 2.0, $units[1]['max'] );
	}

	public function test_repeated_numbers_count_as_one_unit(): void {
		$assessment = $this->assessment( AssessmentKind::EgeComputer, array( 101 => '14', 102 => '14', 103 => '14' ) );
		$this->assessments->method( 'get' )->willReturn( $assessment );

		$tasks = array();
		foreach ( array( 101, 102, 103 ) as $id ) {
			$tasks[] = array( 'task_id' => $id, 'unit_key' => 'n:14', 'number' => '14', 'anchor' => 'a' . $id, 'verdict' => 'correct', 'score' => 1.0, 'max_score' => 1.0 );
		}

		$units = $this->service->units( $this->attempt( 1.0, 1.0 ), $tasks );

		self::assertCount( 1, $units );
		self::assertSame( 1.0, $units[0]['max'] );
		self::assertSame( 1.0, $units[0]['score'] );
		self::assertSame( 'a101', $units[0]['anchor'] );
	}

	public function test_unit_status_is_the_worst_of_its_tasks(): void {
		$assessment = $this->assessment( AssessmentKind::EgeComputer, array( 101 => '14', 102 => '14' ) );
		$this->assessments->method( 'get' )->willReturn( $assessment );

		$units = $this->service->units( $this->attempt( 1.0, 2.0 ), array(
			array( 'task_id' => 101, 'unit_key' => 'n:14', 'number' => '14', 'anchor' => 'a1', 'verdict' => 'correct', 'score' => 1.0, 'max_score' => 1.0 ),
			array( 'task_id' => 102, 'unit_key' => 'n:14', 'number' => '14', 'anchor' => 'a2', 'verdict' => 'partial', 'score' => 0.5, 'max_score' => 1.0 ),
		) );

		self::assertSame( 'partial', $units[0]['status'] );
	}

	public function test_pending_beats_every_other_status(): void {
		$assessment = $this->assessment( AssessmentKind::EgeComputer, array( 101 => '14', 102 => '14' ) );
		$this->assessments->method( 'get' )->willReturn( $assessment );

		$units = $this->service->units( $this->attempt( 0.0, 2.0 ), array(
			array( 'task_id' => 101, 'unit_key' => 'n:14', 'number' => '14', 'anchor' => 'a1', 'verdict' => 'incorrect', 'score' => 0.0, 'max_score' => 1.0 ),
			array( 'task_id' => 102, 'unit_key' => 'n:14', 'number' => '14', 'anchor' => 'a2', 'verdict' => 'pending', 'score' => null, 'max_score' => 1.0 ),
		) );

		self::assertSame( 'pending', $units[0]['status'] );
	}

	public function test_oge_returns_primary_of_21_and_grade_not_secondary(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::OgeComputer ) );
		// Направление — свойство формата из реестра, а не вывод из вида работы.
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array( $this->formatDto( AssessmentKind::OgeComputer, array() ) );
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );

		$result = $this->service->summarize( $this->attempt( 16.0, 21.0 ), $this->event( $this->ogeSnapshot() ) );

		self::assertSame( 'oge', $result['direction'] );
		self::assertSame( 16, $result['primary'] );
		self::assertSame( 21, $result['primary_max'] );
		self::assertSame( 4, $result['grade'] );
		self::assertSame( 5, $result['grade_max'] );
		self::assertNull( $result['secondary'] );
		self::assertNull( $result['secondary_max'] );
	}

	public function test_oge_pending_manual_part_has_no_grade_and_is_not_final(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::OgeComputer ) );
		// Направление — свойство формата из реестра, а не вывод из вида работы.
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array( $this->formatDto( AssessmentKind::OgeComputer, array() ) );
		$this->answers->method( 'hasPendingAnswers' )->willReturn( true );

		$result = $this->service->summarize( $this->attempt( 12.0, 21.0 ), $this->event( $this->ogeSnapshot() ) );

		self::assertTrue( $result['pending'] );
		self::assertFalse( $result['final'] );
		self::assertNull( $result['grade'] );
		self::assertSame( 12, $result['primary'] );
	}

	public function test_scale_is_taken_from_event_snapshot_not_module(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		// Модуль сегодня считает 18 → 60, но проведение прошло по шкале со снимка (18 → 72).
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array( $this->formatDto( AssessmentKind::EgeComputer, array( 18 => 60 ) ) );

		self::assertSame( 72, $this->service->summarize( $this->attempt( 18.0, 29.0 ), $this->event( $this->egeSnapshot() ) )['secondary'] );
	}

	public function test_falls_back_to_module_format_without_snapshot(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array( $this->formatDto( AssessmentKind::EgeComputer, array( 18 => 60 ) ) );

		$result = $this->service->summarize( $this->attempt( 18.0, 29.0 ), $this->event( null ) );

		self::assertSame( 60, $result['secondary'] );
		self::assertSame( 29, $result['primary_max'] );
		self::assertSame( 100, $result['secondary_max'] );
	}

	public function test_primary_is_rounded_total_score(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );

		self::assertSame( 18, $this->service->summarize( $this->attempt( 17.6, 29.0 ), $this->event( $this->egeSnapshot() ) )['primary'] );
	}

	public function test_caption_for_ege_and_oge(): void {
		self::assertSame( '84 из 100', $this->service->caption( array( 'direction' => 'ege', 'primary' => 37, 'primary_max' => 56, 'secondary' => 84, 'secondary_max' => 100, 'grade' => null, 'final' => true ) ) );
		self::assertSame( '15 из 19, отметка 4', $this->service->caption( array( 'direction' => 'oge', 'primary' => 15, 'primary_max' => 19, 'secondary' => null, 'grade' => 4, 'final' => true ) ) );
		self::assertSame( '', $this->service->caption( array() ) );
	}

	public function test_caption_marks_preliminary_when_pending(): void {
		// Ручная часть не проверена: итог — первичным баллом, без вторичного и без отметки.
		self::assertSame( '37 из 56', $this->service->caption( array( 'direction' => 'ege', 'primary' => 37, 'primary_max' => 56, 'secondary' => null, 'secondary_max' => 100, 'grade' => null, 'final' => false ) ) );
		self::assertSame( '9 из 19', $this->service->caption( array( 'direction' => 'oge', 'primary' => 9, 'primary_max' => 19, 'secondary' => null, 'grade' => null, 'final' => false ) ) );
	}
}
