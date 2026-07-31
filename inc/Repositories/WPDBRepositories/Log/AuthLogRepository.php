<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\AuthLogDTO;
use Inc\DTO\Log\AuthLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class AuthLogRepository
 *
 * Репозиторий для работы с журналом аутентификации (auth_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись событий аутентификации** — создание записей в таблице auth_log.
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала: таблица, фильтры, DTO и словарь действий.
 * Журнал аутентификации отслеживает: успешные/неудачные входы, сбросы пароля.
 *
 * ### Фильтры:
 *
 * - action — тип действия (login, login_failed, password_reset)
 * - result — результат (success/failed)
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 *
 * @method AuthLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method AuthLogDTO[] listAll( array $filters )
 */
class AuthLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::Auth;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'action' => array( 'action', LogFilterType::Text ),
			'result' => array( 'result', LogFilterType::Text ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): AuthLogDTO {
		return AuthLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале аутентификации.
	 *
	 * @param AuthLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( AuthLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Уникальные типы действий — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctActions(): array {
		return $this->distinctValues( 'action' );
	}
}
