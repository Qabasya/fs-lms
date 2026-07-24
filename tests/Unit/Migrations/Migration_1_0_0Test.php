<?php

declare( strict_types=1 );

namespace Unit\Migrations;

use Inc\Migrations\Migration_1_0_0;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие data-миграции Этапа 1: `video`-шаг с `payload.recording_slot` → тип
 * `broadcast`. Точечный тест самой миграции данных (`migrateRecordingSlotToBroadcast`),
 * БЕЗ вызова `up()` — тот гоняет dbDelta/ALTER TABLE поверх реальной схемы и не
 * рассчитан на unit-окружение (нет ABSPATH/dbDelta-стаба). Метод приватный —
 * вызывается через Reflection, публичный контракт миграции не расширяется ради теста.
 */
class Migration_1_0_0Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$GLOBALS['_test_options']['fs_lms_subjects_list'] = array(
			'inf' => array( 'key' => 'inf', 'name' => 'Информатика' ),
			'mat' => array( 'key' => 'mat', 'name' => 'Математика' ),
		);
	}

	private function runMigration(): void {
		$migration = new Migration_1_0_0();
		( new \ReflectionMethod( $migration, 'migrateRecordingSlotToBroadcast' ) )->invoke( $migration );
	}

	private function steps( int $postId ): array {
		return get_post_meta( $postId, 'fs_lms_meta', true )['steps'];
	}

	public function test_recording_slot_video_step_becomes_broadcast(): void {
		fs_test_seed_post(
			array( 'ID' => 10, 'post_type' => 'inf_lessons' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array(
					'key'     => 's1',
					'type'    => 'video',
					'payload' => array(
						'title'          => 'Запись занятия',
						'url'            => '',
						'recording_slot' => true,
						'description'    => 'черновик',
						'chapters'       => array( array( 't' => 5, 'title' => 'x' ) ),
						'attachments'    => array( 3 ),
					),
				),
			) ) )
		);

		$this->runMigration();

		$steps = $this->steps( 10 );
		self::assertCount( 1, $steps );
		self::assertSame( 's1', $steps[0]['key'] ); // key не меняется — на него завязан прогресс
		self::assertSame( 'broadcast', $steps[0]['type'] );
		self::assertSame(
			array( 'title' => 'Запись занятия', 'stream_url' => '' ),
			$steps[0]['payload']
		);
	}

	public function test_non_slot_video_step_untouched(): void {
		fs_test_seed_post(
			array( 'ID' => 11, 'post_type' => 'inf_lessons' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array( 'key' => 's1', 'type' => 'video', 'payload' => array( 'url' => 'https://youtube.com/x' ) ),
				array( 'key' => 's2', 'type' => 'text', 'payload' => array( 'content' => 'hi' ) ),
			) ) )
		);

		$this->runMigration();

		$steps = $this->steps( 11 );
		self::assertSame( 'video', $steps[0]['type'] );
		self::assertSame( 'https://youtube.com/x', $steps[0]['payload']['url'] );
		self::assertSame( 'text', $steps[1]['type'] );
	}

	public function test_migration_is_idempotent(): void {
		fs_test_seed_post(
			array( 'ID' => 12, 'post_type' => 'inf_lessons' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array( 'key' => 's1', 'type' => 'video', 'payload' => array( 'title' => 'T', 'recording_slot' => true ) ),
			) ) )
		);

		$this->runMigration();
		$firstRun = $this->steps( 12 );
		$this->runMigration();
		$secondRun = $this->steps( 12 );

		self::assertSame( $firstRun, $secondRun );
		self::assertCount( 1, $secondRun );
		self::assertSame( 'broadcast', $secondRun[0]['type'] );
	}

	public function test_covers_all_subjects_and_skips_lessons_without_meta(): void {
		fs_test_seed_post(
			array( 'ID' => 20, 'post_type' => 'mat_lessons' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array( 'key' => 's1', 'type' => 'video', 'payload' => array( 'recording_slot' => true ) ),
			) ) )
		);
		// Урок без fs_lms_meta вообще — не должен приводить к фатальной ошибке.
		fs_test_seed_post( array( 'ID' => 21, 'post_type' => 'mat_lessons' ) );

		$this->runMigration();

		self::assertSame( 'broadcast', $this->steps( 20 )[0]['type'] );
	}

	public function test_forked_and_trashed_lessons_are_included(): void {
		// Форки группы и посты в любом статусе (trash/auto-draft) тоже мигрируют —
		// PostManager::getIds() специально возвращает их все.
		fs_test_seed_post(
			array( 'ID' => 30, 'post_type' => 'inf_lessons', 'post_status' => 'trash' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array( 'key' => 's1', 'type' => 'video', 'payload' => array( 'recording_slot' => true ) ),
			) ) )
		);

		$this->runMigration();

		self::assertSame( 'broadcast', $this->steps( 30 )[0]['type'] );
	}
}
