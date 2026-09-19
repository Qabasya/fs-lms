<?php

declare( strict_types=1 );

namespace Unit\Managers\Assessment;

use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use PHPUnit\Framework\TestCase;

/**
 * Раскладка слотов конструктора экзамена (`task_slots`): `task_ids` плотный, поэтому без
 * раскладки задания после перезагрузки съезжали к началу списка — слот №20 занимало
 * задание №22.
 */
class AssessmentSlotLayoutTest extends TestCase {

	/** @var array<string, mixed> */
	private array $meta = array();

	private AssessmentManager $manager;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_seed_post( array( 'ID' => 50, 'post_type' => 'inf_assessments', 'post_title' => 'Э' ) );
		$this->meta = array();

		$posts = $this->createMock( PostManager::class );
		$posts->method( 'getMeta' )->willReturnCallback( fn(): array => $this->meta );
		$posts->method( 'updateMeta' )->willReturnCallback( function ( int $id, string $key, mixed $value ): bool {
			$this->meta = $value;
			return true;
		} );
		$this->manager = new AssessmentManager( $posts );
	}

	public function test_layout_with_gaps_is_kept(): void {
		$layout = array( 0, 11, 0, 0, 22 );

		self::assertTrue( $this->manager->setItemIds( 50, array( 11, 22 ), array(), array(), $layout ) );
		self::assertSame( array( 11, 22 ), $this->meta['task_ids'] );
		self::assertSame( $layout, $this->manager->slotLayout( 50 ) );
	}

	public function test_layout_that_disagrees_with_ids_is_dropped(): void {
		$this->manager->setItemIds( 50, array( 11, 22 ), array(), array(), array( 0, 22, 11 ) );

		self::assertArrayNotHasKey( 'task_slots', $this->meta );
		self::assertSame( array(), $this->manager->slotLayout( 50 ) );
	}

	public function test_no_layout_means_no_saved_layout(): void {
		$this->manager->setItemIds( 50, array( 11 ), array(), array() );

		self::assertSame( array(), $this->manager->slotLayout( 50 ) );
	}
}
