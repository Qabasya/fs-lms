<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Course\GradeBadge;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class PassedWorksService
 *
 * Вкладка «Прошедшие» экрана «Работы»: все работы занятий, которые уже начались, по группам
 * преподавателя. У каждой работы видно, кто сдал, а кто нет; несдавшему работу можно
 * «засчитать» (занятие прошло, решали вне платформы) — максимальный балл за каждое задание.
 * Сдача ученика приоритетнее зачёта: у сдавшего зачёт не выставляется.
 *
 * @package Inc\Services\Course
 */
class PassedWorksService {

	public function __construct(
		private readonly TeacherGroupResolver     $teacherGroups,
		private readonly GroupsRepository         $groups,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly StudentRecordRepository  $studentRecords,
		private readonly SubmissionRepository     $submissions,
		private readonly SubmissionService        $submissionService,
		private readonly HomeworkDeadlineService  $deadlines,
		private readonly LessonManager            $lessons,
		private readonly ClockInterface           $clock,
	) {}

	/**
	 * Работы прошедших занятий — по строке на пару «занятие + работа».
	 *
	 * @param int $groupId 0 — все группы пользователя
	 *
	 * @return array<int, array{group_lesson_id:int, work_id:int, group_id:int, group_name:string, date:string, topic:string, title:string, label:string, badge:?string, total:int, submitted:int, missing:int}>
	 */
	public function list( int $userId, bool $allGroups, int $groupId = 0 ): array {
		$groupIds = $this->teacherGroups->idsFor( $userId, $allGroups );
		if ( $groupId > 0 ) {
			$groupIds = in_array( $groupId, $groupIds, true ) ? array( $groupId ) : array();
		}

		$now = $this->clock->now();
		$out = array();

		foreach ( $groupIds as $gid ) {
			$group    = $this->groups->findById( $gid );
			$students = $this->activeStudents( $gid );

			foreach ( $this->groupLessons->listByGroup( $gid ) as $row ) {
				if ( null === $row->scheduledAt || $row->scheduledAt > $now ) {
					continue;
				}
				$works = $this->deadlines->assignedWorks( $row );
				if ( array() === $works ) {
					continue;
				}

				$expected  = $this->expectedStudents( $row->studentPersonId, $row->kind->isIndividual(), $students );
				$submitted = $this->submittedMap( $row->id );
				$topic     = $row->lessonId ? ( $this->lessons->get( $row->lessonId )?->topic ?? '' ) : '';

				foreach ( $works as $work ) {
					if ( array() === $work->itemIds ) {
						continue;
					}
					$done  = count( array_filter( array_keys( $expected ), static fn( int $pid ): bool => isset( $submitted[ $work->id ][ $pid ] ) ) );
					$out[] = array(
						'group_lesson_id' => $row->id,
						'work_id'         => $work->id,
						'group_id'        => $gid,
						'group_name'      => (string) ( $group->name ?? '' ),
						'date'            => substr( $row->scheduledAt, 0, 10 ),
						'topic'           => '' !== $topic ? $topic : (string) ( $row->label ?? '' ),
						'title'           => $work->title,
						'label'           => $work->workType->label(),
						'badge'           => GradeBadge::fromWorkType( $work->workType )->badge(),
						'total'           => count( $expected ),
						'submitted'       => $done,
						'missing'         => count( $expected ) - $done,
					);
				}
			}
		}

		usort( $out, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );

		return $out;
	}

	/**
	 * Ученики работы занятия: сдавшие (сдача открывается) и не сдавшие (можно засчитать).
	 *
	 * @return array<int, array{student_person_id:int, student_name:string, submission_id:int, score:?float, max_score:?float, credited:bool}>|null null — занятие вне доступных групп
	 */
	public function students( int $groupLessonId, int $workId, int $userId, bool $allGroups ): ?array {
		$row = $this->accessibleRow( $groupLessonId, $userId, $allGroups );
		if ( null === $row ) {
			return null;
		}

		$expected = $this->expectedStudents( $row->studentPersonId, $row->kind->isIndividual(), $this->activeStudents( $row->groupId ) );
		$byPerson = array();
		foreach ( $this->submissions->listAggregatesByGroupLesson( $groupLessonId ) as $sub ) {
			if ( $sub->workId === $workId ) {
				$byPerson[ $sub->studentPersonId ] = $sub;
			}
		}

		$out = array();
		foreach ( $expected as $personId => $name ) {
			$sub   = $byPerson[ $personId ] ?? null;
			$out[] = array(
				'student_person_id' => $personId,
				'student_name'      => $name,
				'submission_id'     => $sub->id ?? 0,
				'score'             => $sub?->score,
				'max_score'         => $sub?->maxScore,
				'credited'          => $sub?->isCredited() ?? false,
			);
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['student_name'], $b['student_name'] ) );

		return $out;
	}

	/**
	 * Засчитывает работу ученикам без сдачи.
	 *
	 * @param int[] $personIds Пусто — всем, кто ещё не сдал
	 *
	 * @return int|null Сколько учеников засчитано; null — занятие вне доступных групп
	 *
	 * @throws \InvalidArgumentException Работа не входит в набор занятия.
	 */
	public function credit( int $groupLessonId, int $workId, array $personIds, int $userId, bool $allGroups ): ?int {
		$students = $this->students( $groupLessonId, $workId, $userId, $allGroups );
		if ( null === $students ) {
			return null;
		}

		$credited = 0;
		foreach ( $students as $student ) {
			if ( $student['submission_id'] > 0 ) {
				continue;
			}
			if ( array() !== $personIds && ! in_array( $student['student_person_id'], $personIds, true ) ) {
				continue;
			}
			if ( $this->submissionService->creditWork( $student['student_person_id'], $groupLessonId, $workId, $userId ) ) {
				++$credited;
			}
		}

		return $credited;
	}

	/**
	 * Снимает зачёт, выставленный вручную, у одного ученика.
	 *
	 * @return bool|null null — занятие вне доступных групп; false — засчитанной вручную работы нет
	 */
	public function uncredit( int $groupLessonId, int $workId, int $personId, int $userId, bool $allGroups ): ?bool {
		if ( null === $this->accessibleRow( $groupLessonId, $userId, $allGroups ) ) {
			return null;
		}

		return $this->submissionService->revokeWorkCredit( $personId, $groupLessonId, $workId );
	}

	private function accessibleRow( int $groupLessonId, int $userId, bool $allGroups ): ?\Inc\DTO\Course\GroupLessonDTO {
		$row = $this->groupLessons->find( $groupLessonId );

		return ( null !== $row && in_array( $row->groupId, $this->teacherGroups->idsFor( $userId, $allGroups ), true ) ) ? $row : null;
	}

	/** @return array<int, string> person_id => «Фамилия Имя» активных учеников группы */
	private function activeStudents( int $groupId ): array {
		$out = array();
		foreach ( $this->studentRecords->findActiveByGroupId( $groupId ) as $record ) {
			$out[ $record->studentPersonId ] ??= trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName );
		}

		return $out;
	}

	/**
	 * @param array<int, string> $students
	 *
	 * @return array<int, string>
	 */
	private function expectedStudents( ?int $individualPersonId, bool $individual, array $students ): array {
		if ( ! $individual ) {
			return $students;
		}

		return null !== $individualPersonId && isset( $students[ $individualPersonId ] )
			? array( $individualPersonId => $students[ $individualPersonId ] )
			: array();
	}

	/** @return array<int, array<int, true>> work_id => [person_id => true] сдавших занятие */
	private function submittedMap( int $groupLessonId ): array {
		$map = array();
		foreach ( $this->submissions->listAggregatesByGroupLesson( $groupLessonId ) as $sub ) {
			$map[ $sub->workId ][ $sub->studentPersonId ] = true;
		}

		return $map;
	}
}
