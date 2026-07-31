<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Person\PiiAccessLogDTO;
use Inc\DTO\Person\PiiAccessLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class PiiAccessLogRepository
 *
 * Репозиторий для ведения строгого журнала доступа сотрудников к ПД физлиц.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись доступа** — фиксация факта доступа сотрудника к персональным данным.
 * 2. **Поиск записей** — получение записей по ID, по ID человека.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Записи журнала PII Access являются
 * неизменяемыми для обеспечения compliance (update() выбрасывает исключение).
 *
 * ### Compliance (соответствие законодательству):
 *
 * Журнал создаётся для отслеживания каждого случая доступа к персональным данным.
 * Фиксируется: кто запрашивал (actor_user_id), к каким данным (fields_accessed),
 * причина доступа (access_reason), IP-адрес, время.
 *
 * @method PiiAccessLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method PiiAccessLogDTO[] listAll( array $filters )
 */
class PiiAccessLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::PiiAccess;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'actor_user_id' => array( 'actor_user_id', LogFilterType::Number ),
			'person_id'     => array( 'person_id', LogFilterType::Number ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): PiiAccessLogDTO {
		return PiiAccessLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись доступа к персональным данным.
	 *
	 * @param PiiAccessLogInputDTO $input Входные данные записи доступа
	 *
	 * @return int ID созданной записи
	 */
	public function create( PiiAccessLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Находит запись журнала по ID.
	 *
	 * @param int $id ID записи
	 *
	 * @return PiiAccessLogDTO|null
	 */
	public function find( int $id ): ?PiiAccessLogDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Находит все записи доступа к персональным данным конкретного человека.
	 *
	 * @param int $personId ID человека из таблицы persons
	 *
	 * @return PiiAccessLogDTO[]
	 */
	public function findByPerson( int $personId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d ORDER BY created_at DESC',
				$this->table,
				$personId
			),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Последние записи доступа к данным человека.
	 *
	 * @param int $personId ID человека
	 * @param int $limit    Ограничение выборки
	 *
	 * @return PiiAccessLogDTO[]
	 */
	public function listByPerson( int $personId, int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$personId,
				$limit
			),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Последние записи доступа, выполненные сотрудником.
	 *
	 * @param int $userId ID пользователя WP
	 * @param int $limit  Ограничение выборки
	 *
	 * @return PiiAccessLogDTO[]
	 */
	public function listByActor( int $userId, int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE actor_user_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$userId,
				$limit
			),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Количество обращений сотрудника к ПД за последний час — для рейт-лимита.
	 *
	 * @param int $userId ID пользователя WP
	 */
	public function countByActorInLastHour( int $userId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE actor_user_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
				$this->table,
				$userId
			)
		);
	}

	/**
	 * Обновление записей журнала доступа к ПД запрещено по compliance-требованиям.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @throws \BadMethodCallException Всегда выбрасывает исключение
	 */
	public function update( int $id, array $data ): bool {
		throw new \BadMethodCallException( 'Журнал доступа к персональным данным защищён от изменений.' );
	}
}
