<?php

namespace Inc\Managers;

/**
 * Class MetaBoxManager
 *
 * Низкоуровневый менеджер для работы с метабоксами и мета-данными.
 * Инкапсулирует прямые вызовы WordPress API (add_meta_box, update_post_meta).
 * Не содержит бизнес-логики, только техническую реализацию.
 *
 * @package Inc\Managers
 */
class MetaBoxManager {

	/**
	 * Регистрирует несколько метабоксов в WordPress.
	 *
	 * Метод оборачивает вызовы add_meta_box() в хук add_meta_boxes.
	 * Если массив метабоксов пуст, регистрация не выполняется.
	 *
	 * @param array<string, array{
	 *     title: string,
	 *     callback: callable,
	 *     post_types: string|array<int, string>,
	 *     context?: string,
	 *     priority?: string,
	 *     args?: array
	 * }> $metaboxes Массив конфигураций метабоксов,
	 *               где ключ — уникальный идентификатор метабокса
	 *
	 * @return void
	 */
	public function register(array $metaboxes): void
	{
		// Если нет метабоксов для регистрации — выходим
		if (empty($metaboxes)) {
			return;
		}

		// Подключаем регистрацию метабоксов на хуке add_meta_boxes
		add_action('add_meta_boxes', function () use ($metaboxes) {
			foreach ($metaboxes as $id => $config) {
				$this->addSingleMetabox($id, $config);
			}
		});
	}

	/**
	 * Регистрирует один метабокс.
	 *
	 * Удобно использовать из контроллера при необходимости.
	 * Метод оборачивает вызов add_meta_box() в хук add_meta_boxes.
	 *
	 * @param string          $id          Уникальный идентификатор метабокса
	 * @param string          $title       Заголовок метабокса
	 * @param callable        $callback    Коллбек для отрисовки содержимого
	 * @param string|array    $post_types  Тип(ы) поста, для которых показывать метабокс
	 * @param string          $context     Местоположение ('normal', 'side', 'advanced')
	 * @param string          $priority    Приоритет ('high', 'core', 'default', 'low')
	 * @param array           $args        Дополнительные аргументы, передаваемые в коллбек
	 *
	 * @return void
	 */
	public function addMetabox(
		string $id,
		string $title,
		callable $callback,
		string|array $post_types,
		string $context = 'normal',
		string $priority = 'default',
		array $args = []
	): void {
		// Оборачиваем добавление метабокса в хук add_meta_boxes
		add_action('add_meta_boxes', function () use ($id, $title, $callback, $post_types, $context, $priority, $args) {
			$this->addSingleMetabox($id, [
				'title'      => $title,
				'callback'   => $callback,
				'post_types' => $post_types,
				'context'    => $context,
				'priority'   => $priority,
				'args'       => $args,
			]);
		});
	}

	/**
	 * Внутренний метод для добавления одного метабокса.
	 *
	 * Применяет стандартные настройки по умолчанию и нормализует post_types в массив.
	 *
	 * @param string $id     Уникальный идентификатор метабокса
	 * @param array  $config Конфигурация метабокса с полями:
	 *                       - title: заголовок
	 *                       - callback: коллбек отрисовки
	 *                       - post_types: типы постов
	 *                       - context: местоположение
	 *                       - priority: приоритет
	 *                       - args: доп. аргументы
	 *
	 * @return void
	 */
	private function addSingleMetabox(string $id, array $config): void
	{
		// Настройки по умолчанию
		$defaults = [
			'title'      => 'Untitled Metabox',
			'callback'   => '__return_null',
			'post_types' => [],
			'context'    => 'normal',
			'priority'   => 'default',
			'args'       => [],
		];

		// Объединяем переданные настройки с дефолтными
		$config = wp_parse_args($config, $defaults);

		// Нормализуем post_types в массив (на случай, если передан строкой)
		$post_types = (array) $config['post_types'];

		// Для каждого типа поста добавляем метабокс
		foreach ($post_types as $post_type) {
			// Пропускаем пустые или нестроковые значения
			if (empty($post_type) || !is_string($post_type)) {
				continue;
			}

			// Регистрируем метабокс через WordPress API
			add_meta_box(
				$id,
				$config['title'],
				$config['callback'],
				$post_type,
				$config['context'],
				$config['priority'],
				$config['args']
			);
		}
	}

	/**
	 * Сохраняет мета-данные поста после всех проверок безопасности.
	 *
	 * Рекомендуется использовать в обработчике save_post.
	 * Метод автоматически пропускает ревизии и автосохранения.
	 *
	 * @param int    $post_id  ID поста
	 * @param string $meta_key Ключ мета-поля
	 * @param mixed  $value    Значение (уже очищенное на верхнем уровне)
	 *
	 * @return void
	 */
	public function saveMeta(int $post_id, string $meta_key, $value): void
	{
		// Валидация обязательных параметров
		if (empty($post_id) || empty($meta_key)) {
			return;
		}

		// Дополнительная защита: пропускаем ревизии и автосохранения
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}

		// Сохраняем мета-данные
		update_post_meta($post_id, $meta_key, $value);
	}

	/**
	 * Удаляет мета-данные поста.
	 *
	 * @param int    $post_id  ID поста
	 * @param string $meta_key Ключ мета-поля
	 *
	 * @return void
	 */
	public function deleteMeta(int $post_id, string $meta_key): void
	{
		// Валидация обязательных параметров
		if (empty($post_id) || empty($meta_key)) {
			return;
		}

		// Удаляем мета-данные
		delete_post_meta($post_id, $meta_key);
	}
}