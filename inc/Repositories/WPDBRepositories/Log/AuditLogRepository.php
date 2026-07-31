<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\AuditLogDTO;
use Inc\DTO\Log\AuditLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class AuditLogRepository
 *
 * Репозиторий системного журнала действий для обеспечения прослеживаемости бизнес-логики.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись событий** — создание записей в журнале аудита (create).
 * 2. **Чтение событий** — поиск записей по ID, по цели (target_type + target_id).
 * 3. **Фильтрация и пагинация** — получение отфильтрованного списка для админ-панели.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Записи аудита являются неизменяемыми
 * (update() выбрасывает исключение, delete() не реализован по архитектурным причинам).
 *
 * ### Примечания:
 *
 * - Журнал аудита служит для отслеживания действий пользователей в системе зачисления.
 * - Записи не должны изменяться или удаляться для обеспечения целостности аудита.
 *
 * @method AuditLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method AuditLogDTO[] listAll( array $filters )
 */
class AuditLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::EnrollmentAudit;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'action'        => array( 'action', LogFilterType::Text ),
			'actor_user_id' => array( 'actor_user_id', LogFilterType::Number ),
			'actor_ids'     => array( 'actor_user_id', LogFilterType::NumberList ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): AuditLogDTO {
		return AuditLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале аудита.
	 *
	 * @param AuditLogInputDTO $dto DTO с полями для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( AuditLogInputDTO $dto ): int {
		return $this->insertRow( $dto->toArray() );
	}

	/**
	 * Находит запись аудита по ID.
	 *
	 * @param int $id ID записи
	 *
	 * @return AuditLogDTO|null
	 */
	public function find( int $id ): ?AuditLogDTO {
		// %i — плейсхолдер для идентификатора таблицы (экранирование)
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Находит все записи аудита по цели (тип + ID).
	 *
	 * @param string $targetType Тип цели (application, enrollment, person)
	 * @param int    $targetId   ID цели
	 *
	 * @return AuditLogDTO[]
	 */
	public function findByTarget( string $targetType, int $targetId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE target_type = %s AND target_id = %d ORDER BY created_at DESC',
				$this->table,
				$targetType,
				$targetId
			),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Последние записи аудита по цели.
	 *
	 * @param string $targetType Тип цели
	 * @param int    $targetId   ID цели
	 * @param int    $limit      Ограничение выборки
	 *
	 * @return AuditLogDTO[]
	 */
	public function listByTarget( string $targetType, int $targetId, int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE target_type = %s AND target_id = %d ORDER BY created_at DESC LIMIT %d',
				$this->table,
				$targetType,
				$targetId,
				$limit
			),
			ARRAY_A
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Последние записи аудита по автору действия.
	 *
	 * @param int $userId ID пользователя WP
	 * @param int $limit  Ограничение выборки
	 *
	 * @return AuditLogDTO[]
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
	 * ID пользователей-авторов действий — словарь для фильтра UI.
	 *
	 * @return int[]
	 */
	public function distinctActorUserIds(): array {
		return $this->distinctIntValues( 'actor_user_id' );
	}

	/**
	 * Уникальные типы действий — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctActions(): array {
		return $this->distinctValues( 'action' );
	}

	/**
	 * Обновление записей аудита запрещено по архитектурным причинам.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @throws \BadMethodCallException Всегда выбрасывает исключение
	 */
	public function update( int $id, array $data ): bool {
		throw new \BadMethodCallException( 'Журнал аудита системы неизменяем.' );
	}
}
