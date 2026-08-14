<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\LessonAccessPolicy;

/**
 * Авторизация доступа к контрольной (Bugfix #5).
 *
 * Контрольная отдаётся публичным singular-пермалинком (плеер экзамена живёт на
 * нём), поэтому доступ нельзя оставлять открытым. Ученик вправе видеть и сдавать
 * контрольную только если он зачислен в группу, чей курс содержит урок со ссылкой
 * на эту контрольную, и сам урок ему доступен (делегируем в {@see LessonAccessPolicy}).
 *
 * @package Inc\Services\Assessment
 */
class AssessmentAccessPolicy {

	public function __construct(
		private readonly StudentRecordRepository  $studentRecords,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly LessonManager            $lessons,
		private readonly LessonAccessPolicy       $lessonAccess,
		private readonly GroupsRepository         $groups,
		private readonly CoursePreviewAccessGuard $previewAccess,
	) {}

	/**
	 * Может ли ученик открыть/сдавать контрольную.
	 *
	 * @param int $studentPersonId Person-id ученика.
	 * @param int $assessmentId    ID поста контрольной.
	 */
	public function canAccess( int $studentPersonId, int $assessmentId ): bool {
		return null !== $this->resolveAccessibleLesson( $studentPersonId, $assessmentId );
	}

	/**
	 * Занятие, через которое ученику разрешена эта контрольная.
	 *
	 * Проверка доступа и так перебирает занятия — возвращаем найденное, чтобы
	 * попытку было к чему привязать, когда ученик пришёл по прямому пермалинку
	 * (закладка, возврат к активной попытке), а не из плеера с `?from_gl=`.
	 *
	 * Контрольная может стоять в нескольких занятиях (повтор темы, вторая
	 * группа) — берём самое позднее по дате: оно и есть «текущее» для ученика.
	 * Занятия без даты уступают датированным.
	 *
	 * @param int $studentPersonId Person-id ученика.
	 * @param int $assessmentId    ID поста контрольной.
	 */
	public function resolveAccessibleLesson( int $studentPersonId, int $assessmentId ): ?GroupLessonDTO {
		if ( $studentPersonId <= 0 || $assessmentId <= 0 ) {
			return null;
		}

		// Группы ученика (любой статус — финальное решение за LessonAccessPolicy::canRead,
		// который учитывает статус/видимость/даты/ретеншн).
		$groupIds = array();
		foreach ( $this->studentRecords->findByStudent( $studentPersonId ) as $record ) {
			$groupIds[ $record->groupId ] = true;
		}

		$best = null;

		foreach ( array_keys( $groupIds ) as $groupId ) {
			foreach ( $this->groupLessons->listByGroup( $groupId ) as $groupLesson ) {
				if ( null === $groupLesson->lessonId ) {
					continue;
				}
				$lesson = $this->lessons->get( $groupLesson->lessonId );
				if ( null === $lesson || ! in_array( $assessmentId, $lesson->assessmentIds(), true ) ) {
					continue;
				}
				if ( ! $this->lessonAccess->canRead( $studentPersonId, $groupLesson->id ) ) {
					continue;
				}

				$best = $this->later( $best, $groupLesson );
			}
		}

		return $best;
	}

	/**
	 * Может ли пользователь открыть контрольную «вхолостую» — без ученика,
	 * попытки и записи ответов (см. `AttemptPageService::buildPreview()`).
	 *
	 * Скоуп — по контрольной, а не по пользователю: страница отдаёт полные
	 * условия всех заданий и ссылки на приложенные файлы, поэтому «преподаватель
	 * вообще» здесь недостаточное условие — иначе учитель одного предмета читал
	 * бы неопубликованные контрольные любого другого. Сотруднику (админ,
	 * платформа, автор курсов) отдаём без проверки: он и так видит эти задания
	 * в админке, сужение ничего бы не защитило.
	 *
	 * Проверка зеркалит {@see resolveAccessibleLesson()}, но без ученической
	 * части: преподавателю достаточно, чтобы контрольная стояла в занятии одной
	 * из его групп — даты, статусы и видимость для него не ограничение.
	 *
	 * @param int $wpUserId     ID пользователя WP.
	 * @param int $assessmentId ID поста контрольной.
	 */
	public function canPreview( int $wpUserId, int $assessmentId ): bool {
		if ( $wpUserId <= 0 || $assessmentId <= 0 ) {
			return false;
		}

		if ( $this->previewAccess->isStaffPreviewer( $wpUserId ) ) {
			return true;
		}

		foreach ( $this->groups->findByTeacherId( $wpUserId ) as $group ) {
			foreach ( $this->groupLessons->listByGroup( (int) $group->id ) as $groupLesson ) {
				if ( null === $groupLesson->lessonId ) {
					continue;
				}
				$lesson = $this->lessons->get( $groupLesson->lessonId );
				if ( null !== $lesson && in_array( $assessmentId, $lesson->assessmentIds(), true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Из двух доступных занятий — то, что позже по расписанию.
	 */
	private function later( ?GroupLessonDTO $current, GroupLessonDTO $candidate ): GroupLessonDTO {
		if ( null === $current ) {
			return $candidate;
		}

		$currentAt   = (string) ( $current->scheduledAt ?? '' );
		$candidateAt = (string) ( $candidate->scheduledAt ?? '' );

		return $candidateAt > $currentAt ? $candidate : $current;
	}
}
