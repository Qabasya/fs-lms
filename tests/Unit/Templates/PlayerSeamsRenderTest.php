<?php

declare( strict_types=1 );

namespace Unit\Templates;

use Inc\DTO\Course\GroupLessonDTO;
use PHPUnit\Framework\TestCase;

/**
 * Швы плеера урока (`fs_lms_player_*`): ученику они доступны, преподавателю, родителю и предпросмотру — нет.
 * Без подписчиков разметка не меняется. Шаблон рендерится настоящий (`player.php`), заглушки WP — из bootstrap.
 */
class PlayerSeamsRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_actions'] = array();
		unset( $GLOBALS['_fs_test_filter_returns'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'] );
		$GLOBALS['_fs_test_actions'] = array();
		parent::tearDown();
	}

	private function row(): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array( 'id' => 42, 'group_id' => 10, 'lesson_id' => 7, 'position' => 0 ) );
	}

	/**
	 * Рендер плеера. По умолчанию — вид ученика с заблокированным уроком (шаги не выводятся).
	 *
	 * @param array<string, mixed> $vars Переменные шаблона поверх умолчаний.
	 */
	private function render( array $vars = array() ): string {
		$defaults = array(
			'view'           => array( 'topic' => 'Урок', 'steps' => array(), 'group_lesson_id' => 42, 'lesson_id' => 7, 'shell' => array() ),
			'groupId'        => 10,
			'active_step'    => '',
			'locked'         => true,
			'player_user_id' => 5,
			'player_row'     => $this->row(),
		);
		// array_replace, а не «+»: тест вправе передать null (переменная не задана контроллером).
		extract( array_replace( $defaults, $vars ), EXTR_SKIP );

		ob_start();
		require __DIR__ . '/../../../templates/frontend/lesson-player/player.php';

		return (string) ob_get_clean();
	}

	/** @return list<string> Имена вызванных экшенов в порядке вызова. */
	private function firedActions(): array {
		return array_column( $GLOBALS['_fs_test_actions'], 'hook' );
	}

	public function test_student_without_subscribers_gets_the_standard_page_and_the_actions_are_fired(): void {
		$html = $this->render();

		self::assertStringContainsString( 'class="s-back"', $html );
		self::assertStringContainsString( 'Урок ещё не доступен', $html );
		self::assertSame(
			array( 'fs_lms_player_header_actions', 'fs_lms_player_before_content', 'fs_lms_player_footer' ),
			$this->firedActions(),
			'экшены зовутся в порядке: шапка → до контента → подвал'
		);
	}

	public function test_actions_receive_user_id_and_the_lesson_row(): void {
		$this->render();

		foreach ( $GLOBALS['_fs_test_actions'] as $call ) {
			self::assertSame( 5, $call['args'][0] );
			self::assertInstanceOf( GroupLessonDTO::class, $call['args'][1] );
			self::assertSame( 42, $call['args'][1]->id );
		}
	}

	public function test_empty_back_url_from_a_subscriber_removes_the_back_button(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_back_url'] = '';

		self::assertStringNotContainsString( 'class="s-back"', $this->render() );
	}

	public function test_lock_html_from_a_subscriber_replaces_the_standard_lock_text_but_keeps_the_icon(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_lock_html'] = '<div class="lock-title">Доступ закрыт модулем</div>';

		$html = $this->render();

		self::assertStringContainsString( 'Доступ закрыт модулем', $html );
		self::assertStringNotContainsString( 'Урок ещё не доступен', $html );
		self::assertStringContainsString( 'lock-ico', $html, 'иконка замка остаётся' );
	}

	public function test_teacher_view_ignores_every_seam(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_back_url']  = '';
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_lock_html'] = '<div>МОДУЛЬ</div>';

		$html = $this->render( array( 'is_teacher' => true ) );

		self::assertStringContainsString( 'class="s-back"', $html );
		self::assertStringNotContainsString( 'МОДУЛЬ', $html );
		self::assertSame( array(), $this->firedActions() );
	}

	public function test_parent_view_ignores_every_seam(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_back_url']  = '';
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_lock_html'] = '<div>МОДУЛЬ</div>';

		$html = $this->render( array( 'locked_parent' => true ) );

		self::assertStringContainsString( 'class="s-back"', $html );
		self::assertStringNotContainsString( 'МОДУЛЬ', $html );
		self::assertSame( array(), $this->firedActions() );
	}

	public function test_course_preview_ignores_every_seam(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_back_url'] = '';

		$html = $this->render( array(
			'view'           => array( 'topic' => 'Урок', 'steps' => array(), 'preview' => true, 'shell' => array() ),
			'player_user_id' => null,
			'player_row'     => null,
		) );

		self::assertStringContainsString( 'class="s-back"', $html );
		self::assertSame( array(), $this->firedActions() );
	}

	public function test_without_the_lesson_row_the_seams_stay_off(): void {
		// Контроллер предпросмотра курса переменных шва не задаёт: подписчикам нечего передать.
		$GLOBALS['_fs_test_filter_returns']['fs_lms_player_back_url'] = '';

		$html = $this->render( array( 'player_row' => null ) );

		self::assertStringContainsString( 'class="s-back"', $html );
		self::assertSame( array(), $this->firedActions() );
	}
}
