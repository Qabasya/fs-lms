<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\GuestEntryViewService;
use Inc\Services\Exam\GuestPageResponder;
use Inc\Services\Exam\GuestResultSidebarService;
use Inc\Services\Exam\GuestResultViewService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Exam\GuestPageAccessService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Страница входа гостя на экзамен (этап 11b.1): ключ из ссылки сотрудника → кука → адрес без ключа.
 *
 * Гость **не становится пользователем WordPress**: личность — только гостевая сессия. Недействительный, истёкший, отозванный ключ
 * и ключ другого назначения — обычная 404 без намёков на существование записи; неудачи считаются в общий лимит IP (11a.2.5).
 */
class GuestEntryCallbacks extends BaseController {

	use RequestContextProvider;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly GuestSessionService $sessions,
		private readonly GuestEntryViewService $view,
		private readonly GuestResultViewService $result,
		private readonly GuestPageResponder $responder,
		private readonly GuestResultSidebarService $sidebar,
		private readonly GuestPageAccessService $access,
	) {
		parent::__construct();
	}

	/** Обмен ключа на куку с редиректом на адрес без ключа либо проверка куки. */
	public function handleEntryPage(): GuestPageOutcome {
		return $this->access->entryPage( strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) ), $this->requestContext()->ip );
	}

	/** Шорткод страницы входа: HTML для сессии из куки; без сессии — пусто (страница уже отдала 404). */
	public function renderEntryPage(): string {
		$ctx  = $this->sessions->current();
		$data = null !== $ctx ? $this->view->build( $ctx ) : null;
		if ( null === $data ) {
			return '';
		}

		ob_start();
		$this->render( 'frontend/exam-entry', $data );

		return (string) ob_get_clean();
	}

	/**
	 * Страница результата: ключ результата → сессия `result` и редирект без ключа; иначе — участие из гостевой сессии
	 * (вход или результат). Попытка из адреса не читается; до сдачи, без сессии, после завершения сеанса — 404.
	 */
	public function handleResultPage(): GuestPageOutcome {
		return $this->access->resultPage( strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) ), $this->requestContext()->ip );
	}

	/** Шорткод страницы результата. */
	public function renderResultPage(): string {
		$participationId = $this->sessions->viewableParticipationId();
		$data            = null !== $participationId ? $this->result->build( $participationId ) : null;
		if ( null === $data || true !== $data['revealed'] ) {
			return '';
		}

		$data['can_end_session'] = true;
		$data['sidebar']         = $this->sidebar->build( (string) ( $data['subject_key'] ?? '' ), (array) ( $data['units'] ?? array() ), $participationId );
		$data['crumbs']          = array(
			array( 'label' => __( 'Главная', 'fs-lms' ), 'url' => home_url( '/' ) ),
			array( 'label' => __( 'Результат экзамена', 'fs-lms' ), 'current' => true ),
		);

		ob_start();
		$this->render( 'frontend/exam-result', $data );

		return (string) ob_get_clean();
	}

	/**
	 * «Завершить сеанс» (публичный экшен, nonce `ExamGuest`): отзыв сессий и удаление кук. Пока работа не сдана — отказ:
	 * выйти из экзамена можно, только сдав работу.
	 */
	public function ajaxEndExamGuestSession(): void {
		Nonce::ExamGuest->verify();

		$participationId = $this->sessions->viewableParticipationId();
		$data            = null !== $participationId ? $this->result->build( $participationId ) : null;
		if ( null === $data || true !== $data['revealed'] ) {
			$this->error( 'Завершить сеанс можно после сдачи работы.' );
			return;
		}

		foreach ( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) as $name ) {
			$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) : '';
			if ( '' !== $value ) {
				$this->sessions->revoke( $value );
			}
		}
		$this->responder->clearCookies( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) );

		$this->success( array( 'url' => home_url( '/' ) ) );
	}




}
