<?php

namespace Inc\Controllers;

use Inc\Callbacks\SubjectSettingsCallbacks;
use Inc\Core\BaseController;
use Inc\Registrars\SettingsRegistrar;

/**
 * Class SettingsConfigurator
 *
 * Конфигуратор для WordPress Settings API.
 * Отвечает за формирование структуры настроек (опции, секции, поля)
 * и передачу их в регистратор для регистрации.
 *
 * @package Inc\Controllers
 */
class SettingsConfigurator {
	/**
	 * Коллбеки для рендеринга полей настроек.
	 *
	 * @var SubjectSettingsCallbacks
	 */
	private SubjectSettingsCallbacks $callbacks;

	/**
	 * Конструктор.
	 *
	 * @param SubjectSettingsCallbacks $callbacks Коллбеки для страниц настроек
	 */
	public function __construct(SubjectSettingsCallbacks $callbacks)
	{
		$this->callbacks = $callbacks;
	}

	/**
	 * Применяет всю конфигурацию к регистратору настроек.
	 *
	 * Цепочка вызовов:
	 * 1. Регистрирует опцию (настройку)
	 * 2. Регистрирует секции
	 * 3. Регистрирует поля
	 *
	 * @param SettingsRegistrar $registrar Регистратор настроек
	 *
	 * @return void
	 */
	public function configure(SettingsRegistrar $registrar): void
	{
		// Регистрация основной опции настройки
		$registrar->addSettings($this->getSettings());

		// Секции и поля пока не регистрируются
		// Когда будут готовы, раскомментировать:
		// ->addSections($this->getSections())
		// ->addFields($this->getFields());
	}

	/**
	 * Возвращает конфигурацию опции настройки.
	 *
	 * @return array<int, array{
	 *     option_group: string,
	 *     option_name: string,
	 *     callback: null
	 * }> Конфигурация опции
	 */
	private function getSettings(): array
	{
		return [
			[
				'option_group' => BaseController::SETTINGS_GROUP,
				'option_name'  => BaseController::SETTINGS_OPTION,
				'callback'     => null,
			]
		];
	}

}