<?php
/**
 * Журнал гостевой формы записи на экзамен (этап 11a.2.7): открыта, не прошла проверку, бронь создана, лимит.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamFormTrackInputDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Exam\ExamFormEvent;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\FormTrackingText;
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

	use FormTrackingText;
	use RequestContextProvider;

	private const MESSAGE_MAX = 200;

	/** Поля формы записи для журнала: имя в разметке → подпись. В журнал идут имена, не значения. */
	private const FIELD_LABELS = array(
		'last_name'   => 'фамилия',
		'first_name'  => 'имя',
		'middle_name' => 'отчество',
		'phone'       => 'телефон',
		'messenger'   => 'мессенджер',
		'school'      => 'школа',
		'session_id'  => 'дата и время',
		'consents'    => 'согласия',
	);

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
	public function invalid( ExamSourceDTO $source, string $field, string $message, string $visit = '' ): void {
		$this->authLog->recordEvent(
			AuthAction::ExamFormInvalid,
			AuthResult::Failure,
			null,
			$this->details(
				$source,
				array(
					'failure_reason' => $this->clip( $message ),
					'note'           => '' !== $field ? 'поле: ' . $field : '',
					'visit'          => $visit,
				)
			)
		);

		ExamLogAction::FormInvalid->record( $this->guestEntry( $source, 'failure', array_filter( array( 'field' => $field, 'message' => $this->clip( $message ) ) ) ) );
	}

	/**
	 * Защита формы отклонила отправку: причина из {@see \Inc\Services\Security\FormGuardService::rejectionReason()}
	 * или `request_key`, не прошедший проверку формата. Без этой записи отказ виден гостю как «обновите страницу», а в журнале его нет.
	 */
	public function guardRejected( ExamSourceDTO $source, string $reason, string $visit = '' ): void {
		$label = match ( $reason ) {
			'honeypot_filled' => 'Заполнено скрытое поле формы (возможно, автозаполнение браузером)',
			'token_missing'   => 'Защитная метка формы не передана',
			'token_invalid'   => 'Защитная метка формы повреждена',
			'too_fast'        => 'Форма отправлена менее чем через 3 секунды после получения метки',
			'token_expired'   => 'Защитная метка формы устарела (страница открыта больше 4 часов или взята из кеша)',
			'bad_request_key' => 'Ключ операции формы не прошёл проверку формата',
			default           => 'Защита формы отклонила отправку',
		};

		$this->authLog->recordEvent(
			AuthAction::ExamFormSubmitFailed,
			AuthResult::Failure,
			null,
			$this->details( $source, array( 'failure_reason' => $label, 'visit' => $visit, 'note' => 'источник: проверка на сервере' ) )
		);

		ExamLogAction::FormSubmitFailed->record( $this->guestEntry( $source, 'failure', array( 'message' => $label, 'guard_reason' => $reason, 'visit' => $visit ) ) );
	}

	/**
	 * Событие формы от браузера: начал заполнять, не прошёл проверку в браузере, отправка не удалась, ушёл.
	 * Значения полей не приходят и не пишутся.
	 */
	public function track( ExamSourceDTO $source, ExamFormTrackInputDTO $input ): void {
		$note = $this->note( $input );

		$this->authLog->recordEvent(
			$input->event->authAction(),
			$input->event->isFailure() ? AuthResult::Failure : AuthResult::Success,
			null,
			$this->details(
				$source,
				array(
					'visit'          => $input->visit,
					'failure_reason' => ExamFormEvent::SubmitFailed === $input->event ? ( '' !== $input->message ? $input->message : 'Ответ сервера не получен' ) : '',
					'note'           => $note,
				)
			)
		);

		$this->logAction( $source, $input, $note );
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

	/** Запись журнала «Экзамены» о событии формы: пишутся только поля с ошибкой, счётчики и текст, который увидел гость. */
	private function logAction( ExamSourceDTO $source, ExamFormTrackInputDTO $input, string $note ): void {
		$context = array_filter(
			array(
				'visit'   => $input->visit,
				'fields'  => $this->fieldLabels( $input->fields ),
				'message' => $this->clip( $input->message ),
				'note'    => $note,
			)
		);

		$input->event->logAction()->record( $this->guestEntry( $source, $input->event->isFailure() ? 'failure' : 'success', $context ) );
	}

	/** Текст события для раскрывающегося примечания журнала. */
	private function note( ExamFormTrackInputDTO $input ): string {
		$parts = array();

		switch ( $input->event ) {
			case ExamFormEvent::Started:
				$parts[] = 'через ' . $this->duration( $input->seconds ) . ' после открытия';
				break;

			case ExamFormEvent::Invalid:
				$labels = $this->fieldLabels( $input->fields );
				if ( array() !== $labels ) {
					$parts[] = 'ошибки в полях: ' . implode( ', ', $labels );
				}
				if ( '' !== $input->message ) {
					$parts[] = $this->clip( $input->message );
				}
				$parts[] = $this->filledText( $input->filled, $input->total );
				break;

			case ExamFormEvent::SubmitFailed:
				$parts[] = '' !== $input->message ? 'ответ: «' . $this->clip( $input->message ) . '»' : 'без ответа сервера';
				$parts[] = 'попытка №' . max( 1, $input->submits );
				break;

			case ExamFormEvent::Left:
				$parts[] = 'через ' . $this->duration( $input->seconds );
				$parts[] = $this->filledText( $input->filled, $input->total );
				$parts[] = $input->submits > 0 ? 'нажимали «Перейти к оплате»: ' . $input->submits . ' раз(а)' : '«Перейти к оплате» не нажимали';
				break;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Подписи известных полей по именам из браузера; неизвестные имена отбрасываются.
	 *
	 * @param string[] $names
	 *
	 * @return string[]
	 */
	private function fieldLabels( array $names ): array {
		return array_values( array_filter( array_map( static fn ( string $name ): ?string => self::FIELD_LABELS[ $name ] ?? null, $names ) ) );
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
