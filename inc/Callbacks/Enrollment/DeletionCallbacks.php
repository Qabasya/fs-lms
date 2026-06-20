<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeletePeriodEvent;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class DeletionCallbacks
 *
 * AJAX-обработчики для удаления сущностей (групп, периодов, студентов).
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Проверка возможности удаления** — подсчёт связанных данных перед удалением.
 * 2. **Удаление групп** — удаление группы и открепление студентов.
 * 3. **Удаление периодов** — удаление учебного периода и связанных групп.
 * 4. **Удаление студентов** — полное удаление студента (hard delete) из системы.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику DeletionEventDispatcher (фасад для событий удаления).
 * Использует репозитории GroupsRepository и StudentRecordRepository для подсчёта
 * связанных записей перед подтверждением удаления.
 *
 * ### Примечания:
 *
 * - Удаление групп и периодов предварительно проверяется на наличие студентов.
 * - Hard delete студента полностью удаляет все связанные данные (записи, связи, зачисления).
 */
class DeletionCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), success(), error()
	use Sanitizer;   // Трейт с методами sanitizeInt(), sanitizeKey()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param DeletionEventDispatcher $dispatcher      Диспетчер событий удаления
	 * @param GroupsRepository        $groups          Репозиторий групп
	 * @param StudentRecordRepository $studentRecords  Репозиторий записей студентов
	 */
	public function __construct(
		private readonly DeletionEventDispatcher $dispatcher,
		private readonly GroupsRepository $groups,
		private readonly StudentRecordRepository $studentRecords,
	) {
		parent::__construct();
	}

	/**
	 * Проверяет возможность удаления группы (подсчёт студентов).
	 *
	 * @return void
	 */
	public function ajaxCheckGroupDeletion(): void {
		$this->authorize( Nonce::DeleteGroup, Capability::Admin );

		$groupId = $this->sanitizeInt( 'group_id' );

		$this->success( array(
			// countUniqueStudentsByGroup() — подсчёт уникальных студентов в группе
			'student_count' => $this->studentRecords->countUniqueStudentsByGroup( $groupId ),
		) );
	}

	/**
	 * Удаляет группу (открепляет студентов, удаляет группу).
	 *
	 * @return void
	 */
	public function ajaxDeleteGroup(): void {
		$this->authorize( Nonce::DeleteGroup, Capability::Admin );

		$groupId = $this->sanitizeInt( 'group_id' );

		// dispatch() — запуск обработки события удаления
		$this->dispatcher->dispatch( new DeleteGroupEvent( $groupId, get_current_user_id() ) );

		$this->success( array( 'message' => 'Группа удалена' ) );
	}

	/**
	 * Проверяет возможность удаления предмета (подсчёт групп и студентов).
	 *
	 * @return void
	 */
	public function ajaxCheckSubjectDeletion(): void {
		$this->authorize( Nonce::Subject, Capability::Admin );

		$subjectKey = $this->sanitizeKey( 'subject_key' );

		// findBySubjectKey() — поиск групп по ключу предмета
		$dbGroups     = $this->groups->findBySubjectKey( $subjectKey );
		$groupCount   = count( $dbGroups );
		$studentCount = 0;

		foreach ( $dbGroups as $group ) {
			$studentCount += $this->studentRecords->countUniqueStudentsByGroup( (int) $group->id );
		}

		$this->success( array(
			'student_count' => $studentCount,
			'group_count'   => $groupCount,
		) );
	}

	/**
	 * Проверяет возможность удаления периода (подсчёт групп и студентов).
	 *
	 * @return void
	 */
	public function ajaxCheckPeriodDeletion(): void {
		$this->authorize( Nonce::DeletePeriod, Capability::Admin );

		$periodId = $this->sanitizeKey( 'period_id' );

		// findByPeriodId() — поиск групп по ID периода
		$dbGroups     = $this->groups->findByPeriodId( $periodId );
		$groupCount   = count( $dbGroups );
		$studentCount = 0;

		foreach ( $dbGroups as $group ) {
			$studentCount += $this->studentRecords->countUniqueStudentsByGroup( (int) $group->id );
		}

		$this->success( array(
			'student_count' => $studentCount,
			'group_count'   => $groupCount,
		) );
	}

	/**
	 * Удаляет учебный период и связанные группы.
	 *
	 * @return void
	 */
	public function ajaxDeletePeriod(): void {
		$this->authorize( Nonce::DeletePeriod, Capability::Admin );

		$periodId = $this->sanitizeKey( 'period_id' );

		$this->dispatcher->dispatch( new DeletePeriodEvent( $periodId, get_current_user_id() ) );

		$this->success( array( 'message' => 'Период удалён' ) );
	}

	/**
	 * Полное (hard) удаление студента из системы.
	 * Удаляются все связанные данные: записи, связи, зачисления.
	 *
	 * @return void
	 */
	public function ajaxHardDeleteStudent(): void {
		$this->authorize( Nonce::HardDeleteStudent, Capability::Admin );

		$studentPersonId = $this->sanitizeInt( 'person_id' );

		$this->dispatcher->dispatch( new DeleteStudentEvent( $studentPersonId, get_current_user_id() ) );

		$this->success( array( 'message' => 'Ученик удалён' ) );
	}
}