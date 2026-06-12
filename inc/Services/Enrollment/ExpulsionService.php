<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\AuditAction;
use Inc\Enums\EnrollmentStatus;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\LogEvent;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use RuntimeException;

/**
 * Class ExpulsionService
 *
 * Сервис для отчисления студентов (изменение статуса зачисления на Expelled).
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Отчисление из конкретной записи** — смена статуса указанной записи на Expelled.
 * 2. **Автоматическое отчисление из первой активной записи** — если ID не указан.
 * 3. **Мягкое удаление лиц** — если у студента не осталось активных записей.
 * 4. **Удаление пользователей WP** — если лицо было удалено.
 *
 * ### Архитектурная роль:
 *
 * Делегирует операции с БД репозиториям PersonRepository и StudentRecordRepository.
 * Использует LogEventDispatcherInterface для логирования события отчисления.
 * Использует ClockInterface для получения точного времени отчисления.
 *
 * ### Примечания:
 *
 * - Отчисление выполняется только для активной записи (status = Active).
 * - При отчислении родителя проверяется, остались ли у него другие активные ученики.
 * - Если нет — родитель также мягко удаляется.
 * - Возвращает ID записи отчисления, оставшиеся активные записи и ID студента.
 */
readonly class ExpulsionService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param PersonRepository            $personRepository        Репозиторий лиц
	 * @param StudentRecordRepository     $studentRecordRepository Репозиторий записей студентов
	 * @param LogEventDispatcherInterface $logEvents               Диспетчер событий логирования
	 * @param UserManager                 $userManager             Менеджер пользователей
	 * @param ClockInterface              $clock                   Интерфейс часов
	 */
	public function __construct(
		private PersonRepository            $personRepository,
		private StudentRecordRepository     $studentRecordRepository,
		private LogEventDispatcherInterface $logEvents,
		private UserManager                 $userManager,
		private ClockInterface              $clock,
	) {}

	/**
	 * Отчисляет ученика из конкретной записи зачисления.
	 *
	 * @param int      $studentWpUserId ID пользователя WordPress ученика
	 * @param string   $reason          Причина отчисления
	 * @param int|null $recordId        ID записи student_records; если null — берётся первая активная
	 *
	 * @throws RuntimeException Если студент не найден, запись не найдена или уже не активна
	 *
	 * @return array{
	 *   archive_id: int,
	 *   remaining_active_records: StudentRecordDTO[],
	 *   student_person_id: int,
	 * }
	 */
	public function expel( int $studentWpUserId, string $reason, ?int $recordId = null ): array {
		// Поиск лица студента по ID пользователя WP
		$studentPerson = $this->personRepository->findByWpUserId( $studentWpUserId );
		if ( null === $studentPerson ) {
			throw new RuntimeException( 'Студент не найден в системе.' );
		}

		// Определение записи для отчисления
		if ( $recordId !== null ) {
			// Отчисление из конкретной записи
			$record = $this->studentRecordRepository->find( $recordId );
			if ( null === $record || $record->studentPersonId !== $studentPerson->id ) {
				throw new RuntimeException( 'Запись зачисления не найдена.' );
			}
			if ( $record->status !== EnrollmentStatus::Active ) {
				throw new RuntimeException( 'Запись уже не активна.' );
			}
		} else {
			// Отчисление из первой активной записи
			$records = $this->studentRecordRepository->findActiveByStudent( $studentPerson->id );
			if ( empty( $records ) ) {
				throw new RuntimeException( 'Активная запись ученика не найдена.' );
			}
			$record = $records[0];
		}

		// Поиск родителя (если есть)
		$parentPerson = $record->parentPersonId !== null
			? $this->personRepository->find( $record->parentPersonId )
			: null;

		// Текущее время отчисления
		$now     = $this->clock->now( 'mysql', true );
		$actorId = get_current_user_id() ?: 0;

		// Установка статуса Expelled
		$this->studentRecordRepository->setExpelled( $record->id, $now, $actorId, $reason ?: null );

		// Оставшиеся активные записи после отчисления
		$remainingActive = $this->studentRecordRepository->findActiveByStudent( $studentPerson->id );

		// Если у студента не осталось активных записей — удаляем его
		if ( empty( $remainingActive ) ) {
			$this->personRepository->softDelete( $studentPerson->id );
			$this->userManager->delete( $studentWpUserId );
		}

		// Удаление родителя, если у него не осталось активных учеников
		if ( $parentPerson !== null ) {
			$parentHasActive = ! empty(
			$this->studentRecordRepository->findActiveByParent( $parentPerson->id )
			);
			if ( ! $parentHasActive ) {
				$this->personRepository->softDelete( $parentPerson->id );
				if ( $parentPerson->wpUserId ) {
					$this->userManager->delete( $parentPerson->wpUserId );
				}
			}
		}

		$this->logEvents->dispatch(
			LogEvent::StudentExpelled,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::StudentExpelled, $studentPerson->id, $record->id, $record->groupId ?? null )
		);

		return array(
			'archive_id'               => $record->id,
			'remaining_active_records' => $remainingActive,
			'student_person_id'        => $studentPerson->id,
		);
	}
}