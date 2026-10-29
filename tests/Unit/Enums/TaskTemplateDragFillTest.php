<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Subject\TaskTemplate;
use Inc\MetaBoxes\Templates\DragFillTaskTemplate;
use PHPUnit\Framework\TestCase;

class TaskTemplateDragFillTest extends TestCase {

	public function test_value_label_and_class(): void {
		self::assertSame( 'drag_fill_task', TaskTemplate::DragFill->value );
		self::assertSame( 'Перетаскивание пропусков', TaskTemplate::DragFill->label() );
		self::assertSame( DragFillTaskTemplate::class, TaskTemplate::DragFill->class() );
		self::assertSame( 'drag_fill_task', ( new DragFillTaskTemplate() )->get_id() );
	}

	public function test_from_database(): void {
		self::assertSame( TaskTemplate::DragFill, TaskTemplate::fromDatabase( 'drag_fill_task' ) );
	}

	public function test_no_inline_check_and_no_manual_review(): void {
		self::assertFalse( TaskTemplate::DragFill->allowsInlineCheck() );
		self::assertFalse( TaskTemplate::DragFill->needsManualReview() );
	}

	public function test_template_has_condition_and_drag_fill_fields(): void {
		$fields = ( new DragFillTaskTemplate() )->get_fields();

		self::assertArrayHasKey( 'task_condition', $fields );
		self::assertArrayHasKey( 'task_drag_fill', $fields );
	}
}
