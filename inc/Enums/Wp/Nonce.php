<?php

namespace Inc\Enums\Wp;

/**
 * Ключи безопасности (Nonce) плагина.
 */
enum Nonce: string {
	/** Для создания заданий через модальное окно. */
	case TaskCreation = 'fs_lms_task_creation_nonce';

	/** Для CRUD-операций с предметами и таксономиями. */
	case Subject = 'fs_lms_subject_nonce';

	/** Для менеджера заданий и общих настроек. */
	case Manager = 'fs_lms_manager_nonce';

	/** Сохранение мета-данных (в Metabox). */
	case SaveMeta = 'fs_lms_save_meta';

	/** Сохранение шаблона (Boilerplate). */
	case SaveBoilerplate = 'fs_lms_save_boilerplate_nonce';

	/** Nonce для события зачисления */
	case Apply                 = 'fs_lms_apply';
	case ParentSubmit          = 'fs_lms_parent_submit';
	case Enroll                = 'fs_lms_enroll';
	case RevealPii             = 'fs_lms_reveal_pii';
	case AddRepresentative     = 'fs_lms_add_representative';
	case ReplaceRepresentative = 'fs_lms_replace_representative';
	case UpdatePerson          = 'fs_lms_update_person';
	case WithdrawConsent       = 'fs_lms_withdraw_consent';
	case RequestPiiDeletion    = 'fs_lms_request_pii_deletion';
	case ExportPii             = 'fs_lms_export_pii';
	case VerifyOtp             = 'fs_lms_verify_otp';
	case TrashApplication      = 'fs_lms_trash_application';
	case EditApplication          = 'fs_lms_edit_application';
	case ReviewApplication        = 'fs_lms_review_application';
	case Expulsion                = 'fs_lms_expulsion';
	case SelectExistingParent     = 'fs_lms_select_existing_parent';
	case RemoveParentAssignment        = 'fs_lms_remove_parent_assignment';
	case CheckUsernameAvailable        = 'fs_lms_check_username';
	case CheckEmailAvailable           = 'fs_lms_check_email';
	case RestoreFromArchive       = 'fs_lms_restore_from_archive';
	case DeleteGroup              = 'fs_lms_delete_group';
	case DeletePeriod             = 'fs_lms_delete_period';
	case HardDeleteStudent        = 'fs_lms_hard_delete_student';
	case Config                   = 'fs_lms_config';

	/** AJAX-запросы конструктора урока (выбор работ, статей). */
	case AuthorLesson = 'fs_lms_author_lesson';

	/** AJAX-запросы конструктора работы (выбор заданий). */
	case AuthorWork = 'fs_lms_author_work';

	/** AJAX-запросы конструктора курса (выбор уроков). */
	case AuthorCourse = 'fs_lms_author_course';

	/** AJAX-запросы конструктора контрольной (степ-лист заданий). */
	case AuthorAssessment = 'fs_lms_author_assessment';

	// ==== Этап 2 — программа группы ====
	case AssignCourse         = 'fs_lms_assign_course';
	case SaveSchedule         = 'fs_lms_save_schedule';
	case SetLessonVisibility  = 'fs_lms_set_lesson_visibility';

	// ==== ЛК преподавателя — замены (Эпик 5) ====
	case Substitution         = 'fs_lms_substitution';

	// ==== ЛК учащегося/родителя (Эпик 7) ====
	case LearnerProfile       = 'fs_lms_learner_profile';

	// ==== Кабинеты / аудитории (Эпик 9) ====
	case Room                 = 'fs_lms_room';

	// ==== Этап 3 — сдача работ ====
	case SubmitWork = 'fs_lms_submit_work';
	case GradeWork  = 'fs_lms_grade_work';

	// ==== Этап 7 — пакетная сдача / ручная оценка ====
	case SubmitBatchWork = 'fs_lms_submit_batch_work';
	case GradeBatch      = 'fs_lms_grade_batch';

	// ==== Этап 7 — редактор таблицы перевода ЕГЭ ====
	case ScoreMap = 'fs_lms_score_map';

	// ==== Этап 4 — контрольные и экзамены ====
	case StartAttempt  = 'fs_lms_start_attempt';
	case SubmitAttempt = 'fs_lms_submit_attempt';
	case GradeAttempt  = 'fs_lms_grade_attempt';

	// ==== Этап 1.5 — пошаговый плеер урока ====
	case MarkStepProgress = 'fs_lms_mark_step_progress';

	// ==== Этап 6 — интерактивные задания ====
	case SubmitTaskAnswer = 'fs_lms_submit_task_answer';
	case StepSettings     = 'fs_lms_step_settings';
	case TaskContent      = 'fs_lms_task_content';

	/**
	 * Создает защитный токен.
	 *
	 * @return string
	 */
	public function create(): string {
		return wp_create_nonce( $this->value );
	}

	/** Страница «Все задания» и AJAX-фильтрация. */
	case AllTasks = 'fs_lms_all_tasks_nonce';

	// ==== RBAC — управление ролями (Этап 6) ====
	case SaveRoles = 'fs_lms_save_roles';

	/**
	 * Проверяет входящий запрос.
	 *
	 * @param string $queryArg Ключ в массиве $_POST/$_REQUEST (обычно 'security' или 'nonce').
	 */
	public function verify( string $queryArg = 'security' ): void {
		check_ajax_referer( $this->value, $queryArg );
	}
}
