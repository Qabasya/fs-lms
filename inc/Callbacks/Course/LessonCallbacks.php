<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Managers\LessonManager;
use Inc\Services\Course\LessonAuthoringService;
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
		private readonly LessonAuthoringService $authoringService,
		private readonly LessonManager          $lessonManager,
	) {
		parent::__construct();
	}

	/**
	 * Список кандидатов-работ для селектора урока.
	 * Params: subject_key, work_type (string), scope (mine|subject), search (string)
	 */
	public function ajaxGetLessonWorkCandidates(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$work_type   = $this->sanitizeKey( $_POST['work_type'] ?? '' );
		$scope       = $this->sanitizeKey( $_POST['scope'] ?? 'mine' );
		$search      = $this->sanitizeText( $_POST['search'] ?? '' );

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
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$title       = $this->sanitizeText( $_POST['title'] ?? '' ) ?: 'Новый урок';

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
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
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
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$kind        = $this->sanitizeKey( $_POST['kind'] ?? '' );
		$source      = $this->sanitizeKey( $_POST['source'] ?? 'subject' );
		$search      = $this->sanitizeText( $_POST['search'] ?? '' );

		$this->success( $this->authoringService->getStepCandidates( $subject_key, $kind, $source, $search ) );
	}

	/**
	 * Сохраняет последовательность шагов урока (билдер).
	 * Params: lesson_id, subject_key, steps[]
	 */
	public function ajaxSaveLessonSteps(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$lesson_id   = $this->requireInt( $_POST['lesson_id'] ?? 0 );
		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$raw_steps   = wp_unslash( $_POST['steps'] ?? array() );
		$raw_steps   = is_array( $raw_steps ) ? $raw_steps : array();

		$lesson = $this->lessonManager->get( $lesson_id );
		if ( null === $lesson ) {
			$this->error( 'Урок не найден.' );
			return;
		}

		$sanitized = array_map( array( $this, 'sanitizeStep' ), $raw_steps );
		$steps     = $this->authoringService->buildSteps( $sanitized );

		$dto = new LessonDTO(
			id        : $lesson_id,
			subjectKey: $subject_key,
			topic     : $lesson->topic,
			steps     : $steps,
			authorId  : $lesson->authorId,
			status    : $lesson->status,
		);
		$this->lessonManager->update( $lesson_id, $dto );

		$this->success( array( 'count' => count( $steps ) ) );
	}

	/**
	 * Санитайз одного сырого шага по типу (поля очищаются trait-методами Sanitizer).
	 *
	 * @param mixed $raw
	 *
	 * @return array<string, mixed>
	 */
	private function sanitizeStep( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$type        = $this->sanitizeKey( $raw['type'] ?? '' );
		$key         = $this->sanitizeKey( $raw['key'] ?? '' );
		$raw_payload = is_array( $raw['payload'] ?? null ) ? $raw['payload'] : array();

		$payload = match ( $type ) {
			'text'               => array( 'content' => $this->sanitizeHtml( $raw_payload['content'] ?? '' ) ),
			'video'              => array( 'url' => $this->sanitizeText( $raw_payload['url'] ?? '' ) ),
			'material'           => array_filter( array(
				'article_id'    => $this->sanitizeInt( $raw_payload['article_id'] ?? 0 ),
				'attachment_id' => $this->sanitizeInt( $raw_payload['attachment_id'] ?? 0 ),
			) ),
			'task'               => array(
				'ref'    => $this->sanitizeInt( $raw_payload['ref'] ?? 0 ),
				'source' => 'bank' === $this->sanitizeKey( $raw_payload['source'] ?? 'subject' ) ? 'bank' : 'subject',
			),
			'work', 'assessment' => array( 'ref' => $this->sanitizeInt( $raw_payload['ref'] ?? 0 ) ),
			default              => array(),
		};

		return array( 'key' => $key, 'type' => $type, 'payload' => $payload );
	}
}
