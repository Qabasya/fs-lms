<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;

/**
 * Class SubjectValidationCallbacks
 *
 * Коллбеки для валидации данных предметов и заданий.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Валидация обязательных таксономий** — проверяет, что перед публикацией задания выбраны все обязательные таксономии.
 * 2. **Сброс статуса при ошибке** — если обязательная таксономия не заполнена, статус поста меняется на 'draft'.
 * 3. **Уведомление пользователя** — сохраняет ошибку в транзиент для отображения в админке.
 */
class SubjectValidationCallbacks extends BaseController {
	
	public function __construct(
		private readonly TaxonomyRepository $taxonomies
	) {
		parent::__construct();
	}
	
	// ============================ КОЛЛБЕКИ ВАЛИДАЦИИ ============================ //
	
	/**
	 * Проверяет наличие обязательных таксономий для заданий предмета.
	 * Подключается к фильтру 'wp_insert_post_data'.
	 *
	 * @param array $data    Очищенные данные поста (массив полей)
	 * @param array $postarr Неочищенные данные из $_POST (не используются)
	 *
	 * @return array
	 */
	public function validateRequiredTaxonomies( array $data, array $postarr ): array {
		$post_type = $data['post_type'] ?? '';
		
		// str_ends_with() — проверяет окончание строки (PHP 8.0)
		// Валидация только для кастомных типов постов заданий (суффикс '_tasks')
		if ( ! str_ends_with( $post_type, '_tasks' ) ) {
			return $data;
		}
		
		// Проверка только при попытке публикации (статусы 'publish' или 'future')
		if ( ! in_array( $data['post_status'], [ 'publish', 'future' ], true ) ) {
			return $data;
		}
		
		// DOING_AUTOSAVE — константа WordPress, определяющая выполнение автосохранения
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}
		
		// preg_replace() — регулярное выражение для удаления суффикса '_tasks'
		// Например: 'math_tasks' → 'math'
		$subject_key = preg_replace( '/_tasks$/', '', $post_type );
		
		// Получение всех таксономий предмета из БД
		$subject_taxonomies = $this->taxonomies->getBySubject( $subject_key );
		
		// Проверка каждой обязательной таксономии
		foreach ( $subject_taxonomies as $tax_dto ) {
			if ( ! $tax_dto->is_required ) {
				continue;
			}
			
			// array_filter() — удаляет пустые значения из массива
			// tax_input — стандартный массив WordPress с данными таксономий при сохранении поста
			$values = array_filter( (array) ( $_POST['tax_input'][ $tax_dto->slug ] ?? [] ) );
			
			if ( empty( $values ) ) {
				// Сброс статуса поста в черновик
				$data['post_status'] = 'draft';
				
				// set_transient() — сохраняет временные данные в options таблице
				// get_current_user_id() — ID текущего пользователя (для персональных уведомлений)
				// Третий параметр — срок хранения в секундах (30 сек = достаточно для перезагрузки страницы)
				set_transient( 'fs_lms_required_tax_error_' . get_current_user_id(), $tax_dto->name, 30 );
				break;
			}
		}
		
		return $data;
	}
}