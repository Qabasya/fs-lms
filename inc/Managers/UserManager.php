<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\Enums\UserRole;
use Inc\Enums\Capability;

/**
 * Class UserManager
 *
 * Менеджер управления инфраструктурой пользователей.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **Создание ролей** — регистрация кастомных ролей пользователей (учителя, ученики, родители).
 * 2. **Удаление ролей** — очистка кастомных ролей при деактивации плагина.
 * 3. **Ограничение доступа** — редирект пользователей без прав с админ-панели на фронтенд.
 * 4. **Назначение прав** — добавление специфических возможностей (capabilities) для ролей.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций для управления ролями (add_role, remove_role, get_role).
 * Обеспечивает единую точку управления пользовательской системой прав.
 */
class UserManager {

	/**
	 * Регистрирует все кастомные роли в WordPress.
	 * Вызывается при активации плагина.
	 *
	 * @return void
	 */
	public function createRoles(): void {
		// === Внутренние роли === //

		// add_role() — WordPress-функция для создания роли
		// Параметры: идентификатор, отображаемое имя, массив прав

		// Преподаватель (может заходить в админку, редактировать посты, загружать файлы)
		add_role(
			UserRole::FSTeacher->value,
			UserRole::FSTeacher->label(),
			array(
				'read'         => true,      // Чтение контента
				'edit_posts'   => true,      // Редактирование постов
				'upload_files' => true,      // Загрузка файлов
			)
		);

		// Наши ученики (только чтение и загрузка файлов)
		add_role(
			UserRole::FSStudent->value,
			UserRole::FSStudent->label(),
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);

		// Родители учеников (только чтение, без загрузки файлов)
		add_role(
			UserRole::FSParent->value,
			UserRole::FSParent->label(),
			array(
				'read' => true,
			)
		);

		// === Внешние роли === //

		// Ученики (базовые пользователи с возможностью загрузки файлов)
		add_role(
			UserRole::Student->value,
			UserRole::Student->label(),
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);

		// Учителя (доступ к созданию заданий и подборок)
		add_role(
			UserRole::Teacher->value,
			UserRole::Teacher->label(),
			array(
				'read'         => true,
				'upload_files' => true,
			)
		);

		// Добавление специфических прав для ролей
		$this->addCustomCapabilities();
	}

	/**
	 * Удаляет все кастомные роли из базы данных.
	 * Вызывается при деактивации или деинсталляции плагина.
	 *
	 * @return void
	 */
	public function removeRoles(): void {
		foreach ( UserRole::cases() as $role ) {
			// remove_role() — WordPress-функция для удаления роли
			remove_role( $role->value );
		}
	}

	/**
	 * Ограничивает доступ к админ-панели для всех, кроме администраторов и наших учителей.
	 * Подключается к хуку 'admin_init'.
	 *
	 * @return void
	 */
	public function restrictAdminAccess(): void {
		// is_admin() — проверяет, находится ли пользователь в админ-панели
		// DOING_AJAX — константа, определяющая, выполняется ли AJAX-запрос
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {

			// is_user_logged_in() — проверяет, авторизован ли пользователь
			// current_user_can() — проверяет наличие права у текущего пользователя
			// Capability::ADMIN ('manage_options') — право администратора WordPress
			if ( is_user_logged_in() &&
				! current_user_can( Capability::ADMIN->value ) &&
				! current_user_can( UserRole::FSTeacher->value )
			) {
				// wp_safe_redirect() — безопасное перенаправление (только разрешённые домены)
				// home_url() — возвращает URL главной страницы сайта
				wp_safe_redirect( home_url( '/profile/' ) );
				exit;  // Прерываем выполнение после редиректа
			}
		}
	}

	/**
	 * Формирует аргументы запроса к медиабиблиотеке, чтобы пользователи
	 * видели только свои загрузки.
	 *
	 * @param array $query Аргументы запроса WP_Query
	 * @return array
	 */
	public function getMediaFilterArgs( array $query ): array {
		// Если мы в админке и у пользователя есть право редактировать чужое (админ) — не фильтруем
		if ( current_user_can( Capability::ADMIN->value ) ) {
			return $query;
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$query['author'] = $user_id;
		}

		return $query;
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Назначает специфические права (capabilities) для ролей.
	 *
	 * @return void
	 */
	private function addCustomCapabilities(): void {
		// get_role() — возвращает объект роли по её идентификатору
		$fs_teacher = get_role( UserRole::FSTeacher->value );

		if ( $fs_teacher ) {
			// add_cap() — добавляет право (capability) роли
			$fs_teacher->add_cap( Capability::ViewLMSStats->value );       // Просмотр статистики LMS
			$fs_teacher->add_cap( Capability::ManageLMSAssignments->value ); // Управление подборками
		}
	}
}
