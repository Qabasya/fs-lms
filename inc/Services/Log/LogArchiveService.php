<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\OptionsRepositories\LogArchiveStateRepository;
use Inc\Repositories\WPDBRepositories\Log\LogArchiveRepository;
use Inc\Services\Export\OneTimeDownloadService;
use RuntimeException;
use ZipArchive;

/**
 * Class LogArchiveService
 *
 * Ручной месячный архив «тяжёлых» журналов: скачал ZIP — очистил то, что в него вошло.
 *
 * ### Порядок работы администратора
 *
 * 1. {@see status()} показывает, что попадёт в архив: журналы {@see LogChannel::archivable()}, записи старше
 *    границы (первое число прошлого месяца — так в ноябре уйдёт сентябрь, а октябрь останется «с захлёстом»),
 *    лента событий обучения — целиком.
 * 2. {@see export()} собирает ZIP (по CSV на журнал + `manifest.json`), запоминает границу и наибольший `id`
 *    каждого журнала и отдаёт одноразовую ссылку.
 * 3. {@see purge()} удаляет ровно то, что вошло в последний архив (`created_at < граница AND id <= max_id`);
 *    без архива очистка отказывает. Записи, появившиеся после архива, не затрагиваются.
 * 4. {@see restore()} (WP-CLI `wp fs-lms logs import`) возвращает строки из ZIP; занятые `id` пропускаются.
 *
 * ### Формат CSV
 *
 * UTF-8, все колонки таблицы как есть (включая `id`), первая строка — имена колонок, SQL NULL — `\N`.
 * Данные не «обезвреживаются» от формул: файл — резервная копия для восстановления, а не отчёт для Excel.
 */
class LogArchiveService {

	/** Размер порции при чтении и удалении. */
	private const BATCH = 2000;

	/** Маркер SQL NULL в CSV (как в `mysqldump --tab`). */
	private const NULL_MARK = '\N';

	/** Имя манифеста в ZIP. */
	private const MANIFEST = 'manifest.json';

	/**
	 * @param LogArchiveRepository      $logs      Чтение/удаление/вставка строк журналов
	 * @param LogArchiveStateRepository $state     Состояние последнего архива
	 * @param OneTimeDownloadService    $downloads Одноразовая ссылка на ZIP
	 * @param ExportLogWriter           $exportLog Запись факта выгрузки и очистки в журнал экспорта
	 * @param ClockInterface            $clock     Текущее время сайта
	 */
	public function __construct(
		private readonly LogArchiveRepository $logs,
		private readonly LogArchiveStateRepository $state,
		private readonly OneTimeDownloadService $downloads,
		private readonly ExportLogWriter $exportLog,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Граница архива: первое число прошлого месяца, 00:00 (местное время сайта).
	 */
	public function cutoff(): string {
		return ( new \DateTimeImmutable( $this->clock->now() ) )
			->modify( 'first day of last month' )
			->setTime( 0, 0 )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Что попадёт в архив сейчас и что сделано с последним.
	 *
	 * @return array{cutoff: string, channels: array<int, array{key: string, label: string, count: int, whole: bool}>, last: array<string, mixed>|null, can_purge: bool}
	 */
	public function status(): array {
		$cutoff   = $this->cutoff();
		$channels = array();
		foreach ( LogChannel::archivable() as $channel ) {
			$channels[] = array(
				'key'   => $channel->value,
				'label' => $channel->label(),
				'count' => $this->logs->countBefore( $channel, $this->boundary( $channel, $cutoff ) ),
				'whole' => $channel->archivesWholeTable(),
			);
		}

		$last = $this->state->get();

		return array(
			'cutoff'    => $cutoff,
			'channels'  => $channels,
			'last'      => $last,
			'can_purge' => null !== $last && empty( $last['purged_at'] ),
		);
	}

	/**
	 * Собирает ZIP и возвращает одноразовую ссылку. Состояние архива запоминается только после того,
	 * как файл собран целиком, — недокачанный архив очистку не открывает.
	 *
	 * @return string Одноразовый URL
	 *
	 * @throws RuntimeException Если ZIP не удалось собрать
	 */
	public function export( int $userId ): string {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new RuntimeException( 'На сервере нет расширения zip.' );
		}

		$this->raiseLimits();

		$cutoff   = $this->cutoff();
		$tmpDir   = trailingslashit( get_temp_dir() ) . 'fs-lms-log-archive-' . wp_generate_password( 8, false );
		wp_mkdir_p( $tmpDir );

		$zipPath  = $tmpDir . '/archive.zip';
		$manifest = array(
			'plugin_version' => defined( 'FS_LMS_VERSION' ) ? FS_LMS_VERSION : '',
			'site'           => home_url(),
			'created_at'     => $this->clock->now(),
			'cutoff'         => $cutoff,
			'channels'       => array(),
		);
		$channels = array();
		$files    = array();

		try {
			foreach ( LogChannel::archivable() as $channel ) {
				$before = $this->boundary( $channel, $cutoff );
				$maxId  = $this->logs->maxIdBefore( $channel, $before );
				$path   = $tmpDir . '/' . $channel->value . '.csv';
				$count  = $this->writeCsv( $channel, $before, $maxId, $path );

				$columns                           = $this->logs->columns( $channel );
				$files[ $channel->value . '.csv' ] = $path;
				$channels[ $channel->value ]       = array( 'count' => $count, 'max_id' => $maxId );
				$manifest['channels'][ $channel->value ] = array(
					'table'   => $channel->tableName()->prefixed(),
					'count'   => $count,
					'max_id'  => $maxId,
					'before'  => $before,
					'columns' => $columns,
				);
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Не удалось создать ZIP-архив.' );
			}
			$zip->addFromString( self::MANIFEST, (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			foreach ( $files as $name => $path ) {
				$zip->addFile( $path, $name );
			}
			if ( ! $zip->close() ) {
				throw new RuntimeException( 'Не удалось записать ZIP-архив.' );
			}

			$day = substr( $cutoff, 0, 10 );
			$url = $this->downloads->forFile( $zipPath, 'fs-lms-logs-before-' . $day . '.zip', 'application/zip' );
		} finally {
			foreach ( $files as $path ) {
				wp_delete_file( $path );
			}
			wp_delete_file( $zipPath );
			@rmdir( $tmpDir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- каталог мог уже уйти вместе с файлом
		}

		$this->state->set(
			array(
				'cutoff'      => $cutoff,
				'exported_at' => $this->clock->now(),
				'user_id'     => $userId,
				'channels'    => $channels,
				'purged_at'   => null,
			)
		);
		$this->exportLog->record(
			'log_archive',
			'bulk',
			array(),
			'export',
			array_map( static fn( array $c ): int => $c['count'], $channels )
		);

		return $url;
	}

	/**
	 * Удаляет из журналов то, что вошло в последний скачанный архив.
	 *
	 * @return array<string, int> Сколько строк удалено по каждому журналу
	 *
	 * @throws RuntimeException Если архив не скачивали или очистка уже выполнена
	 */
	public function purge(): array {
		$state = $this->state->get();
		if ( null === $state ) {
			throw new RuntimeException( 'Сначала скачайте архив: очистка удаляет только то, что в него вошло.' );
		}
		if ( ! empty( $state['purged_at'] ) ) {
			throw new RuntimeException( 'Этот архив уже очищен. Скачайте новый архив.' );
		}

		$this->raiseLimits();

		$deleted = array();
		foreach ( LogChannel::archivable() as $channel ) {
			$saved = $state['channels'][ $channel->value ] ?? null;
			if ( ! is_array( $saved ) ) {
				continue;
			}

			$before = $this->boundary( $channel, (string) $state['cutoff'] );
			$maxId  = (int) $saved['max_id'];
			$total  = 0;
			while ( $maxId > 0 ) {
				$removed = $this->logs->deleteBatchBefore( $channel, $before, $maxId, self::BATCH );
				$total  += $removed;
				if ( $removed < self::BATCH ) {
					break;
				}
			}
			$deleted[ $channel->value ] = $total;
		}

		$state['purged_at'] = $this->clock->now();
		$this->state->set( $state );
		$this->exportLog->record( 'log_archive', 'purge', array(), 'delete', $deleted );

		return $deleted;
	}

	/**
	 * Возвращает строки из ZIP-архива в таблицы журналов. Уже существующие `id` пропускаются,
	 * поэтому повторный запуск безопасен.
	 *
	 * @param string $zipPath Путь к ZIP, собранному {@see export()}
	 *
	 * @return array<string, int> Сколько строк вставлено по каждому журналу
	 *
	 * @throws RuntimeException Если архив не открывается или в нём нет манифеста
	 */
	public function restore( string $zipPath ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zipPath ) ) {
			throw new RuntimeException( 'Не удалось открыть архив: ' . $zipPath );
		}
		if ( false === $zip->locateName( self::MANIFEST ) ) {
			$zip->close();
			throw new RuntimeException( 'В архиве нет manifest.json — это не архив журналов fs-lms.' );
		}

		$this->raiseLimits();

		$restored = array();
		foreach ( LogChannel::archivable() as $channel ) {
			$stream = $zip->getStream( $channel->value . '.csv' );
			if ( false === $stream ) {
				continue;
			}
			$restored[ $channel->value ] = $this->readCsv( $channel, $stream );
			fclose( $stream );
		}
		$zip->close();

		return $restored;
	}

	/**
	 * Граница по дате для канала: у «целиком» — без границы, у остальных — граница архива.
	 */
	private function boundary( LogChannel $channel, string $cutoff ): ?string {
		return $channel->archivesWholeTable() ? null : $cutoff;
	}

	/**
	 * Пишет строки архива канала в CSV порциями.
	 *
	 * @return int Сколько строк записано
	 */
	private function writeCsv( LogChannel $channel, ?string $before, int $maxId, string $path ): int {
		$handle = fopen( $path, 'w' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Не удалось создать временный файл архива.' );
		}

		$columns = $this->logs->columns( $channel );
		fputcsv( $handle, $columns, ',', '"', '\\' );

		$count   = 0;
		$afterId = 0;
		while ( $maxId > 0 ) {
			$rows = $this->logs->batchBefore( $channel, $before, $afterId, $maxId, self::BATCH );
			if ( array() === $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$cells = array();
				foreach ( $columns as $column ) {
					$cells[] = null === $row[ $column ] ? self::NULL_MARK : (string) $row[ $column ];
				}
				fputcsv( $handle, $cells, ',', '"', '\\' );
				++$count;
			}
			$afterId = (int) end( $rows )['id'];
		}

		fclose( $handle );

		return $count;
	}

	/**
	 * Читает CSV канала из потока ZIP и вставляет строки порциями.
	 *
	 * @param resource $stream Поток CSV
	 *
	 * @return int Сколько строк вставлено
	 */
	private function readCsv( LogChannel $channel, $stream ): int {
		$header = fgetcsv( $stream, 0, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			return 0;
		}
		$header = array_map( 'strval', $header );

		$inserted = 0;
		$batch    = array();
		while ( false !== ( $line = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) {
			if ( array( null ) === $line || count( $line ) !== count( $header ) ) {
				continue;
			}
			$batch[] = array_map( static fn( $cell ): ?string => self::NULL_MARK === $cell ? null : (string) $cell, $line );
			if ( count( $batch ) >= self::BATCH ) {
				$inserted += $this->logs->insertIgnore( $channel, $header, $batch );
				$batch     = array();
			}
		}
		$inserted += $this->logs->insertIgnore( $channel, $header, $batch );

		return $inserted;
	}

	/** Архив и очистка идут минуты на больших таблицах: лимиты времени и памяти снимаем на этот запрос. */
	private function raiseLimits(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- функция может быть отключена хостингом
		}
		wp_raise_memory_limit( 'admin' );
	}
}
