<?php

declare( strict_types=1 );

namespace Inc\DTO\Subject;

/**
 * Class ImportedEntitiesDTO
 *
 * Журнал сущностей, созданных одним запуском импорта.
 *
 * @package Inc\DTO\Subject
 *
 * ### Зачем
 *
 * `wp_insert_post()`, `wp_insert_term()` и запись в `wp_options` не участвуют
 * в транзакции InnoDB, поэтому «откат» импорта невозможно свести к ROLLBACK.
 * Единственный работающий способ — вести журнал того, что мы создали сами,
 * и при ошибке удалить ровно это ({@see \Inc\Services\Subject\Import\ImportRollbackService}).
 *
 * ### Почему DTO изменяемый
 *
 * В отличие от остальных DTO плагина, этот накапливает состояние по ходу
 * импорта: он и есть «лента отмены». Живёт строго в пределах одного запроса,
 * наружу не отдаётся и в БД не пишется.
 *
 * ### Важно
 *
 * Записывать сюда следует **только созданное этим запуском**. Термин, который
 * уже существовал на целевом сайте, или переиспользованная задача глобального
 * банка не должны попасть в журнал — иначе откат сотрёт чужие данные.
 */
final class ImportedEntitiesDTO {

	/**
	 * ID созданных записей (любых типов).
	 *
	 * @var int[]
	 */
	private array $postIds = array();

	/**
	 * Созданные термины: [ ['term_id' => int, 'taxonomy' => string], ... ].
	 *
	 * @var array<int, array{term_id: int, taxonomy: string}>
	 */
	private array $terms = array();

	/**
	 * ID созданных вложений медиабиблиотеки.
	 *
	 * @var int[]
	 */
	private array $attachmentIds = array();

	/**
	 * Ключи предметов, созданных этим запуском (вместе со всеми их
	 * option-структурами: таксономии, метабоксы, boilerplates).
	 *
	 * @var string[]
	 */
	private array $subjectKeys = array();

	/**
	 * ID групп, созданных этим запуском.
	 *
	 * @var int[]
	 */
	private array $groupIds = array();

	/**
	 * ID лиц (persons), созданных этим запуском.
	 *
	 * @var int[]
	 */
	private array $personIds = array();

	/**
	 * ID пользователей WordPress, созданных этим запуском.
	 *
	 * @var int[]
	 */
	private array $userIds = array();

	/**
	 * Запоминает созданную группу.
	 *
	 * @param int $groupId ID группы (0 игнорируется)
	 *
	 * @return void
	 */
	public function addGroup( int $groupId ): void {
		if ( $groupId > 0 ) {
			$this->groupIds[] = $groupId;
		}
	}

	/**
	 * Запоминает созданное лицо.
	 *
	 * Только созданное: найденное по документу/ФИО лицо принадлежит целевому
	 * сайту, и откат обязан его не трогать.
	 *
	 * @param int $personId ID лица (0 игнорируется)
	 *
	 * @return void
	 */
	public function addPerson( int $personId ): void {
		if ( $personId > 0 ) {
			$this->personIds[] = $personId;
		}
	}

	/**
	 * Запоминает созданную учётную запись WordPress.
	 *
	 * @param int $userId ID пользователя (0 игнорируется)
	 *
	 * @return void
	 */
	public function addUser( int $userId ): void {
		if ( $userId > 0 ) {
			$this->userIds[] = $userId;
		}
	}

	/** @return int[] */
	public function groupIds(): array {
		return $this->groupIds;
	}

	/** @return int[] */
	public function personIds(): array {
		return $this->personIds;
	}

	/** @return int[] */
	public function userIds(): array {
		return $this->userIds;
	}

	/**
	 * Запоминает созданную запись.
	 *
	 * @param int $postId ID записи (0 игнорируется)
	 *
	 * @return void
	 */
	public function addPost( int $postId ): void {
		if ( $postId > 0 ) {
			$this->postIds[] = $postId;
		}
	}

	/**
	 * Запоминает созданный термин.
	 *
	 * @param int    $termId   ID термина (0 игнорируется — значит термин уже был)
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function addTerm( int $termId, string $taxonomy ): void {
		if ( $termId > 0 && '' !== $taxonomy ) {
			$this->terms[] = array(
				'term_id'  => $termId,
				'taxonomy' => $taxonomy,
			);
		}
	}

	/**
	 * Запоминает залитое вложение.
	 *
	 * @param int $attachmentId ID вложения (0 игнорируется)
	 *
	 * @return void
	 */
	public function addAttachment( int $attachmentId ): void {
		if ( $attachmentId > 0 ) {
			$this->attachmentIds[] = $attachmentId;
		}
	}

	/**
	 * Запоминает созданный предмет.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	public function addSubject( string $key ): void {
		if ( '' !== $key ) {
			$this->subjectKeys[] = $key;
		}
	}

	/** @return int[] */
	public function postIds(): array {
		return $this->postIds;
	}

	/** @return array<int, array{term_id: int, taxonomy: string}> */
	public function terms(): array {
		return $this->terms;
	}

	/** @return int[] */
	public function attachmentIds(): array {
		return $this->attachmentIds;
	}

	/** @return string[] */
	public function subjectKeys(): array {
		return $this->subjectKeys;
	}

	/**
	 * Ничего не создано — откатывать нечего.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->postIds
			&& array() === $this->terms
			&& array() === $this->attachmentIds
			&& array() === $this->subjectKeys
			&& array() === $this->groupIds
			&& array() === $this->personIds
			&& array() === $this->userIds;
	}

	/**
	 * Сводка для лога/ответа: сколько чего создано.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return array(
			'posts'       => count( $this->postIds ),
			'terms'       => count( $this->terms ),
			'attachments' => count( $this->attachmentIds ),
			'subjects'    => count( $this->subjectKeys ),
			'groups'      => count( $this->groupIds ),
			'persons'     => count( $this->personIds ),
			'accounts'    => count( $this->userIds ),
		);
	}
}
