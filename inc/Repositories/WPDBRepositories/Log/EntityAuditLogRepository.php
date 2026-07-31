<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\EntityAuditLogDTO;
use Inc\DTO\Log\EntityAuditLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class EntityAuditLogRepository
 *
 * Репозиторий для работы с журналом аудита изменений сущностей (entity_audit_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений сущностей** — создание записей при создании, обновлении или удалении сущностей
 *    (предметы, таксономии, задания, статьи, группы, периоды, пользователи).
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Журнал аудита сущностей отслеживает,
 * кто и когда изменял различные сущности в административной панели.
 *
 * ### Фильтры:
 *
 * - operation — тип операции (create, update, delete)
 * - entity_type — тип сущности (subject, taxonomy, task, article и т.д.)
 * - actor_user_id — ID пользователя, выполнившего действие
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 *
 * @method EntityAuditLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method EntityAuditLogDTO[] listAll( array $filters )
 */
class EntityAuditLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::EntityAudit;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'operation'     => array( 'operation', LogFilterType::Text ),
			'entity_type'   => array( 'entity_type', LogFilterType::Text ),
			'actor_user_id' => array( 'actor_user_id', LogFilterType::Number ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): EntityAuditLogDTO {
		return EntityAuditLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале аудита сущностей.
	 *
	 * @param EntityAuditLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( EntityAuditLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Уникальные типы операций — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctOperations(): array {
		return $this->distinctValues( 'operation' );
	}

	/**
	 * Уникальные типы сущностей — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctEntityTypes(): array {
		return $this->distinctValues( 'entity_type' );
	}

	/**
	 * ID пользователей-авторов действий — словарь для фильтра UI.
	 *
	 * @return int[]
	 */
	public function distinctActorUserIds(): array {
		return $this->distinctIntValues( 'actor_user_id' );
	}
}
