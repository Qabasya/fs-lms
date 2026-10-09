<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Person\ConsentService;
use Inc\Services\Shared\CenterContactsService;
use Inc\Services\Shared\PluginConfig;

/**
 * Чек-лист готовности к запуску гостевой записи (этап 11a.6.5).
 *
 * Публикация и правка проведения с включённой «Записью гостей» блокируются, пока в списке есть непройденный пункт
 * ({@see self::isReady()}). Фиктивные значения вместо недостающих настроек не подставляются: пункт либо пройден, либо нет.
 * Проведение без гостей чек-листу не подчиняется.
 */
class ExamLaunchChecklist {

	/** Согласия, без которых гостевая форма не работает. */
	private const REQUIRED_CONSENTS = array( 'pd_processing', 'pd_transfer' );

	public function __construct(
		private readonly WooGateway $woo,
		private readonly PluginConfig $config,
		private readonly RoomRepository $rooms,
		private readonly ExamSessionRepository $sessions,
		private readonly ConsentService $consents,
		private readonly CenterContactsService $contacts,
		private readonly ExamNotificationComposer $composer,
		private readonly AssessmentManager $assessments,
		private readonly ExamFormatRegistry $formats,
	) {}

	/**
	 * @return list<array{key: string, label: string, ok: bool, hint: string}>
	 */
	public function check( ?ExamEventDTO $event = null ): array {
		$wooActive = $this->woo->isActive();

		return array(
			$this->item( 'woo', 'WooCommerce активен', $wooActive, 'Включите WooCommerce.' ),
			$this->productItem( $wooActive, $event ),
			$this->roomsItem( $event ),
			$this->contactsItem(),
			$this->consentsItem(),
			$this->limitsItem(),
			$this->item( 'recipients', 'Есть получатели уведомлений об оплатах', array() !== $this->composer->paymentRecipients(), 'Назначьте администратора платформы.' ),
		);
	}

	public function isReady( ?ExamEventDTO $event = null ): bool {
		foreach ( $this->check( $event ) as $item ) {
			if ( ! $item['ok'] ) {
				return false;
			}
		}

		return true;
	}

	/** Непройденные пункты одной строкой — для текста отказа. */
	public function failedSummary( ?ExamEventDTO $event = null ): string {
		$hints = array();
		foreach ( $this->check( $event ) as $item ) {
			if ( ! $item['ok'] ) {
				$hints[] = $item['hint'];
			}
		}

		return implode( ' ', $hints );
	}

	/** @return array{key: string, label: string, ok: bool, hint: string} */
	private function item( string $key, string $label, bool $ok, string $hint ): array {
		return array( 'key' => $key, 'label' => $label, 'ok' => $ok, 'hint' => $ok ? '' : $hint );
	}

	/** Товар для класса направления: задан, существует, виртуальный, доступен к покупке, цена > 0. */
	private function productItem( bool $wooActive, ?ExamEventDTO $event ): array {
		$label = 'Товар экзамена задан и доступен к покупке';
		$hint  = 'Выберите товар экзамена в настройках.';
		if ( ! $wooActive ) {
			return $this->item( 'product', $label, false, $hint );
		}

		// Класс направления известен — нужен товар этого класса; неизвестен (вариантов ещё нет) — достаточно товара хотя бы для одного класса.
		$grades = $this->grades( $event );
		if ( array() === $grades ) {
			$ok = $this->productUsable( 9 ) || $this->productUsable( 11 );
		} else {
			$ok = true;
			foreach ( $grades as $grade ) {
				$ok = $ok && $this->productUsable( $grade );
			}
		}

		return $this->item( 'product', $label, $ok, $hint );
	}

	private function productUsable( int $grade ): bool {
		$product = $this->woo->product( $this->config->examProductId( $grade ) );

		return null !== $product && $product['virtual'] && $product['purchasable'] && (float) $product['price'] > 0.0;
	}

	/**
	 * Классы проведения (9/11) по направлению вариантов его сеансов и основного варианта; неизвестны — пусто.
	 *
	 * @return list<int>
	 */
	private function grades( ?ExamEventDTO $event ): array {
		if ( null === $event ) {
			return array();
		}

		$ids = array( (int) $event->defaultAssessmentId );
		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			$ids[] = $session->assessmentId;
		}

		$grades = array();
		foreach ( array_unique( array_filter( $ids ) ) as $assessmentId ) {
			$assessment = $this->assessments->get( $assessmentId );
			$format     = null !== $assessment ? $this->formats->for( $assessment->kind ) : null;
			if ( null !== $format ) {
				$grades[ $format->direction->grade() ] = true;
			}
		}

		return array_keys( $grades );
	}

	private function roomsItem( ?ExamEventDTO $event ): array {
		$label = 'У кабинетов сеансов указана вместимость';
		if ( null === $event ) {
			return $this->item( 'rooms', $label, true, '' );
		}

		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			$room = $this->rooms->find( $session->roomId );
			if ( null === $room || $room->seats <= 0 ) {
				return $this->item( 'rooms', $label, false, sprintf( 'Укажите вместимость кабинета %s.', $room->name ?? '' ) );
			}
		}

		return $this->item( 'rooms', $label, true, '' );
	}

	private function contactsItem(): array {
		$contacts = $this->contacts->get();
		$ok       = '' !== $contacts['phone'] && '' !== trim( $contacts['city'] . $contacts['street'] );

		return $this->item( 'contacts', 'Заполнены контакты центра', $ok, 'Заполните контакты центра.' );
	}

	private function consentsItem(): array {
		$ok = true;
		foreach ( self::REQUIRED_CONSENTS as $type ) {
			$ok = $ok && null !== $this->consents->getPageForType( $type );
		}

		return $this->item( 'consents', 'Созданы страницы согласий', $ok, 'Создайте страницу согласия.' );
	}

	private function limitsItem(): array {
		$hold = $this->config->examHoldMinutes();
		$ok   = $hold >= 5 && $hold <= 120
			&& $this->config->examIpActiveHoldsLimit() >= 1 && $this->config->examIpHourlyLimit() >= 1 && $this->config->examSourceActiveHoldsLimit() >= 1;

		return $this->item( 'limits', 'Срок брони и лимиты в допустимых границах', $ok, 'Проверьте срок брони и лимиты.' );
	}
}
