<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class SubmissionCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly SubmissionService $submissionService,
		private readonly PersonRepository  $personRepository,
		private readonly GroupAccessGuard  $guard,
	) {
		parent::__construct();
	}

	public function ajaxSubmitWork(): void {
		Nonce::SubmitWork->verify();

		$groupLessonId = $this->requireInt( $_POST['group_lesson_id'] ?? '' );
		$workId        = $this->requireInt( $_POST['work_id'] ?? '' );
		$taskId        = isset( $_POST['task_id'] ) ? $this->sanitizeInt( $_POST['task_id'] ) : null;
		$answerText    = $this->sanitizeEditorContent( $_POST['answer_text'] ?? '' );
		$fileKey       = isset( $_FILES['submission_file'] ) ? 'submission_file' : null;

		$userId = get_current_user_id();
		$person = $this->personRepository->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		try {
			$id = $this->submissionService->submit(
				$person->id,
				$groupLessonId,
				$workId,
				$taskId ?: null,
				$answerText,
				$fileKey,
			);
			$this->success( array( 'submission_id' => $id ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxGetMySubmissions(): void {
		Nonce::SubmitWork->verify();

		$groupLessonId = $this->requireInt( $_POST['group_lesson_id'] ?? '' );

		$userId = get_current_user_id();
		$person = $this->personRepository->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		// Проверяем принадлежность к группе через lesson → guard
		$submissions = $this->submissionService->getSubmissionsForView( $person->id, $groupLessonId );
		$this->success( $submissions );
	}
}
