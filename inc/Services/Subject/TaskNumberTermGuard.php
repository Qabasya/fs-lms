<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

/**
 * Class TaskNumberTermGuard
 *
 * Валидирует термины фиксированной таксономии "Номера заданий" ({key}_task_number)
 * при добавлении через нативный экран WordPress ("Добавить новый терм").
 * Гарантирует числовой формат названия и единый slug-паттерн "{ключ_предмета}_{номер}",
 * которым TaxonomySeeder засеивает термины при создании предмета.
 *
 * @package Inc\Services\Subject
 */
class TaskNumberTermGuard {

	private const TAXONOMY_SUFFIX = '_task_number';

	/**
	 * Хук 'pre_insert_term' — блокирует создание терма с некорректным названием.
	 *
	 * @param string|\WP_Error $term     Название нового терма (или уже возникшая ошибка).
	 * @param string           $taxonomy Слаг таксономии.
	 *
	 * @return string|\WP_Error
	 */
	public function validateInsert( string|\WP_Error $term, string $taxonomy ): string|\WP_Error {
		if ( is_wp_error( $term ) || ! $this->isTaskNumberTaxonomy( $taxonomy ) ) {
			return $term;
		}

		$name = trim( $term );

		if ( ! preg_match( '/^[1-9][0-9]*$/', $name ) ) {
			return new \WP_Error(
				'fs_lms_invalid_task_number',
				'Номер задания должен быть целым положительным числом без букв и лишних символов (например, «5»).'
			);
		}

		if ( term_exists( $name, $taxonomy ) ) {
			return new \WP_Error(
				'fs_lms_duplicate_task_number',
				sprintf( 'Задание №%s уже существует в этой таксономии.', $name )
			);
		}

		return $term;
	}

	/**
	 * Хук 'wp_insert_term_data' — приводит слаг нового терма к паттерну
	 * "{ключ_предмета}_{номер}", которым TaxonomySeeder засеивает термины при создании предмета.
	 *
	 * @param array  $data     Данные терма перед вставкой (name, slug, term_group).
	 * @param string $taxonomy Слаг таксономии.
	 *
	 * @return array
	 */
	public function normalizeSlug( array $data, string $taxonomy ): array {
		if ( ! $this->isTaskNumberTaxonomy( $taxonomy ) ) {
			return $data;
		}

		$name = (string) ( $data['name'] ?? '' );
		if ( '' === $name ) {
			return $data;
		}

		$prefix        = substr( $taxonomy, 0, -strlen( self::TAXONOMY_SUFFIX ) );
		$data['slug']  = sanitize_title( "{$prefix}_{$name}" );

		return $data;
	}

	private function isTaskNumberTaxonomy( string $taxonomy ): bool {
		return str_ends_with( $taxonomy, self::TAXONOMY_SUFFIX );
	}
}
