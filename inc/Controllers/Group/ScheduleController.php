<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\GroupRosterCallbacks;
use Inc\Callbacks\Course\IndividualLessonCallbacks;
use Inc\Callbacks\Course\LessonDeliveryCallbacks;
use Inc\Callbacks\Course\LessonScheduleCallbacks;
use Inc\Callbacks\Course\ProgramCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрация AJAX КТП: состав программы, даты/раскладка, индивидуальные
 * занятия, ростер и «доставка» занятия — каждый блок в своём Callbacks-классе.
 */
class ScheduleController extends AjaxController {

	public function __construct(
		private readonly ProgramCallbacks          $program,
		private readonly LessonScheduleCallbacks   $schedule,
		private readonly IndividualLessonCallbacks $individual,
		private readonly GroupRosterCallbacks      $roster,
		private readonly LessonDeliveryCallbacks   $delivery,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			// Состав программы и её публикация
			array( AjaxHook::AssignCourse,            $this->program ),
			array( AjaxHook::GetSubjectCourses,       $this->program ),
			array( AjaxHook::AddLessonToProgram,      $this->program ),
			array( AjaxHook::DuplicateProgramLesson,  $this->program ),
			array( AjaxHook::ContinueProgramLesson,   $this->program ),
			array( AjaxHook::RemoveLessonFromProgram, $this->program ),
			array( AjaxHook::ReorderProgram,          $this->program ),
			array( AjaxHook::SetLessonVisibility,     $this->program ),
			array( AjaxHook::GetGroupProgram,         $this->program ),
			array( AjaxHook::GetGroupActivity,        $this->program ),
			array( AjaxHook::PublishProgram,          $this->program ),
			array( AjaxHook::UnpublishProgram,        $this->program ),
			array( AjaxHook::GetStepSettings,         $this->program ),
			array( AjaxHook::SaveStepSettings,        $this->program ),

			// Даты и раскладка
			array( AjaxHook::SaveLessonSchedule,      $this->schedule ),
			array( AjaxHook::ReflowSchedule,          $this->schedule ),
			array( AjaxHook::PinLesson,               $this->schedule ),
			array( AjaxHook::GetGroupCalendar,        $this->schedule ),
			array( AjaxHook::GetFreeRooms,            $this->schedule ),

			// Индивидуальные занятия
			array( AjaxHook::CreateIndividualLesson,  $this->individual ),
			array( AjaxHook::GetIndividualSlots,      $this->individual ),
			array( AjaxHook::GetLessonCandidates,     $this->individual ),
			array( AjaxHook::AssignIndividualLesson,  $this->individual ),
			array( AjaxHook::UpdateIndividualLesson,  $this->individual ),

			// Ростер группы
			array( AjaxHook::GetGroupRoster,          $this->roster ),
			array( AjaxHook::GetStudentSummary,       $this->roster ),

			// Доставка занятия: работы, дедлайны, запись
			array( AjaxHook::SetLessonExtraWorks,     $this->delivery ),
			array( AjaxHook::GetWorkDeadlines,        $this->delivery ),
			array( AjaxHook::SaveWorkDeadlines,       $this->delivery ),
			array( AjaxHook::SetRecordingUrl,         $this->delivery ),
		);
	}
}
