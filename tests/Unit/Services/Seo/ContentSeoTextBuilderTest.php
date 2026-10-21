<?php

declare( strict_types=1 );

namespace Unit\Services\Seo;

use Inc\Services\Seo\ContentSeoTextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Поисковые формулировки заданий и статей: порядок слов экзамена, номера, обрезка по слову.
 */
class ContentSeoTextBuilderTest extends TestCase {

	private ContentSeoTextBuilder $texts;

	protected function setUp(): void {
		$this->texts = new ContentSeoTextBuilder();
	}

	public function test_exam_label_moves_exam_to_front(): void {
		$this->assertSame( 'ЕГЭ информатика', $this->texts->examLabel( 'Информатика ЕГЭ' ) );
		$this->assertSame( 'ОГЭ информатика', $this->texts->examLabel( 'Информатика ОГЭ' ) );
	}

	public function test_exam_label_keeps_subject_without_exam(): void {
		$this->assertSame( 'Python', $this->texts->examLabel( 'Python' ) );
		$this->assertSame( 'Робототехника Arduino', $this->texts->examLabel( ' Робототехника Arduino ' ) );
	}

	public function test_task_number_takes_digits_from_term_name(): void {
		$this->assertSame( '6', $this->texts->taskNumber( '6 задание' ) );
		$this->assertSame( '13', $this->texts->taskNumber( 'Задание 13.1' ) );
		$this->assertSame( '', $this->texts->taskNumber( 'Без номера' ) );
	}

	public function test_problem_number_prefers_title_then_slug(): void {
		$this->assertSame( '6203', $this->texts->problemNumber( "№\u{00A0}6203. Авторская задача", 'x' ) );
		$this->assertSame( '3041', $this->texts->problemNumber( 'Демоверсия', '3041' ) );
		$this->assertSame( '', $this->texts->problemNumber( 'Демоверсия', 'article-12' ) );
	}

	public function test_task_title_keeps_post_title_after_prefix(): void {
		$this->assertSame(
			'Задание 12 ЕГЭ информатика: № 12017. Демоверсия',
			$this->texts->taskTitle( 'ЕГЭ информатика', '12', "№\u{00A0}12017. Демоверсия" )
		);
	}

	public function test_task_title_without_task_number_has_only_exam_prefix(): void {
		$this->assertSame( 'ЕГЭ информатика: № 12016. Пересдача', $this->texts->taskTitle( 'ЕГЭ информатика', '', '№ 12016. Пересдача' ) );
	}

	public function test_task_title_without_post_title_is_prefix_only(): void {
		$this->assertSame( 'Задание 6 ЕГЭ информатика', $this->texts->taskTitle( 'ЕГЭ информатика', '6', '  ' ) );
	}

	public function test_task_title_falls_back_to_post_title_when_nothing_known(): void {
		$this->assertSame( 'Рабочее имя', $this->texts->taskTitle( '', '', 'Рабочее имя' ) );
	}

	public function test_task_description_fits_limit_and_ends_with_tail(): void {
		$condition = str_repeat( 'Исполнитель преобразует число по правилам. ', 12 );
		$text      = $this->texts->taskDescription( 'ЕГЭ информатика', '6', '6203', $condition );

		$this->assertLessThanOrEqual( ContentSeoTextBuilder::DESCRIPTION_LIMIT, mb_strlen( $text ) );
		$this->assertStringStartsWith( 'Задание 6 ЕГЭ информатика № 6203: Исполнитель', $text );
		$this->assertStringEndsWith( '… Ответ и разбор решения.', $text );
	}

	public function test_task_description_short_condition_is_not_cut(): void {
		$text = $this->texts->taskDescription( 'ЕГЭ информатика', '2', '2001', "Сколько  строк\nв таблице?" );

		$this->assertSame( 'Задание 2 ЕГЭ информатика № 2001: Сколько строк в таблице? Ответ и разбор решения.', $text );
	}

	public function test_task_description_without_condition(): void {
		$this->assertSame(
			'Задание 2 ЕГЭ информатика № 2001: условие, ответ и разбор решения.',
			$this->texts->taskDescription( 'ЕГЭ информатика', '2', '2001', '   ' )
		);
	}

	public function test_article_description_prefers_author_text(): void {
		$this->assertSame( 'Кратко о теме.', $this->texts->articleDescription( ' Кратко о теме. ', 'Длинный текст статьи' ) );
		$this->assertSame( 'Начало статьи', $this->texts->articleDescription( '', "Начало\n статьи" ) );
	}

	public function test_article_description_is_cut_on_word_boundary(): void {
		$text = $this->texts->articleDescription( '', str_repeat( 'слово ', 60 ) );

		$this->assertLessThanOrEqual( ContentSeoTextBuilder::DESCRIPTION_LIMIT, mb_strlen( $text ) );
		$this->assertStringEndsWith( 'слово…', $text );
	}
}
