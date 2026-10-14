<?php
/**
 * Связь гостевых заявок с корзиной и заказами WooCommerce.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam\Payment;

use Inc\Enums\Exam\ExamPaymentState;

use Inc\Managers\Woo\WooCommerceManager;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Exam\ExamOrderItemMeta;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;

/**
 * Связь гостевых заявок с корзиной и заказами WooCommerce (этап 11a.4–11a.5).
 *
 * **Единственная точка смысла** между LMS и магазином: все вызовы функций WooCommerce идут через {@see WooCommerceManager}.
 * Магазин не меняется — корзина, оформление, шлюз, письма и страница «Спасибо» остаются; LMS добавляет только данные позиции,
 * проверки и свой блок статуса. Позиция экзамена несёт ID заявки и подпись `HMAC(id|request_key)`: без заявки товар в корзину не попадает,
 * подмена ID в сессии не проходит. Одна корзина — одна незавершённая экзаменная заявка; чужие товары корзины не трогаются.
 */
class WooExamAdapter {

	public const ITEM_APPLICATION = 'fs_exam_application';
	public const ITEM_SIGNATURE   = 'fs_exam_sig';
	public const ORDER_ITEM_META  = ExamOrderItemMeta::Application->value;

	/** Получает Woo CRUD, заявки и брони для связи экзамена с магазином. */
	public function __construct(
		private readonly WooCommerceManager $woo,
		private readonly PluginConfig $config,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamPaymentLinkRepository $links,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamHoldService $holds,
		private readonly GuestParticipantMaterializer $materializer,
		private readonly ExamTime $time,
	) {}

	/** Товар — один из товаров экзаменов в настройках. */
	public function isExamProduct( int $productId ): bool {
		return $productId > 0 && ( $productId === $this->config->examProductId( 9 ) || $productId === $this->config->examProductId( 11 ) );
	}

	/**
	 * Экзаменная позиция не допускает изменения количества.
	 *
	 * @param array<string, mixed> $item
	 */
	public function quantityHtml( string $html, array $item ): string {
		return $this->isExamProduct( (int) ( $item['product_id'] ?? 0 ) ) ? '1' : $html;
	}

	/**
	 * Запрещает количество экзаменного товара, отличное от единицы.
	 *
	 * @param array<string, mixed> $item
	 */
	public function isQuantityUpdateAllowed( bool $passed, array $item, int $quantity ): bool {
		return $this->isExamProduct( (int) ( $item['product_id'] ?? 0 ) ) && 1 !== $quantity ? false : $passed;
	}

	/**
	 * Готовит оставшееся время действующих броней подписанных позиций корзины.
	 *
	 * @return list<array{until: string, seconds_left: int}>
	 */
	public function holdCountdowns(): array {
		$data = array();
		foreach ( $this->cartExamItems() as $item ) {
			$app = $this->applicationOfItem(
				array(
					self::ITEM_APPLICATION => $item['application_id'],
					self::ITEM_SIGNATURE   => $item['signature'],
				)
			);
			if ( null !== $app && $app->isHeld && null !== $app->holdExpiresAt ) {
				$data[] = array(
					'until'        => substr( $this->time->toLocal( $app->holdExpiresAt ), 11, 5 ),
					'seconds_left' => $this->time->secondsUntil( $this->time->nowUtc(), $app->holdExpiresAt ),
				);
			}
		}

		return $data;
	}

	/** Подпись привязки позиции к заявке. */
	public function signature( ExamGuestApplicationDTO $application ): string {
		return hash_hmac( 'sha256', $application->id . '|' . $application->requestKey, defined( 'FS_LMS_HASH_SALT' ) ? (string) FS_LMS_HASH_SALT : '' );
	}

	/**
	 * Кладёт товар в корзину. Повторный вызов для той же заявки второй позиции не создаёт (вторая вкладка, «Назад»).
	 *
	 * @throws CodedException Товар недоступен, в корзине другая экзаменная заявка или магазин отказал.
	 */
	public function addToCart( ExamGuestApplicationDTO $application ): void {
		$snapshot  = null !== $application->sourceSnapshot ? json_decode( $application->sourceSnapshot, true ) : null;
		$productId = $this->config->examProductId( (int) ( is_array( $snapshot ) ? ( $snapshot['grade'] ?? 0 ) : 0 ) );
		$product   = $this->woo->product( $productId );
		if ( null === $product || ! $product['purchasable'] ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись временно недоступна.' );
		}

		// Одна незавершённая экзаменная запись на корзину: новая заявка заменяет прежнюю (повторная отправка формы, второй участник).
		// Позиция убирается штатным хуком корзины, он же снимает бронь прежней заявки; чужие товары корзины не трогаются.
		foreach ( $this->cartExamItems() as $item ) {
			if ( $item['application_id'] === $application->id ) {
				return;
			}
			$this->woo->removeCartItem( $item['key'] );
		}

		$key = $this->woo->addToCart(
			$productId,
			array(
				self::ITEM_APPLICATION => $application->id,
				self::ITEM_SIGNATURE   => $this->signature( $application ),
			)
		);
		if ( null === $key ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Не удалось перейти к оплате. Попробуйте ещё раз.' );
		}

		// Место по-прежнему занято, срок тот же: меняется только этап пути оплаты.
		$this->holds->transition( $application->id, array( GuestApplicationState::Hold ), GuestApplicationState::AwaitingPayment );
	}

	/**
	 * Оплата экзамена в тестовом окружении (`FS_LMS_TEST_ENV`) не требуется: заказ создаётся и сразу считается оплаченным самим WooCommerce
	 * (`payment_complete()`), поэтому дальше идёт обычный путь подтверждения записи — меняется только шаг платёжного шлюза.
	 * Только для корзины с позицией экзамена; чужие товары и боевое окружение не затрагиваются.
	 */
	public function needsPayment( bool $needsPayment ): bool {
		if ( $needsPayment && $this->config->isTestEnv() && array() !== $this->cartExamItems() ) {
			return false;
		}

		return $needsPayment;
	}

	/**
	 * Тестовое окружение: вместо корзины и оформления создаёт заказ и сразу завершает его оплату штатным `payment_complete()`.
	 * Дальше путь обычный: хуки магазина подтверждают запись, гость попадает на страницу «Спасибо» со статусом.
	 *
	 * @param array{first_name: string, last_name: string, phone: string} $billing
	 *
	 * @return string|null Адрес страницы «Спасибо»; null — не тестовое окружение или заказ создать не удалось (остаётся обычный путь через корзину).
	 */
	public function completeInTestEnv( ExamGuestApplicationDTO $application, array $billing ): ?string {
		if ( ! $this->config->isTestEnv() ) {
			return null;
		}

		$snapshot  = null !== $application->sourceSnapshot ? json_decode( $application->sourceSnapshot, true ) : null;
		$productId = $this->config->examProductId( (int) ( is_array( $snapshot ) ? ( $snapshot['grade'] ?? 0 ) : 0 ) );
		$orderId   = $this->woo->createOrderForProduct(
			$productId,
			array( self::ORDER_ITEM_META => $application->id ),
			$billing,
			$this->discountedPrice( $application )
		);
		if ( null === $orderId ) {
			return null;
		}

		// Заказ создан программно, хук оформления не срабатывает: связь с заявкой — вручную, затем оплата.
		$this->linkOrder( $orderId );
		$this->woo->completePayment( $orderId );
		$this->woo->emptyCart();
		$url = $this->woo->orderReceivedUrl( $orderId );

		return '' !== $url ? $url : null;
	}

	/**
	 * Скидка по ссылке преподавателя из снимка заявки, %; 0 — полная цена. Снимок хранит и 100, но такая запись магазин не вызывает.
	 */
	public function discountOf( ExamGuestApplicationDTO $application ): int {
		$snapshot = null !== $application->sourceSnapshot ? json_decode( $application->sourceSnapshot, true ) : null;
		$percent  = is_array( $snapshot ) ? (int) ( $snapshot['discount_percent'] ?? 0 ) : 0;

		return max( 0, min( 99, $percent ) );
	}

	/**
	 * Цена товара со скидкой ссылки; null — скидки нет или цену не получить (остаётся цена товара).
	 */
	public function discountedPrice( ExamGuestApplicationDTO $application ): ?float {
		$percent = $this->discountOf( $application );
		if ( $percent <= 0 ) {
			return null;
		}

		$snapshot = json_decode( (string) $application->sourceSnapshot, true );
		$product  = $this->woo->product( $this->config->examProductId( (int) ( is_array( $snapshot ) ? ( $snapshot['grade'] ?? 0 ) : 0 ) ) );
		if ( null === $product || (float) $product['price'] <= 0.0 ) {
			return null;
		}

		return round( (float) $product['price'] * ( 100 - $percent ) / 100, 2 );
	}

	/**
	 * Цены экзаменных позиций корзины со скидкой ссылки: ключ позиции → цена. Заявка берётся только при верной подписи.
	 *
	 * @param array<int, array{key: string, product_id: int, data: array<string, mixed>}> $items
	 *
	 * @return array<string, float>
	 */
	public function discountedCartPrices( array $items ): array {
		$prices = array();
		foreach ( $items as $item ) {
			if ( ! $this->isExamProduct( $item['product_id'] ) ) {
				continue;
			}
			$application = $this->applicationOfItem( $item['data'] );
			$price       = null !== $application ? $this->discountedPrice( $application ) : null;
			if ( null !== $price ) {
				$prices[ $item['key'] ] = $price;
			}
		}

		return $prices;
	}

	/**
	 * Экзаменные позиции корзины.
	 *
	 * @return list<array{key: string, application_id: int, signature: string}>
	 */
	public function cartExamItems(): array {
		$items = array();
		foreach ( $this->woo->cartItems() as $item ) {
			if ( $this->isExamProduct( $item['product_id'] ) ) {
				$items[] = array(
					'key'            => $item['key'],
					'application_id' => (int) ( $item['data'][ self::ITEM_APPLICATION ] ?? 0 ),
					'signature'      => (string) ( $item['data'][ self::ITEM_SIGNATURE ] ?? '' ),
				);
			}
		}

		return $items;
	}

	/**
	 * Проверка добавления товара в корзину: товар экзамена без данных заявки (или с неверной подписью) не принимается.
	 * Прямая ссылка «в корзину» место не бронирует.
	 *
	 * @param array<string, mixed> $itemData
	 */
	public function isAddAllowed( int $productId, array $itemData ): bool {
		if ( ! $this->isExamProduct( $productId ) ) {
			return true;
		}

		return null !== $this->applicationOfItem( $itemData );
	}

	/**
	 * Заявка позиции, если привязка подписана верно; иначе null.
	 *
	 * @param array<string, mixed> $itemData
	 */
	public function applicationOfItem( array $itemData ): ?ExamGuestApplicationDTO {
		$id        = (int) ( $itemData[ self::ITEM_APPLICATION ] ?? 0 );
		$signature = (string) ( $itemData[ self::ITEM_SIGNATURE ] ?? '' );
		$app       = $id > 0 ? $this->applications->find( $id ) : null;

		return null !== $app && '' !== $signature && hash_equals( $this->signature( $app ), $signature ) ? $app : null;
	}

	/**
	 * Строки под названием товара: участник, дата и время сеанса, срок брони.
	 *
	 * @param array<string, mixed> $itemData
	 *
	 * @return list<array{key: string, value: string}>
	 */
	public function itemDisplay( array $itemData ): array {
		$app = $this->applicationOfItem( $itemData );
		if ( null === $app ) {
			return array();
		}

		$rows    = array();
		$name    = $this->materializer->draftName( $app );
		$session = $this->sessions->find( $app->sessionId );
		if ( '' !== $name ) {
			$rows[] = array(
				'key'   => 'Участник',
				'value' => $name,
			);
		}
		if ( null !== $session ) {
			$start  = $this->time->toLocal( $session->scheduledAt );
			$rows[] = array(
				'key'   => 'Дата и время',
				'value' => gmdate( 'd.m.Y', (int) strtotime( substr( $start, 0, 10 ) . ' UTC' ) ) . ', ' . substr( $start, 11, 5 ),
			);
		}
		$discount = $this->discountOf( $app );
		if ( $discount > 0 ) {
			$rows[] = array(
				'key'   => 'Скидка по ссылке преподавателя',
				'value' => $discount . '%',
			);
		}
		if ( $app->isHeld && null !== $app->holdExpiresAt ) {
			$rows[] = array(
				'key'   => 'Место удерживается до',
				'value' => substr( $this->time->toLocal( $app->holdExpiresAt ), 11, 5 ),
			);
		}

		return $rows;
	}

	/**
	 * Позицию удалили из корзины — бронь снимается; повторное добавление потребует новой заявки.
	 *
	 * @param array<string, mixed> $itemData
	 */
	public function onItemRemoved( array $itemData ): void {
		$app = $this->applicationOfItem( $itemData );
		if ( null !== $app && $app->isHeld ) {
			$this->holds->release( $app->id, GuestApplicationState::Cancelled );
		}
	}

	/**
	 * Чистка корзины: позиция с заявкой, которая истекла, отменена или подменена, убирается.
	 *
	 * @return bool true — что-то убрано (вызывающий показывает уведомление).
	 */
	public function cleanCart(): bool {
		$removed = false;
		foreach ( $this->woo->cartItems() as $item ) {
			if ( ! $this->isExamProduct( $item['product_id'] ) ) {
				continue;
			}
			$app = $this->applicationOfItem( $item['data'] );
			// Оплаченная заявка (confirmed/payment_pending) из корзины не убирается: заказ уже оформляется.
			$expired = null === $app || ! $app->isHeld || ( null !== $app->holdExpiresAt && $app->holdExpiresAt <= $this->time->nowUtc() );
			if ( null === $app || ( $expired && GuestApplicationState::PaymentPending->value !== $app->state ) ) {
				$this->woo->removeCartItem( $item['key'] );
				$removed = true;
			}
		}

		return $removed;
	}

	/**
	 * Связи заказа со строками `exam_payment_links` (хук `woocommerce_checkout_order_processed`): сумма позиции после скидок
	 * (может быть 0) и товар фиксируются снимком — смена цены в настройках его не меняет. Повторный вызов дубля не создаёт.
	 */
	public function linkOrder( int $orderId ): void {
		$now = $this->time->nowUtc();
		foreach ( $this->woo->orderExamItems( $orderId ) as $item ) {
			if ( null !== $this->links->findByWcOrderItem( $item['item_id'] ) ) {
				continue;
			}

			try {
				$this->links->insert(
					array(
						'application_id'   => $item['application_id'],
						'wc_order_id'      => $orderId,
						'wc_order_item_id' => $item['item_id'],
						'product_id'       => $item['product_id'],
						'amount'           => $item['total'],
						'currency'         => 'RUB',
						'payment_state'    => ExamPaymentState::Pending->value,
						'created_at'       => $now,
						'updated_at'       => $now,
					)
				);
			} catch ( DuplicateKeyException ) {
				continue;
			}

			$this->holds->transition( $item['application_id'], array( GuestApplicationState::Hold, GuestApplicationState::AwaitingPayment ), GuestApplicationState::PaymentPending );
		}
	}
}
