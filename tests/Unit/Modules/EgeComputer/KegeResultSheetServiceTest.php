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
use Inc\Services\Assessment\ArchiveTaskNumber;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Services\Assessment\SecondaryScoreService;
use Inc\Managers\Wp\TermManager;
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
			new ArchiveTaskNumber(),
			new ScoringUnits( $this->createMock( TermManager::class ), new ArchiveTaskNumber() ),
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
	 * Номер 26 у КЕГЭ — одна строка листа, но до двух баллов (два числа ответа,
	 * {@see KegeScaleConfig::answerSlots()}); у ОГЭ таких заданий нет
	 * ({@see OgeScaleConfig::answerSlots()}) — диспетчер должен различать kind.
	 */
	public function test_ege_computer_task_26_is_one_row_worth_two_points(): void {
		$dto  = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '26' ] );
		$sheet = $this->service->buildFromAnswers( $dto, [], [] );

		self::assertCount( 1, $sheet->rows );
		self::assertSame( 29.0, $sheet->primaryMax, 'максимум КЕГЭ фиксирован — 29' );
	}

	/** Частичный балл №26: верно одно из двух чисел — 1 из 2, ответ в одной ячейке. */
	public function test_task_26_gives_partial_score_and_shows_both_numbers_in_one_cell(): void {
		$this->stubCorrectAnswers( array( 10 => array( 'task_26_answer' => '7 8' ) ) );
		$dto = $this->assessment( AssessmentKind::EgeComputer, array( 10 ), array( 10 => '26' ) );

		$sheet = $this->service->buildFromAnswers( $dto, array( 10 => '7 9' ), array() );

		self::assertCount( 1, $sheet->rows );
		self::assertSame( '7 9', $sheet->rows[0]['answer'] );
		self::assertSame( '7 8', $sheet->rows[0]['correct'] );
		self::assertSame( 1.0, $sheet->rows[0]['score'] );
		self::assertSame( 1.0, $sheet->primary );
	}

	/**
	 * Строка листа подписана номером задания (как в навигаторе станции), и форма ответа
	 * — по нему же: задание №26 на 2-м месте — строка «26» на 2 балла.
	 */
	public function test_row_label_is_task_number_and_answer_shape_follows_it(): void {
		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10, 20 ], [ 10 => '1', 20 => '26' ] );
		$sheet = $this->service->buildFromAnswers( $dto, [], [] );

		self::assertCount( 2, $sheet->rows );
		self::assertSame( [ '1', '26' ], array_column( $sheet->rows, 'number' ) );
	}

	/** Архивное №126 (и 1026) — те же два балла, что у живого №26. */
	public function test_archive_task_number_is_worth_same_points_as_live_one(): void {
		foreach ( array( '126', '1026' ) as $archive ) {
			$sheet = $this->service->buildFromAnswers(
				$this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => $archive ] ),
				[],
				[]
			);

			self::assertCount( 1, $sheet->rows, $archive );
		}
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

	/* ── Одинаковые номера — одна единица зачёта ─────────────────────────── */

	/** @param array<int, string> $metaByTask task_id => ['task_N_answer' => …] */
	private function stubCorrectAnswers( array $metaByTask ): void {
		$this->posts->method( 'getMeta' )->willReturnCallback(
			static fn( int $id ): array => $metaByTask[ $id ] ?? array()
		);
	}

	public function test_three_equal_numbers_are_one_unit_all_correct(): void {
		$this->stubCorrectAnswers( array(
			10 => array( 'task_14_answer' => '1' ), 20 => array( 'task_14_answer' => '2' ),
			30 => array( 'task_14_answer' => '3' ), 40 => array( 'task_15_answer' => '9' ),
		) );
		$dto = $this->assessment( AssessmentKind::EgeComputer, array( 10, 20, 30, 40 ), array( 10 => '14', 20 => '14', 30 => '14', 40 => '15' ) );

		$sheet = $this->service->buildFromAnswers( $dto, array( 10 => '1', 20 => '2', 30 => '3', 40 => '9' ), array() );

		self::assertCount( 4, $sheet->rows );
		self::assertSame( array( '14', '14', '14', '15' ), array_column( $sheet->rows, 'number' ) );
		self::assertSame( 29.0, $sheet->primaryMax );
		self::assertSame( 2.0, $sheet->primary, 'три №14 и №15 — два балла' );
		// Балл типа стоит в первой его строке, у остальных «—»: сумма строк сходится с итогом.
		self::assertSame( 1.0, $sheet->rows[0]['score'] );
		self::assertNull( $sheet->rows[1]['score'] );
		self::assertNull( $sheet->rows[2]['score'] );
		self::assertSame( 4, $sheet->answered );
	}

	public function test_one_wrong_task_zeroes_the_whole_number(): void {
		$this->stubCorrectAnswers( array(
			10 => array( 'task_14_answer' => '1' ), 20 => array( 'task_14_answer' => '2' ),
			30 => array( 'task_14_answer' => '3' ), 40 => array( 'task_15_answer' => '9' ),
		) );
		$dto = $this->assessment( AssessmentKind::EgeComputer, array( 10, 20, 30, 40 ), array( 10 => '14', 20 => '14', 30 => '14', 40 => '15' ) );

		$sheet = $this->service->buildFromAnswers( $dto, array( 10 => '1', 20 => 'ошибка', 30 => '3', 40 => '9' ), array() );

		self::assertSame( 29.0, $sheet->primaryMax );
		self::assertSame( 1.0, $sheet->primary, 'засчитан только №15' );
		self::assertSame( 0.0, $sheet->rows[0]['score'] );
		self::assertSame( 1.0, $sheet->rows[3]['score'] );
	}

	/** Архивный номер подписан как есть, а форма и баллы — по «живому». */
	public function test_archive_number_label_is_kept_while_slots_follow_live_number(): void {
		$this->stubCorrectAnswers( array( 10 => array( 'task_26_answer' => '7 8' ) ) );
		$dto = $this->assessment( AssessmentKind::EgeComputer, array( 10 ), array( 10 => '126' ) );

		$sheet = $this->service->buildFromAnswers( $dto, array( 10 => '7 8' ), array() );

		self::assertSame( array( '126' ), array_column( $sheet->rows, 'number' ) );
		self::assertSame( 2.0, $sheet->primary );
		self::assertSame( 29.0, $sheet->primaryMax );
	}

	/** Задание без публичного адреса (не из предметного банка) — без ссылки. */
	public function test_row_has_no_url_for_non_public_task(): void {
		$this->stubCorrectAnswers( array() );
		$dto = $this->assessment( AssessmentKind::EgeComputer, array( 10 ), array( 10 => '1' ) );

		$sheet = $this->service->buildFromAnswers( $dto, array(), array() );

		self::assertSame( '', $sheet->rows[0]['url'] );
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

	/* ── Tasks.md, п. 6: ручной зачёт побеждает сличение с эталоном ────────── */

	/**
	 * Ответ ученика с эталоном НЕ совпадает (опечатка в условии), но
	 * преподаватель задание засчитал — `graded_by_user_id` делает балл
	 * авторитетным, иначе лист станции пересчитал бы задание в 0 и разошёлся
	 * с журналом на одной и той же попытке.
	 */
	public function test_teacher_credit_wins_over_reference_answer(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [
				'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '41',
				'is_correct' => 1, 'score' => 1.0, 'max_score' => 1.0, 'graded_by_user_id' => 99,
			] ),
		] );
		$this->posts->method( 'getMeta' )->willReturn( [ 'task_1_answer' => '42' ] );

		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '1' ] );
		$sheet = $this->service->build( $dto, $this->attempt(), [] );

		self::assertSame( 1.0, $sheet->rows[0]['score'] );
		self::assertSame( 1.0, $sheet->primary );
	}

	/** Авто-оценка `graded_by_user_id` не пишет — балл по-прежнему считает эталон. */
	public function test_auto_graded_answer_still_scored_against_reference(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [
				'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '41',
				'is_correct' => 1, 'score' => 1.0, 'max_score' => 1.0,
			] ),
		] );
		$this->posts->method( 'getMeta' )->willReturn( [ 'task_1_answer' => '42' ] );

		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '1' ] );
		$sheet = $this->service->build( $dto, $this->attempt(), [] );

		self::assertSame( 0.0, $sheet->rows[0]['score'] );
		self::assertSame( 0.0, $sheet->primary );
	}

	/** Зачёт преподавателя на №26 — один балл строки целиком (2 из 2). */
	public function test_teacher_credit_is_the_whole_row_score(): void {
		$this->answers->method( 'listByAttempt' )->willReturn( [
			AttemptAnswerDTO::fromArray( [
				'id' => 1, 'attempt_id' => 9, 'task_id' => 10, 'answer_text' => '1 2',
				'is_correct' => 1, 'score' => 2.0, 'max_score' => 2.0, 'graded_by_user_id' => 99,
			] ),
		] );
		$this->posts->method( 'getMeta' )->willReturn( [ 'task_26_answer' => '3 4' ] );

		$dto   = $this->assessment( AssessmentKind::EgeComputer, [ 10 ], [ 10 => '26' ] );
		$sheet = $this->service->build( $dto, $this->attempt(), [] );

		self::assertCount( 1, $sheet->rows );
		self::assertSame( 2.0, $sheet->rows[0]['score'] );
		self::assertSame( 2.0, $sheet->primary );
	}

	/** 20 заданий одного типа и по одному на остальные 26: 46 строк, но те же 29 баллов. */
	public function test_many_tasks_of_one_type_still_sum_to_29(): void {
		$ids     = range( 1, 46 );
		$numbers = array();
		$meta    = array();
		$answers = array();
		foreach ( $ids as $id ) {
			$n               = $id <= 20 ? 1 : $id - 19;
			$numbers[ $id ]  = (string) $n;
			$meta[ $id ]     = array( "task_{$n}_answer" => in_array( $n, array( 26, 27 ), true ) ? '1 2' : '5' );
			$answers[ $id ]  = in_array( $n, array( 26, 27 ), true ) ? '1 2' : '5';
		}
		$this->stubCorrectAnswers( $meta );
		$dto = $this->assessment( AssessmentKind::EgeComputer, $ids, $numbers );

		$sheet = $this->service->buildFromAnswers( $dto, $answers, array() );

		self::assertCount( 46, $sheet->rows );
		self::assertSame( 46, $sheet->answered );
		self::assertSame( 29.0, $sheet->primaryMax );
		self::assertSame( 29.0, $sheet->primary );
		self::assertSame( 100, $sheet->secondary );
	}
}
