<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Log;

use Inc\Core\BaseController;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Log\ExamLogWriter;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ExamLogCallbacks
 *
 * Наполнение журнала «Экзамены»:
 *
 * 1. хук {@see ExamLogAction::HOOK} — службы экзаменов сообщают о каждом значимом действии;
 * 2. AJAX `LogExamClientEvent` — технические события станции, которые сервер не видел
 *    (закрыта вкладка, потеряна сессия, не сохранился ответ).
 *
 * @package Inc\Callbacks\Log
 */
class ExamLogCallbacks extends BaseController {

	use Sanitizer;
	use RequestContextProvider;

	/** Виды событий, которые вправе прислать браузер: остальное сервер пишет сам. */
	private const CLIENT_KINDS = array( 'left_page', 'save_failed', 'session_lost', 'network_error' );

	public function __construct(
		private readonly ExamLogWriter                $writer,
		private readonly AssessmentAttemptRepository  $attempts,
		private readonly RateLimitService             $rateLimit,
	) {
		parent::__construct();
	}

	/**
	 * Хук {@see ExamLogAction::HOOK}.
	 *
	 * @param ExamLogAction        $action Действие
	 * @param array<string, mixed> $data   Данные действия
	 */
	public function onAction( ExamLogAction $action, array $data = array() ): void {
		$this->writer->write( $action, $data );
	}

	/**
	 * Техническое событие станции экзамена из браузера.
	 *
	 * **Nonce здесь намеренно нет:** событие нужно именно тогда, когда сессия или nonce уже недействительны
	 * (потеряна сессия — любой nonce устарел), а обработчик ничего не меняет, кроме строки журнала. Защита —
	 * белый список видов, лимит частоты на IP и попытку, проверка, что идущая экзаменная попытка существует;
	 * ответ всегда `success`, чтобы по нему нельзя было перебирать номера попыток.
	 *
	 * Params: attempt_id, kind, detail?
	 */
	public function ajaxLogExamClientEvent(): void {
		$attemptId = $this->sanitizeInt( 'attempt_id' );
		$kind      = $this->sanitizeKey( 'kind' );

		if ( $attemptId <= 0 || ! in_array( $kind, self::CLIENT_KINDS, true ) || ! $this->rateLimit->allowExamClientEvent( $this->requestContext()->ip, $attemptId, $kind ) ) {
			$this->success();
			return;
		}

		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt || ! $attempt->isExam() || AttemptStatus::InProgress !== $attempt->status ) {
			$this->success();
			return;
		}

		ExamLogAction::AttemptTechError->record(
			array_merge(
				array(
					'attempt_id' => $attemptId,
					'result'     => 'failure',
					'context'    => array_filter(
						array(
							'kind'       => $kind,
							'detail'     => mb_substr( $this->sanitizeText( 'detail' ), 0, 200 ),
							'from'       => 'browser',
						)
					),
				),
				get_current_user_id() > 0 ? array() : array( 'actor_type' => 'guest', 'actor_label' => 'Гость' )
			)
		);

		$this->success();
	}
}
