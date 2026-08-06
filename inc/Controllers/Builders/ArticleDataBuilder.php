<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\DTO\Article\ArticlePageDTO;
use Inc\DTO\Task\PostViewDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\PublicCourseService;
use Inc\Services\Subject\ArticleContentService;
use Inc\Services\Subject\ArticleService;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ArticleDataBuilder
 *
 * Строитель данных frontend-страницы статьи (CPT {subject}_articles).
 *
 * Собирает контент с оглавлением, крошки, курсы сайдбара, блок «Читать далее»
 * и навигацию по статьям одного номера задания.
 * Сайдбары общие со страницей задания — данные для них берутся из тех же
 * сервисов, чтобы блоки не разъезжались между страницами.
 *
 * @package Inc\Controllers\Builders
 */
readonly class ArticleDataBuilder {

	/**
	 * @param PostManager           $post_manager       Менеджер записей WordPress.
	 * @param SubjectRepository     $subject_repository Репозиторий предметов.
	 * @param ArticleContentService $content_service    Пост-обработка контента статьи.
	 * @param ArticleService        $article_service    Статьи предмета.
	 * @param PublicCourseService   $course_service     Курсы предмета для сайдбара.
	 * @param BreadcrumbsBuilder    $breadcrumbs        Строитель хлебных крошек.
	 */
	public function __construct(
		private PostManager $post_manager,
		private SubjectRepository $subject_repository,
		private ArticleContentService $content_service,
		private ArticleService $article_service,
		private PublicCourseService $course_service,
		private BreadcrumbsBuilder $breadcrumbs,
	) {}

	/**
	 * Возвращает данные статьи для frontend-шаблона.
	 *
	 * @param int $post_id ID записи статьи.
	 *
	 * @return ArticlePageDTO
	 */
	public function getArticleData( int $post_id ): ArticlePageDTO {
		$post = $this->post_manager->get( $post_id );

		if ( ! $post || ! PostTypeResolver::isArticlePostType( $post->post_type ) ) {
			return ArticlePageDTO::empty();
		}

		$subject_key  = PostTypeResolver::subjectFromArticlePostType( $post->post_type );
		$subject      = $this->subject_repository->getByKey( $subject_key );
		$subject_name = $subject ? $subject->name : $subject_key;
		$post_view    = PostViewDTO::normalizePost( $post );

		if ( ! $post_view ) {
			return ArticlePageDTO::empty();
		}

		// Архив статей — он же «Учебник» в крошках и центр блока навигации.
		$textbook_url = $this->post_manager->getArchiveLink( PostTypeResolver::articles( $subject_key ) );

		return new ArticlePageDTO(
			post:        $post_view,
			subject_key: $subject_key,
			content:     $this->content_service->build( $post->post_content ),
			breadcrumbs: $this->breadcrumbs->forArticle(
				$subject_name,
				$this->post_manager->getArchiveLink( PostTypeResolver::tasks( $subject_key ) ),
				$textbook_url,
				$post_view->title
			),
			courses:     $this->course_service->getSidebarCourses( $subject_key ),
			recommended: $this->buildRecommended( $subject_key, $post_view->id ),
			navigation:  $this->article_service->getNavigation( $subject_key, $post_view->id, $textbook_url ),
		);
	}

	/**
	 * Статьи блока «Читать далее» — свежие статьи предмета, кроме текущей.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param int    $current_id  ID открытой статьи.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildRecommended( string $subject_key, int $current_id ): array {
		$articles = $this->article_service->getLatestArticles( $subject_key );

		return array_values(
			array_filter(
				$articles,
				static fn( array $article ): bool => (int) $article['id'] !== $current_id
			)
		);
	}
}