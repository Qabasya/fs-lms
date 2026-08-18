<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Wp\MediaManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class SubmissionCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly PersonRepository            $personRepository,
		private readonly MediaManager                $media,
		private readonly AssessmentAttemptRepository $attempts,
	) {
		parent::__construct();
	}

	/**
	 * Двухшаговая загрузка файла ответа для «Развёрнутого ответа» в контрольных
	 * (Эпик 13, D16): ученик загружает файл ЗАРАНЕЕ, получает attachment_id и
	 * кладёт его в JSON-ответ задачи (`{"text":…,"files":[id]}`) —
	 * save_attempt_answer остаётся JSON-эндпоинтом, без multipart.
	 *
	 * Доступ: СВОЯ попытка (attempt.studentPersonId === person.id).
	 * Params: attempt_id, $_FILES['answer_file'].
	 */
	public function ajaxUploadAnswerFile(): void {
		Nonce::UploadAnswerFile->verify();

		$person = $this->personRepository->findByWpUserId( get_current_user_id() );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		$attemptId = $this->requireInt( 'attempt_id' );
		$attempt   = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $person->id ) {
			$this->error( 'Нет доступа к попытке.' );
			return;
		}

		// Попытка уже сдана/истекла — файл к ответу больше не приложить: он не
		// попадёт ни в один answer_text (autosave отключён тем же гейтом на сервере).
		if ( AttemptStatus::InProgress !== $attempt->status ) {
			$this->error( 'Попытка уже завершена.' );
			return;
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


}
