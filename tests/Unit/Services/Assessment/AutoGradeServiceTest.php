<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\BatchCheckResultDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Services\Course\BatchCheckService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T16.4: правила оценки контрольной (бинарный балл 1/0) и регрессия взвешенного ЕГЭ.
 */
class AutoGradeServiceTest extends TestCase {

	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject  $answers;
	private PostManager&MockObject                 $posts;
	private TemplateResolver&MockObject            $resolver;
	private BatchCheckService&MockObject           $batchCheck;
	private TemplateRegistry&MockObject            $templates;
	private LogEventDispatcherInterface&MockObject $dispatcher;
	private AssessmentManager&MockObject           $assessments;
	private AutoGradeService                       $service;

	/** @var array<string, mixed> Захваченные аргументы последнего attempts->update(). */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();

		$this->attempts    = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers     = $this->createMock( AssessmentAnswerRepository::class );
		$this->posts       = $this->createMock( PostManager::class );
		$this->resolver    = $this->createMock( TemplateResolver::class );
		$this->batchCheck  = $this->createMock( BatchCheckService::class );
		$this->templates   = $this->createMock( TemplateRegistry::class );
		$this->dispatcher  = $this->createMock( LogEventDispatcherInterface::class );
		$this->assessments = $this->createMock( AssessmentManager::class );

		$this->service = new AutoGradeService(
			$this->attempts,
			$this->answers,
			$this->posts,
			$this->resolver,
			$this->dispatcher,
			$this->assessments,
			$this->batchCheck,
			$this->templates,
		);

		// Нет сохранённых ответов — оцениваем по полному составу работы.
		$this->answers->method( 'listByAttempt' )->willReturn( array() );
		$this->answers->method( 'upsert' )->willReturn( true );

		// Захват итогов, которые сервис пишет в попытку.
		$this->attempts->method( 'update' )->willReturnCallback(
			function ( int $id, array $data ): bool {
				$this->captured = $data;
				return true;
			}
		);
		$this->attempts->method( 'find' )->willReturnCallback(
			fn( int $id ): AttemptDTO => $this->attempt()
		);
	}

	private function attempt(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 7, 'assessment_id' => 1, 'student_person_id' => 99,
			'group_id' => null, 'attempt_number' => 1,
			'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'in_progress',
		) );
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id              : 1,
			subjectKey      : 'test',
			title           : 'Работа',
			taskIds         : array( 10, 20, 30 ),
			timeLimit       : 0,
			attemptsAllowed : 0,
			passScore       : 0.0,
			scoringPolicy   : ScoringPolicy::Highest,
			status          : 'publish',
			kind            : $kind,
			taskPoints      : array(),
			scoreMap        : array(),
		);
	}

	/**
	 * Настраивает posts + BatchCheckService (собственные тесты — BatchCheckServiceTest)
	 * на три задания:
	 *  - #10 авто, верно (2/2);
	 *  - #20 ручное (pending; критерии на сумму 3 читает manualMax());
	 *  - #30 авто, частично (1 из 2, verdict=incorrect).
	 */
	private function seedThreeTasks(): void {
		fs_test_seed_post( array( 'ID' => 10, 'post_type' => 'test_tasks' ) );
		fs_test_seed_post( array( 'ID' => 20, 'post_type' => 'test_tasks' ) );
		fs_test_seed_post( array( 'ID' => 30, 'post_type' => 'test_tasks' ) );

		$this->posts->method( 'get' )->willReturnCallback( fn( int $id ) => get_post( $id ) );
		$this->posts->method( 'getMeta' )->willReturnCallback(
			fn( int $id ) => 20 === $id
				? array( 'task_criteria' => array( 'criteria' => array( array( 'max_points' => 3 ) ) ) )
				: array()
		);

		// isComposite() → false для всех: шаблон не разворачивается на экзамене.
		$this->resolver->method( 'resolveId' )->willReturn( '' );
		$this->templates->method( 'get' )->willReturn( null );

		$this->batchCheck->method( 'check' )->willReturn( new BatchCheckResultDTO(
			perTask          : array(
				10 => array( 'verdict' => 'correct',   'score' => 2.0, 'maxScore' => 2.0 ),
				20 => array( 'verdict' => 'pending',   'score' => 0.0, 'maxScore' => 1.0 ),
				30 => array( 'verdict' => 'incorrect', 'score' => 1.0, 'maxScore' => 2.0 ),
			),
			correctCount     : 1,
			totalCount       : 3,
			weightedScore    : 3.0,
			maxWeightedScore : 5.0,
			hasManual        : true,
		) );
	}

	public function test_control_scores_each_task_as_binary_one_or_zero(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Control ) );
		$this->seedThreeTasks();

		$this->service->gradeAttempt( $this->attempt() );

		// Max = число заданий (3), независимо от типов и весов чекеров/критериев.
		$this->assertSame( 3.0, $this->captured['max_score'] );
		// Верно(1) + ручное(0, ждёт проверки) + частично(0, не «верно») = 1.
		$this->assertSame( 1.0, $this->captured['total_score'] );
		// Есть ручное задание → статус submitted (не graded).
		$this->assertSame( 'submitted', $this->captured['status'] );
	}

	public function test_ege_keeps_weighted_scoring(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Ege ) );
		$this->seedThreeTasks();

		$this->service->gradeAttempt( $this->attempt() );

		// Взвешенно: 2/2 (верно) + max 3 (критерии) + 1/2 (частично).
		$this->assertSame( 7.0, $this->captured['max_score'] );
		$this->assertSame( 3.0, $this->captured['total_score'] );
	}
}
