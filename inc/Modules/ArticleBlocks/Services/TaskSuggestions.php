<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskSuggestions
 *
 * Поле выбора задания в элементе «Задание»: поиск опубликованных заданий и подпись выбранного.
 *
 * Отдаёт данные в формате поля `autocomplete` WPBakery — массивы `value` / `label`.
 *
 * @package Inc\Modules\ArticleBlocks\Services
 */
class TaskSuggestions {

	use Sanitizer;

	/** Сколько заданий показывать в подсказках. */
	private const LIMIT = 30;

	public function __construct(
		private readonly PostManager       $posts,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Подсказки по введённой строке: поиск по заголовку («№ 5001. Вариант …») во всех предметах с банком.
	 *
	 * @param mixed $query Строка из поля (сырое значение запроса WPBakery)
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public function search( mixed $query ): array {
		$query = $this->sanitizeTextValue( $query );

		if ( '' === $query ) {
			return array();
		}

		$found = array();

		foreach ( $this->subjects->readAll() as $subject ) {
			if ( ! $subject->hasBank ) {
				continue;
			}

			$posts = $this->posts->search(
				PostTypeResolver::tasks( $subject->key ),
				array(
					'status'     => 'publish',
					'search'     => $query,
					'limit'      => self::LIMIT,
					'meta_query' => $this->posts->bundleChildExclusion(),
				)
			);

			foreach ( $posts as $post ) {
				$found[] = $this->option( $post, $subject );
			}

			if ( count( $found ) >= self::LIMIT ) {
				break;
			}
		}

		return array_slice( $found, 0, self::LIMIT );
	}

	/**
	 * Подпись уже выбранного задания при открытии формы элемента.
	 *
	 * @param mixed $term `['value' => ID, 'label' => ID]` от WPBakery
	 *
	 * @return array{value: int|string, label: string}|false False — задание удалено или не задание
	 */
	public function label( mixed $term ): array|false {
		$post = $this->publishedTask( $this->sanitizeIntValue( is_array( $term ) ? ( $term['value'] ?? 0 ) : 0 ) );

		if ( null === $post ) {
			// Снятое с публикации задание не прячем: автор должен увидеть, что блок пуст, и заменить его.
			return is_array( $term ) && isset( $term['value'] )
				? array( 'value' => $this->sanitizeIntValue( $term['value'] ), 'label' => 'Задание недоступно (не опубликовано или удалено)' )
				: false;
		}

		$subject = $this->subjects->getByKey( PostTypeResolver::subjectFromTaskPostType( $post->post_type ) );

		return $this->option( $post, $subject );
	}

	/**
	 * Опубликованное задание по ID.
	 *
	 * @param int $postId ID записи
	 *
	 * @return \WP_Post|null
	 */
	public function publishedTask( int $postId ): ?\WP_Post {
		if ( $postId <= 0 ) {
			return null;
		}

		$post = $this->posts->get( $postId );

		if ( ! $post || 'publish' !== $post->post_status || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * @return array{value: int, label: string}
	 */
	private function option( \WP_Post $post, ?SubjectDTO $subject ): array {
		$title = wp_strip_all_tags( get_the_title( $post ) );

		// WPBakery выводит подпись в разметку формы без экранирования.
		return array(
			'value' => $post->ID,
			'label' => esc_html( null !== $subject ? $subject->name . ' · ' . $title : $title ),
		);
	}
}
