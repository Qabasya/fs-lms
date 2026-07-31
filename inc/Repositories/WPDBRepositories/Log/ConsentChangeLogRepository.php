<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\ConsentChangeLogDTO;
use Inc\DTO\Log\ConsentChangeLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class ConsentChangeLogRepository
 *
 * Репозиторий для работы с журналом изменений согласий (consent_change_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений согласий** — создание записей при изменении версии документа согласия.
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Лог изменений согласий отслеживает,
 * когда и кем была изменена версия согласия.
 *
 * ### Фильтры:
 *
 * - person_id — ID лица (из persons)
 * - consent_type — тип согласия (pd_processing, marketing и т.д.)
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 *
 * @method ConsentChangeLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method ConsentChangeLogDTO[] listAll( array $filters )
 */
class ConsentChangeLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::ConsentChange;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'person_id'    => array( 'person_id', LogFilterType::Number ),
			'consent_type' => array( 'consent_type', LogFilterType::Text ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): ConsentChangeLogDTO {
		return ConsentChangeLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале изменений согласий.
	 *
	 * @param ConsentChangeLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( ConsentChangeLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Уникальные типы согласий — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctConsentTypes(): array {
		return $this->distinctValues( 'consent_type' );
	}
}
