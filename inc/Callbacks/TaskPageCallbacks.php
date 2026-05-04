<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\TaxonomyRepository;
use Inc\Repositories\SubjectRepository;

class TaskPageCallbacks extends BaseController {

	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository,
		private readonly SubjectRepository $subject_repository,
		private readonly PostManager $post_manager,
		private readonly TermManager $term_manager,
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
		if ( is_singular() ) {
			$post_type = get_post_type();

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
	 * Получает данные задания для отображения во frontend-шаблоне.
	 * Вызывается прямо в single-task.php.
	 *
	 * @param int $post_id ID записи задания.
	 *
	 * @return array Массив данных страницы задания.
	 */
	public function getTaskData( int $post_id ): array {
		$post        = $this->post_manager->get( $post_id );

		if ( ! $post || ! str_ends_with( $post->post_type, '_tasks' ) ) {
			return $this->emptyTaskData();
		}

		$subject_key = substr( $post->post_type, 0, -strlen( '_tasks' ) );
		$meta        = $this->post_manager->getMeta( $post_id, 'fs_lms_meta' );
		$meta        = is_array( $meta ) ? $meta : array();

		$subject = $this->subject_repository->getByKey( $subject_key );
		$current_task_type = $this->getCurrentTaskType( $post_id, $subject_key );

		return $this->buildTaskData( $post, $subject_key, $meta, $subject, $current_task_type );
	}

	/**
	 * Возвращает пустую структуру данных страницы задания.
	 * Используется, если запись не найдена или не является заданием.
	 *
	 * @return array Пустой массив данных страницы задания.
	 */
	private function emptyTaskData(): array {
		return $this->buildTaskData();
	}

	/**
	 * Возвращает тип задания, привязанный к текущей записи.
	 *
	 * @param int $post_id
	 * @param string $subject_key
	 *
	 * @return \WP_Term|null
	 */
	private function getCurrentTaskType( int $post_id, string $subject_key ): ?\WP_Term {
		$taxonomy = "{$subject_key}_task_number";
		$terms = $this->term_manager->getPostTerms( $post_id, $taxonomy );

		return $terms[0] ?? null;
	}

	/**
	 * Собирает единую структуру данных страницы задания.
	 *
	 * @param \WP_Post|null $post
	 * @param string $subject_key
	 * @param array $meta
	 * @param mixed|null $subject
	 * @param \WP_Term|null $current_task_type
	 *
	 * @return array Массив данных страницы задания.
	 */

	private function buildTaskData(
		?\WP_Post $post = null,
		string $subject_key = '',
		array $meta = array(),
		mixed $subject = null,
		?\WP_Term $current_task_type = null
	): array {
		$post_id = $post ? (int) $post->ID : 0;
		$subject_label = $subject ? $subject->name : $subject_key;

		return array(
			// Данные конкретного задания как записи WordPress.
			'post'          => array(
				'id'        => $post_id,
				'title'     => $post ? get_the_title( $post_id ) : '',
				'slug'      => $post ? $post->post_name : '',
				'post_type' => $post ? $post->post_type : '',
				'url'       => $post ? get_permalink( $post_id ) : '',
			),

			// Данные предмета, которому принадлежит задание.
			'subject'       => array(
				'key'       => $subject_key,
				'name'      => $subject_label,
			),

			// Основное содержимое задания из fs_lms_meta.
			'content'       => array(
				'condition' => $this->getCombinedCondition( $meta ),
				'answer'    => $meta['task_answer'] ?? '',
				'code'      => $meta['task_code'] ?? '',
			),

			//Файлы задания.
			'files'  => $this->getTaskFiles( $meta ),

			// Теги и метрики задания.
			'tags'   => $this->getTaskTags( $post_id, $subject_key, $current_task_type ),

			// Статьи для страницы задания: связанные слева и случайные снизу.
			'articles'      => array(
				// Связынные
				'related'   => $this->getRelatedArticles( $subject_key, $current_task_type ),
				// Случайные
				'random'    => $this->getRandomArticles( $subject_key ),
			),

			// Данные для хлебных крошек и верхней навигации.
			'navigation'        => array(
				'breadcrumbs'   => array(
					// Предмет текущего задания.
					'subject'   => array(
						'label' => $subject_label,
						'url'   => '',
					),

					// Ссылка на тренажер.
					'trainer'   => array(
						'label' => 'Тренажер',
						'url'   => '',
					),

					// Ссылка на тренажер с фильтром по текущему типу задания.
					'task_type' => $current_task_type ? array(
						'id'    => $current_task_type->term_id,
						'label' => $current_task_type->name . ' задание',
						'slug'  => $current_task_type->slug,
						'url'   => '',
					) : null,

					// Текущее конкретное задание.
					'task'      => array(
						'label' => $post ? '№ ' . $post->post_name : '',
						'url'   => $post ? get_permalink( $post_id ) : '',
					),
				)
			)
		);
	}

	/**
	 * Возвращает файлы задания из мета-данных.
	 *
	 * @param array $meta
	 *
	 * @return array Список файлов в формате name/url.
	 */
	private function getTaskFiles( array $meta ): array {
		$file_keys = array(
			'file',
			'file_primary',
			'file_secondary',
		);

		$files = array();

		foreach ( $file_keys as $key ) {
			$url = $meta[ $key ] ?? '';

			if (! is_string($url) || '' === $url) {
				continue;
			}

			$files[] = array(
				'name'  => $this->getFileNameFromUrl( $url ),
				'url'   => $url,
			);

			if ( count( $files ) === 2 ) {
				break;
			}
		}

		return $files;
	}

	/**
	 * Получает имя файла из URL.
	 *
	 * @param string $url
	 *
	 * @return string Имя файла для текста ссылки
	 */
	private function getFileNameFromUrl( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || $path === '' ) {
			return $url;
		}

		$file_name = wp_basename( $path );

		return $file_name !== '' ? $file_name : $url;
	}

	/**
	 * Возращает теги и метрики задания.
	 *
	 * @param int $post_id
	 * @param string $subject_key
	 * @param \WP_Term|null $current_task_type
	 *
	 * @return array Список тегов задания.
	 */
	private function getTaskTags( int $post_id, string $subject_key, ?\WP_Term $current_task_type ): array {
		$tags = array();

		if ( $current_task_type ) {
			$tags[] = array(
				'type'      => 'task_type',
				'label'     => 'Задание №' . $current_task_type->name,
				'taxonomy'  => $current_task_type->taxonomy,
				'term_id'   => $current_task_type->term_id,
				'slug'      => $current_task_type->slug,
				'url'       => '',
			);
		}

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $taxonomy_dto) {
			$terms = $this->term_manager->getPostTerms( $post_id, $taxonomy_dto->slug );

			foreach ( $terms as $term ) {
				$tags[] = array(
					'type'          => 'taxonomy',
					'taxonomy'      => $taxonomy_dto->slug,
					'taxonomy_name' => $taxonomy_dto->name,
					'label'         => $term->name,
					'term_id'       => $term->term_id,
					'slug'          => $term->slug,
					'url'           => '',
				);
			}
		}

		return $tags;
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Возвращает статьи текущего предмета, связанные с типом задания.
	 *
	 * @param string $subject_key
	 * @param \WP_Term|null $current_task_type
	 *
	 * @return array Список связанных статей.
	 */
	private function getRelatedArticles( string $subject_key, ?\WP_Term $current_task_type ): array {
		if ( $subject_key === '' || ! $current_task_type ) {
			return array();
		}

		$query = new \WP_Query( array(
			'post_type'      => "{$subject_key}_articles",
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => $current_task_type->taxonomy,
					'field'     => 'term_id',
					'terms'     => $current_task_type->term_id,
				),
			),
		));

		return $this->formatArticlePosts( $query->posts );
	}


	/**
	 * Возвращает рандомный список статей
	 *
	 * @param string $subject_key
	 *
	 * @return array Список рандомных статей
	 */
	private function getRandomArticles( string $subject_key ): array {
		if ( $subject_key === '' ) {
			return array();
		}

		$query = new \WP_Query( array(
			'post_type'      => "{$subject_key}_articles",
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'no_found_rows'  => true,
			'orderby'        => 'rand',
		));

		return $this->formatArticlePosts( $query->posts );
	}

	/**
	 * Приводит записи статей к единому формату для шаблона.
	 *
	 * @param array $posts
	 *
	 * @return array Список статей.
	 */
	private function formatArticlePosts( array $posts ): array {
		$articles = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$articles[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post->ID ),
				'url'       => get_permalink( $post->ID ),
				'excerpt'   => $this->getArticleExcerpt( $post ),
			);
		}

		return $articles;
	}

	private function getArticleExcerpt( \WP_Post $post ): string {
		$excerpt = has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : $post->post_content;

		$excerpt = wp_strip_all_tags( $excerpt );

		return wp_trim_words( $excerpt, 24, '...' );
	}

	/**
	 * Собирает все поля с суффиксом '_condition' из fs_lms_meta в один блок контента.
	 *
	 * @param array $meta Массив мета-полей из fs_lms_meta
	 *
	 * @return string
	 */
	private function getCombinedCondition( array $meta ): string {
		if ( empty( $meta ) ) {
			return '';
		}

		ksort( $meta );
		$condition_parts = array();

		foreach ( $meta as $key => $value ) {
			if ( str_contains( $key, '_condition' ) ) {
				$condition_parts[] = apply_filters( 'the_content', $value );
			}
		}

		return implode( '', $condition_parts );
	}
}
