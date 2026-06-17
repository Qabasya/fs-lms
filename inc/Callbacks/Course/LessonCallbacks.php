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
			'id'                => 0,
			'subject_key'       => $subject_key,
			'topic'             => $title,
			'theory_html'       => '',
			'theory_article_id' => 0,
			'work_ids'          => array(),
			'author_id'         => get_current_user_id(),
			'status'            => 'draft',
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
}
