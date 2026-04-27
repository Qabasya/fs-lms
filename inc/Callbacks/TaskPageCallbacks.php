<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;

/**
 * Class TaskPageCallbacks
 *
 * Управляет отображением страницы одного задания на фронтенде.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. Подмена шаблона — заменяет стандартный шаблон темы на кастомный для CPT заданий.
 * 2. Предоставление данных задания — сбор мета-полей и таксономий для отображения в шаблоне.
 * 3. Объединение условий — сбор всех мета-полей с суффиксом '_condition' в единый блок.
 *
 * Для вывода меток задания используются ТОЛЬКО обязательные таксономии (is_required)
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение таксономий репозиторию TaxonomyRepository.
 * Подключается к фильтру 'template_include' для подмены шаблона.
 */
class TaskPageCallbacks extends BaseController {

	/**
	 * Конструктор
	 *
	 * @param TaxonomyRepository $taxonomy_repository репозиторий таксономий
	 **/
	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository
	) {
		parent::__construct();
	}

	/**
	 * Подменяет путь к шаблону для одиночной страницы задания.
	 * Подключается к фильтру 'template_include'.
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string Путь к шаблону плагина или оригинальный путь
	 */
	public function loadTaskFrontendTemplate( string $template ): string {
		// is_singular() — проверяет, отображается ли страница отдельного поста
		if ( is_singular() ) {
			// get_post_type() — возвращает тип поста текущего объекта
			$post_type = get_post_type();

			// str_ends_with() — проверяет окончание строки (PHP 8.0)
			// Проверяем, что это CPT заданий (суффикс '_tasks')
			if ( $post_type && str_ends_with( $post_type, '_tasks' ) ) {
				// FS_LMS_PATH — константа с путём к корню плагина
				$custom_template = FS_LMS_PATH . 'templates/frontend/single-task.php';

				// file_exists() — проверяет существование файла
				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Получает данные задания для отображения в шаблоне.
	 * Вызывается прямо в single-task.php.
	 *
	 * @param int $post_id ID поста задания
	 *
	 * @return array Массив с данными задания (condition, answer, code, taxonomies)
	 */
	public function getTaskData( int $post_id ): array {
		$post_type = get_post_type( $post_id );
		// str_replace() — удаляем суффикс '_tasks', получаем ключ предмета
		$subject_key = str_replace( '_tasks', '', $post_type );

		return array(
			'condition'  => $this->getCombinedCondition( $post_id ),
			'answer'     => get_post_meta( $post_id, '_task_answer', true ),
			'code'       => get_post_meta( $post_id, '_task_code', true ),
			'taxonomies' => $this->getRequiredTaxonomies( $post_id, $subject_key ),
		);
	}

	/**
	 * Возвращает обязательные таксономии с их терминами для задания.
	 *
	 * @param int    $post_id     ID поста задания
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array
	 */
	private function getRequiredTaxonomies( int $post_id, string $subject_key ): array {
		$result = array();

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $dto ) {
			if ( $dto->is_required ) {
				// get_the_terms() — возвращает массив объектов терминов, привязанных к посту
				$result[ $dto->slug ] = get_the_terms( $post_id, $subject_key . '_' . $dto->slug );
			}
		}

		return $result;
	}

	/**
	 * Собирает все мета-поля с суффиксом '_condition' в один блок контента.
	 *
	 * @param int $post_id ID поста задания
	 *
	 * @return string
	 */
	private function getCombinedCondition( int $post_id ): string {
		// get_post_custom() — возвращает все мета-поля поста в виде массива
		$meta            = get_post_custom( $post_id );
		$condition_parts = array();

		if ( ! $meta ) {
			return '';
		}

		// ksort() — сортирует массив по ключам (для правильного порядка condition_1, condition_2...)
		ksort( $meta );
		foreach ( $meta as $key => $values ) {
			// str_contains() — проверяет наличие подстроки '_condition'
			if ( str_contains( $key, '_condition' ) ) {
				// apply_filters( 'the_content' ) — применяет WordPress-фильтры к контенту (обработка шорткодов и т.д.)
				$condition_parts[] = apply_filters( 'the_content', $values[0] );
			}
		}

		// implode() — объединяет части в одну строку
		return implode( '', $condition_parts );
	}
}
