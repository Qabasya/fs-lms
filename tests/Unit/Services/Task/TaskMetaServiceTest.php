<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

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

	public function test_materials_are_read_from_attachment_ids(): void {
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
}
