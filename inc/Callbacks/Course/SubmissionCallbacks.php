<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Wp\MediaManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class SubmissionCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly SubmissionService           $submissionService,
		private readonly PersonRepository            $personRepository,
		private readonly GroupAccessGuard            $guard,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly MediaManager                $media,
		private readonly AssessmentAttemptRepository $attempts,
	) {
		parent::__construct();
	}

	/**
	 * Двухшаговая загрузка файла ответа (Эпик 13, D16): ученик загружает файл
	 * ЗАРАНЕЕ, получает attachment_id и кладёт его в JSON-ответ задачи
	 * (`{"text":…,"files":[id]}`) — эндпоинты ответов остаются JSON, без multipart.
	 *
	 * Два контекста доступа (взаимоисключающие параметры):
	 *  - урок:    group_lesson_id → член группы занятия (`isMemberEver`);
	 *  - экзамен: attempt_id      → СВОЯ попытка (у попытки нет group_lesson_id).
	 * Params: group_lesson_id | attempt_id, $_FILES['answer_file'].
	 */
	public function ajaxUploadAnswerFile(): void {
		Nonce::UploadAnswerFile->verify();

		$person = $this->personRepository->findByWpUserId( get_current_user_id() );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		$attemptId = isset( $_POST['attempt_id'] ) ? $this->sanitizeInt( 'attempt_id' ) : 0;
		if ( $attemptId > 0 ) {
			$attempt = $this->attempts->find( $attemptId );
			if ( ! $attempt || $attempt->studentPersonId !== $person->id ) {
				$this->error( 'Нет доступа к попытке.' );
				return;
			}
		} else {
			$groupLessonId = $this->requireInt( 'group_lesson_id' );
			$row           = $this->groupLessons->find( $groupLessonId );
			if ( ! $row || ! $this->guard->isMemberEver( $row->groupId, $person->id ) ) {
				$this->error( 'Нет доступа к занятию.' );
				return;
			}
		}

		try {
			$attachmentId = $this->media->uploadFromRequest( 'answer_file' );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( array(
			'attachment_id' => $attachmentId,
			'url'           => $this->media->url( $attachmentId ),
			'name'          => get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}",
			'mime'          => get_post_mime_type( $attachmentId ) ?: '',
		) );
	}

	public function ajaxSubmitWork(): void {
		Nonce::SubmitWork->verify();

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$workId        = $this->requireInt( 'work_id' );
		$taskId        = isset( $_POST['task_id'] ) ? $this->sanitizeInt( 'task_id' ) : null;
		$answerText    = $this->sanitizeHtml( 'answer_text' );
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

		$groupLessonId = $this->requireInt( 'group_lesson_id' );

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
