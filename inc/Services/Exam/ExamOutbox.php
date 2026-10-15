<?php
/**
 * Запись событий в outbox. Вызывается внутри транзакции вызывающего сервиса и сама транзакций
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;

/**
 * Запись событий в outbox. Вызывается внутри транзакции вызывающего сервиса и сама транзакций
 * не открывает: событие и изменение данных фиксируются или откатываются вместе.
 *
 * В payload — только идентификаторы: ключи доступа, ФИО и телефоны класть нельзя.
 */
class ExamOutbox {

	/** Получает хранилище доменных событий и единый источник UTC-времени. */
	public function __construct(
		private readonly ExamOutboxEventRepository $events,
		private readonly ExamTime $time,
	) {}

	/**
	 * Есть ли необработанные события у агрегатов (уведомления ещё не разосланы).
	 *
	 * @param int[] $aggregateIds
	 */
	public function hasPending( string $aggregateType, array $aggregateIds ): bool {
		return $this->events->countPending( $aggregateType, $aggregateIds ) > 0;
	}

	/**
	 * Записывает доменное событие в outbox в транзакции вызывающей операции.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws \RuntimeException Если запись не удалась — транзакция вызывающего должна откатиться.
	 */
	public function add(
		ExamOutboxEvent $type,
		string $aggregateType,
		int $aggregateId,
		int $aggregateVersion,
		array $payload,
		?string $availableAtUtc = null,
	): void {
		$now = $this->time->nowUtc();

		$id = $this->events->insert(
			array(
				'event_uuid'        => wp_generate_uuid4(),
				'type'              => $type->value,
				'aggregate_type'    => $aggregateType,
				'aggregate_id'      => $aggregateId,
				'aggregate_version' => $aggregateVersion,
				'payload'           => (string) wp_json_encode( $payload ),
				'available_at'      => $availableAtUtc ?? $now,
				'created_at'        => $now,
			)
		);

		if ( 0 === $id ) {
			throw new \RuntimeException( 'Не удалось записать событие экзамена в outbox.' );
		}
	}
}
