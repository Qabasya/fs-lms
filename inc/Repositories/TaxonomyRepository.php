<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;

/**
 * Class TaxonomyRepository
 * * Управляет кастомными таксономиями, привязанными к предметам.
 * Данные хранятся в формате: [ 'subject_key' => [ 'tax_slug' => [ 'name' => '...' ], ... ] ]
 */
class TaxonomyRepository extends BaseController implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения предметов.
	 *
	 * @var string
	 */

	// Добавить потом константу
	private string $option_name = 'fs_custom_taxonomies';

	/**
	 * Получить все кастомные таксономии всех предметов.
	 */
	public function read_all(): array {
		return get_option( $this->option_name, [] );
	}


	/**
	 * Обновить или создать таксономию для предмета.
	 * Ожидает в $data: ['subject_key' => '...', 'tax_slug' => '...', 'name' => '...']
	 */
	public function update( array $data ): bool {
		$all = $this->read_all();

		$subject_key = $data['subject_key'];
		$tax_slug    = $data['tax_slug'];

		// Инициализируем массив предмета, если его еще нет
		if ( ! isset( $all[ $subject_key ] ) ) {
			$all[ $subject_key ] = [];
		}

		// Сохраняем данные таксономии
		$all[ $subject_key ][ $tax_slug ] = [
			'name' => sanitize_text_field( $data['name'] )
		];

		return update_option( $this->option_name, $all );
	}

	/**
	 * Удалить таксономию предмета.
	 * Ожидает в $data: ['subject_key' => '...', 'tax_slug' => '...']
	 */
	public function delete( array $data ): bool {
		$all = $this->read_all();

		$subject_key = $data['subject_key'];
		$tax_slug    = $data['tax_slug'];

		if ( isset( $all[ $subject_key ][ $tax_slug ] ) ) {
			unset( $all[ $subject_key ][ $tax_slug ] );

			// Если у предмета больше нет кастомных таксономий, чистим и ключ предмета
			if ( empty( $all[ $subject_key ] ) ) {
				unset( $all[ $subject_key ] );
			}

			return update_option( $this->option_name, $all );
		}

		return false;
	}

	/**
	 * Получить таксономии конкретного предмета (хелпер для контроллера).
	 */
	public function get_by_subject( string $subject_key ): array {
		$all = $this->read_all();

		return $all[ $subject_key ] ?? [];
	}
}