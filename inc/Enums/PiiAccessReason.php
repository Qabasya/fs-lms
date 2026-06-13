<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum PiiAccessReason: string {
	case ApplicationReview      = 'application_review';
	case AdminRevealCredentials = 'admin_reveal_credentials';
	case AdminUserlistEdit      = 'admin_userlist_edit';
	case AdminUserlistReveal    = 'admin_userlist_reveal';
	case AdminMaskedView        = 'admin_masked_view';

	public function label(): string {
		return match ( $this ) {
			self::ApplicationReview      => 'Проверка заявки',
			self::AdminRevealCredentials => 'Просмотр администратором',
			self::AdminUserlistEdit      => 'Редактирование в списке пользователей',
			self::AdminUserlistReveal    => 'Раскрытие ПД в списке пользователей',
			self::AdminMaskedView        => 'Маскированный просмотр ПД',
		};
	}
}
