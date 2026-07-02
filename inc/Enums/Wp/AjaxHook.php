<?php

namespace Inc\Enums\Wp;

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
	case ToggleSubjectArchive = 'toggle_subject_archive';
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
	case SetTaskTemplateType    = 'set_task_template_type'; // params: post_id, template_type

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

	// ==================== Настройки: роли (RBAC) ====================
	case SaveUserRoles = 'save_user_roles';

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
	case GetStepCandidates         = 'get_step_candidates';          // params: subject_key, kind (work|task|assessment|article|lesson), source (subject|bank), search
	case GetWorkItemCandidates     = 'get_work_item_candidates';     // params: subject_key, collection, scope, search
	case SaveWorkItems             = 'save_work_items';              // params: work_id, item_ids[] (степ-лист работы)
	case SaveAssessmentItems       = 'save_assessment_items';        // params: assessment_id, item_ids[] (степ-лист контрольной)
	case GetTaskPreview            = 'get_task_preview';             // params: task_id, subject_key
	case GetRefPreview             = 'get_ref_preview';              // params: ref_id, ref_type (work|assessment) → title + tasks[]
	case CreateAssessmentTaskDraft = 'create_assessment_task_draft'; // params: subject_key, title
	case CreateProblemDraft        = 'create_problem_draft';         // params: title
	case CreateTaskDraft           = 'create_task_draft';            // params: subject_key, title (черновик subject-задачи из билдера)
	case CreateAssessmentDraft     = 'create_assessment_draft';      // params: subject_key, title
	case CreateArticleDraft        = 'create_article_draft';         // params: subject_key, title

	// ==== Конструктор курса (Course Builder) ====
	case CreateCourseDraft   = 'create_course_draft';   // params: subject_key, title
	case GetCourseBuilder    = 'get_course_builder';    // params: course_id
	case SaveCourseStructure = 'save_course_structure'; // params: course_id, modules[] ({id,title,lesson_ids[]})
	case CreateLessonInModule = 'create_lesson_in_module'; // params: course_id, module_id, title
	case DuplicateLessonInModule = 'duplicate_lesson_in_module'; // params: course_id, module_id, lesson_id
	case UpdateLessonMeta    = 'update_lesson_meta';    // params: lesson_id, title, published
	case SaveCourseMeta      = 'save_course_meta';      // params: course_id, title, published

	// ==== Пошаговый плеер урока (Этап 1.5) ====
	case MarkStepProgress = 'mark_step_progress'; // params: group_lesson_id, step_key, status (viewed|completed)

	// ==== Интерактивные задания (Этап 6) ====
	case SubmitTaskAnswer  = 'submit_task_answer';  // params: group_lesson_id, step_key, answer (JSON)
	case GetStepSettings   = 'get_step_settings';   // params: group_lesson_id
	case SaveStepSettings  = 'save_step_settings';  // params: group_lesson_id, overrides (JSON)
	case SaveTaskContent   = 'save_task_content';   // params: subject_key, template, title, post_id? (0=create), fs_lms_meta[...] (поля)
	case GetTaskEditorForm = 'get_task_editor_form'; // params: subject_key, template, post_id? → HTML полей шаблона
	case GetTaskAttempts   = 'get_task_attempts';   // params: group_lesson_id, step_key → список попыток всех студентов

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

	// ==== Пакетная сдача / ручная оценка (Этап 7) ====
	case SubmitBatchWork = 'submit_batch_work'; // params: group_lesson_id, work_id, answers (JSON)
	case GradeBatchTask  = 'grade_batch_task';  // params: submission_id, score, feedback

	// ==== Таблица перевода ЕГЭ (Этап 7, T7.16) ====
	case ParseScoreMap  = 'parse_score_map';   // params: text (сырой текст из Excel/Word)
	case CopyScoreMap   = 'copy_score_map';    // params: source_assessment_id, target_assessment_id

	// ==== Программа группы (Этап 2) ====
	case AssignCourse            = 'assign_course';
	case AddLessonToProgram      = 'add_lesson_to_program';
	case DuplicateProgramLesson  = 'duplicate_program_lesson';
	case RemoveLessonFromProgram = 'remove_lesson_from_program';
	case ReorderProgram          = 'reorder_program';
	case SaveLessonSchedule      = 'save_lesson_schedule';
	case SetLessonExtraWorks     = 'set_lesson_extra_works';
	case SetLessonVisibility     = 'set_lesson_visibility';
	case GetGroupProgram         = 'get_group_program';
	case GetGroupActivity        = 'get_group_activity';
	case PublishProgram          = 'publish_program';   // params: group_id — опубликовать (заблокировать) КТП (T1.8)
	case UnpublishProgram        = 'unpublish_program'; // params: group_id — снять публикацию КТП (T1.8)

	// ==== КТП / расписание (ЛК преподавателя, Эпик 1) ====
	case ReflowSchedule          = 'reflow_schedule';    // params: group_id — авто-распределение тем по слотам периода
	case PinLesson               = 'pin_lesson';         // params: group_lesson_id, scheduled_at — закрепить тему на дату
	case GetGroupCalendar        = 'get_group_calendar'; // params: group_id — слоты периода + выходные + размещённые темы

	// ==== Индивидуальные занятия (ЛК преподавателя, Эпик 4) ====
	case CreateIndividualLesson  = 'create_individual_lesson'; // params: group_id, student_person_id, scheduled_at[, ends_at, lesson_id, label, teacher_user_id, room_id]
	case GetFreeRooms            = 'get_free_rooms'; // params: group_id, scheduled_at[, ends_at] — свободные кабинеты по предмету+времени (Эпик 11 T11.3)

	// ==== Экран «Группы» / ростер (ЛК преподавателя, Эпик 10 T10.7) ====
	case GetGroupRoster          = 'get_group_roster'; // params: group_id — активные ученики + их индивидуальные занятия

	// ==== «Сводка по ученику» (ЛК преподавателя, Эпик 10 T10.8) ====
	case GetStudentSummary       = 'get_student_summary'; // params: group_id, student_person_id — занятия ученика (посещаемость + работы)

	// ==== Деталь работы из сводки (ЛК преподавателя, Эпик 10 T10.9) ====
	case GetWorkDetail           = 'get_work_detail'; // params: source_type (submission|attempt), source_id — условия/ответы/вердикты

	// ==== Курс-пикер КТП (ЛК преподавателя, Эпик 11 T11.1) ====
	case GetSubjectCourses       = 'get_subject_courses'; // params: group_id — курсы предмета группы для назначения

	// ==== Замены преподавателя (офис, Эпик 5) ====
	case AssignSubstitute        = 'assign_substitute';        // params: group_id, substitute_teacher_id, valid_from, valid_to[, reason]
	case RevokeSubstitute        = 'revoke_substitute';        // params: substitution_id
	case GetGroupSubstitutions   = 'get_group_substitutions';  // params: group_id

	// ==== Единый экран «Замены» (офис, Эпик 9) ====
	case GetSubstitutionsData    = 'get_substitutions_data';   // params: group_id — замены + преподаватели + кабинеты
	case SetRoomOverride         = 'set_room_override';        // params: group_id, room_id|'', valid_from, valid_to — замена кабинета на период

	// ==== «Главная» кабинета (ЛК преподавателя, Эпик 6) ====
	case GetProfileDashboard     = 'get_profile_dashboard';    // без params — агрегат по всем группам текущего пользователя

	// ==== ЛК учащегося/родителя (Эпик 7) ====
	case GetLearnerProfile       = 'get_learner_profile';      // [student_person_id] — родитель выбирает ребёнка; ученик игнорит

	// ==== Кабинеты / аудитории (офис, Эпик 9) ====
	case GetRooms                = 'get_rooms';                // список кабинетов + группы (для назначения)
	case SaveRoom                = 'save_room';                // [room_id], name, seats, allowed_subjects[], is_active
	case DeleteRoom              = 'delete_room';              // room_id
	case AssignGroupRoom         = 'assign_group_room';        // group_id, room_id|'' — кабинет-по-умолчанию группы

	// ==== Журнал / посещаемость (ЛК преподавателя, Эпик 2) ====
	case GetGroupJournal         = 'get_group_journal';  // params: group_id — ростер × (занятия+работы)
	case SaveAttendance          = 'save_attendance';    // params: group_lesson_id, student_person_id, is_present
	case BulkAttendance          = 'bulk_attendance';    // params: group_lesson_id, is_present — всем в занятии

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
