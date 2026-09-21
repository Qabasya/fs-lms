<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskNumberService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskSuggestions
 *
 * Поле выбора задания в элементе «Задание»: поиск опубликованных заданий и подпись выбранного.
 *
 * Отдаёт данные в формате поля `autocomplete` WPBakery — массивы `value` / `label`.
 *
 * Поиск идёт в контексте статьи: только задания её предмета и, если у статьи
 * выбран номер задания, только этого номера — иначе «4000» находил и ОГЭ, и
 * ЕГЭ, и 14000/24000. Сам WPBakery в запрос подсказок ни статью, ни форму не
 * передаёт: ID статьи и номер, выбранный в редакторе прямо сейчас (ещё не
 * сохранённый), дописывает `assets/task-context.js`.
 *
 * @package Inc\Modules\ArticleBlocks\Services
 */
class TaskSuggestions {

	use Sanitizer;

	/** Сколько заданий показывать в подсказках. */
	private const LIMIT = 30;

	/** Параметр запроса подсказок: ID редактируемой статьи. */
	public const POST_PARAM = 'fs_article_post_id';

	/** Параметр запроса подсказок: значения поля «Номер задания» формы статьи. */
	public const NUMBER_PARAM = 'fs_article_task_number';

	public function __construct(
		private readonly PostManager       $posts,
		private readonly SubjectRepository $subjects,
		private readonly TermManager       $terms,
		private readonly TaskNumberService $numbers,
	) {}

	/**
	 * Подсказки по введённой строке: поиск по заголовку («№ 5001. Вариант …»).
	 * Без контекста статьи — во всех предметах с банком.
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

		[ $subjectKey, $taskNumber ] = $this->articleContext();

		foreach ( $this->subjects->readAll() as $subject ) {
			if ( ! $subject->hasBank || ( '' !== $subjectKey && $subject->key !== $subjectKey ) ) {
				continue;
			}

			$opts = array(
				'status'     => 'publish',
				'search'     => $query,
				'limit'      => self::LIMIT,
				'meta_query' => $this->posts->bundleChildExclusion(),
			);

			if ( null !== $taskNumber ) {
				$opts['tax_query'] = array(
					array(
						'taxonomy' => PostTypeResolver::getTaskTaxonomy( $subject->key ),
						'field'    => 'name',
						'terms'    => (string) $taskNumber,
					),
				);
			}

			$posts = $this->posts->search( PostTypeResolver::tasks( $subject->key ), $opts );

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
	 * Предмет и номер задания статьи, в которой открыт элемент.
	 *
	 * Номер — из формы редактора, если скрипт её прислал (параметр есть даже
	 * при пустом выборе: «не выбрано» значит «весь предмет», а не сохранённый
	 * номер); иначе — сохранённый у статьи (фронтенд-редактор WPBakery панели
	 * терминов не показывает).
	 *
	 * @return array{0: string, 1: int|null} Ключ предмета ('' — контекста нет) и номер задания
	 */
	private function articleContext(): array {
		$articleId = $this->sanitizeInt( self::POST_PARAM );
		// get_post( 0 ) отдал бы глобальный пост, а не «ничего».
		$article   = $articleId > 0 ? $this->posts->get( $articleId ) : null;

		if ( ! $article || ! PostTypeResolver::isArticlePostType( $article->post_type ) ) {
			return array( '', null );
		}

		$subjectKey = PostTypeResolver::subjectFromArticlePostType( $article->post_type );

		if ( $this->hasParam( self::NUMBER_PARAM ) ) {
			return array( $subjectKey, $this->numbers->resolveTaskNumber( $this->sanitizeKeyList( self::NUMBER_PARAM ), $subjectKey ) );
		}

		$names = array_map(
			static fn( \WP_Term $term ): string => $term->name,
			$this->terms->getPostTerms( $article->ID, PostTypeResolver::getTaskTaxonomy( $subjectKey ) )
		);

		return array( $subjectKey, $this->numbers->resolveTaskNumber( $names, $subjectKey ) );
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
