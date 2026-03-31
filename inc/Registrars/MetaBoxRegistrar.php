<?php

namespace Inc\Registrars;

use Inc\Managers\MetaBoxManager;
use Inc\MetaBoxes\Templates\BaseTemplate;


/**
 * Class MetaBoxRegistrar
 *
 * Фасад для формирования конфигураций метабоксов.
 * Предоставляет Fluent Interface для накопления данных перед регистрацией.
 */
class MetaBoxRegistrar {
	/**
	 * Низкоуровневый менеджер для выполнения регистрации в WP.
	 */
	private MetaBoxManager $manager;

	/**
	 * Очередь метабоксов на регистрацию.
	 * @var array
	 */
	private array $metaboxes = [];

	/**
	 * Конструктор.
	 *
	 * @param MetaBoxManager $manager Менеджер для регистрации
	 */
	public function __construct( MetaBoxManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Базовый метод добавления метабокса.
	 *
	 * @param string $id Уникальный ID метабокса
	 * @param string $title Заголовок, который увидит пользователь
	 * @param callable $callback Функция, которая отрисует содержимое
	 * @param array|string $post_types К какому CPT привязать
	 * @param array $args Дополнительные аргументы (контекст, приоритет, данные)
	 *
	 * @return self Для цепочки вызовов
	 */
	public function add( string $id, string $title, callable $callback, $post_types, array $args = [] ): self {
		$this->metaboxes[ $id ] = [
			'title'      => $title,
			'callback'   => $callback,
			'post_types' => $post_types,
			'context'    => $args['context'] ?? 'normal',  // По умолчанию в центре
			'priority'   => $args['priority'] ?? 'high',    // По умолчанию сверху
			'args'       => $args['args'] ?? []             // Данные, пробрасываемые в callback
		];

		return $this;
	}

	/**
	 * Специализированный метод для регистрации метабокса на основе нашего Шаблона.
	 *
	 * Автоматически берет ID и Имя из объекта шаблона.
	 *
	 * @param BaseTemplate $template Объект шаблона (например, StandardTaskTemplate)
	 * @param array|string $post_types Типы записей
	 * @param callable $callback Метод контроллера для рендеринга
	 *
	 * @return self
	 */
	public function addTemplateBox( BaseTemplate $template, $post_types, callable $callback ): self {
		return $this->add(
			$template->get_id(),
			$template->get_name(),
			$callback,
			$post_types,
			[
				'args' => [ 'template' => $template ] // Передаем шаблон в аргументы коллбека
			]
		);
	}

	/**
	 * Передаёт накопленные данные менеджеру для регистрации.
	 */
	public function register(): void {
		$this->manager->register( $this->metaboxes );
	}
}