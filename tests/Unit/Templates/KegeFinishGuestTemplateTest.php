<?php

declare( strict_types=1 );

namespace Unit\Templates;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Modules\EgeComputer\EgeComputerModule;
use PHPUnit\Framework\TestCase;

/**
 * Экран завершения станции для гостя экзамена: результат не показывается — только просьба
 * связаться с преподавателем. Лист ответов (баллы, ответы, эталоны) в разметку не попадает.
 */
class KegeFinishGuestTemplateTest extends TestCase {

	private const TEMPLATE = __DIR__ . '/../../../templates/frontend/assessment/kege/finish.php';

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'][ EgeComputerModule::SHEET_FILTER ] );
		parent::tearDown();
	}

	private function render( bool $guest ): string {
		// Заглушка apply_filters() отдаёт значение из этого массива: лист с «результатами», которые гость видеть не должен.
		$GLOBALS['_fs_test_filter_returns'][ EgeComputerModule::SHEET_FILTER ] = new KegeSheetDTO(
			array( array( 'number' => '1', 'score' => 1.0, 'answer' => 'СЕКРЕТНЫЙ-ОТВЕТ', 'correct' => 'ЭТАЛОН-777', 'url' => '' ) ),
			1, 1.0, 1.0, 100, 100
		);

		$assessment   = new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Пробник', taskIds: array( 10 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::EgeComputer, taskPoints: array(), scoreMap: array(),
		);
		$lastAttempt  = null;
		$taskViews    = array();
		$previewMode  = false;
		$publicMode   = false;
		$guestMode    = $guest;

		ob_start();
		require self::TEMPLATE;

		return (string) ob_get_clean();
	}

	public function test_guest_sees_only_the_contact_teacher_message(): void {
		$html = $this->render( true );

		self::assertStringContainsString( 'Экзамен завершён', $html );
		self::assertStringContainsString( 'Чтобы узнать результаты, свяжитесь с преподавателем', $html );
	}

	public function test_guest_screen_has_no_results_and_no_exit_to_results(): void {
		$html = $this->render( true );

		self::assertStringNotContainsString( 'СЕКРЕТНЫЙ-ОТВЕТ', $html );
		self::assertStringNotContainsString( 'ЭТАЛОН-777', $html );
		self::assertStringNotContainsString( 'Результаты экзамена', $html );
		self::assertStringNotContainsString( 'kegeFinishBtn', $html );
	}

	public function test_non_guest_screen_is_unchanged(): void {
		$html = $this->render( false );

		self::assertStringContainsString( 'ЭТАЛОН-777', $html );
		self::assertStringContainsString( 'kegeFinishBtn', $html );
		self::assertStringNotContainsString( 'свяжитесь с преподавателем', $html );
	}
}
