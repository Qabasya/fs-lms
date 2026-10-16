<?php
/**
 * Журнал гостевой формы записи на экзамен (этап 11a.2.7): открыта, не прошла проверку, бронь создана, лимит.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;

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

	use RequestContextProvider;

	private const MESSAGE_MAX = 200;

	/** Использует общий журнал авторизации для событий публичной формы; открытие формы дополнительно дедуплицируется для журнала «Экзамены». */
	public function __construct(
		private AuthLogWriter $authLog,
		private RateLimitService $rateLimit,
	) {}

	/** Страница формы показана по действующей ссылке школы. */
	public function opened( ExamSourceDTO $source ): void {
		$this->authLog->recordEvent( AuthAction::ExamFormOpened, AuthResult::Success, null, $this->details( $source ) );

		if ( $this->rateLimit->allowExamFormOpenLog( $this->requestContext()->ip, $source->id ) ) {
			ExamLogAction::FormOpened->record( $this->guestEntry( $source ) );
		}
	}

	/** Ошибка проверки формы: какое поле и что увидел гость (без введённых значений). */
	public function invalid( ExamSourceDTO $source, string $field, string $message ): void {
		$this->authLog->recordEvent(
			AuthAction::ExamFormInvalid,
			AuthResult::Failure,
			null,
			$this->details(
				$source,
				array(
					'failure_reason' => $this->clip( $message ),
					'note'           => '' !== $field ? 'поле: ' . $field : '',
				)
			)
		);

		ExamLogAction::FormInvalid->record( $this->guestEntry( $source, 'failure', array_filter( array( 'field' => $field, 'message' => $this->clip( $message ) ) ) ) );
	}

	/** Бронь создана, гость идёт в корзину. */
	public function holdCreated( ExamSourceDTO $source, ExamGuestApplicationDTO $application ): void {
		$this->authLog->recordEvent(
			AuthAction::ExamHoldCreated,
			AuthResult::Success,
			null,
			$this->details(
				$source,
				array(
					'session' => $application->sessionId,
					'note'    => 'заявка №' . $application->id,
				)
			)
		);
	}

	/** Сработал лимит заявок (адрес или источник): гость увидел отказ. */
	public function limited( ExamSourceDTO $source, string $message ): void {
		$this->authLog->recordEvent(
			AuthAction::ExamFormLimit,
			AuthResult::Failure,
			null,
			$this->details(
				$source,
				array(
					'failure_reason' => $this->clip( $message ),
				)
			)
		);

		ExamLogAction::FormLimited->record( $this->guestEntry( $source, 'failure', array( 'message' => $this->clip( $message ) ) ) );
	}

	/**
	 * Запись журнала «Экзамены» о действии гостя на форме: источник — в контексте, значения полей не пишутся.
	 *
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private function guestEntry( ExamSourceDTO $source, string $result = 'success', array $context = array() ): array {
		return array(
			'event_id'    => $source->eventId,
			'actor_type'  => 'guest',
			'actor_label' => 'Гость',
			'result'      => $result,
			'context'     => array_merge( array( 'source_id' => $source->id, 'school' => $source->schoolName ), $context ),
		);
	}

	/**
	 * Собирает контекст источника и дополнительные поля события формы, пропуская пустые значения.
	 *
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function details( ExamSourceDTO $source, array $extra = array() ): array {
		return array_filter(
			array(
				'form'   => 'exam_signup',
				'source' => $source->id,
				'event'  => $source->eventId,
			) + $extra,
			static fn ( $v ): bool => '' !== $v && null !== $v
		);
	}

	/**
	 * Обрезает диагностический текст до разрешённой длины перед записью события формы.
	 */
	private function clip( string $message ): string {
		return mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
	}
}
