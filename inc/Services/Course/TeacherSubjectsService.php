<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Capability;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Class TeacherSubjectsService
 *
 * Сопоставляет преподавателя с предметами, которые он реально ведёт
 * (через группы по teacher_id). Используется для мягкого скоупа меню «Обучение»:
 * по умолчанию показываем предметы препода, чужое не прячем.
 *
 * @package Inc\Services\Course
 */
class TeacherSubjectsService {

	public function __construct(
		private readonly GroupsRepository  $groups,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Предметы, доступные пользователю для меню «Обучение».
	 *
	 * Администратор видит все предметы; преподаватель — только предметы своих групп.
	 * Если у преподавателя нет групп — возвращаются все предметы (фоллбэк, чтобы
	 * не оставлять меню пустым; чужое всё равно не прячем).
	 *
	 * @param int $userId WP user ID.
	 *
	 * @return SubjectDTO[]
	 */
	public function subjectsForUser( int $userId ): array {
		// readAll() возвращает массив, индексированный ключом предмета (ассоциативный);
		// приводим к list, чтобы потребители могли обращаться по [0].
		$all = array_values( $this->subjects->readAll() );

		if ( user_can( $userId, Capability::Admin->value ) ) {
			return $all;
		}

		$keys = $this->subjectKeysForTeacher( $userId );
		if ( empty( $keys ) ) {
			return $all;
		}

		$result = array();
		foreach ( $all as $subject ) {
			if ( in_array( $subject->key, $keys, true ) ) {
				$result[] = $subject;
			}
		}

		return $result;
	}

	/**
	 * Ключ предмета активной вкладки по умолчанию.
	 *
	 * @param int $userId WP user ID.
	 *
	 * @return string Пустая строка, если предметов нет.
	 */
	public function defaultSubjectKey( int $userId ): string {
		$subjects = $this->subjectsForUser( $userId );
		$first    = $subjects[0] ?? null;

		return $first instanceof SubjectDTO ? $first->key : '';
	}

	/**
	 * Уникальные ключи предметов групп преподавателя.
	 *
	 * @param int $userId WP user ID.
	 *
	 * @return string[]
	 */
	private function subjectKeysForTeacher( int $userId ): array {
		$keys = array();
		foreach ( $this->groups->findAll() as $group ) {
			if ( (int) ( $group->teacher_id ?? 0 ) === $userId ) {
				$key = (string) ( $group->subject_key ?? '' );
				if ( '' !== $key ) {
					$keys[ $key ] = true;
				}
			}
		}

		return array_keys( $keys );
	}
}
