<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\RoomDTO;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamTime;

/**
 * Занятость кабинетов (Эпик 9): свободен ли кабинет в окне и какие кабинеты
 * свободны на время (для пикера индивидуального занятия). Конфликт = пересечение
 * временных окон занятий по эффективному кабинету (см. {@see RoomRepository::isBusy})
 * **или** с сеансом экзамена: кабинет занят на плановое окно `[scheduled_at, planned_end_at]`.
 * Единственная точка «свободен ли кабинет»: занятие не ставится поверх экзамена так же, как экзамен — поверх занятия
 * ({@see \Inc\Services\Exam\ExamRoomService::assertFree()} проверяет обратное направление).
 *
 * @package Inc\Services\Course
 */
class RoomAvailabilityService {

	public function __construct(
		private readonly RoomRepository $rooms,
		private readonly ExamSessionRepository $examSessions,
		private readonly ExamTime $time,
	) {}

	/**
	 * Свободен ли кабинет в окне [$start,$end) — местное время, как у занятий. Сеансы экзаменов хранятся в UTC,
	 * поэтому окно переводится через {@see ExamTime}.
	 *
	 * @param int $excludeGroupLessonId исключить само занятие (напр. при его переносе).
	 * @param int $excludeGroupId       исключить ВСЕ занятия этой группы (T12.5: своя
	 *                                  группа не конфликтует сама с собой — две темы
	 *                                  одной группы в один кабинет/день/время — не конфликт).
	 * @param int $excludeExamSessionId исключить сеанс экзамена (правка самого сеанса не конфликтует с собой).
	 */
	public function isFree( int $roomId, string $start, string $end, int $excludeGroupLessonId = 0, int $excludeGroupId = 0, int $excludeExamSessionId = 0 ): bool {
		return ! $this->rooms->isBusy( $roomId, $start, $end, $excludeGroupLessonId, $excludeGroupId )
			&& ! $this->hasExamConflict( $roomId, $start, $end, $excludeExamSessionId );
	}

	/**
	 * Занят ли кабинет сеансом экзамена в окне [$start,$end) (местное время). Окно строго `[начало, конец)`:
	 * занятие, начинающееся ровно в плановый конец экзамена, разрешено — дополнительной брони после конца нет.
	 */
	public function hasExamConflict( int $roomId, string $start, string $end, int $excludeSessionId = 0 ): bool {
		return $this->examSessions->isRoomBusy( $roomId, $this->time->toUtc( $start ), $this->time->toUtc( $end ), $excludeSessionId );
	}

	/**
	 * Свободные на окно [$start,$end) активные кабинеты, подходящие под предмет.
	 *
	 * @param string $subjectKey фильтр по `allowed_subjects` (пусто = любой).
	 * @return RoomDTO[]
	 */
	public function listFreeRooms( string $start, string $end, string $subjectKey = '', int $excludeGroupLessonId = 0 ): array {
		$free = array();
		foreach ( $this->rooms->findAll( true ) as $room ) {
			if ( '' !== $subjectKey && ! $room->allowsSubject( $subjectKey ) ) {
				continue;
			}
			if ( $this->isFree( $room->id, $start, $end, $excludeGroupLessonId ) ) {
				$free[] = $room;
			}
		}
		return $free;
	}
}
