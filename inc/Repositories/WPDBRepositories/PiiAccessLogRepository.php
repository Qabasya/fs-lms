<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\PiiAccessLogDTO;
use Inc\Enums\TableName;

/**
 * Class PiiAccessLogRepository
 *
 * Репозиторий для ведения строгого журнала доступа сотрудников к ПД физлиц.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Запись доступа** — фиксация факта доступа сотрудника к персональным данным.
 * 2. **Поиск записей** — получение записей по ID, по ID человека.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Записи журнала PII Access являются
 * неизменяемыми для обеспечения compliance (update() выбрасывает исключение).
 *
 * ### Compliance (соответствие законодательству):
 *
 * Журнал создаётся для отслеживания каждого случая доступа к персональным данным.
 * Фиксируется: кто запрашивал (actor_user_id), к каким данным (fields_accessed),
 * причина доступа (access_reason), IP-адрес, время.
 */
class PiiAccessLogRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::PiiAccessLog->prefixed();
	}

	/**
	 * Находит запись журнала по ID.
	 *
	 * @param int $id ID записи
	 *
	 * @return PiiAccessLogDTO|null
	 */
	public function find( int $id ): ?PiiAccessLogDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? PiiAccessLogDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все записи доступа к персональным данным конкретного человека.
	 *
	 * @param int $personId ID человека из таблицы persons
	 *
	 * @return PiiAccessLogDTO[]
	 */
	public function findByPerson( int $personId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d ORDER BY created_at DESC',
				$this->table,
				$personId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PiiAccessLogDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Создаёт новую запись доступа к персональным данным.
	 *
	 * @param array $data Массив полей таблицы (actor_user_id, person_id, fields_accessed, access_reason, actor_ip)
	 *
	 * @return int ID созданной записи
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновление записей журнала доступа к ПД запрещено по compliance-требованиям.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @throws \BadMethodCallException Всегда выбрасывает исключение
	 */
	public function update( int $id, array $data ): bool {
		throw new \BadMethodCallException( 'Журнал доступа к персональным данным защищён от изменений.' );
	}
}