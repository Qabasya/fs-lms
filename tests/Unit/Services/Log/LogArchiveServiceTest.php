<?php

declare( strict_types=1 );

namespace Unit\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\OptionsRepositories\LogArchiveStateRepository;
use Inc\Repositories\WPDBRepositories\Log\LogArchiveRepository;
use Inc\Services\Export\OneTimeDownloadService;
use Inc\Services\Log\ExportLogWriter;
use Inc\Services\Log\LogArchiveService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Месячный архив журналов: граница «с захлёстом», статус и условия очистки.
 * Сборка ZIP и восстановление проверяются на живой БД (нужны файловая система и WordPress).
 */
final class LogArchiveServiceTest extends TestCase {

	private LogArchiveRepository&MockObject $logs;
	private LogArchiveStateRepository&MockObject $state;
	private ExportLogWriter&MockObject $exportLog;
	private ClockInterface&MockObject $clock;
	private LogArchiveService $service;

	protected function setUp(): void {
		$this->logs      = $this->createMock( LogArchiveRepository::class );
		$this->state     = $this->createMock( LogArchiveStateRepository::class );
		$this->exportLog = $this->createMock( ExportLogWriter::class );
		$this->clock     = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2026-11-14 12:30:00' );

		$this->service = new LogArchiveService(
			$this->logs,
			$this->state,
			$this->createMock( OneTimeDownloadService::class ),
			$this->exportLog,
			$this->clock
		);
	}

	public function test_cutoff_is_first_day_of_previous_month(): void {
		// В ноябре уходит сентябрь и старше, октябрь остаётся.
		self::assertSame( '2026-10-01 00:00:00', $this->service->cutoff() );
	}

	public function test_cutoff_crosses_year_boundary(): void {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2027-01-05 08:00:00' );
		$service = new LogArchiveService( $this->logs, $this->state, $this->createMock( OneTimeDownloadService::class ), $this->exportLog, $clock );

		self::assertSame( '2026-12-01 00:00:00', $service->cutoff() );
	}

	public function test_status_counts_whole_table_for_learning_events_and_cutoff_for_the_rest(): void {
		$this->state->method( 'get' )->willReturn( null );
		$calls = array();
		$this->logs->method( 'countBefore' )->willReturnCallback(
			static function ( LogChannel $channel, ?string $before ) use ( &$calls ): int {
				$calls[ $channel->value ] = $before;
				return 7;
			}
		);

		$status = $this->service->status();

		self::assertSame( '2026-10-01 00:00:00', $calls['entity_audit'] );
		self::assertSame( '2026-10-01 00:00:00', $calls['auth'] );
		self::assertSame( '2026-10-01 00:00:00', $calls['exams'] );
		self::assertNull( $calls['learning_events'], 'События обучения архивируются целиком, без границы по дате.' );
		self::assertFalse( $status['can_purge'] );
		self::assertCount( 4, $status['channels'] );
	}

	public function test_purge_is_refused_without_downloaded_archive(): void {
		$this->state->method( 'get' )->willReturn( null );
		$this->logs->expects( self::never() )->method( 'deleteBatchBefore' );

		$this->expectException( \RuntimeException::class );
		$this->service->purge();
	}

	public function test_purge_is_refused_when_archive_is_already_purged(): void {
		$this->state->method( 'get' )->willReturn( $this->savedState( '2026-11-14 13:00:00' ) );
		$this->logs->expects( self::never() )->method( 'deleteBatchBefore' );

		$this->expectException( \RuntimeException::class );
		$this->service->purge();
	}

	public function test_purge_deletes_only_what_was_archived(): void {
		$this->state->method( 'get' )->willReturn( $this->savedState( null ) );
		$seen = array();
		$this->logs->method( 'deleteBatchBefore' )->willReturnCallback(
			static function ( LogChannel $channel, ?string $before, int $maxId ) use ( &$seen ): int {
				$seen[ $channel->value ] = array( $before, $maxId );
				return 5; // меньше порции — цикл останавливается
			}
		);
		$this->state->expects( self::once() )->method( 'set' )->with( self::callback(
			static fn( array $state ): bool => ! empty( $state['purged_at'] )
		) );

		$deleted = $this->service->purge();

		// Граница и наибольший id — из скачанного архива, а не пересчитанные заново.
		self::assertSame( array( '2026-10-01 00:00:00', 900 ), $seen['entity_audit'] );
		self::assertSame( array( null, 40 ), $seen['learning_events'] );
		self::assertSame( 5, $deleted['auth'] );
	}

	public function test_purge_skips_channel_without_archived_rows(): void {
		$state = $this->savedState( null );
		$state['channels']['exams'] = array( 'count' => 0, 'max_id' => 0 );
		$this->state->method( 'get' )->willReturn( $state );
		$channels = array();
		$this->logs->method( 'deleteBatchBefore' )->willReturnCallback(
			static function ( LogChannel $channel ) use ( &$channels ): int {
				$channels[] = $channel->value;
				return 0;
			}
		);

		$this->service->purge();

		self::assertNotContains( 'exams', $channels );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function savedState( ?string $purgedAt ): array {
		return array(
			'cutoff'      => '2026-10-01 00:00:00',
			'exported_at' => '2026-11-14 12:00:00',
			'user_id'     => 1,
			'purged_at'   => $purgedAt,
			'channels'    => array(
				'entity_audit'    => array( 'count' => 120, 'max_id' => 900 ),
				'auth'            => array( 'count' => 15, 'max_id' => 77 ),
				'exams'           => array( 'count' => 3, 'max_id' => 12 ),
				'learning_events' => array( 'count' => 40, 'max_id' => 40 ),
			),
		);
	}
}
