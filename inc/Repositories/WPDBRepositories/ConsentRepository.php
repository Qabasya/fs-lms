<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\ConsentDTO;
use Inc\Enums\TableName;

/**
 * Class ConsentRepository
 *
 * Репозиторий для управления юридическими согласиями на обработку персональных данных.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **Создание согласий** — запись факта подписания согласия пользователем.
 * 2. **Поиск согласий** — получение согласий по ID, по ID человека, по ID заявки.
 * 3. **Отзыв согласий** — обновление полей withdrawn_at и withdrawn_reason.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Хранит информацию о подписанных
 * версиях согласий (тип, версия, IP, User-Agent) для юридической значимости.
 */
class ConsentRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Consents->prefixed();
	}

	/**
	 * Находит согласие по ID.
	 *
	 * @param int $id ID согласия
	 *
	 * @return ConsentDTO|null
	 */
	public function find( int $id ): ?ConsentDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? ConsentDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит все согласия, подписанные конкретным человеком.
	 *
	 * @param int $personId ID человека из таблицы persons
	 *
	 * @return ConsentDTO[]
	 */
	public function findByPerson( int $personId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d ORDER BY accepted_at DESC',
				$this->table,
				$personId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => ConsentDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Находит все согласия, подписанные в рамках конкретной заявки.
	 *
	 * @param int $applicationId ID заявки
	 *
	 * @return ConsentDTO[]
	 */
	public function findByApplication( int $applicationId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE application_id = %d ORDER BY accepted_at DESC',
				$this->table,
				$applicationId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => ConsentDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * Создаёт новую запись согласия.
	 *
	 * @param array $data Массив полей таблицы (application_id, person_id, consent_type, и т.д.)
	 *
	 * @return int ID созданной записи
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую запись согласия.
	 *
	 * @param int   $id   ID согласия
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Отзывает согласие (указывает дату отзыва и причину).
	 *
	 * @param int    $id     ID согласия
	 * @param string $reason Причина отзыва
	 *
	 * @return bool
	 */
	public function withdraw( int $id, string $reason ): bool {
		return $this->update( $id, array(
			'withdrawn_at'     => current_time( 'mysql' ), // Текущая дата и время
			'withdrawn_reason' => $reason,
		) );
	}
}