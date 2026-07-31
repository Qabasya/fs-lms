<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\EmailLogDTO;
use Inc\DTO\Log\EmailLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class EmailLogRepository
 *
 * Репозиторий для работы с журналом отправки email (email_log).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись отправки email** — создание записей при отправке писем (OTP, уведомления, сброс пароля).
 * 2. **Список с фильтрацией** — получение записей с поддержкой фильтров и пагинации.
 * 3. **Получение всех записей** — для экспорта в CSV.
 *
 * ### Архитектурная роль:
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository};
 * здесь только специфика канала. Лог отправки email важен для аудита
 * и отладки проблем с доставкой уведомлений.
 *
 * ### Фильтры:
 *
 * - email_type — тип письма (otp_code, password_setup, application_confirmation и т.д.)
 * - status — статус отправки (success/failed)
 * - target_person_id — ID лица (из persons), которому адресовано письмо
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 *
 * @method EmailLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method EmailLogDTO[] listAll( array $filters )
 */
class EmailLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::Email;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'email_type'       => array( 'email_type', LogFilterType::Text ),
			'status'           => array( 'status', LogFilterType::Text ),
			'target_person_id' => array( 'target_person_id', LogFilterType::Number ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): EmailLogDTO {
		return EmailLogDTO::fromArray( $row );
	}

	/**
	 * Создаёт новую запись в журнале отправки email.
	 *
	 * @param EmailLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( EmailLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Уникальные типы писем — словарь для фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctEmailTypes(): array {
		return $this->distinctValues( 'email_type' );
	}

	/**
	 * ID адресатов — словарь для фильтра UI.
	 *
	 * @return int[]
	 */
	public function distinctPersonIds(): array {
		return $this->distinctIntValues( 'target_person_id' );
	}
}
