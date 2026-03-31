<?php

namespace Inc\Managers;

/**
 * Class TaxonomyManager
 *
 * Низкоуровневый менеджер регистрации таксономий.
 *
 * Инкапсулирует вызовы WordPress API (register_taxonomy).
 * Не содержит логики выбора предметов или типов записей,
 * только техническую реализацию регистрации через хук init.
 *
 * @package Inc\Managers
 */
class TaxonomyManager
{
	/**
	 * Регистрирует накопленные конфигурации таксономий.
	 *
	 * Принимает массив конфигураций таксономий и регистрирует их
	 * в WordPress через хук init. Если массив пуст — регистрация не выполняется.
	 *
	 * @param array<string, array{
	 *     post_types: string|array<int, string>,
	 *     args: array<string, mixed>
	 * }> $taxonomies Массив конфигураций, где ключ — слаг таксономии,
	 *                значение содержит:
	 *                - post_types: тип(ы) постов, к которым привязать таксономию
	 *                - args: аргументы для register_taxonomy()
	 *
	 * @return void
	 */
	public function register(array $taxonomies): void
	{
		// Если нет таксономий для регистрации — выходим
		if (empty($taxonomies)) {
			return;
		}

		// Регистрируем таксономии на хуке init (как и CPT)
		add_action('init', function () use ($taxonomies) {
			foreach ($taxonomies as $slug => $data) {
				// Регистрируем каждую таксономию через WordPress API
				register_taxonomy(
					$slug,                      // Слаг таксономии (уникальный идентификатор)
					$data['post_types'],        // Тип(ы) постов для привязки
					$data['args']               // Аргументы конфигурации таксономии
				);
			}
		});
	}
}