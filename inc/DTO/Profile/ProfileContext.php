<?php

declare( strict_types=1 );

namespace Inc\DTO\Profile;

use Inc\Enums\Access\UserRole;

/**
 * Class ProfileContext
 *
 * Контекст личного кабинета: КТО смотрит, ЧЬИ данные и можно ли писать.
 *
 * Ключевая идея раскладки «2 формы на 3 роли»: ученик и родитель используют ОДНУ
 * витрину ({@see \Inc\Services\Profile\LearnerProfileView}), различаясь только этим
 * контекстом — родитель получает данные ребёнка ($subjectPersonId из $children)
 * и режим только чтения ($readOnly = true).
 *
 * @package Inc\DTO\Profile
 */
final readonly class ProfileContext {

	/**
	 * @param int                                          $wpUserId        WP-идентификатор вошедшего пользователя.
	 * @param int|null                                     $personId        Person-id вошедшего (null, если не привязан).
	 * @param UserRole                                     $role            Основная роль (через UserRole::primary()).
	 * @param int|null                                     $subjectPersonId Чьи данные показывать: ученик — свои; родитель — выбранного ребёнка.
	 * @param bool                                         $readOnly        true — режим только чтения (родитель).
	 * @param list<array{personId: int, name: string}>    $children        Дети родителя для переключателя (пусто для остальных).
	 */
	public function __construct(
		public int $wpUserId,
		public ?int $personId,
		public UserRole $role,
		public ?int $subjectPersonId,
		public bool $readOnly,
		public array $children = array(),
	) {}

	/**
	 * Person-id, чьи данные показывать, с учётом клиентского запроса.
	 *
	 * Правило доступа «родитель видит только своих детей» живёт здесь,
	 * а не в AJAX-слое: ученик всегда получает свои данные (клиентский
	 * параметр игнорируется), родитель (read-only) может запросить только
	 * ребёнка из $children — иначе остаётся ребёнок по умолчанию.
	 *
	 * @param int $requested Клиентский `student_person_id` (0 — не передан).
	 */
	public function resolveSubjectPersonId( int $requested ): ?int {
		if ( ! $this->readOnly || $requested <= 0 ) {
			return $this->subjectPersonId;
		}

		foreach ( $this->children as $child ) {
			if ( (int) $child['personId'] === $requested ) {
				return $requested;
			}
		}

		return $this->subjectPersonId;
	}
}
