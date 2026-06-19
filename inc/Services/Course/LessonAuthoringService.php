<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\PostMetaName;
use Inc\Enums\StepType;
use Inc\Enums\WorkType;
use Inc\Managers\LessonManager;
use Inc\Managers\PostManager;
use Inc\Services\PostTypeResolver;

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
		private readonly PostManager   $posts,
		private readonly LessonManager $lessons,
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
	 */
	public function createTaskDraft( string $subjectKey, string $title ): int {
		return $this->createDraft( PostTypeResolver::tasks( $subjectKey ), $title );
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
	private function createDraft( string $postType, string $title ): int {
		return $this->posts->insert( array(
			'post_title'  => '' !== $title ? $title : 'Черновик',
			'post_type'   => $postType,
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		) );
	}

	/**
	 * Переносит один шаг между уроками (cut → append): вырезает из `steps[]` исходного
	 * урока и добавляет в конец `steps[]` целевого. Контент не дублируется (T1.5.5).
	 * Перенос между разными предметами запрещён (шаг может ссылаться на контент предмета).
	 *
	 * @param int    $sourceLessonId
	 * @param int    $targetLessonId
	 * @param string $stepKey
	 *
	 * @return bool true — перенесён; false — невалидный ввод / урок или шаг не найден.
	 */
	public function moveStep( int $sourceLessonId, int $targetLessonId, string $stepKey ): bool {
		if ( $sourceLessonId === $targetLessonId || $sourceLessonId <= 0 || $targetLessonId <= 0 || '' === $stepKey ) {
			return false;
		}

		$source = $this->lessons->get( $sourceLessonId );
		$target = $this->lessons->get( $targetLessonId );
		if ( null === $source || null === $target || $source->subjectKey !== $target->subjectKey ) {
			return false;
		}

		$moved     = null;
		$remaining = array();
		foreach ( $source->steps as $step ) {
			if ( null === $moved && $step->key === $stepKey ) {
				$moved = $step;
				continue;
			}
			$remaining[] = $step;
		}

		if ( null === $moved ) {
			return false;
		}

		// LessonDTO неизменяем (readonly) — пересобираем с новыми steps[].
		$this->lessons->update( $sourceLessonId, $this->withSteps( $source, $remaining ) );
		$this->lessons->update( $targetLessonId, $this->withSteps( $target, array_merge( $target->steps, array( $moved ) ) ) );

		return true;
	}

	/**
	 * Копия LessonDTO с заменённым списком шагов.
	 *
	 * @param LessonDTO $lesson
	 * @param StepDTO[] $steps
	 *
	 * @return LessonDTO
	 */
	private function withSteps( LessonDTO $lesson, array $steps ): LessonDTO {
		return new LessonDTO(
			id        : $lesson->id,
			subjectKey: $lesson->subjectKey,
			topic     : $lesson->topic,
			steps     : $steps,
			authorId  : $lesson->authorId,
			status    : $lesson->status,
		);
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
