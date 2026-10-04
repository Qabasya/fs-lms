<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Гейт управления проведениями по правам пользователя.
 *
 * **Глобальный доступ** — методист и администратор управляют проведениями любого предмета.
 *
 * **Локальный доступ** — преподаватель управляет проведениями только своих предметов
 * (предметы групп, где он указан учителем). Запасного варианта нет: пустой результат
 * `subjectKeysFor()` означает отсутствие групп и, как следствие, отсутствие прав.
 *
 * **Офис не может управлять проведениями** — у офисного пользователя нет `ManageExams`,
 * даже если есть `ManageSubjects`.
 *
 * **Замены** не даруют права: только администратор может передать право через смену
 * преподавателя в группе (не в рамках этапа 1).
 *
 * @package Inc\Services\Exam
 */
class ExamAccessGuard {

	public function __construct(
		private readonly GroupsRepository $groups,
	) {}

	/**
	 * Глобальный доступ: методист или администратор на экзамены.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return bool
	 */
	public function isGlobal( int $userId ): bool {
		// Должен иметь ManageExams И (Admin OR ManageSubjects)
		if ( ! user_can( $userId, Capability::ManageExams->value ) ) {
			return false;
		}

		return user_can( $userId, Capability::Admin->value ) ||
			   user_can( $userId, Capability::ManageSubjects->value );
	}

	/**
	 * Предметы групп, где пользователь указан учителем.
	 *
	 * Пустой результат означает отсутствие групп и отсутствие прав.
	 * Без запасного варианта «все предметы».
	 *
	 * @param int $userId ID пользователя WordPress (teacher_id в таблице групп)
	 *
	 * @return string[] Ключи предметов (уникальные, отсортированные)
	 */
	public function subjectKeysFor( int $userId ): array {
		$groups = $this->groups->findByTeacherId( $userId );
		$subjects = array();

		foreach ( $groups as $group ) {
			// Пропускаем удалённые группы
			if ( null !== $group->deleted_at ) {
				continue;
			}

			$subjects[ $group->subject_key ] = true;
		}

		$keys = array_keys( $subjects );
		sort( $keys );
		return $keys;
	}

	/**
	 * Может ли пользователь управлять проведениями этого предмета.
	 *
	 * @param int    $userId    ID пользователя
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return bool
	 */
	public function canManageSubject( int $userId, string $subjectKey ): bool {
		// Сначала проверяем наличие базового права
		if ( ! user_can( $userId, Capability::ManageExams->value ) ) {
			return false;
		}

		// Если глобальный доступ — может управлять
		if ( $this->isGlobal( $userId ) ) {
			return true;
		}

		// Иначе только свои предметы
		return in_array( $subjectKey, $this->subjectKeysFor( $userId ), true );
	}

	/**
	 * Может ли пользователь управлять конкретным проведением.
	 *
	 * Глобальный доступ — любым. Преподаватель — только своим (владелец проведения) и только пока предмет проведения
	 * остаётся его предметом: учитель того же предмета чужим проведением не управляет (SPEC §3).
	 */
	public function canManageEvent( int $userId, ExamEventDTO $event ): bool {
		if ( $this->isGlobal( $userId ) ) {
			return true;
		}

		return $event->ownerUserId === $userId && $this->canManageSubject( $userId, $event->subjectKey );
	}

	/**
	 * Может ли пользователь управлять гостевой записью проведения (источники и ссылки приглашений): право на гостей плюс право на проведение.
	 */
	public function canManageEventGuests( int $userId, ExamEventDTO $event ): bool {
		return user_can( $userId, Capability::ManageExamGuests->value ) && $this->canManageEvent( $userId, $event );
	}

	/**
	 * Предметы из переданного списка, которыми пользователь может управлять.
	 *
	 * Для селектора предмета: глобальному пользователю — все, локальному — пересечение.
	 *
	 * @param int      $userId         ID пользователя
	 * @param string[] $allSubjectKeys Все доступные ключи предметов
	 *
	 * @return string[] Управляемые ключи (в исходном порядке)
	 */
	public function manageableSubjectKeys( int $userId, array $allSubjectKeys ): array {
		if ( $this->isGlobal( $userId ) ) {
			return $allSubjectKeys;
		}

		$mySubjects = $this->subjectKeysFor( $userId );
		return array_intersect( $allSubjectKeys, $mySubjects );
	}
}
