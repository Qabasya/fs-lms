<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\DataChangeLogDTO;
use Inc\DTO\Log\DataChangeLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class DataChangeLogRepository
 *
 * Репозиторий для работы с журналом изменений персональных данных (data_change_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений данных** — создание записей при изменении полей лица (ФИО, документы, контакты).
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Лог изменений данных отслеживает, кто и когда
 * изменял персональные данные, а также старые и новые значения (в зашифрованном виде).
 *
 * ### Фильтры:
 *
 * - actor_user_id — ID пользователя, изменившего данные
 * - target_person_id — ID лица, чьи данные изменены
 * - field_name — название изменённого поля
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 *
 * @method DataChangeLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method DataChangeLogDTO[] listAll( array $filters )
 */
class DataChangeLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::DataChange;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'actor_user_id'    => array( 'actor_user_id', LogFilterType::Number ),
			'target_person_id' => array( 'target_person_id', LogFilterType::Number ),
			'field_name'       => array( 'field_name', LogFilterType::Text ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): DataChangeLogDTO {
		return DataChangeLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале изменений данных.
	 *
	 * @param DataChangeLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( DataChangeLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * ID пользователей, менявших данные — словарь для фильтра UI.
	 *
	 * @return int[]
	 */
	public function distinctActorUserIds(): array {
		return $this->distinctIntValues( 'actor_user_id' );
	}

	/**
	 * ID лиц, чьи данные менялись — словарь для фильтра UI.
	 *
	 * @return int[]
	 */
	public function distinctPersonIds(): array {
		return $this->distinctIntValues( 'target_person_id' );
	}
}
