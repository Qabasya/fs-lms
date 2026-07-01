<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\GradebookService;

/**
 * Read-модель профиля учащегося (Эпик 7). Собирает по одному `student_person_id`
 * (ученик — свой, родитель — ребёнка): группы, расписание/дедлайны, дневник
 * (сырые баллы, D4) и посещаемость (бинарно + %). Только чтение.
 *
 * @package Inc\Services\Profile
 */
class LearnerService {

	public function __construct(
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
		private readonly GradebookService        $gradebook,
		private readonly AttendanceRepository    $attendance,
		private readonly ClockInterface          $clock,
	) {}

	/** @return array<string, mixed> */
	public function build( int $personId ): array {
		$now = $this->clock->now( 'mysql' );

		// Группы ученика.
		$groups   = array();
		$groupIds = array();
		foreach ( $this->records->findActiveByStudent( $personId ) as $rec ) {
			if ( isset( $groups[ $rec->groupId ] ) ) {
				continue;
			}
			$g = $this->groups->findById( $rec->groupId );
			if ( $g ) {
				$groups[ $rec->groupId ] = array( 'id' => (int) $g->id, 'name' => $g->name, 'subject' => $g->subject_key );
				$groupIds[]              = (int) $g->id;
			}
		}

		// Карта занятий групп ученика (+ его индивидуальные).
		$lessonMap = array();
		$allLessons = array();
		foreach ( $groupIds as $gid ) {
			$gname = $groups[ $gid ]['name'];
			foreach ( $this->groupLessons->listByGroup( $gid ) as $row ) {
				if ( 'individual' === $row->kind && $row->studentPersonId !== $personId ) {
					continue;
				}
				$topic = $this->topicOf( $row );
				$item  = array(
					'group_lesson_id' => $row->id,
					'group_id'        => $gid,
					'group_name'      => $gname,
					'topic'           => $topic,
					'date'            => $row->scheduledAt ? substr( $row->scheduledAt, 0, 10 ) : '',
					'start'           => $row->scheduledAt ? substr( $row->scheduledAt, 11, 5 ) : '',
					'scheduled_at'    => $row->scheduledAt,
					'homework_due_at' => $row->homeworkDueAt,
					'visibility'      => $row->visibility,
					'kind'            => $row->kind,
				);
				$lessonMap[ $row->id ] = $item;
				$allLessons[]          = $item;
			}
		}

		// Расписание (ближайшие) и дедлайны.
		$upcoming  = array();
		$deadlines = array();
		foreach ( $allLessons as $l ) {
			if ( $l['scheduled_at'] && $l['scheduled_at'] >= $now ) {
				$upcoming[] = $l;
			}
			if ( $l['homework_due_at'] && $l['homework_due_at'] >= $now ) {
				$deadlines[] = array(
					'due_at'     => $l['homework_due_at'],
					'topic'      => $l['topic'],
					'group_name' => $l['group_name'],
				);
			}
		}
		usort( $upcoming, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );
		usort( $deadlines, static fn( $a, $b ) => strcmp( $a['due_at'], $b['due_at'] ) );
		usort( $allLessons, static fn( $a, $b ) => strcmp( (string) $b['scheduled_at'], (string) $a['scheduled_at'] ) );

		// Дневник (сырые баллы).
		$grades = array();
		foreach ( $this->gradebook->forStudent( $personId ) as $e ) {
			$grades[] = array(
				'title'      => $e->title,
				'category'   => $e->category,
				'value'      => $e->displayValue(),
				'display'    => $e->displayType,
				'graded_at'  => $e->gradedAt,
				'group_name' => $groups[ $e->groupId ]['name'] ?? '',
			);
		}
		$recent = array_values( array_filter( $grades, static fn( $g ) => ! empty( $g['graded_at'] ) ) );
		usort( $recent, static fn( $a, $b ) => strcmp( (string) $b['graded_at'], (string) $a['graded_at'] ) );

		// Посещаемость (бинарно + %).
		$rows    = array();
		$present = 0;
		$total   = 0;
		foreach ( $this->attendance->listByStudent( $personId ) as $a ) {
			$l = $lessonMap[ $a->groupLessonId ] ?? null;
			$rows[] = array(
				'date'    => $l ? $l['date'] : substr( $a->markedAt, 0, 10 ),
				'topic'   => $l ? $l['topic'] : '—',
				'present' => $a->isPresent,
			);
			++$total;
			if ( $a->isPresent ) {
				++$present;
			}
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) $b['date'], (string) $a['date'] ) );

		return array(
			'groups'    => array_values( $groups ),
			'upcoming'  => array_slice( $upcoming, 0, 6 ),
			'deadlines' => array_slice( $deadlines, 0, 6 ),
			'recent'    => array_slice( $recent, 0, 5 ),
			'lessons'   => $allLessons,
			'grades'    => $grades,
			'attendance' => array(
				'rows'    => $rows,
				'present' => $present,
				'total'   => $total,
				'percent' => $total > 0 ? (int) round( $present / $total * 100 ) : null,
			),
		);
	}

	private function topicOf( \Inc\DTO\Course\GroupLessonDTO $row ): string {
		$lesson = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		return $lesson?->topic ?? ( $row->label ?? '' );
	}
}
