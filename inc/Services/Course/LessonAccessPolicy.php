<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\AccessLevel;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\OptionName;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

class LessonAccessPolicy {

	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly PersonRepository        $personRepository,
	) {}

	/**
	 * Разрешить доступ одной записи к одному уроку группы.
	 * Полная матрица: видимость × статус × даты × политика ретеншна.
	 */
	public function resolve( StudentRecordDTO $record, GroupLessonDTO $lesson ): AccessLevel {
		// hidden — никому.
		if ( 'hidden' === $lesson->visibility ) {
			return AccessLevel::None;
		}

		if ( $record->status === EnrollmentStatus::Active ) {
			// Поздний ученик видит весь бэк-каталог; сдавать может только с даты своего зачисления.
			if ( null !== $lesson->openedAt && $lesson->openedAt >= $record->enrolledAt ) {
				return AccessLevel::ReadSubmit;
			}
			return AccessLevel::Read;
		}

		// Терминальный статус.
		if ( $record->status->isTerminal() ) {
			$policy = get_option( OptionName::ExpulsionRetentionPolicy->value, 'retain' );
			if ( 'block' === $policy ) {
				return AccessLevel::None;
			}
			// retain: видит уроки, опубликованные до даты отчисления.
			$expelledAt = $record->expelledAt;
			if ( null !== $expelledAt && null !== $lesson->openedAt && $lesson->openedAt <= $expelledAt ) {
				return AccessLevel::Read;
			}
			return AccessLevel::None;
		}

		return AccessLevel::None;
	}

	/** Может ли ученик читать конкретный урок группы. */
	public function canRead( int $studentPersonId, int $groupLessonId ): bool {
		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $lesson ) {
			return false;
		}
		foreach ( $this->studentRecords->findAllByStudentAndGroup( $studentPersonId, $lesson->groupId ) as $record ) {
			if ( $this->resolve( $record, $lesson ) !== AccessLevel::None ) {
				return true;
			}
		}
		return false;
	}

	/** Может ли ученик сдавать работы урока. */
	public function canSubmit( int $studentPersonId, int $groupLessonId ): bool {
		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $lesson ) {
			return false;
		}
		foreach ( $this->studentRecords->findAllByStudentAndGroup( $studentPersonId, $lesson->groupId ) as $record ) {
			if ( $this->resolve( $record, $lesson ) === AccessLevel::ReadSubmit ) {
				return true;
			}
		}
		return false;
	}

	/** @return GroupLessonDTO[] Видимые уроки группы с учётом политики доступа. */
	public function visibleLessonsForStudent( int $studentPersonId, int $groupId ): array {
		$records = $this->studentRecords->findAllByStudentAndGroup( $studentPersonId, $groupId );
		$lessons = $this->groupLessons->listOpenByGroup( $groupId );
		$visible = array();
		foreach ( $lessons as $lesson ) {
			foreach ( $records as $record ) {
				if ( $this->resolve( $record, $lesson ) !== AccessLevel::None ) {
					$visible[] = $lesson;
					break;
				}
			}
		}
		return $visible;
	}
}
