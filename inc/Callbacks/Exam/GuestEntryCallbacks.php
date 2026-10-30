<?php
/**
 * AJAX завершения гостевого сеанса после сдачи экзамена.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\GuestPageResponder;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\GuestSessionService;
use Inc\Shared\Traits\Sanitizer;

/** AJAX завершения гостевого сеанса после сдачи экзамена. */
class GuestEntryCallbacks extends BaseController {

	use Sanitizer;

	/** Получает отзыв сессии и проверку раскрытия результата отдельно от удаления HTTP-кук. */
	public function __construct(
		private readonly GuestSessionService $sessions,
		private readonly ExamReviewProjection $reviews,
		private readonly GuestPageResponder $responder,
	) {
		parent::__construct();
	}

	/**
	 * «Завершить сеанс» (публичный экшен, nonce `ExamGuest`): отзыв сессий и удаление кук. Пока работа не сдана — отказ:
	 * выйти из экзамена можно, только сдав работу.
	 */
	public function ajaxEndExamGuestSession(): void {
		Nonce::ExamGuest->verify();

		$participationId = $this->sessions->viewableParticipationId();
		if ( null === $participationId || ! $this->reviews->isParticipationSubmitted( $participationId ) ) {
			$this->error( 'Завершить сеанс можно после сдачи работы.' );
			return;
		}

		foreach ( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) as $name ) {
			$value = $this->sanitizeCookieText( $name );
			if ( '' !== $value ) {
				$this->sessions->revoke( $value );
			}
		}
		$this->responder->clearCookies( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) );

		$this->success( array( 'url' => home_url( '/' ) ) );
	}
}
