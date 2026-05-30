<?php

namespace Inc\Enums;

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
	case Reject                = 'fs_lms_reject';
	case RevealPii             = 'fs_lms_reveal_pii';
	case AddRepresentative     = 'fs_lms_add_representative';
	case ReplaceRepresentative = 'fs_lms_replace_representative';
	case UpdatePerson          = 'fs_lms_update_person';
	case WithdrawConsent       = 'fs_lms_withdraw_consent';
	case RequestPiiDeletion    = 'fs_lms_request_pii_deletion';
	case ExportPii             = 'fs_lms_export_pii';
	case VerifyOtp             = 'fs_lms_verify_otp';
	case TrashApplication      = 'fs_lms_trash_application';
	case EditApplication       = 'fs_lms_edit_application';
	case ReviewApplication     = 'fs_lms_review_application';

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

	/**
	 * Проверяет входящий запрос.
	 *
	 * @param string $queryArg Ключ в массиве $_POST/$_REQUEST (обычно 'security' или 'nonce').
	 */
	public function verify( string $queryArg = 'security' ): void {
		check_ajax_referer( $this->value, $queryArg );
	}
}
