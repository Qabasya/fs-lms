<?php

declare( strict_types=1 );

namespace Inc\Controllers\Settings;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Settings\AcademicPeriodCallbacks;
use Inc\Callbacks\Settings\ConsentSettingsCallbacks;
use Inc\Callbacks\Settings\EmailTemplateSettingsCallbacks;
use Inc\Callbacks\Settings\RolesSettingsCallbacks;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class SettingsController
 *
 * Контроллер для управления настройками плагина (email-шаблоны, согласия, учебные периоды).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для сохранения и сброса настроек.
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику специализированным коллбекам:
 * - EmailTemplateSettingsCallbacks — управление шаблонами email-писем
 * - ConsentSettingsCallbacks — управление определениями согласий
 * - AcademicPeriodCallbacks — управление учебными периодами (годами/семестрами)
 *
 * ### Группы настроек:
 *
 * - **Email шаблоны** — сохранение, сброс кастомных шаблонов
 * - **Согласия** — добавление, удаление определений, поиск по хешу
 * - **Учебные периоды** — сохранение, удаление периодов
 */
class SettingsController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param EmailTemplateSettingsCallbacks $emailTemplateCallbacks Коллбеки для шаблонов email
	 * @param ConsentSettingsCallbacks       $consentCallbacks       Коллбеки для согласий
	 * @param AcademicPeriodCallbacks        $academicPeriodCallbacks Коллбеки для учебных периодов
	 */
	public function __construct(
		private readonly EmailTemplateSettingsCallbacks $emailTemplateCallbacks,
		private readonly ConsentSettingsCallbacks       $consentCallbacks,
		private readonly AcademicPeriodCallbacks        $academicPeriodCallbacks,
		private readonly RolesSettingsCallbacks         $rolesCallbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		parent::register();

		// Вкладка «Роли» видна только носителям Capability::ManageLmsRoles (administrator).
		add_filter( 'fs_lms_settings_tabs', function ( array $tabs ): array {
			if ( current_user_can( Capability::ManageLmsRoles->value ) ) {
				$tabs['tab-8'] = array(
					'title' => 'Роли',
					'file'  => '/components/tabs/settings-tabs/settings-8-roles.php',
				);
			}
			return $tabs;
		} );
	}

	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array
	 */
	protected function ajaxActions(): array {
		return array(
			// Сохранение кастомного шаблона email-письма
			array( AjaxHook::SaveEmailTemplate, $this->emailTemplateCallbacks ),
			// Сброс кастомного шаблона к значению по умолчанию
			array( AjaxHook::ResetEmailTemplate, $this->emailTemplateCallbacks ),
			// Поиск версии согласия по SHA-256 хешу
			array( AjaxHook::LookupConsentByHash, $this->consentCallbacks ),
			// Добавление нового определения согласия
			array( AjaxHook::AddConsentDefinition, $this->consentCallbacks ),
			// Удаление определения согласия
			array( AjaxHook::DeleteConsentDefinition, $this->consentCallbacks ),
			// Сохранение учебного периода (создание/обновление)
			array( AjaxHook::SaveAcademicPeriod, $this->academicPeriodCallbacks ),
			// Удаление учебного периода
			array( AjaxHook::DeleteAcademicPeriod, $this->academicPeriodCallbacks ),
			// Сохранение LMS-ролей пользователя
			array( AjaxHook::SaveUserRoles, $this->rolesCallbacks ),
		);
	}
}