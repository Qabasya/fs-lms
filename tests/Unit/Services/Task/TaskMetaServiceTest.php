<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Services\Task\TaskMetaService;
use PHPUnit\Framework\TestCase;

/**
 * Публичная страница задания и карточка тренажёра читают условие и файлы
 * через этот сервис. Задания «Развёрнутый ответ» / «Два условия на выбор»
 * (ОГЭ №13–15) устроены иначе остальных: материалы у них — вложения
 * медиатеки, а условий может быть два.
 */
class TaskMetaServiceTest extends TestCase {

	private TaskMetaService $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$GLOBALS['_fs_test_attachment_urls'] = array();
		$this->service = new TaskMetaService();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_home_url'] );
		parent::tearDown();
	}

	public function test_single_condition_is_returned_as_is(): void {
		$html = $this->service->getCombinedCondition( array( 'task_condition' => 'Одно условие' ) );

		self::assertStringContainsString( 'Одно условие', $html );
		self::assertStringNotContainsString( 'fs-task-subcondition', $html );
	}

	public function test_two_alternative_conditions_are_separated(): void {
		// Раньше оба варианта склеивались встык и читались как одно условие.
		$html = $this->service->getCombinedCondition( array(
			'task_condition_1' => 'Вариант 13.1',
			'task_condition_2' => 'Вариант 13.2',
		) );

		self::assertSame( 2, substr_count( $html, 'fs-task-subcondition' ) );
		self::assertStringContainsString( 'Вариант 13.1', $html );
		self::assertStringContainsString( 'Вариант 13.2', $html );
	}

	public function test_empty_condition_field_does_not_produce_empty_block(): void {
		$html = $this->service->getCombinedCondition( array(
			'task_condition_1' => 'Единственный заполненный вариант',
			'task_condition_2' => '   ',
		) );

		self::assertStringNotContainsString( 'fs-task-subcondition', $html );
	}

	public function test_common_condition_goes_first_and_collapses_on_request(): void {
		$meta = array(
			'task_condition'   => 'Своя часть',
			'common_condition' => 'Типовая часть',
		);

		$html = $this->service->getCombinedCondition( $meta, true );

		self::assertStringContainsString( '<details class="fs-common-cond">', $html );
		self::assertLessThan( strpos( $html, 'Своя часть' ), strpos( $html, 'Типовая часть' ) );
		self::assertStringNotContainsString( 'fs-task-subcondition', $html );
	}

	public function test_common_condition_is_static_by_default(): void {
		$html = $this->service->getCombinedCondition( array(
			'task_condition'   => 'Своя часть',
			'common_condition' => 'Типовая часть',
		) );

		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( 'fs-common-cond--static', $html );
	}

	public function test_empty_common_condition_adds_no_block(): void {
		$html = $this->service->getCombinedCondition( array(
			'task_condition'   => 'Своя часть',
			'common_condition' => '  ',
		), true );

		self::assertStringNotContainsString( 'fs-common-cond', $html );
	}

	public function test_materials_are_read_from_attachment_ids(): void {
		$GLOBALS['_fs_test_home_url'] = 'https://example.com';
		fs_test_seed_post( array( 'ID' => 55, 'post_type' => 'attachment', 'post_title' => 'Исходные данные.xlsx' ) );
		$GLOBALS['_fs_test_attachment_urls'][55] = 'https://example.com/data.xlsx';

		$materials = $this->service->getTaskMaterials( array(
			'task_materials' => array( 'attachment_ids' => array( 55 ) ),
		) );

		self::assertSame( 'https://example.com/data.xlsx', $materials[0]['url'] );
		self::assertSame( 'Исходные данные.xlsx', $materials[0]['name'] );
	}

	public function test_missing_attachment_is_skipped(): void {
		$materials = $this->service->getTaskMaterials( array(
			'task_materials' => array( 'attachment_ids' => array( 404 ) ),
		) );

		self::assertSame( array(), $materials );
	}

	public function test_task_without_materials_field_gives_empty_list(): void {
		self::assertSame( array(), $this->service->getTaskMaterials( array( 'task_condition' => 'Условие' ) ) );
	}

	public function test_own_file_link_is_marked_downloadable(): void {
		$files = $this->service->getTaskFiles( array( 'file' => 'http://example.com/2026/09/900.txt' ) );

		self::assertTrue( $files[0]['download'] );
	}

	public function test_foreign_file_link_is_not_downloadable(): void {
		// Атрибут download действует только для своего origin — чужую ссылку
		// чип открывает во вкладке, иначе клик уводит со страницы задания.
		$files = $this->service->getTaskFiles( array( 'file' => 'https://drive.example.org/file.pdf' ) );

		self::assertFalse( $files[0]['download'] );
		self::assertSame( 'https://drive.example.org/file.pdf', $files[0]['url'] );
	}

	public function test_own_file_link_follows_site_scheme(): void {
		// Сайт переехал на HTTPS позже, и часть ссылок осталась с http://.
		// Для браузера это ЧУЖОЙ origin, и download молча игнорируется.
		$GLOBALS['_fs_test_home_url'] = 'https://example.com';

		$files = $this->service->getTaskFiles( array( 'file' => 'http://example.com/2026/09/900.txt' ) );

		self::assertSame( 'https://example.com/2026/09/900.txt', $files[0]['url'] );
		self::assertTrue( $files[0]['download'] );
	}

	public function test_bundle_answers_are_listed_per_subpart(): void {
		$meta = array(
			'task_19_answer' => '25',
			'task_20_answer' => '21 24',
			'task_21_answer' => '20',
		);

		self::assertSame(
			"Ответ на 19: 25\nОтвет на 20: 21 24\nОтвет на 21: 20",
			$this->service->getDisplayAnswer( $meta, TaskTemplate::Triple )
		);
	}

	public function test_regular_task_answer_is_returned_as_is(): void {
		self::assertSame( '42', $this->service->getDisplayAnswer( array( 'task_answer' => '42' ), TaskTemplate::Standard ) );
	}
}
