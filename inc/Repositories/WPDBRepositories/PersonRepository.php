<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\PersonDTO;
use Inc\Enums\TableName;

/**
 * Class PersonRepository
 *
 * Репозиторий для управления сущностями физических лиц (ученики, родители, преподаватели)
 * и работы с зашифрованными персональными данными.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и мягкое удаление записей о людях.
 * 2. **Поиск по уникальным хэшам** — поиск человека по хэшу документа, ИНН, ID пользователя WP.
 * 3. **Мягкое удаление** — физические записи не удаляются, только помечаются deleted_at.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 * Использует wpdb для прямых SQL-запросов. Работает с DTO PersonDTO для типобезопасной
 * передачи данных. Хранит персональные данные в зашифрованном виде (поля *_enc).
 */
class PersonRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Persons->prefixed();
	}

	/**
	 * Находит человека по ID (только не удалённые записи).
	 *
	 * @param int $id ID записи
	 *
	 * @return PersonDTO|null
	 */
	public function find( int $id ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит человека по хэшу номера документа.
	 *
	 * @param string $docNumberHash Хэш номера документа (SHA-256)
	 *
	 * @return PersonDTO|null
	 */
	public function findByDocNumberHash( string $docNumberHash ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE doc_number_hash = %s AND deleted_at IS NULL LIMIT 1',
				$this->table,
				$docNumberHash
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит человека по хэшу ИНН.
	 *
	 * @param string $innHash Хэш ИНН (SHA-256)
	 *
	 * @return PersonDTO|null
	 */
	public function findByInnHash( string $innHash ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE inn_hash = %s AND deleted_at IS NULL LIMIT 1',
				$this->table,
				$innHash
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит человека по ID пользователя WordPress.
	 *
	 * @param int $wpUserId ID пользователя WP
	 *
	 * @return PersonDTO|null
	 */
	public function findByWpUserId( int $wpUserId ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE wp_user_id = %d AND deleted_at IS NULL LIMIT 1',
				$this->table,
				$wpUserId
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	/**
	 * Создаёт новую запись человека.
	 *
	 * @param array $data Массив полей таблицы (wp_user_id, email, full_name_enc и т.д.)
	 *
	 * @return int ID созданной записи
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет существующую запись человека.
	 *
	 * @param int   $id   ID записи
	 * @param array $data Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Мягкое удаление записи (заполняет поле deleted_at).
	 * Физические записи не удаляются для сохранения истории.
	 *
	 * @param int $id ID записи
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return $this->softDelete( $id );
	}

	/**
	 * Помечает запись как удалённую (deleted_at = NOW()).
	 * Данные остаются в БД для аудита; retention job обезличит их позже.
	 *
	 * @param int $id ID записи
	 *
	 * @return bool
	 */
	public function softDelete( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Обезличивает запись: обнуляет все зашифрованные поля (*_enc).
	 * Вызывается retention job после истечения срока хранения.
	 * Запись остаётся в БД (для ссылочной целостности), но PII удалены безвозвратно.
	 *
	 * @param int $id ID записи
	 *
	 * @return bool
	 */
	public function anonymize( int $id ): bool {
		return $this->update( $id, array(
			'full_name_enc'  => null,
			'doc_number_enc' => null,
			'inn_enc'        => null,
			'snils_enc'      => null,
			'address_enc'    => null,
			'phone_enc'      => null,
		) );
	}
}