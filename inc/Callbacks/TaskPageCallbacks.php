<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;

/*
 * Это класс для управления отображение страницы одного задания на фронтенде
 */

class TaskPageCallbacks extends BaseController {
	/**
	 * Подменяет путь к шаблону для одиночной страницы задания.
	 *
	 * @param string $template Путь к текущему шаблону темы.
	 * @return string Пурат к шаблону плагина.
	 */
	public function loadTaskFrontendTemplate( string $template ): string {
		if ( is_singular() ) {
			$post_type = get_post_type();

			// Проверяем, что это CPT заданий (_tasks)
			if ( $post_type && str_ends_with( $post_type, '_tasks' ) ) {
				$custom_template = FS_LMS_PATH . 'templates/frontend/single-task.php';

				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Метод для получения данных задания (мета-поля, таксономии).
	 * Его можно будет вызвать прямо в шаблоне single-task.php
	 * * @param int $post_id
	 *
	 * @return array
	 */
	public function getTaskData( int $post_id ): array {
		$post_type   = get_post_type( $post_id );
		$subject_key = str_replace( '_tasks', '', $post_type );

		return array(
			'condition'  => $this->getCombinedCondition( $post_id ),
			'answer'     => get_post_meta( $post_id, '_task_answer', true ),
			'code'       => get_post_meta( $post_id, '_task_code', true ),
			// TODO: мб вынести выбор таксономий в менеджер в админке
			// Вообще их пока нет, и можно ошибиться в названии слага
			'taxonomies' => array(
				'difficulty' => get_the_terms( $post_id, "{$subject_key}_difficulty" ),
				'year'       => get_the_terms( $post_id, "{$subject_key}_year" ),
				'author'     => get_the_terms( $post_id, "{$subject_key}_author" ),
			),
		);
	}

	/**
	 * Собирает все мета-поля с суффиксом _condition в один блок контента.
	 */
	private function getCombinedCondition( int $post_id ): string {
		$meta            = get_post_custom( $post_id );
		$condition_parts = array();

		if ( ! $meta ) {
			return '';
		}

		// Фильтруем и сортируем ключи (например, condition_1, condition_2)
		ksort( $meta );
		foreach ( $meta as $key => $values ) {
			if ( str_contains( $key, '_condition' ) ) {
				$condition_parts[] = apply_filters( 'the_content', $values[0] );
			}
		}

		return implode( '', $condition_parts );
	}
}
