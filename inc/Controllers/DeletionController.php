<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\DeletionCallbacks;
use Inc\Enums\AjaxHook;
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

class DeletionController extends AjaxController {

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

	public function register(): void {
		$this->wireDispatcher();
		parent::register();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::CheckGroupDeletion,   $this->callbacks ),
			array( AjaxHook::DeleteGroup,          $this->callbacks ),
			array( AjaxHook::CheckSubjectDeletion, $this->callbacks ),
			array( AjaxHook::CheckPeriodDeletion,  $this->callbacks ),
			array( AjaxHook::DeletePeriod,         $this->callbacks ),
			array( AjaxHook::HardDeleteStudent,    $this->callbacks ),
		);
	}

	private function wireDispatcher(): void {
		$this->dispatcher->listen( DeleteStudentEvent::class,                    array( $this->studentHandler,       'handle' ) );
		$this->dispatcher->listen( DeleteParentEvent::class,                     array( $this->parentHandler,        'handle' ) );
		$this->dispatcher->listen( StudentRecordsRemovedFromGroupEvent::class,   array( $this->studentOrphanHandler, 'handle' ) );
		$this->dispatcher->listen( ParentRecordsRemovedFromGroupEvent::class,    array( $this->parentOrphanHandler,  'handle' ) );
		$this->dispatcher->listen( DeleteGroupEvent::class,                      array( $this->groupHandler,         'handle' ) );
		$this->dispatcher->listen( DeleteSubjectEvent::class,                    array( $this->subjectHandler,       'handle' ) );
		$this->dispatcher->listen( DeletePeriodEvent::class,                     array( $this->periodHandler,        'handle' ) );
	}
}
