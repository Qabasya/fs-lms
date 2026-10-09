<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;
use Inc\Shared\PluginLogger;

/**
 * Доставка событий outbox в ленту уведомлений (9.2.4).
 *
 * Событие записано в той же транзакции, что и изменение; worker после фиксации берёт строки в аренду, отдаёт их
 * {@see ExamNotificationComposer} и помечает обработанными. Повторный и параллельный запуск безопасен: аренда исключает двойную
 * обработку, а уведомления идемпотентны по ключу. Сбой одной строки откладывает её с нарастающей паузой
 * (`min( 60, 2^попытки )` минут) и не останавливает остальные; после {@see ExamOutboxEventRepository::MAX_ATTEMPTS} неудач строка
 * остаётся необработанной с `last_error` и больше не берётся.
 *
 * Недоставка уведомления допуск к старту не затрагивает: допуск проверяется при запросе, а не по уведомлению.
 */
class ExamOutboxWorker {

	/** Аренда строки, мин: за это время обработка обязана завершиться, иначе строку возьмёт другой запуск. */
	private const LEASE_MINUTES = 2;

	public function __construct(
		private readonly ExamOutboxEventRepository $outbox,
		private readonly ExamNotificationComposer $composer,
		private readonly ExamTime $time,
	) {}

	/**
	 * @return int Сколько строк обработано успешно.
	 */
	public function run( int $limit = 100 ): int {
		$now  = $this->time->nowUtc();
		$done = 0;

		foreach ( $this->outbox->leaseBatch( $now, $this->time->addMinutes( $now, self::LEASE_MINUTES ), $limit ) as $row ) {
			try {
				$this->composer->handle( $row );
				$this->outbox->markProcessed( $row->id, $this->time->nowUtc() );
				++$done;
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamOutbox', $e, array( 'outbox_id' => $row->id, 'type' => $row->type ), true );
				$this->outbox->markFailed( $row->id, $e->getMessage(), $this->time->addMinutes( $this->time->nowUtc(), min( 60, 2 ** $row->attempts ) ) );
			}
		}

		return $done;
	}
}
