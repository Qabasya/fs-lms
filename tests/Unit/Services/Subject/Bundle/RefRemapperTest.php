<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Enums\Subject\BundleSection;
use Inc\Services\Subject\Bundle\ExportIdMapper;
use Inc\Services\Subject\Bundle\MediaIdMap;
use Inc\Services\Subject\Bundle\RefRemapper;
use PHPUnit\Framework\TestCase;

/**
 * Ремап ссылок между записями и вложениями внутри меты.
 *
 * Это ядро корректности переноса: если ссылка уедет неверной, работа на целевом
 * сайте покажет чужое задание, а не сломается заметным образом.
 */
class RefRemapperTest extends TestCase {

	public function test_export_replaces_work_item_ids_with_export_ids(): void {
		$mapper = new ExportIdMapper();
		$mapper->register( BundleSection::Tasks, 11 );
		$mapper->register( BundleSection::Tasks, 12 );

		$remapped = ( new RefRemapper() )->toExportIds(
			array( 'work_type' => 'homework', 'item_ids' => array( 11, 12 ) ),
			$mapper
		);

		self::assertSame( array( 'tasks:11', 'tasks:12' ), $remapped['item_ids'] );
		self::assertSame( 'homework', $remapped['work_type'], 'нессылочные поля не должны меняться' );
	}

	public function test_export_reaches_refs_nested_in_lesson_steps(): void {
		$mapper = new ExportIdMapper();
		$mapper->register( BundleSection::Works, 55 );

		$remapped = ( new RefRemapper() )->toExportIds(
			array(
				'steps' => array(
					array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'content' => 'привет' ) ),
					array( 'key' => 's2', 'type' => 'work', 'payload' => array( 'ref' => 55 ) ),
				),
			),
			$mapper
		);

		self::assertSame( 'works:55', $remapped['steps'][1]['payload']['ref'] );
		self::assertSame( 'привет', $remapped['steps'][0]['payload']['content'] );
	}

	public function test_export_drops_unresolvable_list_refs_and_reports_them(): void {
		$mapper = new ExportIdMapper();
		$mapper->register( BundleSection::Tasks, 11 );

		$remapper = new RefRemapper();
		$remapped = $remapper->toExportIds( array( 'task_ids' => array( 11, 999 ) ), $mapper );

		// Чужой ID переносить нельзя: на целевом сайте он указал бы на произвольную запись.
		self::assertSame( array( 'tasks:11' ), $remapped['task_ids'] );
		self::assertCount( 1, $remapper->droppedRefs() );
		self::assertStringContainsString( '999', $remapper->droppedRefs()[0] );
	}

	public function test_export_zeroes_unresolvable_scalar_ref_keeping_step_structure(): void {
		$remapper = new RefRemapper();
		$remapped = $remapper->toExportIds(
			array( 'steps' => array( array( 'type' => 'task', 'payload' => array( 'ref' => 777 ) ) ) ),
			new ExportIdMapper()
		);

		// Ключ остаётся: шаг без `ref` структурно битый.
		self::assertArrayHasKey( 'ref', $remapped['steps'][0]['payload'] );
		self::assertSame( 0, $remapped['steps'][0]['payload']['ref'] );
	}

	public function test_export_converts_attachment_refs_to_media_keys(): void {
		$remapped = ( new RefRemapper() )->toExportIds(
			array(
				'task_materials' => array( 'attachment_ids' => array( 7, 9 ) ),
				'task_audio'     => array( 'attachment_id' => 3 ),
			),
			new ExportIdMapper()
		);

		self::assertSame( array( 'media:7', 'media:9' ), $remapped['task_materials']['attachment_ids'] );
		self::assertSame( 'media:3', $remapped['task_audio']['attachment_id'] );
	}

	public function test_import_restores_post_and_media_ids_from_keys(): void {
		$mapper = new ExportIdMapper();
		$mapper->bind( 'tasks:11', 501 );
		$mapper->bind( 'works:55', 777 );

		$media = new MediaIdMap();
		$media->bind( 'media:7', 900 );

		$remapped = ( new RefRemapper() )->toPostIds(
			array(
				'item_ids'       => array( 'tasks:11' ),
				'steps'          => array( array( 'payload' => array( 'ref' => 'works:55' ) ) ),
				'task_materials' => array( 'attachment_ids' => array( 'media:7' ) ),
			),
			$mapper,
			$media
		);

		self::assertSame( array( 501 ), $remapped['item_ids'] );
		self::assertSame( 777, $remapped['steps'][0]['payload']['ref'] );
		self::assertSame( array( 900 ), $remapped['task_materials']['attachment_ids'] );
	}

	public function test_import_drops_media_refs_absent_from_package(): void {
		$remapper = new RefRemapper();

		$remapped = $remapper->toPostIds(
			array( 'task_materials' => array( 'attachment_ids' => array( 'media:7' ) ) ),
			new ExportIdMapper(),
			new MediaIdMap()
		);

		// Пакет собран без медиа — ссылка не должна превратиться в чужой ID.
		self::assertSame( array(), $remapped['task_materials']['attachment_ids'] );
		self::assertNotEmpty( $remapper->droppedRefs() );
	}

	public function test_round_trip_preserves_reference_graph(): void {
		$meta = array(
			'item_ids' => array( 11, 12 ),
			'steps'    => array( array( 'payload' => array( 'ref' => 55 ) ) ),
		);

		$exportMap = new ExportIdMapper();
		$exportMap->register( BundleSection::Tasks, 11 );
		$exportMap->register( BundleSection::Tasks, 12 );
		$exportMap->register( BundleSection::Works, 55 );

		$packed = ( new RefRemapper() )->toExportIds( $meta, $exportMap );

		// На целевом сайте те же сущности получили другие WP ID.
		$importMap = new ExportIdMapper();
		$importMap->bind( 'tasks:11', 101 );
		$importMap->bind( 'tasks:12', 102 );
		$importMap->bind( 'works:55', 155 );

		$restored = ( new RefRemapper() )->toPostIds( $packed, $importMap, new MediaIdMap() );

		self::assertSame( array( 101, 102 ), $restored['item_ids'] );
		self::assertSame( 155, $restored['steps'][0]['payload']['ref'] );
	}
}
