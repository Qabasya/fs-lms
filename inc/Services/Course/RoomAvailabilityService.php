<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\RoomDTO;
use Inc\Repositories\WPDBRepositories\RoomRepository;

/**
 * Занятость кабинетов (Эпик 9): свободен ли кабинет в окне и какие кабинеты
 * свободны на время (для пикера индивидуального занятия). Конфликт = пересечение
 * временных окон занятий по эффективному кабинету (см. {@see RoomRepository::isBusy}).
 *
 * @package Inc\Services\Course
 */
class RoomAvailabilityService {

	public function __construct(
		private readonly RoomRepository $rooms,
	) {}

	/** Свободен ли кабинет в окне [$start,$end) (кроме самого занятия $excludeGroupLessonId). */
	public function isFree( int $roomId, string $start, string $end, int $excludeGroupLessonId = 0 ): bool {
		return ! $this->rooms->isBusy( $roomId, $start, $end, $excludeGroupLessonId );
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
