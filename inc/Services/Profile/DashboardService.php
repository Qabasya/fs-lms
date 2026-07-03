<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\AttendanceService;

/**
 * Read-модель «Главной» кабинета преподавателя (Эпик 6): кросс-групповая
 * агрегация по всем группам, которые ведёт пользователь (свои + активные замены;
 * офис — все). Расписание сегодня/неделя, ворклист «заполнить»/«проверить»,
 * стат-плитки, маркеры замен (Эпик 5, T5.6/T5.7).
 *
 * @package Inc\Services\Profile
 */
class DashboardService {

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
		private readonly AttendanceService       $attendance,
		private readonly StudentRecordRepository $records,
		private readonly SubmissionRepository    $submissions,
		private readonly SubstitutionRepository  $substitutions,
		private readonly RoomRepository          $rooms,
		private readonly ClockInterface          $clock,
	) {}

	/**
	 * @param bool $allGroups офис видит все группы (findAll), препод — свои + замены.
	 * @return array<string, mixed>
	 */
	public function build( int $userId, bool $allGroups ): array {
		$now     = $this->clock->now( 'mysql' );      // 'Y-m-d H:i:s'
		$today   = substr( $now, 0, 10 );
		$weekEnd = ( new \DateTimeImmutable( $today ) )->modify( '+6 days' )->format( 'Y-m-d' );

		[ $groups, $covering ] = $this->collectGroups( $userId, $allGroups, $today );

		$roomNames = $this->roomNames();

		$todayItems  = array();
		$weekItems   = array();
		$toFill      = array();
		$toReview    = array();
		$groupCards  = array();
		$lessonsTd   = 0;
		$reviewTotal = 0;

		foreach ( $groups as $gid => $g ) {
			$isCovering = isset( $covering[ $gid ] );

			$coveredUntil = $this->coveredUntil( $gid, $today, $userId, $isCovering );
			$matrix       = $this->attendance->matrixForGroup( $gid );
			$activeCount  = $this->records->countActiveByGroup( $gid );

			foreach ( $this->groupLessons->listByGroup( $gid ) as $row ) {
				if ( ! $row->scheduledAt ) {
					continue;
				}
				$date = substr( $row->scheduledAt, 0, 10 );
				$item = $this->lessonItem( $row, $gid, $g, $isCovering, $roomNames );

				if ( $date === $today ) {
					$item['state'] = $this->stateOf( (string) $row->scheduledAt, $row->endsAt, $now );
					$todayItems[]  = $item;
					++$lessonsTd;
				}
				if ( $date >= $today && $date <= $weekEnd ) {
					$weekItems[] = $item;
				}

				// «Заполнить»: прошедшее групповое занятие без единой отметки.
				if ( 'individual' !== $row->kind && $row->scheduledAt < $now && ! isset( $matrix[ $row->id ] ) ) {
					$toFill[] = array(
						'group_lesson_id' => $row->id,
						'group_id'        => $gid,
						'group_name'      => $g->name,
						'date'            => $date,
						'topic'           => $this->topicOf( $row ),
						'missing'         => $activeCount,
					);
				}
			}

			// «Проверить»: очередь сдач группы.
			$queue = count( $this->submissions->listQueueByGroup( $gid ) );
			if ( $queue > 0 ) {
				$toReview[]   = array( 'group_id' => $gid, 'group_name' => $g->name, 'count' => $queue );
				$reviewTotal += $queue;
			}

			$groupCards[] = array(
				'id'            => $gid,
				'name'          => $g->name,
				'subject'       => $g->subject_key,
				'students'      => $activeCount,
				'covered_until' => $coveredUntil,
				'covering_until' => $covering[ $gid ] ?? null,
			);
		}

		usort( $todayItems, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
		usort( $weekItems, static fn( $a, $b ) => strcmp( $a['date'] . $a['start'], $b['date'] . $b['start'] ) );
		usort( $toFill, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		$toFill = array_slice( $toFill, 0, 12 );

		return array(
			'stats'    => array(
				'lessons_today' => $lessonsTd,
				'to_review'     => $reviewTotal,
				'to_fill'       => count( $toFill ),
				'groups'        => count( $groups ),
			),
			'today'    => $todayItems,
			'week'     => $weekItems,
			'worklist' => array(
				'to_fill'   => $toFill,
				'to_review' => $toReview,
			),
			'groups'   => $groupCards,
			'covering' => array_map(
				static fn( $gid ) => array(
					'group_id'   => $gid,
					'group_name' => $groups[ $gid ]->name,
					'valid_to'   => $covering[ $gid ],
				),
				array_keys( $covering )
			),
		);
	}

	/**
	 * Набор групп пользователя: свои (или все для офиса) + группы, которые он
	 * замещает (Эпик 5, T5.6), и карта замещений group_id → valid_to.
	 *
	 * @return array{0: array<int, object>, 1: array<int, string>} [groups, covering]
	 */
	private function collectGroups( int $userId, bool $allGroups, string $today ): array {
		$groups = array();
		foreach ( $allGroups ? $this->groups->findAll() : $this->groups->findByTeacherId( $userId ) as $g ) {
			$groups[ (int) $g->id ] = $g;
		}

		$covering = array();
		foreach ( $this->substitutions->findActiveBySubstitute( $userId, $today ) as $sub ) {
			$covering[ $sub->groupId ] = $sub->validTo;
			if ( ! isset( $groups[ $sub->groupId ] ) ) {
				$g = $this->groups->findById( $sub->groupId );
				if ( $g ) {
					$groups[ $sub->groupId ] = $g;
				}
			}
		}

		return array( $groups, $covering );
	}

	/** @return array<int, string> id кабинета → название */
	private function roomNames(): array {
		$names = array();
		foreach ( $this->rooms->findAll() as $r ) {
			$names[ $r->id ] = $r->name;
		}
		return $names;
	}

	/**
	 * T5.7: своя группа под замену другим — маркер «замена до [дата]».
	 */
	private function coveredUntil( int $gid, string $today, int $userId, bool $isCovering ): ?string {
		if ( $isCovering ) {
			return null;
		}
		$active = $this->substitutions->findActiveForGroup( $gid, $today );
		return ( $active && $active->substituteTeacherId !== $userId ) ? $active->validTo : null;
	}

	/**
	 * Элемент расписания (сегодня/неделя) по строке занятия.
	 *
	 * @param array<int,string> $roomNames
	 * @return array<string, mixed>
	 */
	private function lessonItem( \Inc\DTO\Course\GroupLessonDTO $row, int $gid, object $g, bool $isCovering, array $roomNames ): array {
		return array(
			'group_lesson_id' => $row->id,
			'group_id'        => $gid,
			'group_name'      => $g->name,
			'subject'         => $g->subject_key,
			'topic'           => $this->topicOf( $row ),
			'date'            => substr( (string) $row->scheduledAt, 0, 10 ),
			'start'           => substr( (string) $row->scheduledAt, 11, 5 ),
			'end'             => $row->endsAt ? substr( $row->endsAt, 11, 5 ) : '',
			'kind'            => $row->kind,
			'is_substitute'   => $isCovering,
			'room'            => $this->roomName( $row, $g, $roomNames ),
		);
	}

	/** @param array<int,string> $roomNames */
	private function roomName( \Inc\DTO\Course\GroupLessonDTO $row, object $group, array $roomNames ): string {
		$rid = $row->roomId ?? ( isset( $group->room_id ) && $group->room_id ? (int) $group->room_id : null );
		return $rid ? ( $roomNames[ $rid ] ?? '' ) : '';
	}

	private function topicOf( \Inc\DTO\Course\GroupLessonDTO $row ): string {
		$lesson = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		return $lesson?->topic ?? ( $row->label ?? '' );
	}

	private function stateOf( string $start, ?string $end, string $now ): string {
		if ( $now < $start ) {
			return 'soon';
		}
		if ( $end && $now > $end ) {
			return 'done';
		}
		return 'now';
	}
}
