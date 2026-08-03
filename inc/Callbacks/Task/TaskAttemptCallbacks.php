<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\TaskAttemptReportService;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ProgramAccess;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskAttemptCallbacks
 *
 * AJAX экрана «Активность» кабинета, вкладка «Решения задач»: история попыток
 * учеников по заданиям-шагам занятия.
 *
 * @package Inc\Callbacks\Task
 *
 * Отчёт строится по ЗАНЯТИЮ целиком (а не по одному шагу, как в снятом кокпите):
 * преподавателю нужен разрез «кто как решал это занятие», а список шагов взять
 * больше неоткуда — панель настроек шагов удалена вместе с кокпитом.
 */
class TaskAttemptCallbacks extends BaseController {

	use Authorizer;
	use ProgramAccess;
	use Sanitizer;

	public function __construct(
		private readonly TaskAttemptReportService  $report,
		private readonly GroupAccessGuard          $guard,
		private readonly ProgramCompositionService $program,
	) {
		parent::__construct();
	}

	protected function accessGuard(): GroupAccessGuard {
		return $this->guard;
	}

	protected function programService(): ProgramCompositionService {
		return $this->program;
	}

	/**
	 * Попытки занятия, сгруппированные по шагу и ученику.
	 * POST: group_lesson_id.
	 */
	public function ajaxGetTaskAttempts(): void {
		$this->authorize( Nonce::TaskAttempts, Capability::ManageLmsTeaching );

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$row           = $this->requireProgramRow( $groupLessonId );

		$this->success( $this->report->forLesson( $row->groupId, $groupLessonId ) );
	}
}
