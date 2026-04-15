<?php

declare(strict_types=1);

namespace Inc\Managers;

/**
 * Class PostManager
 *
 * Менеджер для работы с постами WordPress.
 * Инкапсулирует базовые операции: получение, создание, удаление постов,
 * а также работу с мета-полями.
 *
 * @package Inc\Managers
 */
class PostManager
{
	/**
	 * Возвращает массив ID постов указанного типа.
	 *
	 * @param string $post_type Тип поста (например, "math_tasks")
	 *
	 * @return int[] Массив ID постов
	 */
	public function getIds(string $post_type): array
	{
		return get_posts([
			'post_type'   => $post_type,
			'numberposts' => -1,      // Получить все посты
			'post_status' => 'any',   // Все статусы (опубликованные, черновики, корзина)
			'fields'      => 'ids',   // Возвращать только ID
		]);
	}

	/**
	 * Возвращает массив объектов WP_Post указанного типа.
	 *
	 * @param string $post_type Тип поста (например, "math_tasks")
	 *
	 * @return \WP_Post[] Массив объектов постов
	 */
	public function getAll(string $post_type): array
	{
		return get_posts([
			'post_type'   => $post_type,
			'numberposts' => -1,      // Получить все посты
			'post_status' => 'any',   // Все статусы
		]);
	}

	/**
	 * Удаляет пост полностью (включая корзину).
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function delete(int $post_id): void
	{
		// true — полное удаление без перемещения в корзину
		wp_delete_post($post_id, true);
	}

	/**
	 * Удаляет все посты указанного типа.
	 *
	 * @param string $post_type Тип поста
	 *
	 * @return void
	 */
	public function deleteAll(string $post_type): void
	{
		foreach ($this->getIds($post_type) as $id) {
			$this->delete((int) $id);
		}
	}

	/**
	 * Создаёт новый пост.
	 *
	 * @param array $data Данные поста (post_title, post_content, post_type и т.д.)
	 *
	 * @return int ID созданного поста или 0 при ошибке
	 */
	public function insert(array $data): int
	{
		$id = wp_insert_post($data);

		// При ошибке wp_insert_post возвращает WP_Error
		return is_wp_error($id) ? 0 : (int) $id;
	}

	/**
	 * Возвращает все мета-поля поста в виде ассоциативного массива.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return array<string, mixed> Массив мета-данных [meta_key => meta_value]
	 */
	public function getAllMeta(int $post_id): array
	{
		$raw = get_post_meta($post_id);
		$result = [];

		foreach ($raw as $key => $_) {
			// get_post_meta с true возвращает одно значение (не массив)
			$result[$key] = get_post_meta($post_id, $key, true);
		}

		return $result;
	}

	/**
	 * Обновляет мета-поле поста.
	 *
	 * @param int    $post_id ID поста
	 * @param string $key     Ключ мета-поля
	 * @param mixed  $value   Значение мета-поля
	 *
	 * @return void
	 */
	public function updateMeta(int $post_id, string $key, mixed $value): void
	{
		update_post_meta($post_id, $key, $value);
	}
}