<?php

declare( strict_types=1 );

namespace Inc\Services\Exam\Payment;

use Inc\Enums\Exam\GuestApplicationState;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Shared\CenterContactsService;

/**
 * Статус гостевой записи для блока на странице «Спасибо» (этап 11a.5.6).
 *
 * Показывается **только владельцу заказа**: проверка ключа заказа из адреса. По одному номеру заказа (или телефону) статус не отдаётся —
 * нет ключа или он чужой → пустой список, без намёка на существование заказа. Тексты без рода; кнопок «Я оплатил» и повторной оплаты нет.
 */
class GuestOrderStatusService {

	public function __construct(
		private readonly WooGateway $woo,
		private readonly ExamPaymentLinkRepository $links,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamSessionRepository $sessions,
		private readonly RoomRepository $rooms,
		private readonly CenterContactsService $contacts,
		private readonly ExamTime $time,
	) {}

	/**
	 * @return list<array{state: string, title: string, lines: list<string>, refresh: bool}>
	 */
	public function forOrder( int $orderId, string $orderKey ): array {
		if ( ! $this->woo->orderKeyValid( $orderId, $orderKey ) ) {
			return array();
		}

		$blocks = array();
		foreach ( $this->links->listByOrder( $orderId ) as $link ) {
			$application = $this->applications->find( $link->applicationId );
			if ( null !== $application ) {
				$blocks[] = $this->block( $application->state, $application->sessionId, $orderId );
			}
		}

		return $blocks;
	}

	/** @return array{state: string, title: string, lines: list<string>, refresh: bool} */
	private function block( string $state, int $sessionId, int $orderId ): array {
		$contacts = $this->contacts->get();
		$phone    = '' !== $contacts['phone'] ? sprintf( 'Телефон центра: %s.', $contacts['phone'] ) : '';

		switch ( $state ) {
			case GuestApplicationState::Confirmed->value:
				return array(
					'state'   => $state,
					'title'   => 'Оплата получена. Вы записаны.',
					'lines'   => array_merge( $this->sessionLines( $sessionId ), array( 'Ссылку для входа на экзамен выдаст сотрудник на площадке.' ) ),
					'refresh' => false,
				);
			case GuestApplicationState::PaidNeedsResolution->value:
				return array(
					'state'   => $state,
					'title'   => 'Оплата получена, запись пока не подтверждена. Сотрудник свяжется с вами.',
					'lines'   => array_values( array_filter( array( sprintf( 'Номер заказа: %d.', $orderId ), $phone ) ) ),
					'refresh' => false,
				);
			case GuestApplicationState::ExpiredUnpaid->value:
				return array( 'state' => $state, 'title' => 'Время брони истекло.', 'lines' => array( 'Проверьте оплату, затем выберите дату заново.' ), 'refresh' => false );
			case GuestApplicationState::Failed->value:
			case GuestApplicationState::Cancelled->value:
				return array( 'state' => $state, 'title' => 'Оплата не подтверждена.', 'lines' => array( 'Проверьте оплату, затем выберите дату заново.' ), 'refresh' => false );
			default:
				return array(
					'state'   => $state,
					'title'   => 'Подтверждение оплаты ещё не получено. Не оплачивайте повторно.',
					'lines'   => array(),
					'refresh' => true,
				);
		}
	}

	/** @return list<string> Дата, время, адрес и кабинет сеанса. */
	private function sessionLines( int $sessionId ): array {
		$session = $this->sessions->find( $sessionId );
		if ( null === $session ) {
			return array();
		}

		$start = $this->time->toLocal( $session->scheduledAt );
		$room  = $this->rooms->find( $session->roomId )->name ?? '';

		return array_values( array_filter( array(
			sprintf( '%s, %s', gmdate( 'd.m.Y', (int) strtotime( substr( $start, 0, 10 ) . ' UTC' ) ), substr( $start, 11, 5 ) ),
			trim( $this->contacts->addressWithoutRoom() . ( '' !== $room ? ', каб. ' . $room : '' ) ),
		) ) );
	}
}
