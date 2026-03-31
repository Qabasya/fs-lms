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
	 * Базовый метод добавления метабокса с fluent interface.
	 *
	 * @param string $id Уникальный ID метабокса
	 * @param string $title Заголовок метабокса
	 * @param callable $callback Callback для отрисовки
	 * @param string|string[] $post_types Типы записей (строка или массив)
	 * @param array $args Дополнительные параметры (context, priority, args и т.д.)
	 *
	 * @return self
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
			'context'    => $args['context'] ?? 'normal',
			'priority'   => $args['priority'] ?? 'high',
			'args'       => $args['args'] ?? $args, // если передали args напрямую — используем их
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
	 * Финализирует регистрацию — передаёт все накопленные метабоксы менеджеру.
	 */
	public function register(): void
	{
		if (empty($this->metaboxes)) {
			return;
		}

		$this->manager->register($this->metaboxes);

		// Очищаем очередь после регистрации (на случай повторного вызова)
		$this->metaboxes = [];
	}
}