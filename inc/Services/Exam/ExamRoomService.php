<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Course\RoomDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Shared\CodedException;

/**
 * Кабинет под сеанс экзамена: годится ли он предмету, свободен ли в плановое окно, не помешает ли поздний старт.
 *
 * Кабинет занят ровно `[scheduled_at, planned_end_at]`: дополнительной брони после конца нет (SPEC §3, критерий 9).
 * Время сеансов — UTC, занятий — местное; перевод — только через {@see ExamTime}.
 *
 * Проверки не атомарны сами по себе: «свободен ли» и запись сеанса выполняет вызывающий сервис в одной транзакции, первым
 * оператором которой стоит {@see lock()} — два одновременных назначения одного кабинета идут по очереди.
 */
class ExamRoomService {

	public function __construct(
		private readonly RoomRepository $rooms,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamTime $time,
		private readonly RoomAvailabilityService $availability,
	) {}

	/**
	 * Кабинет существует, активен, допускает предмет и имеет вместимость.
	 *
	 * @throws CodedException `ExamRoom` — с текстом причины.
	 */
	public function assertUsable( int $roomId, string $subjectKey ): RoomDTO {
		$room = $this->rooms->find( $roomId );

		if ( null === $room ) {
			throw new CodedException( ErrorCode::ExamRoom, 'Кабинет не найден.' );
		}
		if ( ! $room->isActive ) {
			throw new CodedException( ErrorCode::ExamRoom, 'Кабинет отключён.' );
		}
		if ( ! $room->allowsSubject( $subjectKey ) ) {
			throw new CodedException( ErrorCode::ExamRoom, 'Кабинет не предназначен для этого предмета.' );
		}
		if ( ! $room->hasCapacity() ) {
			throw new CodedException( ErrorCode::ExamRoom, 'Укажите вместимость кабинета в „Настройки → Кабинеты“.' );
		}

		return $room;
	}

	/**
	 * Кабинет свободен в окне: нет пересечения ни с другим сеансом экзамена, ни с занятием. Решает единая точка
	 * {@see RoomAvailabilityService::isFree()} — та же, что у назначения кабинета занятиям.
	 *
	 * @throws CodedException `ExamConflict`.
	 */
	public function assertFree( int $roomId, string $startUtc, string $endUtc, int $excludeSessionId = 0 ): void {
		$free = $this->availability->isFree(
			$roomId,
			$this->time->toLocal( $startUtc ),
			$this->time->toLocal( $endUtc ),
			0,
			0,
			$excludeSessionId
		);

		if ( ! $free ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Кабинет занят в это время.' );
		}
	}

	/** Блокирует кабинет до конца транзакции: первый оператор транзакции любого назначения кабинета. */
	public function lock( int $roomId ): void {
		$this->rooms->lockForUpdate( $roomId );
	}

	/**
	 * Что в кабинете мешает позднему старту: занятия и сеансы в окне `( planned_end_at, latestDeadline ]`.
	 * Ничего не блокирует и не отменяет — только список для предупреждения преподавателю.
	 *
	 * @param string $latestDeadlineUtc Самый поздний дедлайн попытки сеанса (UTC).
	 *
	 * @return list<array{kind: 'lesson'|'exam', title: string, start: string}> `start` — местное время; по возрастанию начала.
	 */
	public function lateStartConflicts( ExamSessionDTO $session, string $latestDeadlineUtc ): array {
		if ( $latestDeadlineUtc <= $session->plannedEndAt ) {
			return array();
		}

		$conflicts = array();

		$lessons = $this->rooms->listLessonsInWindow(
			$session->roomId,
			$this->time->toLocal( $session->plannedEndAt ),
			$this->time->toLocal( $latestDeadlineUtc )
		);
		foreach ( $lessons as $lesson ) {
			$conflicts[] = array( 'kind' => 'lesson', 'title' => $lesson['title'], 'start' => $lesson['start'] );
		}

		$exams = $this->sessions->listInWindowByRoom( $session->roomId, $session->plannedEndAt, $latestDeadlineUtc, $session->id );
		foreach ( $exams as $exam ) {
			$conflicts[] = array( 'kind' => 'exam', 'title' => $exam['title'], 'start' => $this->time->toLocal( $exam['start'] ) );
		}

		usort( $conflicts, static fn ( array $a, array $b ): int => strcmp( $a['start'], $b['start'] ) );

		return $conflicts;
	}
}
