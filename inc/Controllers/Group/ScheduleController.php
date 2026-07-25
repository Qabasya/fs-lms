<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\ProgramCallbacks;
use Inc\Enums\Wp\AjaxHook;

class ScheduleController extends AjaxController {

	public function __construct(
		private readonly ProgramCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::AssignCourse,            $this->callbacks ),
			array( AjaxHook::AddLessonToProgram,      $this->callbacks ),
			array( AjaxHook::DuplicateProgramLesson,  $this->callbacks ),
			array( AjaxHook::ContinueProgramLesson,   $this->callbacks ),
			array( AjaxHook::RemoveLessonFromProgram, $this->callbacks ),
			array( AjaxHook::ReorderProgram,          $this->callbacks ),
			array( AjaxHook::SaveLessonSchedule,      $this->callbacks ),
			array( AjaxHook::SetLessonExtraWorks,     $this->callbacks ),
			array( AjaxHook::SetLessonVisibility,     $this->callbacks ),
			array( AjaxHook::GetGroupProgram,         $this->callbacks ),
			array( AjaxHook::GetGroupActivity,        $this->callbacks ),
			array( AjaxHook::PublishProgram,          $this->callbacks ),
			array( AjaxHook::UnpublishProgram,        $this->callbacks ),
			array( AjaxHook::GetStepSettings,         $this->callbacks ),
			array( AjaxHook::SaveStepSettings,        $this->callbacks ),
			array( AjaxHook::ReflowSchedule,          $this->callbacks ),
			array( AjaxHook::PinLesson,               $this->callbacks ),
			array( AjaxHook::GetGroupCalendar,        $this->callbacks ),
			array( AjaxHook::GetWorkDeadlines,        $this->callbacks ),
			array( AjaxHook::SaveWorkDeadlines,       $this->callbacks ),
			array( AjaxHook::SetRecordingUrl,         $this->callbacks ),
			array( AjaxHook::CreateIndividualLesson,  $this->callbacks ),
			array( AjaxHook::GetFreeRooms,            $this->callbacks ),
			array( AjaxHook::GetGroupRoster,          $this->callbacks ),
			array( AjaxHook::GetStudentSummary,       $this->callbacks ),
			array( AjaxHook::GetSubjectCourses,       $this->callbacks ),
			array( AjaxHook::GetIndividualSlots,      $this->callbacks ),
			array( AjaxHook::GetLessonCandidates,     $this->callbacks ),
			array( AjaxHook::AssignIndividualLesson,  $this->callbacks ),
			array( AjaxHook::UpdateIndividualLesson,  $this->callbacks ),
		);
	}
}
