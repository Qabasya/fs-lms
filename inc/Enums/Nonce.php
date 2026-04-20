<?php

namespace Inc\Enums;

/**
 * Ключи безопасности (Nonce) плагина.
 */
enum Nonce: string {
	/** Для создания заданий через модальное окно. */
	case TaskCreation = 'fs_task_creation_nonce';

	/** Для CRUD-операций с предметами и таксономиями. */
	case Subject = 'fs_subject_nonce';

	/** Для менеджера заданий и общих настроек. */
	case Manager = 'fs_lms_manager_nonce';

	/** Сохранение мета-данных (в Metabox). */
	case SaveMeta = 'fs_lms_save_meta';

	/** Сохранение шаблона (Boilerplate). */
	case SaveBoilerplate = 'save_boilerplate_nonce';

	/**
	 * Создает защитный токен.
	 *
	 * @return string
	 */
	public function create(): string {
		return wp_create_nonce( $this->value );
	}

	/**
	 * Проверяет входящий запрос.
	 *
	 * @param string $queryArg Ключ в массиве $_POST/$_REQUEST (обычно 'security' или 'nonce').
	 */
	public function verify( string $queryArg = 'security' ): void {
		check_ajax_referer( $this->value, $queryArg );
	}
}
