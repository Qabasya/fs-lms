<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Profile;

use Inc\Callbacks\Profile\NotificationCallbacks;
use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationCallbacksTest extends TestCase {

	private NotificationRepository&\PHPUnit\Framework\MockObject\MockObject $notifications;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject    $service;
	private NotificationCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_test_logged_in']  = true;
		$GLOBALS['_fs_test_user_id'] = 7;

		$this->notifications = $this->createMock( NotificationRepository::class );
		$this->service       = $this->createMock( NotificationService::class );
		$this->cb            = new NotificationCallbacks( $this->notifications, $this->service );
	}

	private function dto(): NotificationDTO {
		return new NotificationDTO(
			id: 1, recipientUserId: 7, type: NotificationType::WorkGraded, groupId: 5,
			entityType: 'submission', entityId: 1, payload: array( 'topic' => 'Тема' ),
			url: '/profile/', createdAt: '2026-01-01 00:00:00', seenAt: null, readAt: null,
		);
	}

	public function test_get_notifications_requires_login(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->notifications->expects( $this->never() )->method( 'listRecent' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetNotifications() );

		self::assertFalse( $r->success );
	}

	public function test_get_notifications_lists_maps_client_array_and_marks_seen(): void {
		$dto = $this->dto();
		$this->notifications->method( 'listRecent' )->with( 7, 30 )->willReturn( array( $dto ) );
		$this->service->method( 'toClientArray' )->with( $dto )->willReturn( array( 'id' => 1, 'title' => 'X' ) );
		$this->notifications->expects( $this->once() )->method( 'markAllSeen' )->with( 7 );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetNotifications() );

		self::assertTrue( $r->success );
		self::assertSame( array( array( 'id' => 1, 'title' => 'X' ) ), $r->payload['items'] );
		self::assertSame( 0, $r->payload['unseen'] );
	}

	public function test_get_notifications_count_requires_login(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->notifications->expects( $this->never() )->method( 'unseenCount' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetNotificationsCount() );

		self::assertFalse( $r->success );
	}

	public function test_get_notifications_count_returns_unseen_for_current_user(): void {
		$this->notifications->method( 'unseenCount' )->with( 7 )->willReturn( 3 );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetNotificationsCount() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['unseen'] );
	}

	public function test_mark_notification_read_uses_current_user_id_ignoring_client_supplied_ids(): void {
		// Клиент пытается подставить чужой user_id/recipient_user_id — оба поля не читаются нигде.
		$_POST = array( 'id' => '42', 'user_id' => '999', 'recipient_user_id' => '999' );

		$this->notifications->expects( $this->once() )->method( 'markRead' )->with( 7, 42 );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxMarkNotificationRead() );

		self::assertTrue( $r->success );
	}

	public function test_mark_notification_read_requires_login(): void {
		$GLOBALS['_test_logged_in'] = false;
		$_POST = array( 'id' => '42' );
		$this->notifications->expects( $this->never() )->method( 'markRead' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxMarkNotificationRead() );

		self::assertFalse( $r->success );
	}

	public function test_mark_all_notifications_read_scopes_to_current_user(): void {
		$this->notifications->expects( $this->once() )->method( 'markAllRead' )->with( 7 );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxMarkAllNotificationsRead() );

		self::assertTrue( $r->success );
	}

	public function test_mark_all_notifications_read_requires_login(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->notifications->expects( $this->never() )->method( 'markAllRead' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxMarkAllNotificationsRead() );

		self::assertFalse( $r->success );
	}
}
