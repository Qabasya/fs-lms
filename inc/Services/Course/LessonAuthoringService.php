<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Course\StepType;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Subject\TemplateCategory;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

/**
 * Class LessonAuthoringService
 *
 * Бизнес-логика авторинга урока: кандидаты-работы для селектора, статьи, валидация.
 * Урок ссылается на работы, не на задачи. Доступ к данным — через PostManager.
 *
 * @package Inc\Services\Course
 */
class LessonAuthoringService {

	public function __construct(
		private readonly PostManager      $posts,
		private readonly LessonManager    $lessons,
		private readonly TemplateRegistry $templates,
	) {}

	/**
	 * Кандидаты-работы для селектора урока (только текущий предмет).
	 *
	 * @param string $subjectKey
	 * @param string $workType  '' = все типы
	 * @param string $scope     'mine' | 'subject'
	 * @param string $search
	 * @return array<int, array{id: int, title: string, work_type: string, author: int}>
	 */
	public function getWorkCandidates(
		string $subjectKey,
		string $workType = '',
		string $scope    = 'mine',
		string $search   = ''
	): array {
		$posts = $this->posts->search( PostTypeResolver::works( $subjectKey ), array(
			'limit'  => 50,
			'author' => 'mine' === $scope ? get_current_user_id() : 0,
			'search' => $search,
		) );

		// Фильтр по типу работы — на стороне PHP (тип лежит в сериализованной мете).
		$filter_type = WorkType::tryFrom( $workType );

		$result = array();
		foreach ( $posts as $post ) {
			$meta      = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
			$post_type = is_array( $meta ) ? (string) ( $meta['work_type'] ?? '' ) : '';

			if ( null !== $filter_type && $post_type !== $filter_type->value ) {
				continue;
			}

			$result[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'work_type' => $post_type,
				'author'    => (int) $post->post_author,
			);
		}

		return $result;
	}

	/**
	 * Статьи предмета для ArticleRefField.
	 *
	 * @param string $subjectKey
	 * @return array<int, string> post_id => title
	 */
	public function getArticles( string $subjectKey ): array {
		$result = array();
		foreach ( $this->posts->getAll( PostTypeResolver::articles( $subjectKey ) ) as $post ) {
			$result[ $post->ID ] = $post->post_title;
		}

		return $result;
	}

	/**
	 * Кандидаты для шага-ссылки (модалка «Добавить шаг», T1.5.5).
	 *
	 * @param string $subjectKey
	 * @param string $kind   work|task|assessment|article|lesson
	 * @param string $source subject|bank — источник задачи (для kind=task)
	 * @param string $search
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	public function getStepCandidates( string $subjectKey, string $kind, string $source = 'subject', string $search = '' ): array {
		$post_type = match ( $kind ) {
			'work'       => PostTypeResolver::works( $subjectKey ),
			'assessment' => PostTypeResolver::assessments( $subjectKey ),
			'article'    => PostTypeResolver::articles( $subjectKey ),
			'lesson'     => PostTypeResolver::lessons( $subjectKey ),
			'task'       => 'bank' === $source ? PostTypeResolver::problems() : PostTypeResolver::tasks( $subjectKey ),
			default      => '',
		};

		if ( '' === $post_type ) {
			return array();
		}

		$result = array();
		foreach ( $this->posts->search( $post_type, array( 'limit' => 50, 'search' => $search ) ) as $post ) {
			$result[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			);
		}

		return $result;
	}

	/**
	 * Черновик subject-задачи из билдера (только заголовок; детали/таксономии — при правке).
	 * Зеркалит `WorkAuthoringService::createProblemDraft` для bank-задач.
	 *
	 * @param TemplateCategory|null $category Если задана — задаче проставляется дефолтный
	 *                                        шаблон категории (question/code), чтобы при правке
	 *                                        сразу открылись нужные поля (type-first из шага).
	 */
	public function createTaskDraft( string $subjectKey, string $title, ?TemplateCategory $category = null ): int {
		$id = $this->createDraft( PostTypeResolver::tasks( $subjectKey ), $title );

		if ( $id > 0 && null !== $category ) {
			$template = $this->templates->defaultForCategory( $category );
			if ( null !== $template ) {
				$this->posts->updateMeta( $id, PostMetaName::TemplateType->value, $template->get_id() );
			}
		}

		return $id;
	}

	/**
	 * Черновик контрольной из билдера (только заголовок).
	 */
	public function createAssessmentDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::assessments( $subjectKey ), $title );
	}

	/**
	 * Черновик статьи предмета (материал) из билдера (только заголовок).
	 */
	public function createArticleDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::articles( $subjectKey ), $title );
	}

	/**
	 * Создаёт черновик-пост указанного CPT с одним заголовком.
	 */
	/**
	 * Черновик задачи в глобальном банке задач (fs_lms_problems) для контрольной.
	 * Не привязан к предмету, не появляется в «Задания предмета».
	 */
	public function createPrivateTaskDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::problems(), $title, 'draft' );
	}

	private function createDraft( string $postType, string $title, string $status = 'draft' ): int {
		return $this->posts->insert( array(
			'post_title'  => '' !== $title ? $title : 'Черновик',
			'post_type'   => $postType,
			'post_status' => $status,
			'post_author' => get_current_user_id(),
		) );
	}

	/**
	 * Строит StepDTO[] из сырого (уже санитайзнутого коллбеком) ввода билдера:
	 * валидирует тип, присваивает стабильный `key` (генерирует отсутствующий), сохраняет payload.
	 * Шаги с неизвестным типом отбрасываются.
	 *
	 * @param array<int, mixed> $rawSteps
	 *
	 * @return StepDTO[]
	 */
	public function buildSteps( array $rawSteps ): array {
		$steps = array();
		foreach ( $rawSteps as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$type = StepType::tryFrom( (string) ( $raw['type'] ?? '' ) );
			if ( null === $type ) {
				continue;
			}

			$key     = (string) ( $raw['key'] ?? '' );
			$payload = is_array( $raw['payload'] ?? null ) ? $raw['payload'] : array();

			$steps[] = new StepDTO( '' !== $key ? $key : $this->generateStepKey(), $type, $payload );
		}

		return $steps;
	}

	/**
	 * Стабильный идентификатор шага (без WP-зависимостей; переживает реордер).
	 */
	private function generateStepKey(): string {
		return 's_' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Оставляет только работы нужного предмета.
	 *
	 * @param string $subjectKey
	 * @param int[]  $workIds
	 * @return int[]
	 */
	public function validateWorkIds( string $subjectKey, array $workIds ): array {
		$post_type = PostTypeResolver::works( $subjectKey );

		return array_values( array_filter( $workIds, function ( int $id ) use ( $post_type ): bool {
			$post = $this->posts->get( $id );
			return $post instanceof \WP_Post && $post->post_type === $post_type;
		} ) );
	}
}
