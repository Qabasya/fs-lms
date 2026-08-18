<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Task\TaskBundleService;
use PHPUnit\Framework\TestCase;

/**
 * TaskBundleService: parent triple_task → 3 children standard_task (19/20/21).
 */
class TaskBundleServiceTest extends TestCase {

	private PostManager $posts;
	private TermManager $terms;
	private TaskBundleService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->posts   = $this->createMock( PostManager::class );
		$this->terms   = $this->createMock( TermManager::class );
		$this->service = new TaskBundleService( $this->posts, $this->terms );
	}

	private function parentPost(): \WP_Post {
		return new \WP_Post( array(
			'ID'          => 100,
			'post_type'   => 'inf_tasks',
			'post_title'  => 'Связка 19-21, Теория игр',
			'post_status' => 'publish',
		) );
	}

	public function test_creates_three_children_when_none_exist(): void {
		$parent = $this->parentPost();

		$this->posts->method( 'get' )->willReturnMap( array(
			array( 100, $parent ),
		) );
		$this->posts->method( 'taskMeta' )->with( 100 )->willReturn( array(
			'task_19_condition' => 'Условие 19',
			'task_19_answer'    => 'Ответ 19',
			'task_20_condition' => 'Условие 20',
			'task_20_answer'    => 'Ответ 20',
			'task_21_condition' => 'Условие 21',
			'task_21_answer'    => 'Ответ 21',
		) );
		$this->posts->method( 'getMeta' )->willReturn( array() );

		$this->posts->expects( self::exactly( 3 ) )
			->method( 'insert' )
			->willReturnOnConsecutiveCalls( 201, 202, 203 );

		$this->terms->method( 'getOrCreateIdByName' )->willReturnMap( array(
			array( '19', 'inf_task_number', 301 ),
			array( '20', 'inf_task_number', 302 ),
			array( '21', 'inf_task_number', 303 ),
		) );

		$calls = array();
		$this->posts->method( 'updateMeta' )
			->willReturnCallback( function ( int $id, string $key, mixed $value ) use ( &$calls ): void {
				$calls[] = array( $id, $key, $value );
			} );

		$childIds = $this->service->syncChildren( 100 );

		self::assertSame( array( 201, 202, 203 ), $childIds );
		self::assertContains(
			array( 100, PostMetaName::TaskBundleChildIds->value, array( 201, 202, 203 ) ),
			$calls
		);
	}

	public function test_updates_existing_children_instead_of_creating(): void {
		$parent = $this->parentPost();

		$this->posts->method( 'get' )->willReturnMap( array(
			array( 100, $parent ),
			array( 201, new \WP_Post( array( 'ID' => 201 ) ) ),
			array( 202, new \WP_Post( array( 'ID' => 202 ) ) ),
			array( 203, new \WP_Post( array( 'ID' => 203 ) ) ),
		) );
		$this->posts->method( 'taskMeta' )->willReturn( array() );
		$this->posts->method( 'getMeta' )
			->with( 100, PostMetaName::TaskBundleChildIds->value, true )
			->willReturn( array( 201, 202, 203 ) );

		$this->posts->expects( self::never() )->method( 'insert' );
		$this->posts->expects( self::exactly( 3 ) )->method( 'update' );

		$this->terms->method( 'getOrCreateIdByName' )->willReturn( 301 );

		$childIds = $this->service->syncChildren( 100 );

		self::assertSame( array( 201, 202, 203 ), $childIds );
	}

	public function test_returns_empty_when_parent_not_found(): void {
		$this->posts->method( 'get' )->willReturn( null );

		self::assertSame( array(), $this->service->syncChildren( 999 ) );
	}

	/**
	 * Связка глобального банка (fs_lms_problems) — без ключа предмета и без
	 * таксономии номеров; children тоже создаются в fs_lms_problems.
	 */
	public function test_bank_bundle_children_use_problems_cpt_without_taxonomy(): void {
		$parent = new \WP_Post( array(
			'ID'          => 100,
			'post_type'   => 'fs_lms_problems',
			'post_title'  => 'Тест теории игр',
			'post_status' => 'publish',
		) );

		$this->posts->method( 'get' )->willReturnMap( array(
			array( 100, $parent ),
		) );
		$this->posts->method( 'taskMeta' )->willReturn( array(
			'task_19_condition' => 'Условие 19',
			'task_19_answer'    => 'Ответ 19',
			'task_20_condition' => 'Условие 20',
			'task_20_answer'    => 'Ответ 20',
			'task_21_condition' => 'Условие 21',
			'task_21_answer'    => 'Ответ 21',
		) );
		$this->posts->method( 'getMeta' )->willReturn( array() );

		$insertedTypes = array();
		$this->posts->method( 'insert' )
			->willReturnCallback( function ( array $data ) use ( &$insertedTypes ) {
				$insertedTypes[] = $data['post_type'];
				return 200 + count( $insertedTypes );
			} );

		$this->terms->expects( self::never() )->method( 'getOrCreateIdByName' );
		$this->terms->expects( self::never() )->method( 'setPostTerms' );

		$childIds = $this->service->syncChildren( 100 );

		self::assertCount( 3, $childIds );
		self::assertSame( array( 'fs_lms_problems', 'fs_lms_problems', 'fs_lms_problems' ), $insertedTypes );
	}

	public function test_children_summary_returns_id_and_title(): void {
		$this->posts->method( 'getMeta' )
			->with( 100, PostMetaName::TaskBundleChildIds->value, true )
			->willReturn( array( 201, 202, 203 ) );
		$this->posts->method( 'get' )->willReturnMap( array(
			array( 201, new \WP_Post( array( 'ID' => 201, 'post_title' => '№ 19. Связка' ) ) ),
			array( 202, new \WP_Post( array( 'ID' => 202, 'post_title' => '№ 20. Связка' ) ) ),
			array( 203, new \WP_Post( array( 'ID' => 203, 'post_title' => '№ 21. Связка' ) ) ),
		) );

		self::assertSame(
			array(
				array( 'id' => 201, 'title' => '№ 19. Связка', 'number' => '19' ),
				array( 'id' => 202, 'title' => '№ 20. Связка', 'number' => '20' ),
				array( 'id' => 203, 'title' => '№ 21. Связка', 'number' => '21' ),
			),
			$this->service->childrenSummary( 100 )
		);
	}

	public function test_children_summary_empty_for_plain_task(): void {
		$this->posts->method( 'getMeta' )->willReturn( '' );

		self::assertSame( array(), $this->service->childrenSummary( 100 ) );
	}

	public function test_cascade_status_updates_all_children(): void {
		$this->posts->method( 'getMeta' )
			->with( 100, PostMetaName::TaskBundleChildIds->value, true )
			->willReturn( array( 201, 202, 203 ) );

		$this->posts->expects( self::exactly( 3 ) )
			->method( 'updateStatus' )
			->willReturnMap( array(
				array( 201, 'trash', true ),
				array( 202, 'trash', true ),
				array( 203, 'trash', true ),
			) );

		$this->service->cascadeStatus( 100, 'trash' );
	}

	public function test_cascade_status_noop_without_children_meta(): void {
		$this->posts->method( 'getMeta' )->willReturn( '' );

		$this->posts->expects( self::never() )->method( 'updateStatus' );

		$this->service->cascadeStatus( 100, 'trash' );
	}
}
