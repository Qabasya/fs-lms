<?php

namespace Inc\Registrars;

use Inc\Managers\MetaBoxManager;
use Inc\MetaBoxes\Templates\BaseTemplate;

/**
 * Class MetaBoxRegistrar
 *
 * Фасад для формирования конфигураций метабоксов.
 * Предоставляет Fluent Interface для накопления данных перед регистрацией.
 *
 * Паттерны:
 * - Facade — упрощает интерфейс работы с MetaBoxManager
 * - Fluent Interface — позволяет объединять вызовы в цепочку
 * - Builder — накапливает данные перед регистрацией
 *
 * @package Inc\Registrars
 */
class MetaBoxRegistrar {
	/**
	 * Низкоуровневый менеджер для выполнения регистрации в WordPress.
	 *
	 * @var MetaBoxManager
	 */
	private MetaBoxManager $manager;

	/**
	 * Очередь метабоксов на регистрацию.
	 *
	 * Структура:
	 * [
	 *     'metabox_id' => [
	 *         'title'      => string,
	 *         'callback'   => callable,
	 *         'post_types' => string|array,
	 *         'context'    => string,
	 *         'priority'   => string,
	 *         'args'       => array
	 *     ]
	 * ]
	 *
	 * @var array<string, array{
	 *     title: string,
	 *     callback: callable,
	 *     post_types: string|array,
	 *     context: string,
	 *     priority: string,
	 *     args: array
	 * }>
	 */
	private array $metaboxes = [];

	/**
	 * Конструктор.
	 *
	 * @param MetaBoxManager $manager Менеджер для регистрации метабоксов
	 */
	public function __construct( MetaBoxManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Базовый метод добавления метабокса с fluent interface.
	 *
	 * Позволяет добавить метабокс с полным контролем над параметрами.
	 *
	 * @param string $id Уникальный ID метабокса
	 * @param string $title Заголовок метабокса
	 * @param callable $callback Коллбек для отрисовки содержимого
	 * @param string|string[] $post_types Типы записей (строка или массив)
	 * @param array $args Дополнительные параметры:
	 *                                     - context: 'normal', 'side', 'advanced'
	 *                                     - priority: 'high', 'core', 'default', 'low'
	 *                                     - args: дополнительные аргументы для коллбека
	 *
	 * @return self Для цепочки вызовов (Fluent Interface)
	 */
	public function add(
		string $id,
		string $title,
		callable $callback,
		string|array $post_types,
		array $args = []
	): self {
		$this->metaboxes[ $id ] = [
			'title'      => $title,
			'callback'   => $callback,
			'post_types' => $post_types,
			'context'    => $args['context'] ?? 'normal',   // Местоположение метабокса
			'priority'   => $args['priority'] ?? 'high',    // Приоритет отображения
			'args'       => $args['args'] ?? $args,         // Аргументы для коллбека
		];

		return $this;
	}

	/**
	 * Добавляет метабокс на основе шаблона.
	 *
	 * Упрощённый метод для регистрации метабокса из объекта шаблона.
	 * Автоматически извлекает ID и название из шаблона.
	 *
	 * @param object $template Объект шаблона (должен иметь методы get_id и get_name)
	 * @param array|string $post_types Типы записей (строка или массив)
	 * @param callable $callback Коллбек для отрисовки содержимого
	 *
	 * @return self Для цепочки вызовов (Fluent Interface)
	 */
	public function addTemplateBox( $template, $post_types, callable $callback ): self {
		return $this->add(
			$template->get_id(),
			$template->get_name() . ' (Настройка)',  // Добавляем суффикс для ясности
			$callback,
			$post_types,
			[
				'args' => [ 'template' => $template ]   // Передаём шаблон в аргументы коллбека
			]
		);
	}

	/**
	 * Финализирует регистрацию — передаёт все накопленные метабоксы менеджеру.
	 *
	 * Если очередь метабоксов пуста, регистрация не выполняется.
	 * После регистрации очищает очередь на случай повторного вызова.
	 *
	 * @return void
	 */
	public function register(): void {
		// Если нет метабоксов для регистрации — выходим
		if ( empty( $this->metaboxes ) ) {
			return;
		}

		// Делегируем регистрацию низкоуровневому менеджеру
		$this->manager->register( $this->metaboxes );

		// Очищаем очередь после регистрации (на случай повторного вызова)
		$this->metaboxes = [];
	}
}