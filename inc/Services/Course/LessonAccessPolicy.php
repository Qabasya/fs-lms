<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Access\AccessLevel;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Course\LessonVisibility;
use Inc\Repositories\OptionsRepositories\ExpulsionPolicyRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

class LessonAccessPolicy {

	/** @var array<int, bool> Per-request кэш «группа открытая» (resolve() зовётся на каждый урок). */
	private array $openModeCache = array();

	public function __construct(
		private readonly StudentRecordRepository   $studentRecords,
		private readonly GroupLessonRepository     $groupLessons,
		private readonly ExpulsionPolicyRepository $expulsionPolicy,
		private readonly LessonVisibilityService   $visibility,
		private readonly GroupsRepository          $groups,
	) {}

	/**
	 * Разрешить доступ одной записи к одному уроку группы.
	 * Полная матрица: видимость × статус × даты × политика ретеншна.
	 */
	public function resolve( StudentRecordDTO $record, GroupLessonDTO $lesson ): AccessLevel {
		// #13 (T2.34): скрытый урок с наступившей датой занятия авто-открывается —
		// все прошедшие уроки доступны сразу, без ручной публикации.
		$effectiveVisibility = $this->visibility->effectiveVisibility( $lesson );

		// hidden (и после авто-открытия всё ещё hidden — дата не наступила) — никому.
		if ( LessonVisibility::Hidden->value === $effectiveVisibility ) {
			return AccessLevel::None;
		}

		// Эффективная дата открытия: открытый урок без явного opened_at «открылся»
		// в момент занятия (scheduled_at). Покрывает и авто-открытие (было hidden,
		// дата наступила), и урок с visibility=open, которому opened_at не проставили
		// (иначе активный ученик получал бы только Read и не мог сдать — баг сдачи).
		$openedAt = $lesson->openedAt;
		if ( null === $openedAt && LessonVisibility::Open->value === $effectiveVisibility ) {
			$openedAt = $lesson->scheduledAt;
		}

		if ( $record->status === EnrollmentStatus::Active ) {
			// Активный ученик может сдавать любой доступный (открытый) урок —
			// в т.ч. занятия, прошедшие ДО его зачисления: «поздний» ученик тоже
			// вправе сдавать работы (скрытые/будущие уроки отсекаются выше как None).
			return AccessLevel::ReadSubmit;
		}

		// Терминальный статус.
		if ( $record->status->isTerminal() ) {
			if ( ExpulsionPolicyRepository::BLOCK === $this->expulsionPolicy->getRetentionPolicy() ) {
				return AccessLevel::None;
			}
			// retain: видит уроки, опубликованные до даты отчисления.
			$expelledAt = $record->expelledAt;
			if ( null !== $expelledAt && null !== $openedAt && $openedAt <= $expelledAt ) {
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

	/** Режим группы с кэшем на время запроса. */
	private function isOpenGroup( int $groupId ): bool {
		if ( ! isset( $this->openModeCache[ $groupId ] ) ) {
			$group = $this->groups->findById( $groupId );

			$this->openModeCache[ $groupId ] = AccessMode::Open === AccessMode::fromValueOrDefault(
				(string) ( $group->access_mode ?? '' )
			);
		}
		return $this->openModeCache[ $groupId ];
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
