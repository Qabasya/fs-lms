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
	case SaveStudentGroup   = 'save_student_group';
	case DeleteStudentGroup = 'delete_student_group';

	// ==================== Каскадное удаление ====================
	case CheckGroupDeletion   = 'check_group_deletion';
	case DeleteGroup          = 'delete_group';
	case CheckSubjectDeletion = 'check_subject_deletion';
	case CheckPeriodDeletion  = 'check_period_deletion';
	case DeletePeriod         = 'delete_period';
	case HardDeleteStudent    = 'hard_delete_student';

	// ==================== Журналы ====================
	case ExportAuditLog        = 'export_audit_log';
	case ExportPiiLog          = 'export_pii_log';
	case ExportExportLog       = 'export_export_log';
	case ExportDataChangeLog   = 'export_data_change_log';
	case ExportConsentChangeLog = 'export_consent_change_log';
	case ExportEmailLog        = 'export_email_log';
	case ExportDeletionLog     = 'export_deletion_log';
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
