<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\AcademicPeriodCallbacks;
use Inc\Callbacks\ConsentSettingsCallbacks;
use Inc\Callbacks\EmailTemplateSettingsCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class SettingsController
 *
 * Контроллер для управления настройками плагина (email-шаблоны, согласия, учебные периоды).
 *
 * @package Inc\Controllers
 */
class SettingsController extends AjaxController {

	public function __construct(
		private readonly EmailTemplateSettingsCallbacks $emailTemplateCallbacks,
		private readonly ConsentSettingsCallbacks       $consentCallbacks,
		private readonly AcademicPeriodCallbacks        $academicPeriodCallbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		parent::register();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SaveEmailTemplate,      $this->emailTemplateCallbacks ),
			array( AjaxHook::ResetEmailTemplate,     $this->emailTemplateCallbacks ),
			array( AjaxHook::LookupConsentByHash,    $this->consentCallbacks ),
			array( AjaxHook::AddConsentDefinition,   $this->consentCallbacks ),
			array( AjaxHook::DeleteConsentDefinition, $this->consentCallbacks ),
			array( AjaxHook::SaveAcademicPeriod,     $this->academicPeriodCallbacks ),
			array( AjaxHook::DeleteAcademicPeriod,   $this->academicPeriodCallbacks ),
		);
	}
}
