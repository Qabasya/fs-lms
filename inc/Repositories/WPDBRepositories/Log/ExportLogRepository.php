<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\ExportLogDTO;
use Inc\DTO\Log\ExportLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class ExportLogRepository
 *
 * Репозиторий для работы с журналом экспорта данных (export_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись экспорта данных** — создание записей при экспорте групп, студентов, родителей, архива, логов.
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV (двойной экспорт).
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Журнал экспорта отслеживает, кто и когда
 * выгружал данные, а также какие ID были экспортированы (для единичных экспортов).
 *
 * ### Фильтры:
 *
 * - actor_user_id — ID пользователя, выполнившего экспорт
 * - data_type — тип экспортируемых данных (groups, students, parents, archive, log_audit и т.д.)
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 *
 * @method ExportLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method ExportLogDTO[] listAll( array $filters )
 */
class ExportLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::Export;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'actor_user_id' => array( 'actor_user_id', LogFilterType::Number ),
			'data_type'     => array( 'data_type', LogFilterType::Text ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): ExportLogDTO {
		return ExportLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале экспорта.
	 *
	 * @param ExportLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( ExportLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Уникальные типы выгрузок — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctDataTypes(): array {
		return $this->distinctValues( 'data_type' );
	}

	/**
	 * ID пользователей, выполнявших экспорт — словарь для фильтра UI.
	 *
	 * @return int[]
	 */
	public function distinctActorUserIds(): array {
		return $this->distinctIntValues( 'actor_user_id' );
	}
}
