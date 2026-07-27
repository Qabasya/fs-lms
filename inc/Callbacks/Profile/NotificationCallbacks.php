<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Services\Profile\NotificationService;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX-выдача уведомлений кабинета.
 *
 * No-capability эталон ({@see \Inc\Callbacks\Profile\LearnerCallbacks}):
 * `Nonce::Notifications->verify()` + `is_user_logged_in()` — доступ гейтится
 * нонсом и логином, без Capability (нужно всем ролям кабинета). Получатель —
 * ВСЕГДА `get_current_user_id()`; клиентский id не принимается ни в одном
 * действии (чужие уведомления недостижимы: репозиторий скоупит по recipient).
 *
 * @package Inc\Callbacks\Profile
 */
class NotificationCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly NotificationRepository $notifications,
		private readonly NotificationService     $service,
	) {
		parent::__construct();
	}

	/** Последние 30 + пометка seen (открытие поповера гасит badge колокольчика). */
	public function ajaxGetNotifications(): void {
		Nonce::Notifications->verify();
		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$userId = get_current_user_id();
		$items  = array_map(
			fn( $n ) => $this->service->toClientArray( $n ),
			$this->notifications->listRecent( $userId, 30 )
		);
		$this->notifications->markAllSeen( $userId );

		$this->success( array( 'items' => $items, 'unseen' => 0 ) );
	}

	/** Счётчик непрочитанных для поллинга badge (раз в 60 с). */
	public function ajaxGetNotificationsCount(): void {
		Nonce::Notifications->verify();
		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'unseen' => $this->notifications->unseenCount( get_current_user_id() ) ) );
	}

	/** Клик по плитке — гасит точку непрочитанного. Params: id. */
	public function ajaxMarkNotificationRead(): void {
		Nonce::Notifications->verify();
		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$this->notifications->markRead( get_current_user_id(), $this->requireInt( 'id' ) );

		$this->success();
	}

	/** «Прочитать все» в шапке поповера. */
	public function ajaxMarkAllNotificationsRead(): void {
		Nonce::Notifications->verify();
		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$this->notifications->markAllRead( get_current_user_id() );

		$this->success();
	}
}
