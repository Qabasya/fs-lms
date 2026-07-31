<?php

declare( strict_types=1 );

namespace Unit\Services\Subject;

use Inc\Services\Subject\TaskNumberTermGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskNumberTermGuardTest extends TestCase {

	private TaskNumberTermGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_terms']        = array();
		$GLOBALS['_fs_test_term_objects'] = array();
		$GLOBALS['_fs_test_term_objs']     = array();
		$GLOBALS['_fs_test_filter_returns'] = array();
		$this->guard               = new TaskNumberTermGuard();
	}

	/** @return array<string, array{0:string}> */
	public static function invalidNames(): array {
		return array(
			'буквы'        => array( 'пять' ),
			'номер с текстом' => array( '5 задание' ),
			'ноль'         => array( '0' ),
			'отрицательное' => array( '-3' ),
			'дробное'      => array( '2.5' ),
			'с пробелом внутри' => array( '1 2' ),
		);
	}

	// ── Создание ──────────────────────────────────────────────

	public function test_insert_accepts_positive_integer(): void {
		self::assertSame( '5', $this->guard->validateInsert( '5', 'inf_task_number' ) );
	}

	#[DataProvider( 'invalidNames' )]
	public function test_insert_rejects_non_number( string $name ): void {
		$result = $this->guard->validateInsert( $name, 'inf_task_number' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'fs_lms_invalid_task_number', $result->get_error_code() );
	}

	public function test_insert_ignores_other_taxonomies(): void {
		self::assertSame( 'любое имя', $this->guard->validateInsert( 'любое имя', 'inf_topics' ) );
	}

	// ── Переименование (регрессия: защиты не было вовсе) ──────

	public function test_update_allows_positive_integer(): void {
		$this->guard->validateUpdate( 12, 'inf_task_number', array( 'name' => '7' ) );

		self::assertTrue( true ); // wp_die не бросился — правка разрешена
	}

	#[DataProvider( 'invalidNames' )]
	public function test_update_rejects_non_number( string $name ): void {
		$this->expectException( \FsTestWpDie::class );
		$this->expectExceptionMessage( 'Номер задания должен быть целым положительным числом' );

		$this->guard->validateUpdate( 12, 'inf_task_number', array( 'name' => $name ) );
	}

	public function test_update_rejects_number_taken_by_another_term(): void {
		$GLOBALS['_fs_test_terms']['inf_task_number']['7'] = 99;

		$this->expectException( \FsTestWpDie::class );
		$this->expectExceptionMessage( 'Задание №7 уже существует' );

		$this->guard->validateUpdate( 12, 'inf_task_number', array( 'name' => '7' ) );
	}

	public function test_update_allows_saving_term_under_its_own_number(): void {
		$GLOBALS['_fs_test_terms']['inf_task_number']['7'] = 12;

		$this->guard->validateUpdate( 12, 'inf_task_number', array( 'name' => '7' ) );

		self::assertTrue( true ); // тот же терм — не дубликат самого себя
	}

	public function test_update_ignores_other_taxonomies(): void {
		$this->guard->validateUpdate( 12, 'inf_topics', array( 'name' => 'Алгоритмы' ) );

		self::assertTrue( true );
	}

	public function test_update_ignores_call_without_args(): void {
		// `edit_terms` срабатывает и внутри wp_insert_term — там $args нет.
		$this->guard->validateUpdate( 12, 'inf_task_number' );

		self::assertTrue( true );
	}

	// ── Слаг ──────────────────────────────────────────────────

	public function test_normalize_slug_uses_subject_prefix(): void {
		$data = $this->guard->normalizeSlug( array( 'name' => '5', 'slug' => '' ), 'inf_task_number' );

		self::assertSame( 'inf_5', $data['slug'] );
	}

	// ── Удаление ──────────────────────────────────────────────

	private function registerTerm( int $id, string $name, string $taxonomy, array $attachedPosts = array() ): void {
		$GLOBALS['_fs_test_term_objs'][ $id ]    = new \WP_Term( $id, $name, $taxonomy );
		$GLOBALS['_fs_test_term_objects'][ $id ] = $attachedPosts;
	}

	public function test_delete_allowed_for_unused_number(): void {
		$this->registerTerm( 20, '9', 'inf_task_number' );

		$this->guard->preventDeleteWithContent( 20, 'inf_task_number' );

		self::assertTrue( true ); // wp_die не бросился
	}

	public function test_delete_blocked_when_tasks_attached(): void {
		$this->registerTerm( 20, '1', 'inf_task_number', array( 101, 102, 103 ) );

		$this->expectException( \FsTestWpDie::class );
		$this->expectExceptionMessage( 'Нельзя удалить номер задания «1»: к нему привязано записей — 3' );

		$this->guard->preventDeleteWithContent( 20, 'inf_task_number' );
	}

	public function test_delete_ignores_other_taxonomies(): void {
		$this->registerTerm( 20, 'Алгоритмы', 'inf_topics', array( 101 ) );

		$this->guard->preventDeleteWithContent( 20, 'inf_topics' );

		self::assertTrue( true );
	}

	public function test_delete_passes_through_when_bypass_filter_raised(): void {
		// Снос предмета целиком / откат импорта — терм обязан уйти с записями.
		$this->registerTerm( 20, '1', 'inf_task_number', array( 101, 102 ) );
		$GLOBALS['_fs_test_filter_returns'][ TaskNumberTermGuard::BYPASS_FILTER ] = true;

		$this->guard->preventDeleteWithContent( 20, 'inf_task_number' );

		self::assertTrue( true );
	}

	public function test_delete_capability_denied_for_busy_number(): void {
		$this->registerTerm( 20, '1', 'inf_task_number', array( 101 ) );

		$caps = $this->guard->restrictDeleteCapability( array( 'manage_categories' ), 'delete_term', 1, array( 20 ) );

		self::assertSame( array( 'do_not_allow' ), $caps );
	}

	public function test_delete_capability_untouched_for_free_number(): void {
		$this->registerTerm( 20, '9', 'inf_task_number' );

		$caps = $this->guard->restrictDeleteCapability( array( 'manage_categories' ), 'delete_term', 1, array( 20 ) );

		self::assertSame( array( 'manage_categories' ), $caps );
	}

	public function test_delete_capability_untouched_for_other_caps(): void {
		$this->registerTerm( 20, '1', 'inf_task_number', array( 101 ) );

		$caps = $this->guard->restrictDeleteCapability( array( 'edit_posts' ), 'edit_term', 1, array( 20 ) );

		self::assertSame( array( 'edit_posts' ), $caps );
	}
}
