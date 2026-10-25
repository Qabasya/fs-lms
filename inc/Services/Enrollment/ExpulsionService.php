<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
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
 * Генерирует generic-хук `fs_lms_student_expelled` (AdSync и др. модули).
 *
 * ### Примечания:
 *
 * - Отчисление выполняется только для активной записи (status = Active).
 * - При отчислении родителя проверяется, остались ли у него другие активные ученики.
 * - Если нет — родитель также мягко удаляется.
 * - Возвращает ID записи отчисления, оставшиеся активные записи и ID студента.
 *
 * ### Общие (дополнительные) группы
 *
 * Запись общей группы (`groups.is_shared`) — не зачисление, а доступ к курсу поверх основной группы:
 * - «Оставшимися» записями и первой записью для отчисления считаются только основные;
 * - выход из общей группы (запись выбрана явно) закрывает только её — статус Finished, без архива
 *   отчисленных, мягкого удаления лиц и хука `fs_lms_student_expelled`;
 * - отчисление из последней основной записи закрывает и все общие записи ученика.
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
	 * @param GroupsRepository            $groupsRepository        Репозиторий групп (признак общей группы записи)
	 */
	public function __construct(
		private PersonRepository            $personRepository,
		private StudentRecordRepository     $studentRecordRepository,
		private LogEventDispatcherInterface $logEvents,
		private UserManager                 $userManager,
		private ClockInterface              $clock,
		private GroupsRepository            $groupsRepository,
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
			// Отчисление из первой активной записи — основной, а не общей группы
			$records = array_values( array_filter(
				$this->studentRecordRepository->findActiveByStudent( $studentPerson->id, false ),
				static fn( StudentRecordDTO $r ): bool => ! $r->isTrial
			) );
			if ( empty( $records ) ) {
				throw new RuntimeException( 'Активная запись ученика не найдена.' );
			}
			$record = $records[0];
		}

		// Временный доступ — не обучение: в архив отчисленных ему не место, снимается в заявке.
		if ( $record->isTrial ) {
			throw new RuntimeException( 'У ученика временный доступ до зачисления — снимите его в таблице заявок.' );
		}

		// Общая группа — не зачисление: ученик просто выходит из неё (аккаунт, родитель и архив отчисленных не затрагиваются).
		if ( $this->isSharedGroup( $record ) ) {
			return $this->leaveSharedGroup( $studentPerson->id, $record );
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

		// Оставшиеся основные записи после отчисления: общие группы ученика не удерживают.
		if ( empty( $this->studentRecordRepository->findActiveByStudent( $studentPerson->id, false ) ) ) {
			// Основного зачисления не осталось — доступ к общим группам заканчивается вместе с ним.
			$this->finishSharedRecords( $studentPerson->id );

			// Если у студента не осталось активных записей — soft delete (WP-аккаунт сохраняется)
			$this->personRepository->softDelete( $studentPerson->id );
		}

		// Для ответа: всё, что ещё действует (в том числе общие группы при остающемся основном зачислении).
		$remainingActive = $this->studentRecordRepository->findActiveByStudent( $studentPerson->id );

		// Soft delete родителя, если у него не осталось активных учеников
		if ( $parentPerson !== null ) {
			$parentHasActive = ! empty(
			$this->studentRecordRepository->findActiveByParent( $parentPerson->id )
			);
			if ( ! $parentHasActive ) {
				$this->personRepository->softDelete( $parentPerson->id );
			}
		}

		$this->logEvents->dispatch(
			LogEvent::StudentExpelled,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::StudentExpelled, $studentPerson->id, $record->id, $record->groupId ?? null )
		);

		// Generic-хук отчисления (AdSync и др. модули), симметричен fs_lms_student_enrolled.
		do_action( 'fs_lms_student_expelled', $record->id, $studentPerson->id );

		return array(
			'archive_id'               => $record->id,
			'remaining_active_records' => $remainingActive,
			'student_person_id'        => $studentPerson->id,
		);
	}

	/** Запись состоит в общей (дополнительной) группе. */
	private function isSharedGroup( StudentRecordDTO $record ): bool {
		$group = $record->groupId > 0 ? $this->groupsRepository->findById( $record->groupId ) : null;

		return null !== $group && 1 === (int) ( $group->is_shared ?? 0 );
	}

	/**
	 * Выход из общей группы: запись закрывается (Finished), остальное зачисление ученика не затрагивается.
	 *
	 * @return array{archive_id: int, remaining_active_records: StudentRecordDTO[], student_person_id: int}
	 */
	private function leaveSharedGroup( int $studentPersonId, StudentRecordDTO $record ): array {
		$this->closeSharedRecord( $studentPersonId, $record );

		return array(
			'archive_id'               => $record->id,
			'remaining_active_records' => $this->studentRecordRepository->findActiveByStudent( $studentPersonId ),
			'student_person_id'        => $studentPersonId,
		);
	}

	/** Закрывает все активные записи ученика в общих группах (основного зачисления не осталось). */
	private function finishSharedRecords( int $studentPersonId ): void {
		foreach ( $this->studentRecordRepository->findActiveByStudent( $studentPersonId ) as $record ) {
			if ( $this->isSharedGroup( $record ) ) {
				$this->closeSharedRecord( $studentPersonId, $record );
			}
		}
	}

	/** Закрытие записи общей группы с записью в журнал аудита; хук `fs_lms_student_expelled` не вызывается (аккаунт не деактивируется). */
	private function closeSharedRecord( int $studentPersonId, StudentRecordDTO $record ): void {
		$this->studentRecordRepository->setFinished( $record->id );

		$this->logEvents->dispatch(
			LogEvent::StudentExpelled,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::StudentExpelled, $studentPersonId, $record->id, $record->groupId ?? null )
		);
	}
}