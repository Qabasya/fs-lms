<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Enrollment\DeletionCallbacks;
use Inc\Enums\Wp\AjaxHook;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeleteParentEvent;
use Inc\Services\Deletion\DeletePeriodEvent;
use Inc\Services\Deletion\DeleteStudentEvent;
use Inc\Services\Deletion\DeleteSubjectEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Deletion\GroupDeletionHandler;
use Inc\Services\Deletion\ParentDeletionHandler;
use Inc\Services\Deletion\ParentOrphanCheckHandler;
use Inc\Services\Deletion\ParentRecordsRemovedFromGroupEvent;
use Inc\Services\Deletion\PeriodDeletionCascadeHandler;
use Inc\Services\Deletion\StudentDeletionHandler;
use Inc\Services\Deletion\StudentOrphanCheckHandler;
use Inc\Services\Deletion\StudentRecordsRemovedFromGroupEvent;
use Inc\Services\Deletion\SubjectDeletionCascadeHandler;

/**
 * Class DeletionController
 *
 * Контроллер для управления удалением сущностей (студенты, родители, группы, периоды, предметы).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для проверки и выполнения удаления.
 * 2. **Настройка event dispatcher'а** — связывание событий удаления с их обработчиками.
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику DeletionCallbacks и DeletionEventDispatcher.
 *
 * ### Используемые события:
 *
 * - DeleteStudentEvent → StudentDeletionHandler (удаление студента)
 * - DeleteParentEvent → ParentDeletionHandler (удаление родителя)
 * - DeleteGroupEvent → GroupDeletionHandler (удаление группы)
 * - DeleteSubjectEvent → SubjectDeletionCascadeHandler (каскадное удаление предмета)
 * - DeletePeriodEvent → PeriodDeletionCascadeHandler (каскадное удаление периода)
 * - StudentRecordsRemovedFromGroupEvent → StudentOrphanCheckHandler (проверка "осиротевших" студентов - без записей в records)
 * - ParentRecordsRemovedFromGroupEvent → ParentOrphanCheckHandler (проверка "осиротевших" родителей - без записей в records)
 */
class DeletionController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param DeletionEventDispatcher          $dispatcher          Диспетчер событий удаления
	 * @param DeletionCallbacks                $callbacks           Коллбеки для AJAX-запросов
	 * @param StudentDeletionHandler           $studentHandler      Обработчик удаления студента
	 * @param ParentDeletionHandler            $parentHandler       Обработчик удаления родителя
	 * @param StudentOrphanCheckHandler        $studentOrphanHandler Проверка студентов без родителей
	 * @param ParentOrphanCheckHandler         $parentOrphanHandler  Проверка родителей без детей
	 * @param GroupDeletionHandler             $groupHandler        Обработчик удаления группы
	 * @param SubjectDeletionCascadeHandler    $subjectHandler      Обработчик каскадного удаления предмета
	 * @param PeriodDeletionCascadeHandler     $periodHandler       Обработчик каскадного удаления периода
	 */
	public function __construct(
		private readonly DeletionEventDispatcher $dispatcher,
		private readonly DeletionCallbacks $callbacks,
		private readonly StudentDeletionHandler $studentHandler,
		private readonly ParentDeletionHandler $parentHandler,
		private readonly StudentOrphanCheckHandler $studentOrphanHandler,
		private readonly ParentOrphanCheckHandler $parentOrphanHandler,
		private readonly GroupDeletionHandler $groupHandler,
		private readonly SubjectDeletionCascadeHandler $subjectHandler,
		private readonly PeriodDeletionCascadeHandler $periodHandler,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// Настройка event dispatcher'а перед регистрацией AJAX-хуков
		$this->wireDispatcher();

		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array
	 */
	protected function ajaxActions(): array {
		return array(
			// Проверка возможности удаления группы (подсчёт студентов)
			array( AjaxHook::CheckGroupDeletion, $this->callbacks ),
			// Удаление группы
			array( AjaxHook::DeleteGroup, $this->callbacks ),
			// Проверка возможности удаления предмета (подсчёт групп и студентов)
			array( AjaxHook::CheckSubjectDeletion, $this->callbacks ),
			// Проверка возможности удаления периода (подсчёт групп и студентов)
			array( AjaxHook::CheckPeriodDeletion, $this->callbacks ),
			// Удаление периода
			array( AjaxHook::DeletePeriod, $this->callbacks ),
			// Полное (hard) удаление студента
			array( AjaxHook::HardDeleteStudent, $this->callbacks ),
		);
	}

	/**
	 * Настраивает связи между событиями и их обработчиками.
	 *
	 * @return void
	 */
	private function wireDispatcher(): void {
		// Событие удаления студента
		$this->dispatcher->listen( DeleteStudentEvent::class, array( $this->studentHandler, 'handle' ) );

		// Событие удаления родителя
		$this->dispatcher->listen( DeleteParentEvent::class, array( $this->parentHandler, 'handle' ) );

		// Событие удаления всех записей студента из группы (для проверки на отсутвствие записей в records)
		$this->dispatcher->listen( StudentRecordsRemovedFromGroupEvent::class, array( $this->studentOrphanHandler, 'handle' ) );

		// Событие удаления всех записей родителя из группы (для проверки на отсутвствие записей в records)
		$this->dispatcher->listen( ParentRecordsRemovedFromGroupEvent::class, array( $this->parentOrphanHandler, 'handle' ) );

		// Событие удаления группы
		$this->dispatcher->listen( DeleteGroupEvent::class, array( $this->groupHandler, 'handle' ) );

		// Событие удаления предмета (каскадное)
		$this->dispatcher->listen( DeleteSubjectEvent::class, array( $this->subjectHandler, 'handle' ) );

		// Событие удаления периода (каскадное)
		$this->dispatcher->listen( DeletePeriodEvent::class, array( $this->periodHandler, 'handle' ) );
	}
}