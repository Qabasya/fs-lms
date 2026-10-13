<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Course\LessonStatus;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class LiveLessonService
 *
 * «Занятие идёт» для ученика: единый ответ на вопрос «началось ли занятие и куда
 * подключаться». Окно занятия — то же, что у плеера и расписания: от начала по
 * расписанию до его окончания (`ends_at`, без него — час,
 * {@see GroupLessonDTO::isOver()}). Ссылка на трансляцию — одна на группу
 * (`groups.broadcast_url`).
 *
 * Используется баннером «Занятие уже идёт» в шапке кабинета, блоком расписания на
 * главной и уведомлением «Урок начался»: все три открывают урок и, если ссылка задана,
 * трансляцию в новой вкладке.
 *
 * @package Inc\Services\Course
 */
class LiveLessonService {

	/** Как далеко вперёд кабинет заранее знает о занятиях (баннер появляется сам, без перезагрузки), сек. */
	private const LOOKAHEAD = DAY_IN_SECONDS;

	public function __construct(
		private readonly GroupLessonRepository   $groupLessons,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		private readonly LessonManager           $lessons,
		private readonly ClockInterface          $clock,
	) {}

	/**
	 * Занятие идёт прямо сейчас: началось по расписанию и ещё не закончилось.
	 * Отменённые и перенесённые не считаются.
	 */
	public function isLive( GroupLessonDTO $row ): bool {
		if ( null === $row->scheduledAt || '' === $row->scheduledAt ) {
			return false;
		}

		$status = LessonStatus::fromValueOrDefault( $row->status );
		if ( in_array( $status, array( LessonStatus::Cancelled, LessonStatus::Moved ), true ) ) {
			return false;
		}

		$now = $this->clock->now();

		return $row->scheduledAt <= $now && ! $row->isOver( $now );
	}

	/**
	 * Ссылка на трансляцию группы; пусто — не задана.
	 */
	public function streamUrl( int $groupId ): string {
		$group = $groupId > 0 ? $this->groups->findById( $groupId ) : null;

		return (string) ( $group->broadcast_url ?? '' );
	}

	/**
	 * Адрес трансляции занятия, если оно идёт прямо сейчас; иначе пусто.
	 */
	public function liveStreamUrl( GroupLessonDTO $row ): string {
		return $this->isLive( $row ) ? $this->streamUrl( $row->groupId ) : '';
	}

	/**
	 * То же по ID занятия (для уведомления «Урок начался»): пусто, если занятия нет или оно не идёт.
	 */
	public function liveStreamUrlById( int $groupLessonId ): string {
		$row = $this->groupLessons->find( $groupLessonId );

		return null !== $row ? $this->liveStreamUrl( $row ) : '';
	}

	/**
	 * Окна занятий ученика для баннера в шапке: идущее сейчас и те, что начнутся в
	 * ближайшие сутки. Клиент сам решает по времени, когда показать баннер.
	 *
	 * @return array{now:int, lessons:array<int, array{id:int, topic:string, group_name:string, start:int, end:int, player_url:string, stream_url:string}>}
	 */
	public function windowsForStudent( int $studentPersonId ): array {
		$now      = $this->clock->now();
		$nowTs    = $this->timestamp( $now );
		$lessons  = array();
		$seen     = array();

		foreach ( $this->records->findActiveByStudent( $studentPersonId ) as $record ) {
			$groupId = (int) $record->groupId;
			if ( $groupId <= 0 || isset( $seen[ $groupId ] ) ) {
				continue;
			}
			$seen[ $groupId ] = true;

			$group     = $this->groups->findById( $groupId );
			$streamUrl = (string) ( $group->broadcast_url ?? '' );
			$groupName = (string) ( $group->name ?? '' );

			foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
				if ( null === $row->scheduledAt || '' === $row->scheduledAt ) {
					continue;
				}
				// Индивидуальное занятие группы — только у своего ученика.
				if ( LessonKind::Individual === $row->kind && (int) $row->studentPersonId !== $studentPersonId ) {
					continue;
				}

				$start = $this->timestamp( $row->scheduledAt );
				if ( $start > $nowTs + self::LOOKAHEAD ) {
					continue;
				}

				$status = LessonStatus::fromValueOrDefault( $row->status );
				if ( in_array( $status, array( LessonStatus::Cancelled, LessonStatus::Moved ), true ) || $row->isOver( $now ) ) {
					continue;
				}

				$hasLesson = null !== $row->lessonId && 0 !== $row->lessonId;
				if ( ! $hasLesson && '' === $streamUrl ) {
					continue; // открывать нечего
				}

				// Конец окна — как в GroupLessonDTO::isOver(): ends_at, без него час от начала.
				$end = null !== $row->endsAt ? $this->timestamp( $row->endsAt ) : $start + HOUR_IN_SECONDS;

				$lessons[] = array(
					'id'         => $row->id,
					'topic'      => $this->topicOf( $row ),
					'group_name' => $groupName,
					'start'      => $start,
					'end'        => $end,
					'player_url' => $hasLesson ? PageRoutes::LessonPlayer->lessonUrl( $groupId, $row->id ) : '',
					'stream_url' => $streamUrl,
				);
			}
		}

		usort( $lessons, static fn( array $a, array $b ): int => $a['start'] <=> $b['start'] );

		return array(
			'now'     => $nowTs,
			'lessons' => $lessons,
		);
	}

	private function topicOf( GroupLessonDTO $row ): string {
		$lesson = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;

		return $lesson?->topic ?? ( $row->label ?? '' );
	}

	/** Время сайта 'Y-m-d H:i:s' → unix-время (с учётом часового пояса сайта). */
	private function timestamp( string $local ): int {
		try {
			return ( new \DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception ) {
			return 0;
		}
	}
}
