<?php

declare( strict_types=1 );

namespace Unit\Templates;

use PHPUnit\Framework\TestCase;

/**
 * Страница результата гостя: ручная часть без балла, нет кнопок перерешивания, эталон только у ошибочных заданий.
 */
class ExamResultTemplateTest extends TestCase {

	private const TEMPLATE = __DIR__ . '/../../../templates/frontend/exam-result.php';

	/** @param array<string, mixed> $override */
	private function render( array $override = array() ): string {
		$task = static fn ( array $o ): array => $o + array(
			'n' => 1, 'anchor' => 'u-1', 'verdict' => 'correct', 'verdict_text' => 'Верно', 'score' => 1.0, 'max_score' => 1.0,
			'condition' => '<p>Условие</p>', 'answer' => '42', 'code' => '', 'files' => array(), 'correct' => '', 'solution' => null,
		);
		$data = $override + array(
			'revealed' => true, 'event_title' => 'Пробный ОГЭ', 'title' => 'Вариант', 'submitted' => '2026-03-10 12:00:00', 'caption' => '9 из 19', 'preliminary' => true,
			'units' => array( array( 'status' => 'correct', 'anchor' => 'u-1', 'number' => '1' ), array( 'status' => 'pending', 'anchor' => 'u-2', 'number' => '2' ) ),
			'counts' => array( 'correct' => 1, 'partial' => 0, 'wrong' => 0, 'pending' => 1 ),
			'tasks' => array(
				$task( array() ),
				$task( array( 'n' => 2, 'anchor' => 'u-2', 'verdict' => 'pending', 'verdict_text' => 'Проверяется преподавателем', 'score' => null, 'answer' => 'моё сочинение' ) ),
				$task( array( 'n' => 3, 'anchor' => 'u-3', 'verdict' => 'incorrect', 'verdict_text' => 'Неверно', 'score' => 0.0, 'answer' => '7', 'correct' => '8' ) ),
			),
			'contacts' => array( 'phone' => '+7 000' ), 'can_end_session' => true, 'crumbs' => array(),
		);

		ob_start();
		( static function ( array $vars, string $file ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $file;
		} )( $data, self::TEMPLATE );

		return (string) ob_get_clean();
	}

	public function test_pending_task_has_no_score_and_has_review_note(): void {
		$html = $this->render();

		self::assertStringContainsString( 'Проверяется преподавателем.', $html );
		self::assertStringContainsString( 'Предварительный результат: проверены не все задания.', $html );
		// Балл есть только у проверенных: у второго задания блока с баллом нет.
		self::assertSame( 2, substr_count( $html, 'fs-exam-task__score' ) );
	}

	public function test_no_retry_or_practice_controls(): void {
		$html = $this->render();

		foreach ( array( 'Перерешать', 'Решить самостоятельно', 'тренировк', 'Попробовать ещё' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $html );
		}
		self::assertStringContainsString( 'data-exam-end-session', $html );
	}

	public function test_correct_answer_shown_only_for_wrong_tasks(): void {
		$html = $this->render();

		self::assertSame( 1, substr_count( $html, 'Правильный ответ:' ) );
		// Эталон «8» стоит у неверного задания №3; у верного №1 и проверяемого №2 строки «Правильный ответ» нет.
		self::assertMatchesRegularExpression( '#Правильный ответ:</span> 8#u', $html );
	}

	public function test_not_revealed_shows_no_tasks(): void {
		$html = $this->render( array( 'revealed' => false, 'tasks' => array() ) );

		self::assertStringContainsString( 'Результат появится здесь после сдачи работы.', $html );
		self::assertStringNotContainsString( 'fs-exam-task', $html );
	}

	public function test_end_session_button_absent_when_not_allowed(): void {
		self::assertStringNotContainsString( 'data-exam-end-session', $this->render( array( 'can_end_session' => false ) ) );
	}
}
