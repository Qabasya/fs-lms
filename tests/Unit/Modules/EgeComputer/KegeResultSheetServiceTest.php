<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Wp\PostManager;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Services\Assessment\SecondaryScoreService;
use Inc\Services\Task\CorrectAnswerResolver;
use PHPUnit\Framework\TestCase;

/**
 * §6.3 (.docs/Tasks.md): лист ответов обязан считать позиции/шкалу по виду
 * станции (KegeScaleConfig для EgeComputer, OgeScaleConfig для OgeComputer),
 * а не всегда по КЕГЭ-таблице.
 */
class KegeResultSheetServiceTest extends TestCase {

	private KegeResultSheetService $service;
	private PostManager $posts;

	protected function setUp(): void {
		parent::setUp();

		$this->posts = $this->createMock( PostManager::class );
		$this->posts->method( 'getMeta' )->willReturn( [] );

		$correctAnswers = $this->createMock( CorrectAnswerResolver::class );
		$correctAnswers->method( 'resolve' )->willReturn( null );

		$this->service = new KegeResultSheetService(
			$this->createMock( AssessmentAnswerRepository::class ),
			$correctAnswers,
			new SecondaryScoreService(),
			$this->posts,
		);
	}

	private function assessment( AssessmentKind $kind, array $taskIds, array $taskNumbers ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Экзамен', taskIds: $taskIds,
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: [], scoreMap: [], taskNumbers: $taskNumbers,
		);
	}

	/**
	 * Номер 26 у КЕГЭ занимает 2 позиции листа ({@see KegeScaleConfig::answerSlots()}) —
	 * у ОГЭ таких заданий нет ({@see OgeScaleConfig::answerSlots()}), несмотря на тот же
	 * номер (в текущей БД он не встречается у ОГЭ, но диспетчер должен различать kind,
	 * а не полагаться на диапазон номеров).
	 */
	public function test_ege_computer_gives_two_slots_for_task_26(): void {
		$dto  = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '26' ] );
		$sheet = $this->service->buildFromAnswers( $dto, [], [] );

		self::assertCount( 2, $sheet->rows );
		self::assertSame( 2.0, $sheet->primaryMax );
	}

	public function test_oge_computer_always_gives_one_slot(): void {
		$dto   = $this->assessment( AssessmentKind::OgeComputer, [ 10 ], [ 10 => '26' ] );
		$sheet = $this->service->buildFromAnswers( $dto, [], [] );

		self::assertCount( 1, $sheet->rows );
		self::assertSame( 1.0, $sheet->primaryMax );
	}

	public function test_ege_computer_secondary_max_is_100(): void {
		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '1' ] );
		$sheet = $this->service->buildFromAnswers( $dto, [], [] );

		self::assertSame( KegeScaleConfig::secondaryMax(), $sheet->secondaryMax );
		self::assertSame( 100, $sheet->secondaryMax );
	}

	public function test_oge_computer_secondary_max_is_5(): void {
		$dto   = $this->assessment( AssessmentKind::OgeComputer, [ 10 ], [ 10 => '1' ] );
		$sheet = $this->service->buildFromAnswers( $dto, [], [] );

		self::assertSame( OgeScaleConfig::secondaryMax(), $sheet->secondaryMax );
		self::assertSame( 5, $sheet->secondaryMax );
	}

	/**
	 * Без ответов (0 первичных баллов) КЕГЭ переводит в 0, ОГЭ — в отметку «2»
	 * (нижняя граница шкалы, см. `OgeScaleConfig::SCALE[0] === 2`) — разные
	 * таблицы перевода, не общий фолбэк.
	 */
	public function test_secondary_score_uses_kind_specific_scale(): void {
		$ege = $this->service->buildFromAnswers(
			$this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '1' ] ), [], []
		);
		$oge = $this->service->buildFromAnswers(
			$this->assessment( AssessmentKind::OgeComputer, [ 10 ], [ 10 => '1' ] ), [], []
		);

		self::assertSame( 0, $ege->secondary );
		self::assertSame( 2, $oge->secondary );
	}
}
