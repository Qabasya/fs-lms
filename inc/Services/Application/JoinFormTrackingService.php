<?php

declare( strict_types=1 );

namespace Inc\Services\Application;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\JoinTrackInputDTO;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Enrollment\JoinFormEvent;
use Inc\Enums\Enrollment\JoinFormField;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Security\VisitorClassifier;

/**
 * Class JoinFormTrackingService
 *
 * Что происходило с формой родителя (/lms/join/{code}) после открытия — в журнал
 * «Зачисления» к заявке.
 *
 * @package Inc\Services\Application
 *
 * ### Визит
 *
 * Открытие страницы получает короткий ID визита; его же браузер присылает со всеми
 * событиями формы. Так в журнале видно, какие события относятся к одному открытию,
 * а открытия без событий (превью мессенджеров, почтовые сканеры) — отличимы.
 *
 * ### Что НЕ пишется
 *
 * Значения полей: там паспортные данные. Только имена полей с ошибкой, счётчики
 * и текст ошибки, который увидел родитель.
 */
readonly class JoinFormTrackingService {

	private const MESSAGE_MAX = 200;

	public function __construct(
		private ApplicationRepository       $applications,
		private JoinCodeService             $joinCodes,
		private LogEventDispatcherInterface $logEvents,
		private VisitorClassifier           $visitors,
	) {}

	/**
	 * Новый ID визита для открытия страницы.
	 */
	public function newVisit(): string {
		return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
	}

	/**
	 * Журнал: открытие JOIN-ссылки — кто открыл (превью-бот или устройство человека).
	 *
	 * @param int    $applicationId ID заявки
	 * @param string $visit         ID визита
	 * @param string $userAgent     User-Agent запроса
	 */
	public function recordOpened( int $applicationId, string $visit, string $userAgent ): void {
		$bot  = $this->visitors->previewBot( $userAgent );
		$note = null !== $bot
			? 'Превью/проверка ссылки: ' . $bot . ' — не человек'
			: $this->visitors->device( $userAgent );

		$this->dispatch( AuditAction::ViewJoinLink, $applicationId, $visit, $note );
	}

	/**
	 * Журнал: событие формы от браузера. Неизвестный или закрытый код — молча игнорируется.
	 *
	 * @param JoinTrackInputDTO $input Событие
	 */
	public function track( JoinTrackInputDTO $input ): void {
		$app = $this->applications->findByJoinCodeHash( $this->joinCodes->hash( $input->joinCode ) );
		if ( null === $app || ApplicationStatus::PendingParent !== $app->status ) {
			return;
		}

		$this->dispatch( $input->event->auditAction(), $app->id, $input->visit, $this->note( $input ) );
	}

	private function note( JoinTrackInputDTO $input ): string {
		$parts = array();

		switch ( $input->event ) {
			case JoinFormEvent::Started:
				$parts[] = 'через ' . $this->duration( $input->seconds ) . ' после открытия';
				break;

			case JoinFormEvent::Invalid:
				$labels = array_filter( array_map(
					static fn( string $name ): ?string => JoinFormField::tryFrom( $name )?->label(),
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

			case JoinFormEvent::SubmitFailed:
				$parts[] = '' !== $input->message ? 'ответ: «' . $input->message . '»' : 'без ответа сервера';
				$parts[] = 'попытка отправки №' . max( 1, $input->submits );
				break;

			case JoinFormEvent::Left:
				$parts[] = 'через ' . $this->duration( $input->seconds );
				$parts[] = $this->filled( $input );
				$parts[] = $input->submits > 0
					? 'нажимали «Отправить»: ' . $input->submits . ' раз(а)'
					: '«Отправить» не нажимали';
				break;
		}

		return implode( ' · ', $parts );
	}

	private function filled( JoinTrackInputDTO $input ): string {
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

	private function dispatch( AuditAction $action, int $applicationId, string $visit, string $note ): void {
		$this->logEvents->dispatch(
			LogEvent::ApplicationViewed,
			new ApplicationStatusEvent(
				0,
				$action,
				$applicationId,
				null,
				array(
					'visit' => $visit,
					'note'  => mb_substr( '' !== $visit ? "визит {$visit} · {$note}" : $note, 0, 500 ),
				)
			)
		);
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
