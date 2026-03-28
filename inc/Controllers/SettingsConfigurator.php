<?php

namespace Inc\Controllers;

use Inc\Callbacks\AdminCallbacks;
use Inc\Core\BaseController;
use Inc\Registrars\SettingsRegistrar;

/**
 * Конфигурирует WordPress Settings API.
 * Отвечает за регистрацию настроек, секций и полей.
 */
class SettingsConfigurator {
	private AdminCallbacks $callbacks;

	public function __construct( AdminCallbacks $callbacks ) {
		$this->callbacks = $callbacks;
	}

	/**
	 * Применить всю конфигурацию к Settings API.
	 */
	public function configure( SettingsRegistrar $registrar ): void {
		$registrar->addSettings( $this->getSettings() );

		// Секции и поля пока не регистрируются!!
		// Когда будут готовы, раскомментировать:
		// ->addSections( $this->getSections() )
		// ->addFields( $this->getFields() );
	}

	private function getSettings(): array {
		return [
			[
				'option_group' => BaseController::SETTINGS_GROUP,
				'option_name'  => BaseController::SETTINGS_OPTION,
				'callback'     => null,
			]
		];
	}

}