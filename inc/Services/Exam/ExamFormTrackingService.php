<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Services\Log\AuthLogWriter;

/**
 * Журнал гостевой формы записи на экзамен (этап 11a.2.7): открыта, не прошла проверку, бронь создана, лимит.
 *
 * Механизм тот же, что у формы заявки (`ApplyFormTrackingService`): журнал «Аутентификация» (`AuthLogWriter`), события — в `AuthAction`,
 * параллельной таблицы нет. Форма ведётся без входа, поэтому записи связывает адрес и источник в подробностях.
 *
 * **Что не пишется:** значения полей (ФИО, телефон, мессенджер), ключ ссылки, куки. Только имя поля с ошибкой, номера источника,
 * проведения и сеанса и текст ошибки, который увидел гость.
 */
readonly class ExamFormTrackingService {

	private const MESSAGE_MAX = 200;

	public function __construct(
		private AuthLogWriter $authLog,
	) {}

	/** Страница формы показана по действующей ссылке школы. */
	public function opened( ExamSourceDTO $source ): void {
		$this->authLog->recordEvent( AuthAction::ExamFormOpened, AuthResult::Success, null, $this->details( $source ) );
	}

	/** Ошибка проверки формы: какое поле и что увидел гость (без введённых значений). */
	public function invalid( ExamSourceDTO $source, string $field, string $message ): void {
		$this->authLog->recordEvent( AuthAction::ExamFormInvalid, AuthResult::Failure, null, $this->details( $source, array(
			'failure_reason' => $this->clip( $message ),
			'note'           => '' !== $field ? 'поле: ' . $field : '',
		) ) );
	}

	/** Бронь создана, гость идёт в корзину. */
	public function holdCreated( ExamSourceDTO $source, ExamGuestApplicationDTO $application ): void {
		$this->authLog->recordEvent( AuthAction::ExamHoldCreated, AuthResult::Success, null, $this->details( $source, array(
			'session' => $application->sessionId,
			'note'    => 'заявка №' . $application->id,
		) ) );
	}

	/** Сработал лимит заявок (адрес или источник): гость увидел отказ. */
	public function limited( ExamSourceDTO $source, string $message ): void {
		$this->authLog->recordEvent( AuthAction::ExamFormLimit, AuthResult::Failure, null, $this->details( $source, array(
			'failure_reason' => $this->clip( $message ),
		) ) );
	}

	/**
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function details( ExamSourceDTO $source, array $extra = array() ): array {
		return array_filter( array( 'form' => 'exam_signup', 'source' => $source->id, 'event' => $source->eventId ) + $extra, static fn ( $v ): bool => '' !== $v && null !== $v );
	}

	private function clip( string $message ): string {
		return mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
	}
}
