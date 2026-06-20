<?php

namespace Inc\Enums;

/**
 * Enum AjaxHook
 *
 * AJAX-хуки плагина с автоматической генерацией имён.
 *
 * Каждый кейс хранит имя метода в PascalCase (например, 'StoreSubject').
 * Преобразование в WordPress-формат (wp_ajax_store_subject) и snake_case для JS
 * происходит автоматически через методы action(), jsAction() и callbackMethod().
 *
 * @package Inc\Enums
 */
enum AjaxHook: string {
	// ==================== SubjectController (Предметы) ====================
	case StoreSubject  = 'store_subject';
	case UpdateSubject = 'update_subject';
	case DeleteSubject = 'delete_subject';
	case ExportSubject = 'export_subject';
	case ImportSubject = 'import_subject';

	// ==================== SubjectController (Таксономии) ====================
	case StoreTaxonomy  = 'store_taxonomy';
	case UpdateTaxonomy = 'update_taxonomy';
	case DeleteTaxonomy = 'delete_taxonomy';

	// ==================== BoilerplateController (Полноценный CRUD редактор) ====================
	case SaveBoilerplate   = 'save_boilerplate';
	case DeleteBoilerplate = 'delete_boilerplate';

	// ==================== TaskCreationController (Создание задач в модалке) ====================
	case GetTaskTypes        = 'get_task_types';
	case GetTaskBoilerplates = 'get_task_boilerplates';
	case CreateTask          = 'create_task';

	// ==================== SubjectController (Прочие хуки) ====================
	case GetPostsTable     = 'get_posts_table';
	case GetTasksByNumber  = 'get_tasks_by_number';
	case GetRecentTasks    = 'get_recent_tasks';
	case GetRecentArticles = 'get_recent_articles';

	// ==================== TemplateManager (Быстрые настройки и структура) ====================
	case GetTemplateStructure   = 'get_template_structure';
	case SaveTaskBoilerplate    = 'save_task_boilerplate';
	case GetTaskBoilerplate     = 'get_boilerplate';
	case UpdateTermTemplate     = 'update_term_template';
	case SaveTemplateAssignment = 'save_template_assignment';

	// ==================== AcademicPeriod (Учебные периоды) ====================
	case SaveAcademicPeriod   = 'save_academic_period';
	case DeleteAcademicPeriod = 'delete_academic_period';

	// ==================== Группы ====================
	case SaveStudentGroup        = 'save_student_group';
	case UpdateStudentGroup      = 'update_student_group';
	case DeleteStudentGroup      = 'delete_student_group';
	case GetGroupStudentsDetail  = 'get_group_students_detail';

	// ==================== Каскадное удаление ====================
	case CheckGroupDeletion   = 'check_group_deletion';
	case DeleteGroup          = 'delete_group';
	case CheckSubjectDeletion = 'check_subject_deletion';
	case CheckPeriodDeletion  = 'check_period_deletion';
	case DeletePeriod         = 'delete_period';
	case HardDeleteStudent    = 'hard_delete_student';

	// ==================== Импорт данных ====================
	case ImportStudentsCsv = 'import_students_csv';

	// ==================== Настройки: конфигурация ====================
	case SaveConfig              = 'save_config';
	case GenerateKey             = 'generate_key';
	case SaveApplicationSettings = 'save_application_settings'; // настройки заявок (привязка к направлению)

	// ==================== Экспорт данных ====================
	case ExportGroups   = 'export_groups';
	case ExportStudents = 'export_students';
	case ExportParents  = 'export_parents';
	case ExportArchive  = 'export_archive';

	// ==================== Журналы ====================
	case ExportEntityAuditLog  = 'export_entity_audit_log';
	case ExportEnrollmentLog   = 'export_enrollment_log';
	case ExportAuditLog        = 'export_audit_log';
	case ExportPiiLog          = 'export_pii_log';
	case ExportExportLog       = 'export_export_log';
	case ExportDataChangeLog   = 'export_data_change_log';
	case ExportConsentChangeLog = 'export_consent_change_log';
	case ExportEmailLog        = 'export_email_log';
	case ExportAuthLog         = 'export_auth_log';

	// ==================== Настройки: шаблоны писем ====================
	case SaveEmailTemplate   = 'save_email_template';
	case ResetEmailTemplate  = 'reset_email_template';

	// ==================== Настройки: согласия ====================
	case LookupConsentByHash     = 'lookup_consent_by_hash';
	case AddConsentDefinition    = 'add_consent_definition';
	case DeleteConsentDefinition = 'delete_consent_definition';

	// ==================== Отчисление ====================
	case ExpelStudent         = 'expel_student';
	case ExportExpelledRecord = 'export_expelled_record';

	// ==================== Система зачисления ====================
	case CreateApplication           = 'create_application';
	case SubmitParentData            = 'submit_parent_data';
	case EnrollStudent               = 'enroll_student';
	case RevealPiiField              = 'reveal_pii_field';
	case RevealAllPersonPii          = 'reveal_all_person_pii';
	case AddRepresentative           = 'add_representative';
	case ReplaceRepresentative       = 'replace_representative';
	case UpdatePerson                = 'update_person';
	case WithdrawConsent             = 'withdraw_consent';
	case RequestPiiDeletion          = 'request_pii_deletion';
	case ExportPii                   = 'export_pii';
	case SendOtpCode                 = 'send_otp_code';
	case MoveApplicationToTrash      = 'move_application_to_trash';
	case RestoreApplicationFromTrash = 'restore_application_from_trash';
	case EmptyApplicationsTrash      = 'empty_applications_trash';
	case DeleteApplication           = 'delete_application';
	case UpdateApplicationData       = 'update_application_data';
	case UpdateReviewData            = 'update_review_data';
	case StartEnrollment             = 'start_enrollment';
	case CancelEnrollment            = 'cancel_enrollment';
	case GetApplicationData          = 'get_application_data';
	case GetStudentGroups            = 'get_student_groups';
	case RevealUserCredentials       = 'reveal_user_credentials';
	case RegenerateUserPassword      = 'regenerate_user_password';
	case GetStudentsByGroup          = 'get_students_by_group';
	case GetPersonData               = 'get_person_data';
	case SelectExistingParent        = 'select_existing_parent';
	case RemoveParentAssignment      = 'remove_parent_assignment';
	case RestoreFromArchive          = 'restore_from_archive';
	case SearchParents               = 'search_parents';
	case CheckUsernameAvailable      = 'check_username_available';
	case CheckEmailAvailable         = 'check_email_available';
	case ValidateDirectionCode       = 'validate_direction_code'; // nopriv: проверка кода направления (модалка apply)

	// ==== Банки контента (работы / уроки / курсы) ====
	case GetWorkTaskCandidates     = 'get_work_task_candidates';     // params: subject_key, task_type, collection, scope, search
	case GetWorkCollections        = 'get_work_collections';         // params: subject_key
	case GetLessonWorkCandidates   = 'get_lesson_work_candidates';   // params: subject_key, work_type, scope, search
	case GetCourseLessonCandidates = 'get_course_lesson_candidates'; // params: subject_key, scope, search
	case GetLessonArticles         = 'get_lesson_articles';          // params: subject_key
	case CreateWorkDraft           = 'create_work_draft';            // params: subject_key, title, work_type
	case CreateLessonDraft         = 'create_lesson_draft';          // params: subject_key, title
	case SaveLessonSteps           = 'save_lesson_steps';            // params: lesson_id, subject_key, steps[]
	case MoveLessonStep            = 'move_lesson_step';             // params: source_lesson_id, target_lesson_id, step_key
	case GetStepCandidates         = 'get_step_candidates';          // params: subject_key, kind (work|task|assessment|article|lesson), source (subject|bank), search
	case GetWorkItemCandidates     = 'get_work_item_candidates';     // params: subject_key, collection, scope, search
	case CreateProblemDraft        = 'create_problem_draft';         // params: title
	case CreateTaskDraft           = 'create_task_draft';            // params: subject_key, title (черновик subject-задачи из билдера)
	case CreateAssessmentDraft     = 'create_assessment_draft';      // params: subject_key, title
	case CreateArticleDraft        = 'create_article_draft';         // params: subject_key, title

	// ==== Конструктор курса (Course Builder) ====
	case CreateCourseDraft   = 'create_course_draft';   // params: subject_key, title
	case GetCourseBuilder    = 'get_course_builder';    // params: course_id
	case SaveCourseStructure = 'save_course_structure'; // params: course_id, modules[] ({id,title,lesson_ids[]})
	case CreateLessonInModule = 'create_lesson_in_module'; // params: course_id, module_id, title
	case UpdateLessonMeta    = 'update_lesson_meta';    // params: lesson_id, title, published
	case SaveCourseMeta      = 'save_course_meta';      // params: course_id, title, published

	// ==== Пошаговый плеер урока (Этап 1.5) ====
	case MarkStepProgress = 'mark_step_progress'; // params: group_lesson_id, step_key, status (viewed|completed)

	// ==== Контрольные и экзамены (Этап 4) ====
	case StartAttempt      = 'start_attempt';
	case SaveAttemptAnswer = 'save_attempt_answer';
	case SubmitAttempt     = 'submit_attempt';
	case GradeAttempt      = 'grade_attempt';
	case GetAttemptResult  = 'get_attempt_result';

	// ==== Сдача работ (Этап 3) ====
	case SubmitWork          = 'submit_work';
	case SaveGrade           = 'save_grade';
	case ReturnSubmission    = 'return_submission';
	case GetGroupSubmissions = 'get_group_submissions';
	case GetMySubmissions    = 'get_my_submissions';
	case GetGradebook        = 'get_gradebook';

	// ==== Программа группы (Этап 2) ====
	case AssignCourse            = 'assign_course';
	case AddLessonToProgram      = 'add_lesson_to_program';
	case RemoveLessonFromProgram = 'remove_lesson_from_program';
	case ReorderProgram          = 'reorder_program';
	case SaveLessonSchedule      = 'save_lesson_schedule';
	case SetLessonExtraWorks     = 'set_lesson_extra_works';
	case SetLessonVisibility     = 'set_lesson_visibility';
	case GetGroupProgram         = 'get_group_program';
	case GetGroupActivity        = 'get_group_activity';

	// ==== Клонирование / форк контента (T1.5.11) ====
	case CloneLesson         = 'clone_lesson';
	case CloneWork           = 'clone_work';
	case CloneAssessment     = 'clone_assessment';
	case CloneCourse         = 'clone_course';
	case ForkLessonForGroup  = 'fork_lesson_for_group';


	// ============================ ГЕНЕРАЦИЯ ИМЁН ============================ //

	/**
	 * Возвращает полное имя WordPress AJAX-действия для авторизованных пользователей.
	 *
	 * @return string Например: "wp_ajax_store_subject"
	 */
	public function action(): string {
		return 'wp_ajax_' . $this->toSnakeCase();
	}

	/**
	 * Возвращает полное имя WordPress AJAX-действия для неавторизованных пользователей.
	 *
	 * @return string Например: "wp_ajax_nopriv_store_subject"
	 */
	public function noPrivAction(): string {
		return 'wp_ajax_nopriv_' . $this->toSnakeCase();
	}

	/**
	 * Возвращает имя экшена для использования в JavaScript (параметр action).
	 *
	 * @return string Например: "store_subject"
	 */
	public function jsAction(): string {
		return $this->toSnakeCase();
	}

	/**
	 * Возвращает имя метода в PHP-коллбеке.
	 *
	 * @return string Например: "ajaxStoreSubject"
	 */
	public function callbackMethod(): string {
		return 'ajax' . $this->name;
	}

	/**
	 * Конвертирует PascalCase в snake_case.
	 *
	 * @return string Например: "StoreSubject" → "store_subject"
	 */
	private function toSnakeCase(): string {
		return strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $this->name ) );
	}

	/**
	 * Возвращает массив всех хуков для передачи в JavaScript через wp_localize_script.
	 *
	 * @return array<string, string> Массив [lcfirst(case) => jsAction]
	 */
	public static function toJsArray(): array {
		$actions = array();
		foreach ( self::cases() as $case ) {
			$actions[ lcfirst( $case->name ) ] = $case->jsAction();
		}

		return $actions;
	}
}
