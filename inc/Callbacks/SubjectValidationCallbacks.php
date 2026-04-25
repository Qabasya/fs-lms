<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;

class SubjectValidationCallbacks extends BaseController {
	public function __construct(
		private readonly TaxonomyRepository $taxonomies
	) {
		parent::__construct();
	}
	
	/**
	 * Проверяет наличие обязательных таксономий для заданий предмета.
	 * Если таксономия не заполнена, сбрасывает статус в 'draft' и ставит ошибку в транзиент.
	 *
	 * @param array $data    Очищенные данные поста
	 * @param array $postarr Неочищенные данные из $_POST
	 *
	 * @return array
	 */
	public function validateRequiredTaxonomies( array $data, array $postarr ): array {
		$post_type = $data['post_type'] ?? '';
		
		// Проверяем только наши кастомные типы заданий (формат: {subject}_tasks)
		if ( ! str_ends_with( $post_type, '_tasks' ) ) {
			return $data;
		}
		
		// Проверяем только при попытке публикации
		if ( ! in_array( $data['post_status'], [ 'publish', 'future' ], true ) ) {
			return $data;
		}
		
		// Игнорируем автосохранение
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}
		
		// Извлекаем ключ предмета из типа поста (например, "phys" из "phys_tasks")
		$subject_key = preg_replace( '/_tasks$/', '', $post_type );
		
		// Получаем все таксономии этого предмета
		$subject_taxonomies = $this->taxonomies->getBySubject( $subject_key );
		
		foreach ( $subject_taxonomies as $tax_dto ) {
			if ( ! $tax_dto->is_required ) {
				continue;
			}
			
			// Проверяем наличие значений в $_POST (tax_input)
			$values = array_filter( (array) ( $_POST['tax_input'][ $tax_dto->slug ] ?? [] ) );
			
			if ( empty( $values ) ) {
				$data['post_status'] = 'draft';
				// Сохраняем ошибку для вывода в админке через admin_notices
				set_transient( 'fs_lms_required_tax_error_' . get_current_user_id(), $tax_dto->name, 30 );
				break;
			}
		}
		
		return $data;
	}
}