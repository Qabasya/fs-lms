<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;
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
	private AssessmentAnswerRepository $answers;

	protected function setUp(): void {
		parent::setUp();

		// getMeta() без явного стаба в тесте отдаёт null → correctAnswer() трактует
		// как пустой массив (is_array-фолбэк) — блок сюда не заводим, чтобы тесты
		// ниже могли настроить свой без коллизии двух ->method() на одном методе.
		$this->posts = $this->createMock( PostManager::class );

		$correctAnswers = $this->createMock( CorrectAnswerResolver::class );
		$correctAnswers->method( 'resolve' )->willReturn( null );

		// Аналогично getMeta() выше — стаб только там, где нужен конкретный ответ,
		// buildFromAnswers()-тесты этот репозиторий вообще не трогают.
		$this->answers = $this->createMock( AssessmentAnswerRepository::class );

		$this->service = new KegeResultSheetService(
			$this->answers,
			$correctAnswers,
			new SecondaryScoreService(),
			$this->posts,
		);
	}

	private function attempt(): AttemptDTO {
		return AttemptDTO::fromArray( [
			'id' => 9, 'assessment_id' => 1, 'student_person_id' => 10, 'group_id' => null,
			'attempt_number' => 1, 'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => AttemptStatus::Submitted->value,
		] );
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

	/* ── D18: гейт видимости + фикс ручного балла ОГЭ 13-16 ──────────────── */

	/** revealed=false — сервис сам зачищает correct/score, шаблон не получает ничего чувствительного. */
	public function test_hidden_when_not_revealed_blanks_correct_and_score(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [ 'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '42' ] ),
		] );
		$this->posts->method( 'getMeta' )->willReturn( [ 'task_1_answer' => '42' ] );

		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '1' ] );
		$sheet = $this->service->build( $dto, $this->attempt(), [], revealed: false );

		self::assertFalse( $sheet->revealed );
		self::assertSame( '', $sheet->rows[0]['correct'] );
		self::assertNull( $sheet->rows[0]['score'] );
		self::assertSame( '42', $sheet->rows[0]['answer'] ); // свой ответ не секрет
		self::assertSame( 0.0, $sheet->primary );
		self::assertNull( $sheet->secondary );
	}

	/** revealed=true (по умолчанию) — прежнее поведение, ничего не зачищено. */
	public function test_revealed_by_default_keeps_correct_and_score(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [ 'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '42' ] ),
		] );
		$this->posts->method( 'getMeta' )->willReturn( [ 'task_1_answer' => '42' ] );

		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '1' ] );
		$sheet = $this->service->build( $dto, $this->attempt(), [] );

		self::assertTrue( $sheet->revealed );
		self::assertSame( '42', $sheet->rows[0]['correct'] );
		self::assertSame( 1.0, $sheet->rows[0]['score'] );
	}

	/**
	 * D18: ручная проверка (ОГЭ 13-16) — эталонного текста в мете нет
	 * (`task_{n}_answer` отсутствует), балл берётся из ручной оценки учителя
	 * (`AttemptAnswerDTO::$score`), а не всегда «—»/0, и максимум задания в сумме —
	 * рубрика (2-3 балла), а не «1 балл на слот» по умолчанию.
	 */
	public function test_manually_graded_task_uses_teacher_score_not_text_comparison(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [
				'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '{"text":"решение"}',
				'is_correct' => 1, 'score' => 3.0,
			] ),
		] );
		// Никакого task_14_answer в мете — задание ручной проверки.
		$this->posts->method( 'getMeta' )->willReturn( [] );

		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'ОГЭ', taskIds: [ 10 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::OgeComputer, taskPoints: [ 10 => 3.0 ], scoreMap: [],
			taskNumbers: [ 10 => '14' ],
		);

		$sheet = $this->service->build( $dto, $this->attempt(), [] );

		self::assertSame( 3.0, $sheet->rows[0]['score'] );
		self::assertSame( 3.0, $sheet->primary );
		self::assertSame( 3.0, $sheet->primaryMax );
	}

	/** Тот же случай, но ещё не оценено учителем — балл «—» (null), не 0. */
	public function test_manually_graded_task_pending_shows_no_score_yet(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [
				'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '{"text":"решение"}',
			] ), // is_correct не задан — ещё не проверено
		] );
		$this->posts->method( 'getMeta' )->willReturn( [] );

		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'ОГЭ', taskIds: [ 10 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::OgeComputer, taskPoints: [ 10 => 3.0 ], scoreMap: [],
			taskNumbers: [ 10 => '14' ],
		);

		$sheet = $this->service->build( $dto, $this->attempt(), [] );

		self::assertNull( $sheet->rows[0]['score'] );
		self::assertSame( 3.0, $sheet->primaryMax ); // максимум уже учтён, только балл пока пуст
	}
}
