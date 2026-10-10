<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamPaymentState;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;

/**
 * Гостевая часть доски сеанса (этап 8.8.1): оплата строки гостя и брони без записи («Место удерживается до …»).
 *
 * У гостя четыре независимых состояния — оплата, запись, допуск, попытка; здесь считается только оплата и брони, остальное доска берёт из участия.
 * Только чтение. Контактов в данных нет.
 */
class ExamGuestBoardService {

	public const ACTION_COPY_PAY_LINK = 'copy_pay_link';

	/** Подписи оплаты для сотрудника (ожидание оплаты — не «ожидание» вообще). */
	private const PAYMENT_LABELS = array(
		'pending'   => 'Ожидает оплаты',
		'paid'      => 'Оплачено',
		'failed'    => 'Оплата не прошла',
		'cancelled' => 'Оплата отменена',
	);

	public function __construct(
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamPaymentLinkRepository $links,
		private readonly GuestParticipantMaterializer $guestData,
		private readonly ExamTime $time,
	) {}

	/**
	 * Состояние оплаты участия: по связи заказа заявки. Нет заявки или связи (участие создано без оплаты) — null: пилюля «—».
	 *
	 * @return array{state: string, label: string}|null
	 */
	public function paymentOf( int $participationId ): ?array {
		$application = $this->applications->findByParticipation( $participationId );
		$link        = null !== $application ? ( $this->links->findByApplication( $application->id )[0] ?? null ) : null;
		if ( null === $link ) {
			return null;
		}

		$state = ExamPaymentState::tryFrom( $link->paymentState )?->value ?? $link->paymentState;

		return array( 'state' => $state, 'label' => self::PAYMENT_LABELS[ $state ] ?? $state );
	}

	/**
	 * Гости с действующей бронью без записи: строка «Место удерживается до {время}» и действие «Скопировать ссылку на оплату».
	 * Идут отдельным списком, а не строками доски: у них нет записи, попытки и результата, и выгрузки их не считают.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function holdsOf( ExamSessionDTO $session ): array {
		$nowUtc = $this->time->nowUtc();
		$holds  = array();
		foreach ( $this->applications->listHeldBySession( $session->id ) as $application ) {
			if ( null === $application->holdExpiresAt || $application->holdExpiresAt <= $nowUtc ) {
				continue;
			}

			$name    = $this->guestData->draftName( $application );
			$holds[] = array(
				'application_id' => $application->id,
				'name'           => '' !== $name ? $name : 'Гость',
				'hold_until'     => substr( $this->time->toLocal( $application->holdExpiresAt ), 11, 5 ),
				'seconds_left'   => max( 0, $this->time->secondsUntil( $nowUtc, $application->holdExpiresAt ) ),
				'on_site'        => null !== $application->createdByUserId,
				'actions'        => array( self::ACTION_COPY_PAY_LINK ),
			);
		}

		return $holds;
	}
}
