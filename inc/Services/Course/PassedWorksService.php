<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\GradeBadge;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class PassedWorksService
 *
 * Экран «Работы» кабинета: один список работ прошедших занятий по группам преподавателя, новые
 * сверху, порциями. У каждой работы видно, кто сдал, кто ждёт проверки и кто не сдал; несдавшему
 * работу можно «засчитать» (занятие прошло, решали вне платформы) — максимальный балл за каждое
 * задание. Сдача ученика приоритетнее зачёта: у сдавшего зачёт не выставляется.
 *
 * Работы только занятий групп; попытки экзаменов и контрольных сюда не входят.
 *
 * @package Inc\Services\Course
 */
class PassedWorksService {

	/** Сколько работ отдаёт одна порция; порция закрывается целым занятием, поэтому работ может быть чуть больше. */
	public const PAGE_SIZE = 10;

	/** Быстрый фильтр: есть сдачи, которые ждут проверки. */
	public const STATUS_PENDING = 'pending';

	/** Быстрый фильтр: есть ученики, не сдавшие работу. */
	public const STATUS_MISSING = 'missing';

	/** Сколько занятий просматривается за один запрос: редкий фильтр не должен перебирать всю историю разом. */
	private const SCAN_LIMIT = 40;

	public function __construct(
		private readonly TeacherGroupResolver     $teacherGroups,
		private readonly GroupsRepository         $groups,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly StudentRecordRepository  $studentRecords,
		private readonly SubmissionRepository     $submissions,
		private readonly SubmissionService        $submissionService,
		private readonly HomeworkDeadlineService  $deadlines,
		private readonly LessonManager            $lessons,
		private readonly WorkManager              $works,
		private readonly WorkMarksService         $marks,
		private readonly ClockInterface           $clock,
	) {}

	/**
	 * Порция списка работ.
	 *
	 * Просматривает занятия от новых к старым, пока не наберёт {@see self::PAGE_SIZE} работ (целым
	 * занятием) или не упрётся в предел просмотра. Продолжение — по метке `next`.
	 *
	 * @param int    $groupId 0 — все группы пользователя
	 * @param string $type    Значение {@see WorkType}; пусто — любой тип
	 * @param string $status  {@see self::STATUS_PENDING} / {@see self::STATUS_MISSING}; пусто — без фильтра
	 * @param string $cursor  Метка продолжения из прошлой порции; пусто — с самого начала
	 *
	 * @return array{items: array<int, array<string, mixed>>, next: ?string, pending_total: ?int} `pending_total` — только в первой порции
	 *
	 * @throws \InvalidArgumentException Метка продолжения не распознана.
	 */
	public function feed( int $userId, bool $allGroups, int $groupId, string $type, string $status, string $cursor ): array {
		[ $cursorAt, $cursorId ] = self::parseCursor( $cursor );

		$groupIds = $this->teacherGroups->idsFor( $userId, $allGroups );
		if ( $groupId > 0 ) {
			$groupIds = in_array( $groupId, $groupIds, true ) ? array( $groupId ) : array();
		}
		if ( array() === $groupIds ) {
			return array( 'items' => array(), 'next' => null, 'pending_total' => '' === $cursor ? 0 : null );
		}

		$rows    = $this->groupLessons->listFeedPage( $groupIds, $this->clock->now(), $cursorAt, $cursorId, self::SCAN_LIMIT + 1 );
		$hasMore = count( $rows ) > self::SCAN_LIMIT;
		$rows    = array_slice( $rows, 0, self::SCAN_LIMIT );
		$last    = count( $rows ) - 1;

		$items   = array();
		$next    = null;
		$context = array( 'students' => array(), 'groups' => array() );

		foreach ( $rows as $i => $entry ) {
			array_push( $items, ...$this->lessonItems( $entry['row'], $entry['at'], $type, $status, $context ) );

			if ( count( $items ) >= self::PAGE_SIZE ) {
				$next = ( $i < $last || $hasMore ) ? self::cursorOf( $entry ) : null;
				break;
			}
			if ( $i === $last && $hasMore ) {
				$next = self::cursorOf( $entry );
			}
		}

		return array(
			'items'         => $items,
			'next'          => $next,
			'pending_total' => '' === $cursor ? $this->pendingTotal( $groupIds ) : null,
		);
	}

	/**
	 * Ученики работы занятия: сдавшие (сдача открывается) и не сдавшие (можно засчитать).
	 *
	 * `status` — статус сдачи ({@see SubmissionStatus}) или пусто, если сдачи нет. У сдавшего есть ещё `marks`
	 * (вердикты по заданиям — полоска в карточке), `submitted_at` и `duration_sec` (сколько решал).
	 *
	 * @return array<int, array{student_person_id:int, student_name:string, submission_id:int, status:string, score:?float, max_score:?float, credited:bool, marks:string[], submitted_at:?string, duration_sec:?int}>|null null — занятие вне доступных групп
	 */
	public function students( int $groupLessonId, int $workId, int $userId, bool $allGroups ): ?array {
		$row = $this->accessibleRow( $groupLessonId, $userId, $allGroups );
		if ( null === $row ) {
			return null;
		}

		$expected = $this->expectedStudents( $row->studentPersonId, $row->kind->isIndividual(), $this->activeStudents( $row->groupId ) );
		$byPerson = $this->lessonSubmissions( $groupLessonId )[ $workId ] ?? array();

		$out = array();
		foreach ( $expected as $personId => $name ) {
			$sub   = $byPerson[ $personId ] ?? null;
			$out[] = array(
				'student_person_id' => $personId,
				'student_name'      => $name,
				'submission_id'     => $sub->id ?? 0,
				'status'            => null !== $sub ? $sub->status->value : '',
				'score'             => $sub?->score,
				'max_score'         => $sub?->maxScore,
				'credited'          => $sub?->isCredited() ?? false,
				'marks'             => null !== $sub ? $this->marks->marksFor( 'submission', $sub->id ) : array(),
				'submitted_at'      => $sub?->submittedAt,
				'duration_sec'      => $sub?->durationSec,
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

	/**
	 * Работы одного занятия, прошедшие фильтры, — строки списка.
	 *
	 * Работа со сдачами показывается, даже если её потом убрали из набора занятия: сдача не должна пропасть.
	 *
	 * @param array{students: array<int, array<int, string>>, groups: array<int, string>} $context Кеш на время запроса (ученики и названия групп)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lessonItems( GroupLessonDTO $row, string $at, string $type, string $status, array &$context ): array {
		$submitted = $this->lessonSubmissions( $row->id );

		/** @var array<int, WorkDTO> $works */
		$works = array();
		foreach ( $this->deadlines->assignedWorks( $row ) as $work ) {
			if ( array() !== $work->itemIds ) {
				$works[ $work->id ] = $work;
			}
		}
		foreach ( array_keys( $submitted ) as $workId ) {
			if ( ! isset( $works[ $workId ] ) ) {
				$work = $this->works->get( $workId );
				if ( null !== $work ) {
					$works[ $workId ] = $work;
				}
			}
		}
		if ( array() === $works ) {
			return array();
		}

		$gid                       = $row->groupId;
		$context['students'][ $gid ] ??= $this->activeStudents( $gid );
		$context['groups'][ $gid ]   ??= (string) ( $this->groups->findById( $gid )->name ?? '' );
		$expected                    = $this->expectedStudents( $row->studentPersonId, $row->kind->isIndividual(), $context['students'][ $gid ] );
		$topic                       = $row->lessonId ? ( $this->lessons->get( $row->lessonId )?->topic ?? '' ) : '';
		$pendingStatuses             = self::pendingStatuses();

		$out = array();
		foreach ( $works as $work ) {
			if ( '' !== $type && $work->workType->value !== $type ) {
				continue;
			}

			$byPerson = $submitted[ $work->id ] ?? array();
			$done     = 0;
			$pending  = 0;
			foreach ( array_keys( $expected ) as $personId ) {
				$sub = $byPerson[ $personId ] ?? null;
				if ( null === $sub ) {
					continue;
				}
				++$done;
				if ( in_array( $sub->status->value, $pendingStatuses, true ) ) {
					++$pending;
				}
			}
			$missing = count( $expected ) - $done;

			if ( ( self::STATUS_PENDING === $status && 0 === $pending ) || ( self::STATUS_MISSING === $status && 0 === $missing ) ) {
				continue;
			}

			$out[] = array(
				'group_lesson_id' => $row->id,
				'work_id'         => $work->id,
				'group_id'        => $gid,
				'group_name'      => $context['groups'][ $gid ],
				'date'            => substr( $at, 0, 10 ),
				'topic'           => '' !== $topic ? $topic : (string) ( $row->label ?? '' ),
				'title'           => $work->title,
				'label'           => $work->workType->label(),
				'badge'           => GradeBadge::fromWorkType( $work->workType )->badge(),
				'total'           => count( $expected ),
				'submitted'       => $done,
				'missing'         => $missing,
				'pending'         => $pending,
			);
		}

		return $out;
	}

	/** Сколько сдач ждут проверки в выбранных группах — число у фильтра «Ждут проверки». */
	private function pendingTotal( array $groupIds ): int {
		$total = 0;
		foreach ( $this->submissions->summaryByGroups( $groupIds, self::pendingStatuses() ) as $summary ) {
			$total += (int) $summary['cnt'];
		}

		return $total;
	}

	/** @return string[] Статусы сдачи, при которых работа ждёт проверки преподавателя. */
	private static function pendingStatuses(): array {
		return array( SubmissionStatus::Submitted->value, SubmissionStatus::PendingReview->value );
	}

	private function accessibleRow( int $groupLessonId, int $userId, bool $allGroups ): ?GroupLessonDTO {
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

	/** @return array<int, array<int, SubmissionDTO>> work_id => [person_id => сдача] занятия */
	private function lessonSubmissions( int $groupLessonId ): array {
		$map = array();
		foreach ( $this->submissions->listAggregatesByGroupLesson( $groupLessonId ) as $sub ) {
			$map[ $sub->workId ][ $sub->studentPersonId ] = $sub;
		}

		return $map;
	}

	/**
	 * Метка продолжения: «дата» и ID последнего просмотренного занятия.
	 *
	 * @param array{row: GroupLessonDTO, at: string} $entry
	 */
	private static function cursorOf( array $entry ): string {
		return $entry['at'] . '|' . $entry['row']->id;
	}

	/**
	 * @return array{0: ?string, 1: int} [«дата», ID]; для пустой метки — [null, 0]
	 *
	 * @throws \InvalidArgumentException Метка не распознана.
	 */
	private static function parseCursor( string $cursor ): array {
		if ( '' === $cursor ) {
			return array( null, 0 );
		}
		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $cursor, $m ) ) {
			throw new \InvalidArgumentException( 'Некорректная метка продолжения списка.' );
		}

		return array( $m[1], (int) $m[2] );
	}
}
