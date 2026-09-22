<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Application\ApplicationRecordInputDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Settings\TableName;

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
class ApplicationRepository {

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
	 * Занят ли логин другой незавершённой заявкой.
	 *
	 * Учитываются заявки, чей логин ещё станет учёткой: ожидание родителя, проверка, зачисление
	 * и `converted` (учётки не созданы). Истёкшие и удалённые логин освобождают.
	 *
	 * @param string   $usernameHash Хэш логина ({@see \Inc\Services\Security\PiiCryptoService::hash()})
	 * @param int|null $exceptId     Заявка, которую не учитывать (правка её же логина)
	 */
	public function existsActiveByUsernameHash( string $usernameHash, ?int $exceptId = null ): bool {
		$statuses = array(
			ApplicationStatus::PendingParent->value,
			ApplicationStatus::ReadyForReview->value,
			ApplicationStatus::Enrolling->value,
			ApplicationStatus::Converted->value,
		);

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM %i WHERE username_hash = %s AND status IN ($placeholders) AND id <> %d LIMIT 1",
				array_merge( array( $this->table, $usernameHash ), $statuses, array( (int) $exceptId ) )
			)
		);

		return null !== $found;
	}

	/**
	 * Получает отфильтрованный список заявок для админ-панели.
	 *
	 * @param array $filters Ассоциативный массив фильтров (status, date_from, date_to)
	 * @param int   $page    Номер страницы
	 * @param int   $perPage Количество элементов на страницу; 0 — все строки (таблица заявок без пагинации)
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function list( array $filters, int $page = 1, int $perPage = 0 ): array {
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
		$limit    = '';

		if ( $perPage > 0 ) {
			$limit      = ' LIMIT %d OFFSET %d';
			$bindings[] = $perPage;
			$bindings[] = $offset;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE $whereStr ORDER BY id DESC$limit",
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
	 * @param ApplicationRecordInputDTO $dto DTO с полями для вставки
	 *
	 * @return int ID созданной строки
	 */
	public function create( ApplicationRecordInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
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
	 * Правило допустимости перехода живёт в {@see \Inc\Services\Application\ApplicationService::changeStatus()}
	 * — хранилище только пишет (аудит §P3).
	 *
	 * @throws \InvalidArgumentException Если заявка не найдена
	 *
	 * @return bool
	 */
	public function setStatus( int $id, ApplicationStatus $status ): bool {
		$currentApplication = $this->find( $id );

		if ( null === $currentApplication ) {
			throw new \InvalidArgumentException( "Заявка с ID {$id} не найдена." );
		}

		// Время в UTC, как у остальных записей заявки: выборки по времени сравнивают с UTC.
		return $this->update(
			$id,
			array(
				'status'     => $status->value,
				'updated_at' => current_time( 'mysql', true ),
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
	public function markConverted( int $id, int $recordId ): bool {
		return $this->update(
			$id,
			array(
				'status'              => ApplicationStatus::Converted->value,
				'converted_record_id' => $recordId,
				'updated_at'          => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Заявки `converted`: зачисление прошло, а учётки не созданы (успешное зачисление заявку удаляет).
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function findConverted(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s',
				$this->table,
				ApplicationStatus::Converted->value
			),
			ARRAY_A
		);

		return array_map( static fn( array $row ): ApplicationDTO => ApplicationDTO::fromArray( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Находит заявки, «зависшие» в процессе транзакции зачисления.
	 *
	 * Порог считается в PHP в UTC, а не через `NOW()`: `updated_at` пишется в UTC, а часовой пояс
	 * сервера БД может быть любым — с `NOW()` в московском времени любая заявка в `enrolling`
	 * считалась бы зависшей сразу.
	 *
	 * @param int $minMinutes Таймаут в минутах
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function findStuckEnrolling( int $minMinutes ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND updated_at < %s',
				$this->table,
				ApplicationStatus::Enrolling->value,
				gmdate( 'Y-m-d H:i:s', time() - $minMinutes * MINUTE_IN_SECONDS )
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
	 * Правило «удалять только из корзины» — в
	 * {@see \Inc\Services\Application\ApplicationService::deleteFromTrash()}.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$application = $this->find( $id );

		if ( null === $application ) {
			throw new \InvalidArgumentException( "Заявка с ID {$id} не найдена." );
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
	 * Физически удаляет заявки в указанных статусах, не обновлявшиеся дольше заданного числа месяцев.
	 *
	 * @param int      $months   Порог устаревания в месяцах
	 * @param string[] $statuses Список статусов для очистки
	 *
	 * @return int Количество удалённых строк
	 */
	public function purgeExpiredOlderThan( int $months, array $statuses ): int {
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE status IN ($placeholders) AND updated_at < DATE_SUB(NOW(), INTERVAL %d MONTH)",
				array_merge( array( $this->table ), $statuses, array( $months ) )
			)
		);

		return (int) $this->wpdb->rows_affected;
	}

	/**
	 * Находит заявки, которые не дождались родителя за срок заявки (`expires_at`).
	 *
	 * Срок JOIN-ссылки (`join_code_expires_at`) здесь не участвует: истёкшую ссылку
	 * выдают заново, заявку она не закрывает.
	 *
	 * @return array<int, ApplicationDTO>
	 */
	public function findExpiredPending(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// Срок пишется в UTC (JoinCodeService), сравниваем тоже с UTC, а не с NOW() сервера БД.
				'SELECT * FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s',
				$this->table,
				ApplicationStatus::PendingParent->value,
				gmdate( 'Y-m-d H:i:s' )
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
	 * Заявки с указанными статусами (без условия по сроку).
	 *
	 * @param string[] $statuses Значения ApplicationStatus.
	 *
	 * @return ApplicationDTO[]
	 */
	public function findByStatuses( array $statuses ): array {
		if ( empty( $statuses ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ($placeholders)",
				array_merge( array( $this->table ), array_values( $statuses ) )
			),
			ARRAY_A
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[] = ApplicationDTO::fromArray( $row );
		}

		return $result;
	}

	/** Safety net: удаляет заявки ученика (зависшие в enrolling после частичного сбоя). */
	public function hardDeleteByStudentPersonId( int $personId ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE student_person_id = %d', $this->table, $personId )
		);
	}

	public function hardDeleteByParentPersonId( int $personId ): void {
		$this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE parent_person_id = %d', $this->table, $personId )
		);
	}
}
