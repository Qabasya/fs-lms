<?php

declare( strict_types=1 );

namespace Unit\MetaBoxes;

use Inc\MetaBoxes\Templates\ChoiceTaskTemplate;
use Inc\MetaBoxes\Templates\FillTaskTemplate;
use Inc\MetaBoxes\Templates\MatchingTaskTemplate;
use Inc\MetaBoxes\Templates\OrderingTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use PHPUnit\Framework\TestCase;

class EditorSchemaTest extends TestCase {

	public function test_choice_template_schema_structure(): void {
		$schema = ( new ChoiceTaskTemplate() )->getEditorSchema();

		self::assertSame( 'choice_task', $schema['id'] );
		self::assertNotEmpty( $schema['label'] );
		self::assertIsArray( $schema['fields'] );

		$types = array_column( $schema['fields'], 'type' );
		self::assertContains( 'rich_text', $types );
		self::assertContains( 'options',   $types );
		self::assertContains( 'hint',      $types );
	}

	public function test_matching_template_schema_has_pairs(): void {
		$schema = ( new MatchingTaskTemplate() )->getEditorSchema();
		$types  = array_column( $schema['fields'], 'type' );
		self::assertContains( 'pairs', $types );
	}

	public function test_ordering_template_schema_has_order_items(): void {
		$schema = ( new OrderingTaskTemplate() )->getEditorSchema();
		$types  = array_column( $schema['fields'], 'type' );
		self::assertContains( 'order_items', $types );
	}

	public function test_fill_template_schema_has_gap_text(): void {
		$schema = ( new FillTaskTemplate() )->getEditorSchema();
		$types  = array_column( $schema['fields'], 'type' );
		self::assertContains( 'gap_text', $types );
	}

	public function test_every_field_has_required_keys(): void {
		$schema = ( new StandardTaskTemplate() )->getEditorSchema();
		foreach ( $schema['fields'] as $field ) {
			self::assertArrayHasKey( 'key',    $field );
			self::assertArrayHasKey( 'label',  $field );
			self::assertArrayHasKey( 'type',   $field );
			self::assertArrayHasKey( 'config', $field );
		}
	}

	public function test_all_templates_include_hint_field(): void {
		$templates = [
			new ChoiceTaskTemplate(),
			new MatchingTaskTemplate(),
			new OrderingTaskTemplate(),
			new FillTaskTemplate(),
			new StandardTaskTemplate(),
		];
		foreach ( $templates as $tpl ) {
			$keys = array_column( $tpl->getEditorSchema()['fields'], 'key' );
			self::assertContains( 'task_hint', $keys, get_class( $tpl ) . ' must have task_hint' );
		}
	}
}
