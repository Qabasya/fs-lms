<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Person\PersonDocumentsDTO;
use Inc\Enums\TableName;

/**
 * Class PersonDocumentsRepository
 *
 * Репозиторий для работы с документами и контактными данными лиц (PersonDocuments).
 * Хранит зашифрованные данные (email, phone, doc_number, doc_issued_by, inn, address)
 * и их хеши для поиска.
 *
 * @package Inc\Repositories\WPDBRepositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление записей документов.
 * 2. **Поиск по хешам** — поиск записей по хешу email, телефона, номера документа, ИНН.
 * 3. **Анонимизация** — очистка зашифрованных полей и их хешей (для соответствия 152-ФЗ).
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы $wpdb для прямых SQL-запросов.
 * Использует DTO PersonDocumentsDTO для типобезопасной передачи данных.
 * Таблица содержит отдельные поля для email, телефона и документов,
 * чтобы избежать дублирования при создании связей родитель-ученик.
 */
class PersonDocumentsRepository {

	private \wpdb $wpdb;
	private string $table;

	/**
	 * Конструктор репозитория.
	 *
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::PersonDocuments->prefixed();
	}

	/**
	 * Находит запись документов по ID лица.
	 *
	 * @param int $personId ID лица
	 *
	 * @return PersonDocumentsDTO|null
	 */
	public function findByPersonId( int $personId ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d LIMIT 1',
				$this->table,
				$personId
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит запись документов по хешу email.
	 *
	 * @param string $hash SHA-256 хеш email
	 *
	 * @return PersonDocumentsDTO|null
	 */
	public function findByEmailHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE email_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит запись документов по хешу телефона.
	 *
	 * @param string $hash SHA-256 хеш телефона
	 *
	 * @return PersonDocumentsDTO|null
	 */
	public function findByPhoneHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE phone_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит запись документов по хешу номера документа.
	 *
	 * @param string $hash SHA-256 хеш номера документа
	 *
	 * @return PersonDocumentsDTO|null
	 */
	public function findByDocNumberHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE doc_number_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	/**
	 * Находит запись документов по хешу ИНН.
	 *
	 * @param string $hash SHA-256 хеш ИНН
	 *
	 * @return PersonDocumentsDTO|null
	 */
	public function findByInnHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE inn_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	/**
	 * Создаёт новую запись документов.
	 *
	 * @param array $data Массив полей таблицы (person_id, email_enc, email_hash и т.д.)
	 *
	 * @return int ID созданной записи
	 */
	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Обновляет запись документов по ID лица.
	 *
	 * @param int   $personId ID лица
	 * @param array $data     Массив обновляемых полей
	 *
	 * @return bool
	 */
	public function update( int $personId, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'person_id' => $personId ) );
	}

	/**
	 * Физически удаляет запись документов по ID лица.
	 *
	 * @param int $personId ID лица
	 *
	 * @return bool
	 */
	public function hardDeleteByPersonId( int $personId ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'person_id' => $personId ) );
	}

	/**
	 * Анонимизирует запись документов (очищает зашифрованные поля и хеши).
	 * Используется при удалении лица в соответствии с 152-ФЗ.
	 *
	 * @param int $personId ID лица
	 *
	 * @return bool
	 */
	public function hasAny(): bool {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i LIMIT 1', $this->table )
		) > 0;
	}

	public function anonymize( int $personId ): bool {
		return $this->update( $personId, array(
			'email_enc'         => null,
			'email_hash'        => null,
			'phone_enc'         => null,
			'phone_hash'        => null,
			'doc_number_enc'    => null,
			'doc_number_hash'   => null,
			'doc_issued_by_enc' => null,
			'inn_enc'           => null,
			'inn_hash'          => null,
			'address_enc'       => null,
		) );
	}
}