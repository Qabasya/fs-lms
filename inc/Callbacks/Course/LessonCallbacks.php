<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Subject\TemplateCategory;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonCallbacks
 *
 * Admin-AJAX обработчики для конструктора бакетов урока.
 *
 * @package Inc\Callbacks\Course
 */
class LessonCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly LessonAuthoringService  $authoringService,
		private readonly LessonManager           $lessonManager,
		private readonly LessonVisibilityService $visibilityService,
	) {
		parent::__construct();
	}

	/**
	 * Список кандидатов-работ для селектора урока.
	 * Params: subject_key, work_type (string), scope (mine|subject), search (string)
	 */
	public function ajaxGetLessonWorkCandidates(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$work_type   = $this->sanitizeKey( 'work_type' );
		$scope       = $this->sanitizeKey( 'scope' );
		$search      = $this->sanitizeText( 'search' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getWorkCandidates( $subject_key, $work_type, $scope, $search )
		);
	}

	/**
	 * Создаёт черновик урока из конструктора курса.
	 * Params: subject_key, title
	 */
	public function ajaxCreateLessonDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новый урок';

		$dto = LessonDTO::fromArray( array(
			'id'          => 0,
			'subject_key' => $subject_key,
			'topic'       => $title,
			'steps'       => array(),
			'author_id'   => get_current_user_id(),
			'status'      => 'draft',
		) );

		try {
			$lesson_id = $this->lessonManager->create( $subject_key, $dto );
			$this->success( array( 'id' => $lesson_id, 'title' => $title ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать урок: ' . $e->getMessage() );
		}
	}

	/**
	 * Статьи предмета для ArticleRefField.
	 * Params: subject_key
	 */
	public function ajaxGetLessonArticles(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$articles    = $this->authoringService->getArticles( $subject_key );

		$result = array();
		foreach ( $articles as $id => $title ) {
			$result[] = array( 'id' => $id, 'title' => $title );
		}

		$this->success( $result );
	}

	/**
	 * Кандидаты для шага-ссылки (модалка билдера).
	 * Params: subject_key, kind (work|task|assessment|article), source (subject|bank), search
	 */
	public function ajaxGetStepCandidates(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$kind        = $this->sanitizeKey( 'kind' );
		$source      = $this->sanitizeKey( 'source' );
		$search      = $this->sanitizeText( 'search' );

		$this->success( $this->authoringService->getStepCandidates( $subject_key, $kind, $source, $search ) );
	}

	/**
	 * Черновик subject-задачи из билдера. Params: subject_key, title, category (question|code)
	 */
	public function ajaxCreateTaskDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новая задача';
		$categoryRaw = $this->sanitizeKey( 'category' );
		$category    = '' !== $categoryRaw ? TemplateCategory::fromValueOrDefault( $categoryRaw ) : null;
		$id          = $this->authoringService->createTaskDraft( $subject_key, $title, $category );

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Черновик контрольной из билдера. Params: subject_key, title
	 */
	public function ajaxCreateAssessmentDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новая контрольная';
		$id          = $this->authoringService->createAssessmentDraft( $subject_key, $title );

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Черновик статьи предмета (материал) из билдера. Params: subject_key, title
	 */
	public function ajaxCreateArticleDraft(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLmsArticles );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новый материал';
		$id          = $this->authoringService->createArticleDraft( $subject_key, $title );

		$this->success( array( 'id' => $id, 'title' => $title ) );
	}

	/**
	 * Сохраняет последовательность шагов урока (билдер).
	 * Params: lesson_id, subject_key, steps[]
	 */
	public function ajaxSaveLessonSteps(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::AuthorLmsCourses );

		$lesson_id   = $this->requireInt( 'lesson_id' );
		$subject_key = $this->requireKey( 'subject_key' );
		$raw_steps   = wp_unslash( $_POST['steps'] ?? array() );
		$raw_steps   = is_array( $raw_steps ) ? $raw_steps : array();

		$lesson = $this->lessonManager->get( $lesson_id );
		if ( null === $lesson ) {
			$this->error( 'Урок не найден.' );
			return;
		}

		$sanitized = array_map( array( $this->authoringService, 'sanitizeStep' ), $raw_steps );
		$steps     = $this->authoringService->buildSteps( $sanitized );

		if ( count( $steps ) > LessonAuthoringService::MAX_STEPS_PER_LESSON ) {
			$this->error( sprintf( 'В одном уроке не может быть больше %d шагов.', LessonAuthoringService::MAX_STEPS_PER_LESSON ) );
			return;
		}

		$dto = new LessonDTO(
			id        : $lesson_id,
			subjectKey: $subject_key,
			topic     : $lesson->topic,
			steps     : $steps,
			authorId  : $lesson->authorId,
			status    : $lesson->status,
		);
		$this->lessonManager->update( $lesson_id, $dto );
		// Уже открытым для групп занятиям — доложить новые работы урока (copy-on-publish).
		$this->visibilityService->syncExtraWorksForOpenOccurrences( $lesson_id );

		$this->success( array( 'count' => count( $steps ) ) );
	}
}
