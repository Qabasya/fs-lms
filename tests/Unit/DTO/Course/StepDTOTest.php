<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\StepType;
use PHPUnit\Framework\TestCase;

class StepDTOTest extends TestCase {

	public function test_round_trip_text_step(): void {
		$dto = new StepDTO( 's1', StepType::Text, array( 'content' => 'Теория' ) );
		$rebuilt = StepDTO::fromArray( $dto->toArray() );

		self::assertSame( 's1', $rebuilt->key );
		self::assertSame( StepType::Text, $rebuilt->type );
		self::assertSame( array( 'content' => 'Теория' ), $rebuilt->payload );
	}

	public function test_round_trip_task_step_keeps_ref_and_source(): void {
		$dto     = new StepDTO( 'abc', StepType::Task, array( 'ref' => 42, 'source' => 'bank' ) );
		$rebuilt = StepDTO::fromArray( $dto->toArray() );

		self::assertSame( StepType::Task, $rebuilt->type );
		self::assertSame( 42, $rebuilt->payload['ref'] );
		self::assertSame( 'bank', $rebuilt->payload['source'] );
	}

	public function test_to_array_uses_string_type_value(): void {
		$dto = new StepDTO( 'k', StepType::Assessment, array( 'ref' => 7 ) );
		self::assertSame( 'assessment', $dto->toArray()['type'] );
	}

	public function test_from_array_defaults_on_missing_fields(): void {
		$dto = StepDTO::fromArray( array() );
		self::assertSame( '', $dto->key );
		self::assertSame( StepType::Text, $dto->type );
		self::assertSame( array(), $dto->payload );
	}

	public function test_from_array_coerces_non_array_payload(): void {
		$dto = StepDTO::fromArray( array( 'key' => 'k', 'type' => 'video', 'payload' => 'broken' ) );
		self::assertSame( array(), $dto->payload );
	}

	public function test_from_list_skips_non_array_rows_and_to_list_round_trips(): void {
		$rows = array(
			array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'content' => 'A' ) ),
			'garbage',
			array( 'key' => 's2', 'type' => 'work', 'payload' => array( 'ref' => 9 ) ),
		);

		$steps = StepDTO::fromList( $rows );
		self::assertCount( 2, $steps );
		self::assertSame( 's2', $steps[1]->key );
		self::assertSame( StepType::Work, $steps[1]->type );

		$serialized = StepDTO::toList( $steps );
		self::assertSame( 'text', $serialized[0]['type'] );
		self::assertSame( 9, $serialized[1]['payload']['ref'] );
	}

	public function test_links_of_is_unique_sorted_and_decodes_ampersand(): void {
		$links = StepDTO::linksOf( '<a href="https://b.ru/?a=1&amp;b=2">x</a> https://a.ru https://a.ru' );
		self::assertSame( array( 'https://a.ru', 'https://b.ru/?a=1&b=2' ), $links );
	}

	public function test_marked_for_review_skips_broadcast(): void {
		$step = new StepDTO( 's', StepType::Broadcast, array( 'stream_url' => 'https://t.me/x' ) );
		self::assertFalse( $step->markedForReview()->isUnreviewed() );
	}

	public function test_text_step_without_links_stays_unreviewed_until_flag_removed(): void {
		$marked = ( new StepDTO( 's', StepType::Text, array( 'content' => 'просто текст' ) ) )->markedForReview();
		self::assertTrue( $marked->isUnreviewed() );
		self::assertArrayNotHasKey( 'review_links', $marked->payload );
	}

	public function test_text_step_with_links_is_reviewed_only_when_links_change(): void {
		$marked = ( new StepDTO( 's', StepType::Text, array( 'content' => '<a href="https://a.ru/1">зал</a>' ) ) )->markedForReview();
		self::assertTrue( $marked->isUnreviewed() );

		$textEdited = new StepDTO( 's', StepType::Text, array_merge( $marked->payload, array( 'content' => 'другой текст <a href="https://a.ru/1">зал</a>' ) ) );
		self::assertTrue( $textEdited->isUnreviewed() );

		$linkEdited = new StepDTO( 's', StepType::Text, array_merge( $marked->payload, array( 'content' => '<a href="https://a.ru/2">зал</a>' ) ) );
		self::assertFalse( $linkEdited->isUnreviewed() );
	}
}
