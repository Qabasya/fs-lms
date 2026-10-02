<?php

declare( strict_types=1 );

namespace Inc\Services\Application;

use Inc\DTO\Application\ApplyTrackInputDTO;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Enrollment\ApplyFormEvent;
use Inc\Enums\Enrollment\ApplyFormField;
use Inc\Services\Log\AuthLogWriter;

/**
 * Class ApplyFormTrackingService
 *
 * Что происходило с формой заявки (/lms/apply) до отправки кода — в журнал «Аутентификация».
 * Заявки к этому моменту ещё нет, поэтому записи связывает ID визита, который браузер
 * присылает со всеми событиями (и с запросом отправки кода — там он попадает в запись
 * об OTP и о пропуске без капчи).
 *
 * @package Inc\Services\Application
 *
 * ### Что НЕ пишется
 *
 * Значения полей (ПД ребёнка, пароль). Только имена полей с ошибкой, счётчики
 * и текст ошибки, который увидел пользователь.
 */
readonly class ApplyFormTrackingService {

	private const MESSAGE_MAX = 200;

	public function __construct(
		private AuthLogWriter $authLog,
	) {}

	/**
	 * Журнал: событие формы от браузера.
	 *
	 * @param ApplyTrackInputDTO $input Событие
	 */
	public function track( ApplyTrackInputDTO $input ): void {
		$this->authLog->recordEvent(
			$input->event->auditAction(),
			$this->result( $input ),
			ApplyFormEvent::CaptchaFailed === $input->event ? $input->captcha?->failReason() : null,
			array_filter( array(
				'form'  => 'apply',
				'visit' => $input->visit,
				'note'  => $this->note( $input ),
			) )
		);
	}

	/**
	 * Начало и показ капчи — не неудачи, остальное — «форма не дошла до конца».
	 */
	private function result( ApplyTrackInputDTO $input ): AuthResult {
		return match ( $input->event ) {
			ApplyFormEvent::Started, ApplyFormEvent::CaptchaShown => AuthResult::Success,
			default                                               => AuthResult::Failure,
		};
	}

	private function note( ApplyTrackInputDTO $input ): string {
		$parts = array();

		switch ( $input->event ) {
			case ApplyFormEvent::Started:
				$parts[] = 'через ' . $this->duration( $input->seconds ) . ' после открытия';
				break;

			case ApplyFormEvent::Invalid:
				$labels = array_filter( array_map(
					static fn( string $name ): ?string => ApplyFormField::tryFrom( $name )?->label(),
					$input->fields
				) );
				if ( ! empty( $labels ) ) {
					$parts[] = 'ошибки в полях: ' . implode( ', ', $labels );
				}
				if ( '' !== $input->message ) {
					$parts[] = $input->message;
				}
				$parts[] = $this->filled( $input );
				break;

			case ApplyFormEvent::CaptchaShown:
				$parts[] = 'капча не справилась сама и показала задание — запрос, вероятно, из нетипичной сети';
				break;

			case ApplyFormEvent::CaptchaFailed:
				$parts[] = $input->captcha?->failReason()->label() ?? 'причина не передана';
				$parts[] = $input->captcha?->allowsFallback()
					? 'форма уходит на сервер без токена (смягчённое правило)'
					: 'форма не отправлена';
				break;

			case ApplyFormEvent::SubmitFailed:
				$parts[] = '' !== $input->message ? 'ответ: «' . $input->message . '»' : 'без ответа сервера';
				$parts[] = 'попытка №' . max( 1, $input->submits );
				$parts[] = 'этап: ' . ( 'otp' === $input->stage ? 'код из письма' : 'данные' );
				break;

			case ApplyFormEvent::Left:
				$parts[] = 'через ' . $this->duration( $input->seconds );
				$parts[] = 'этап: ' . ( 'otp' === $input->stage ? 'код из письма' : 'данные' );
				$parts[] = $this->filled( $input );
				$parts[] = $input->submits > 0
					? 'нажимали «Продолжить»: ' . $input->submits . ' раз(а)'
					: '«Продолжить» не нажимали';
				break;
		}

		return implode( ' · ', $parts );
	}

	private function filled( ApplyTrackInputDTO $input ): string {
		return $input->total > 0
			? sprintf( 'заполнено %d из %d полей', $input->filled, $input->total )
			: sprintf( 'заполнено полей: %d', $input->filled );
	}

	private function duration( int $seconds ): string {
		$seconds = max( 0, $seconds );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return $seconds . ' с';
		}

		$minutes = intdiv( $seconds, MINUTE_IN_SECONDS );

		return $minutes < 60
			? sprintf( '%d мин %d с', $minutes, $seconds % MINUTE_IN_SECONDS )
			: sprintf( '%d ч %d мин', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * Текст ошибки из браузера — обрезанный, для журнала.
	 *
	 * @param string $message Текст
	 */
	public function clipMessage( string $message ): string {
		return mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
	}
}
