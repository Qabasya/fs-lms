<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\Settings\TableName;
use Inc\Enums\Course\GroupType;
use Inc\Services\Group\MeetingsNormalizer;
use Inc\Shared\PluginLogger;

/**
 * Class GroupsRepository
 *
 * Репозиторий для работы с группами студентов в wpdb (таблица fs_lms_groups).
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление групп.
 * 2. **Поиск по различным критериям** — по ID, ключу предмета, периоду.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует enum TableName для получения имени таблицы с префиксом.
 * Работает напрямую с объектами результата запроса (не DTO).
 */
class GroupsRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Groups->prefixed();
	}

	/** Reentrant only on this repository; serialize preparation with subject/period deletion. */
	private int $selfPacedLifecycleDepth = 0;

	public function withSelfPacedLifecycleLock( callable $operation ): mixed {
		if ( $this->selfPacedLifecycleDepth > 0 ) {
			return $operation();
		}
		$database = $this->wpdb->get_var( 'SELECT DATABASE()' );
		if ( ! is_string( $database ) || '' === $database ) {
			throw new \RuntimeException( 'Не удалось определить базу для блокировки жизненного цикла курса.' );
		}
		$key = 'fs-lms:selfpaced:' . substr( hash( 'sha256', $database . ':' . $this->table ), 0, 40 );
		$locked = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) );
		if ( '1' !== (string) $locked ) {
			throw new \DomainException( 'Жизненный цикл самостоятельного курса занят другой операцией. Повторите позже.' );
		}
		$this->selfPacedLifecycleDepth = 1;
		try {
			return $operation();
		} finally {
			$this->selfPacedLifecycleDepth = 0;
			if ( '1' !== (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ) ) {
				throw new \RuntimeException( 'Не удалось освободить блокировку жизненного цикла курса.' );
			}
		}
	}

	/** Serialize service-group preparation on the existing course row. No nested commit. */
	public function withCourseTransaction( int $courseId, callable $operation ): mixed {
		$nested = $this->wpdb->get_var( 'SELECT @@in_transaction' );
		if ( null === $nested || (int) $nested !== 0 ) {
			throw new \RuntimeException( 'Подготовку курса нельзя запускать внутри другой транзакции.' );
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Не удалось начать подготовку курса.' );
		}
		try {
			$locked = $this->wpdb->get_var( $this->wpdb->prepare(
				'SELECT ID FROM %i WHERE ID = %d FOR UPDATE', $this->wpdb->posts, $courseId
			) );
			if ( (int) $locked !== $courseId ) {
				throw new \RuntimeException( 'Курс не найден или не удалось заблокировать запись.' );
			}
			$result = $operation();
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Не удалось завершить подготовку курса.' );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	public function findBySelfPacedCourse( int $courseId ): ?object {
		return $this->wpdb->get_row( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE self_paced_course_id = %d LIMIT 1', $this->table, $courseId
		) ) ?: null;
	}

	/**
	 * Блокирует строку группы до конца транзакции (смена режима и зачисление идут по очереди).
	 * Вызывать первым оператором транзакции.
	 */
	public function lockForUpdate( int $groupId ): void {
		$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $this->table, $groupId ) );
	}

	/**
	 * Находит группу по ID.
	 *
	 * @param int $id ID группы
	 *
	 * @return object|null
	 */
	public function findById( int $id ): ?object {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			)
		);

		return $row ?: null;
	}

	/**
	 * Находит все группы по ключу предмета.
	 *
	 * @param string $subjectKey Ключ предмета (например, 'math')
	 *
	 * @return array
	 */
	public function findBySubjectKey( string $subjectKey ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE subject_key = %s ORDER BY name ASC',
				$this->table,
				$subjectKey
			)
		) ?: array();
	}

	/**
	 * Находит группы по ID периода и ключу предмета.
	 *
	 * @param string $periodId   ID учебного периода
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return array
	 */
	public function findByPeriodAndSubject( string $periodId, string $subjectKey ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE academic_period_id = %s AND subject_key = %s ORDER BY name ASC',
				$this->table,
				$periodId,
				$subjectKey
			)
		) ?: array();
	}

	/**
	 * Находит группу по названию в рамках предмета и периода (find-or-create при импорте).
	 *
	 * @param string $name       Название группы
	 * @param string $subjectKey Ключ предмета
	 * @param string $periodId   ID учебного периода
	 *
	 * @return object|null Найденная группа или null
	 */
	public function findByNameSubjectPeriod( string $name, string $subjectKey, string $periodId ): ?object {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE name = %s AND subject_key = %s AND academic_period_id = %s LIMIT 1',
				$this->table,
				$name,
				$subjectKey,
				$periodId
			)
		);

		return $row ?: null;
	}

	/**
	 * Находит все группы по ID периода.
	 *
	 * @param string $periodId ID учебного периода
	 *
	 * @return array
	 */
	public function findByPeriodId( string $periodId ): array {
		return $this->findByFilters( $periodId );
	}

	public function findByFilters( string $periodId, string $subjectKey = '', int $teacherId = 0, int $roomId = 0, ?GroupType $type = null ): array {
		$where    = array( 'academic_period_id = %s' );
		$bindings = array( $this->table, $periodId );

		if ( '' !== $subjectKey ) {
			$where[]    = 'subject_key = %s';
			$bindings[] = $subjectKey;
		}

		if ( $teacherId > 0 ) {
			$where[]    = 'teacher_id = %d';
			$bindings[] = $teacherId;
		}

		if ( $roomId > 0 ) {
			$where[]    = 'room_id = %d';
			$bindings[] = $roomId;
		}

		if ( null !== $type ) {
			$where[] = 'access_mode = %s';
			$bindings[] = $type->accessMode()->value;
			$where[] = 'is_shared = %d';
			$bindings[] = $type->isShared() ? 1 : 0;
		}

		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY name ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $bindings )
		) ?: array();
	}

	/**
	 * Возвращает все группы преподавателя (по teacher_id), отсортированные по названию.
	 *
	 * @param int $teacherId WP-идентификатор преподавателя
	 *
	 * @return array
	 */
	public function findByTeacherId( int $teacherId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE teacher_id = %d ORDER BY name ASC',
				$this->table,
				$teacherId
			)
		) ?: array();
	}

	/**
	 * Возвращает все группы, которым назначен указанный курс (по course_id).
	 * Используется для ре-синка новых уроков курса в КТП (НБ-7).
	 *
	 * @param int $courseId ID курса
	 *
	 * @return array
	 */
	public function findByCourse( int $courseId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE course_id = %d ORDER BY name ASC',
				$this->table,
				$courseId
			)
		) ?: array();
	}

	/**
	 * Возвращает открытые группы (Эпик 15): каталог курсов свободного прохождения.
	 *
	 * @return array
	 */
	public function findOpen(): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE access_mode = 'open' AND deleted_at IS NULL ORDER BY name ASC",
				$this->table
			)
		) ?: array();
	}

	/**
	 * Возвращает все группы, отсортированные по предмету и названию.
	 *
	 * @return array
	 */
	public function findAll(): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY subject_key, name ASC', $this->table )
		) ?: array();
	}

	/**
	 * Возвращает количество групп в указанном учебном периоде.
	 *
	 * @param string $periodId ID учебного периода
	 *
	 * @return int
	 */
	public function countByPeriodId( string $periodId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE academic_period_id = %s',
				$this->table,
				$periodId
			)
		);
	}

	/**
	 * Проверяет, существует ли группа с таким именем в данном периоде.
	 *
	 * @param string $name     Название группы
	 * @param string $periodId ID учебного периода
	 *
	 * @return bool
	 */
	public function existsByNameAndPeriod( string $name, string $periodId ): bool {
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE name = %s AND academic_period_id = %s LIMIT 1',
				$this->table,
				$name,
				$periodId
			)
		);

		return $count > 0;
	}

	/**
	 * Создаёт новую группу.
	 *
	 * @param array $data Массив полей таблицы (name, subject_key, academic_period_id, teacher_id, meetings)
	 *
	 * @return int ID созданной группы
	 */
	public function create( array $data ): int {
		$result = $this->wpdb->insert( $this->table, $data );

		// Не проглатываем ошибку БД: при провале insert (например, отсутствует
		// колонка/невалидное enum-значение — так проявлялся баг с access_mode на
		// стендах без применённой миграции) логируем last_error, чтобы причина
		// не терялась за общим «Не удалось создать группу».
		if ( false === $result ) {
			PluginLogger::warning(
				'GroupsRepository',
				'insert failed',
				array(
					'table'      => $this->table,
					'columns'    => array_keys( $data ),
					'last_error' => $this->wpdb->last_error,
				)
			);
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую группу.
	 *
	 * @param int   $id   ID группы
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Удаляет группу (мягкое или физическое удаление).
	 *
	 * @param int $id ID группы
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}

	/**
	 * Физически удаляет группу (синоним delete для ясности).
	 *
	 * @param int $id ID группы
	 *
	 * @return bool
	 */
	public function hardDelete( int $id ): bool {
		return $this->delete( $id );
	}

	/**
	 * Расписание группы в каноне для календаря.
	 * Нормализует на чтении — `weekday/time/duration_min` присутствуют даже у
	 * легаси-строк формата `{day,start,end}` (миграция backfill — опц. поверх).
	 *
	 * @return array<int, array{day:string,start:string,end:string,weekday:int,time:string,duration_min:int}>
	 */
	public function getMeetings( int $groupId ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT meetings FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$groupId
			)
		);
		if ( ! $row || ! $row->meetings ) {
			return array();
		}
		$decoded = json_decode( $row->meetings, true );
		return is_array( $decoded ) ? MeetingsNormalizer::normalizeList( $decoded ) : array();
	}

	public function setMeetings( int $groupId, array $meetings ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'meetings' => wp_json_encode( MeetingsNormalizer::normalizeList( $meetings ) ) ),
			array( 'id' => $groupId )
		);
	}

	/**
	 * Публикация/распубликация КТП (T1.8): дата блокировки или NULL.
	 *
	 * @param string|null $lockedAt 'Y-m-d H:i:s' при публикации, null при снятии.
	 */
	public function setProgramLocked( int $groupId, ?string $lockedAt ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array( 'program_locked_at' => $lockedAt ),
			array( 'id' => $groupId )
		);
	}

	/** Снимает ссылку на удаляемый кабинет со всех групп (RoomAssignmentService). */
	public function clearRoomId( int $roomId ): int {
		return (int) $this->wpdb->update( $this->table, array( 'room_id' => null ), array( 'room_id' => $roomId ) );
	}

	/**
	 * Load only the requested groups, keyed by ID.
	 * @param int[] $ids Group IDs.
	 * @return array<int, object>
	 */
	public function findByIds( array $ids ): array {
		$result = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i WHERE id IN ($placeholders)", array_merge( array( $this->table ), $chunk ) ) );
			foreach ( $rows ?: array() as $row ) {
				$result[ (int) $row->id ] = $row;
			}
		}
		return $result;
	}

}