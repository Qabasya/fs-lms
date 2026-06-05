<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\ConsentSettingsCallbacks;
use Inc\Callbacks\EmailTemplateSettingsCallbacks;
use Inc\Enums\AjaxHook;

class SettingsController extends AjaxController {

	public function __construct(
		private readonly EmailTemplateSettingsCallbacks $emailTemplateCallbacks,
		private readonly ConsentSettingsCallbacks $consentCallbacks,
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
		);
	}
}
