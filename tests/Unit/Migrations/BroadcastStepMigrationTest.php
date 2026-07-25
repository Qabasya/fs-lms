<?php

declare( strict_types=1 );

namespace Unit\Migrations;

use Inc\Migrations\BroadcastStepMigration;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие одноразовой data-миграции Этапа 1: `video`-шаг с `payload.recording_slot`
 * → тип `broadcast`.
 *
 * Логика трансформации проверяется прогоном {@see BroadcastStepMigration::migrate()}
 * по явному списку post-id (поиск кандидатов — тонкая SQL-обёртка, не unit-уровень).
 * Отдельно проверяется version-gate в {@see BroadcastStepMigration::ensure()} через
 * подкласс, подставляющий список кандидатов.
 */
class BroadcastStepMigrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		unset( $GLOBALS['_test_options']['fs_lms_broadcast_migration'] );
	}

	private function migrate( array $postIds ): void {
		( new BroadcastStepMigration() )->migrate( $postIds );
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

		$this->migrate( array( 10 ) );

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

		$this->migrate( array( 11 ) );

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

		$this->migrate( array( 12 ) );
		$firstRun = $this->steps( 12 );
		$this->migrate( array( 12 ) );
		$secondRun = $this->steps( 12 );

		self::assertSame( $firstRun, $secondRun );
		self::assertCount( 1, $secondRun );
		self::assertSame( 'broadcast', $secondRun[0]['type'] );
	}

	public function test_skips_lessons_without_meta(): void {
		fs_test_seed_post(
			array( 'ID' => 20, 'post_type' => 'mat_lessons' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array( 'key' => 's1', 'type' => 'video', 'payload' => array( 'recording_slot' => true ) ),
			) ) )
		);
		// Урок без fs_lms_meta вообще — не должен приводить к фатальной ошибке.
		fs_test_seed_post( array( 'ID' => 21, 'post_type' => 'mat_lessons' ) );

		$this->migrate( array( 20, 21 ) );

		self::assertSame( 'broadcast', $this->steps( 20 )[0]['type'] );
	}

	public function test_ensure_is_version_gated_and_runs_once(): void {
		fs_test_seed_post(
			array( 'ID' => 30, 'post_type' => 'inf_lessons' ),
			array( 'fs_lms_meta' => array( 'steps' => array(
				array( 'key' => 's1', 'type' => 'video', 'payload' => array( 'recording_slot' => true ) ),
			) ) )
		);

		$migration = new class( array( 30 ) ) extends BroadcastStepMigration {
			public int $scanCalls = 0;
			/** @param int[] $ids */
			public function __construct( private array $ids ) {}
			protected function candidatePostIds(): array {
				++$this->scanCalls;
				return $this->ids;
			}
		};

		$migration->ensure();
		self::assertSame( 'broadcast', $this->steps( 30 )[0]['type'] );
		self::assertSame( '1', get_option( 'fs_lms_broadcast_migration' ) );
		self::assertSame( 1, $migration->scanCalls );

		// Повторный вызов — no-op по опции-гейту, кандидатов больше не сканирует.
		$migration->ensure();
		self::assertSame( 1, $migration->scanCalls );
	}
}
