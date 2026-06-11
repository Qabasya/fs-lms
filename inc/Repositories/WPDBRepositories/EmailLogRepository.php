<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Log\EmailLogDTO;
use Inc\DTO\Log\EmailLogInputDTO;
use Inc\Enums\TableName;

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
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO EmailLogDTO для чтения и EmailLogInputDTO для вставки.
 * Лог отправки email важен для аудита и отладки проблем с доставкой уведомлений.
 *
 * ### Фильтры:
 *
 * - email_type — тип письма (otp_code, password_setup, application_confirmation и т.д.)
 * - status — статус отправки (success/failed)
 * - target_person_id — ID лица (из persons), которому адресовано письмо
 * - date_from — дата начала периода
 * - date_to — дата окончания периода
 */
class EmailLogRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::EmailLog->prefixed();
	}

	/**
	 * Создаёт новую запись в журнале отправки email.
	 *
	 * @param EmailLogInputDTO $input DTO с данными для вставки
	 *
	 * @return int ID созданной записи
	 */
	public function create( EmailLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Возвращает список записей с фильтрацией и пагинацией.
	 *
	 * @param array $filters Массив фильтров (email_type, status, target_person_id, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество записей на страницу
	 *
	 * @return EmailLogDTO[]
	 */
	public function list( array $filters, int $page, int $perPage ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where      = implode( ' AND ', $conditions );
		$bindings[] = $perPage;
		$bindings[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EmailLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Подсчитывает количество записей по заданным фильтрам.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return int
	 */
	public function countFiltered( array $filters ): int {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $bindings )
		);
	}

	/**
	 * Возвращает все записи по фильтрам (без пагинации) для экспорта.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return EmailLogDTO[]
	 */
	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EmailLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Формирует WHERE-условие и массив параметров для запроса.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return array{0: string[], 1: array}
	 */
	private function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		// Фильтр по типу письма
		if ( ! empty( $filters['email_type'] ) ) {
			$conditions[] = 'email_type = %s';
			$bindings[]   = $filters['email_type'];
		}

		// Фильтр по статусу отправки
		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$bindings[]   = $filters['status'];
		}

		// Фильтр по ID получателя
		if ( ! empty( $filters['target_person_id'] ) ) {
			$conditions[] = 'target_person_id = %d';
			$bindings[]   = (int) $filters['target_person_id'];
		}

		// Фильтр по дате начала (с 00:00:00)
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'created_at >= %s';
			$bindings[]   = $filters['date_from'] . ' 00:00:00';
		}

		// Фильтр по дате окончания (до 23:59:59)
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'created_at <= %s';
			$bindings[]   = $filters['date_to'] . ' 23:59:59';
		}

		return array( $conditions, $bindings );
	}
}