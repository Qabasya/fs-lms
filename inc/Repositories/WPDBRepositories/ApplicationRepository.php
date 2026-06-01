<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Enums\ApplicationStatus;
use Inc\DTO\ApplicationDTO;
use Inc\Enums\TableName;

/**
 * Class ApplicationRepository
 *
 * Центральный репозиторий системы зачисления для управления входящими заявками.
 * Контролирует логику переходов статусной машины.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление заявок в БД.
 * 2. **Поиск по ключам** — поиск заявок по коду приглашения, email студента.
 * 3. **Статусная машина** — валидация переходов статусов через Enum ApplicationStatus.
 * 4. **Фильтрация и пагинация** — получение отфильтрованного списка для админ-панели.
 * 5. **Обслуживание** — поиск зависших и просроченных заявок.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов (оптимизация и сложные условия).
 * Работает с DTO ApplicationDTO для типобезопасной передачи данных.
 */
class ApplicationRepository implements RepositoryInterface {

	/**
	 * Экземпляр класса управления БД WordPress.
	 */
	private \wpdb $wpdb;

	/**
	 * Полное имя таблицы с префиксом.
	 */
	private string $table;

	/**
	 * Конструктор репозитория. Применяет паттерн с дефолтом для корректной работы DI.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Applications->prefixed();
	}

	/**
	 * Находит заявку по ID.
	 *
	 * @param int $id ID заявки
	 *
	 * @return ApplicationDTO|null
	 */
	public function find( int $id ): ?ApplicationDTO {
		// %i — плейсхолдер для идентификатора таблицы, get_row() с ARRAY_A — строка как ассоциативный массив
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? ApplicationDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит активную заявку по хэшу кода приглашения.
	 *
	 * @param string $hash Хэш кода доступа
	 *
	 * @return ApplicationDTO|null
	 */
	public function findByJoinCodeHash( string $hash ): ?ApplicationDTO {
		// Только активные статусы (не конвертированные, не отклонённые, не истекшие)
		$statuses = array(
			ApplicationStatus::PendingParent->value,
			ApplicationStatus::ReadyForReview->value,
			ApplicationStatus::Enrolling->value,
		);

		// array_fill() — создаёт массив с плейсхолдерами (%s, %s, %s); значения статусов — из enum, не из user input
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE join_code_hash = %s AND status IN ($placeholders) LIMIT 1",
				array_merge( array( $this->table, $hash ), $statuses )
			),
			ARRAY_A
		);

		return $row ? ApplicationDTO::fromArray( $row ) : null;
	}

	/**
	 * Проверяет наличие незавершённых заявок по хэшу email студента.
	 *
	 * @param string $emailHash Хэш почтового адреса студента
	 *
	 * @return ApplicationDTO|null
	 */
	public function findActiveByEmail( string $emailHash ): ?ApplicationDTO {
		$statuses = array(
			ApplicationStatus::PendingParent->value,
			ApplicationStatus::ReadyForReview->value,
		);

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE student_email_hash = %s AND status IN ($placeholders) LIMIT 1",
				array_merge( array( $this->table, $emailHash ), $statuses )
			),
			ARRAY_A
		);

		return $row ? ApplicationDTO::fromArray( $row ) : null;
	}

	/**
	 * Получает постраничный отфильтрованный список заявок для админ-панели.
	 *
	 * @param array $filters Ассоциативный массив фильтров (status, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество элементов на страницу
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function list( array $filters, int $page, int $perPage ): array {
		$offset     = ( $page - 1 ) * $perPage;
		$conditions = array( '1=1' );
		$bindings   = array();

		// Фильтр по статусу; без явного фильтра корзина скрыта
		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$bindings[]   = $filters['status'];
		} else {
			$conditions[] = 'status != %s';
			$bindings[]   = ApplicationStatus::Trash->value;
		}

		// Фильтр по дате начала
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'created_at >= %s';
			$bindings[]   = $filters['date_from'];
		}

		// Фильтр по дате окончания
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'created_at <= %s';
			$bindings[]   = $filters['date_to'];
		}

		$whereStr = implode( ' AND ', $conditions );

		// Добавляем биндинги для LIMIT и OFFSET
		$bindings[] = $perPage;
		$bindings[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE $whereStr ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( array( $this->table ), $bindings )
		);

		$rows   = $this->wpdb->get_results( $query, ARRAY_A );
		$result = array();

		if ( ! empty( $rows ) && is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = ApplicationDTO::fromArray( $row );
			}
		}

		return $result;
	}

	/**
	 * Возвращает общее число строк по заданным фильтрам для пагинации.
	 *
	 * @param array $filters Массив фильтров
	 *
	 * @return int
	 */
	public function count( array $filters ): int {
		$conditions = array( '1=1' );
		$bindings   = array();

		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$bindings[]   = $filters['status'];
		} else {
			$conditions[] = 'status != %s';
			$bindings[]   = ApplicationStatus::Trash->value;
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'created_at >= %s';
			$bindings[]   = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'created_at <= %s';
			$bindings[]   = $filters['date_to'];
		}

		$whereStr = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE $whereStr",
			array_merge( array( $this->table ), $bindings )
		);

		// get_var() — получает одно значение из результата запроса
		return (int) $this->wpdb->get_var( $query );
	}

	/**
	 * Создаёт новую запись заявки в базе данных.
	 *
	 * @param array $data Плоский массив полей таблицы
	 *
	 * @return int ID созданной строки
	 */
	public function create( array $data ): int {
		// insert() — вставляет строку в таблицу
		$this->wpdb->insert( $this->table, $data );
		// insert_id — последний автоматически сгенерированный ID
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет выборочные поля существующей заявки.
	 *
	 * @param int   $id   ID заявки
	 * @param array $data Ассоциативный массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		// update() — обновляет строки, возвращает количество обновлённых строк или false
		$result = $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
		return false !== $result;
	}

	/**
	 * Изменяет статус заявки с валидацией через машину состояний (Enum).
	 *
	 * @param int               $id     ID заявки
	 * @param ApplicationStatus $status Целевой статус
	 *
	 * @throws \InvalidArgumentException Если переход между статусами невалиден
	 *
	 * @return bool
	 */
	public function setStatus( int $id, ApplicationStatus $status ): bool {
		$currentApplication = $this->find( $id );

		if ( null === $currentApplication ) {
			throw new \InvalidArgumentException( "Заявка с ID {$id} не найдена." );
		}

		$currentStatus = $currentApplication->status;

		// Валидация перехода через метод Enum
		if ( ! $currentStatus->canTransitionTo( $status ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					"Запрещённый переход статуса для заявки #%d: из '%s' в '%s'.",
					$id,
					$currentStatus->value,
					$status->value
				)
			);
		}

		// current_time() — возвращает текущее время в формате MySQL
		return $this->update(
			$id,
			array(
				'status'     => $status->value,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Переводит статус в 'converted' и связывает с фактом успешного зачисления.
	 *
	 * @param int $id           ID заявки
	 * @param int $enrollmentId ID зачисления из таблицы enrollments
	 *
	 * @return bool
	 */
	public function markConverted( int $id, int $enrollmentId ): bool {
		return $this->update(
			$id,
			array(
				'status'                     => ApplicationStatus::Converted->value,
				'converted_to_enrollment_id' => $enrollmentId,
				'updated_at'                 => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Находит заявки, «зависшие» в процессе транзакции зачисления.
	 *
	 * @param int $minMinutes Таймаут в минутах
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function findStuckEnrolling( int $minMinutes ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)',
				$this->table,
				ApplicationStatus::Enrolling->value,
				$minMinutes
			),
			ARRAY_A
		);
		$result = array();

		if ( ! empty( $rows ) && is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = ApplicationDTO::fromArray( $row );
			}
		}

		return $result;
	}

	/**
	 * Физически удаляет заявку. Разрешено только если статус — 'trash'.
	 *
	 * @param int $id ID заявки
	 *
	 * @throws \LogicException Если статус заявки не 'trash'
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$application = $this->find( $id );

		if ( null === $application ) {
			throw new \InvalidArgumentException( "Заявка с ID {$id} не найдена." );
		}

		if ( ApplicationStatus::Trash !== $application->status ) {
			throw new \LogicException(
				sprintf( "Физическое удаление разрешено только для заявок в статусе 'trash'. Текущий статус: '%s'.", $application->status->value )
			);
		}

		$result = $this->wpdb->delete( $this->table, array( 'id' => $id ) );

		return false !== $result;
	}

	/**
	 * Физически удаляет заявку без проверки статуса. Используется сервисом зачисления.
	 *
	 * @param int $id ID заявки
	 *
	 * @return bool
	 */
	public function forceDelete( int $id ): bool {
		$result = $this->wpdb->delete( $this->table, array( 'id' => $id ) );

		return false !== $result;
	}

	/**
	 * Находит просроченные заявки на этапе ожидания заполнения родителем.
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function findExpiredPending(): array {
		$statuses = array(
			ApplicationStatus::PendingParent->value,
			ApplicationStatus::ReadyForReview->value,
		);

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ($placeholders) AND join_code_expires_at < NOW()",
				array_merge( array( $this->table ), $statuses )
			),
			ARRAY_A
		);
		$result = array();

		if ( ! empty( $rows ) && is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = ApplicationDTO::fromArray( $row );
			}
		}

		return $result;
	}
}
