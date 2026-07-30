<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\DTO\Subject\BundleOptionsDTO;
use Inc\DTO\Subject\ImportedEntitiesDTO;
use Inc\Enums\Subject\BundleSection;
use Inc\Services\Subject\Bundle\BundleSchema;
use Inc\Services\Subject\Bundle\ExportIdMapper;
use Inc\Services\Subject\Bundle\MediaIdMap;
use Inc\Services\Subject\Bundle\ProblemDeduplicator;
use PHPUnit\Framework\TestCase;

/**
 * Формат пакета: версионирование, порядок графа, карты идентификаторов
 * и журнал отката.
 */
class BundleFormatTest extends TestCase {

	// ── Версионирование формата ──────────────────────────────────────

	public function test_accepts_same_major_version(): void {
		self::assertTrue( BundleSchema::isCompatible( '1.0.0' ) );
		// Больший minor допустим: новые поля старый импортёр просто игнорирует.
		self::assertTrue( BundleSchema::isCompatible( '1.7.3' ) );
	}

	public function test_rejects_other_major_version(): void {
		self::assertFalse( BundleSchema::isCompatible( '2.0.0' ) );
		self::assertFalse( BundleSchema::isCompatible( '0.9.0' ) );
		self::assertFalse( BundleSchema::isCompatible( 'что угодно' ) );
	}

	public function test_media_path_is_prefixed_with_attachment_id(): void {
		// Префикс из ID обязателен: два разных вложения легко называются одинаково.
		self::assertSame( 'media/12__photo.jpg', BundleSchema::mediaPath( 12, 'photo.jpg' ) );
		self::assertNotSame(
			BundleSchema::mediaPath( 12, 'photo.jpg' ),
			BundleSchema::mediaPath( 34, 'photo.jpg' )
		);
	}

	// ── Граф зависимостей ────────────────────────────────────────────

	public function test_sections_are_ordered_topologically(): void {
		$order = array_map( static fn( BundleSection $s ): string => $s->value, BundleSection::cases() );

		// Каждая сущность обязана идти после всех, на кого ссылается.
		self::assertLessThan( array_search( 'works', $order, true ), array_search( 'tasks', $order, true ) );
		self::assertLessThan( array_search( 'works', $order, true ), array_search( 'problems', $order, true ) );
		self::assertLessThan( array_search( 'lessons', $order, true ), array_search( 'works', $order, true ) );
		self::assertLessThan( array_search( 'lessons', $order, true ), array_search( 'assessments', $order, true ) );
		self::assertLessThan( array_search( 'courses', $order, true ), array_search( 'lessons', $order, true ) );
	}

	public function test_section_resolves_post_type_for_subject(): void {
		self::assertSame( 'math_tasks', BundleSection::Tasks->postType( 'math' ) );
		self::assertSame( 'math_courses', BundleSection::Courses->postType( 'math' ) );
		// Банк задач глобальный — ключ предмета на него не влияет.
		self::assertSame( 'fs_lms_problems', BundleSection::Problems->postType( 'math' ) );
		self::assertSame( 'fs_lms_problems', BundleSection::Problems->postType( 'rus' ) );
	}

	public function test_only_problems_section_is_global(): void {
		foreach ( BundleSection::cases() as $section ) {
			self::assertSame(
				BundleSection::Problems === $section,
				$section->isGlobal(),
				$section->value
			);
		}
	}

	// ── Объём пакета ─────────────────────────────────────────────────

	public function test_bank_only_scope_drops_curriculum_sections(): void {
		$sections = ( new BundleOptionsDTO( includeCurriculum: false ) )->sections();
		$values   = array_map( static fn( BundleSection $s ): string => $s->value, $sections );

		self::assertSame( array( 'tasks', 'articles' ), $values );
	}

	public function test_students_are_opt_in_by_default(): void {
		$options = BundleOptionsDTO::fromRequest( array() );

		self::assertTrue( $options->includeCurriculum );
		self::assertTrue( $options->includeMedia );
		// Пакет с ПД не должен собираться «за компанию».
		self::assertFalse( $options->includeStudents );
	}

	public function test_scope_flags_are_read_from_request(): void {
		$options = BundleOptionsDTO::fromRequest( array(
			'include_curriculum' => '0',
			'include_media'      => '0',
			'include_students'   => '1',
		) );

		self::assertFalse( $options->includeCurriculum );
		self::assertFalse( $options->includeMedia );
		self::assertTrue( $options->includeStudents );
	}

	// ── Карты идентификаторов ────────────────────────────────────────

	public function test_export_id_is_namespaced_by_section(): void {
		// Числовые ID из разных банков совпадают — различает их только префикс.
		self::assertNotSame(
			ExportIdMapper::make( BundleSection::Tasks, 5 ),
			ExportIdMapper::make( BundleSection::Problems, 5 )
		);
	}

	public function test_mapper_resolves_both_directions(): void {
		$mapper = new ExportIdMapper();
		$mapper->register( BundleSection::Lessons, 42 );

		self::assertSame( 'lessons:42', $mapper->toExportId( 42 ) );
		self::assertTrue( $mapper->hasPost( 42 ) );
		self::assertNull( $mapper->toExportId( 43 ) );

		$mapper->bind( 'lessons:42', 900 );
		self::assertSame( 900, $mapper->toPostId( 'lessons:42' ) );
		self::assertNull( $mapper->toPostId( 'lessons:43' ) );
	}

	public function test_media_map_returns_null_for_unknown_key(): void {
		$map = new MediaIdMap();
		$map->bind( MediaIdMap::exportId( 7 ), 700 );

		self::assertSame( 700, $map->resolve( 'media:7' ) );
		self::assertNull( $map->resolve( 'media:8' ) );
		self::assertSame( 1, $map->count() );
	}

	// ── Дедуп глобального банка ──────────────────────────────────────

	public function test_problem_origin_id_separates_sites(): void {
		// `problems:12` с двух разных сайтов — две разные задачи.
		self::assertNotSame(
			ProblemDeduplicator::originId( 'https://a.example', 'problems:12' ),
			ProblemDeduplicator::originId( 'https://b.example', 'problems:12' )
		);

		self::assertSame(
			ProblemDeduplicator::originId( 'https://a.example', 'problems:12' ),
			ProblemDeduplicator::originId( 'https://a.example', 'problems:12' )
		);
	}

	// ── Журнал отката ────────────────────────────────────────────────

	public function test_rollback_log_ignores_reused_entities(): void {
		$created = new ImportedEntitiesDTO();

		// 0 — «термин уже был» / «вставка не удалась»: удалять нечего.
		$created->addTerm( 0, 'math_task_number' );
		$created->addPost( 0 );
		$created->addPerson( 0 );
		$created->addUser( 0 );

		self::assertTrue( $created->isEmpty() );
	}

	public function test_rollback_log_counts_created_entities(): void {
		$created = new ImportedEntitiesDTO();
		$created->addSubject( 'math' );
		$created->addPost( 10 );
		$created->addPost( 11 );
		$created->addTerm( 5, 'math_task_number' );
		$created->addAttachment( 90 );
		$created->addGroup( 3 );
		$created->addPerson( 7 );
		$created->addUser( 8 );

		self::assertFalse( $created->isEmpty() );
		self::assertSame(
			array(
				'posts'       => 2,
				'terms'       => 1,
				'attachments' => 1,
				'subjects'    => 1,
				'groups'      => 1,
				'persons'     => 1,
				'accounts'    => 1,
			),
			$created->counts()
		);
		self::assertSame( array( 10, 11 ), $created->postIds() );
		self::assertSame( array( array( 'term_id' => 5, 'taxonomy' => 'math_task_number' ) ), $created->terms() );
	}
}
