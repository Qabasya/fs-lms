<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Managers\UserManager;

readonly class EmailService {

	public function __construct(
		private UserManager $userManager,
	) {}

	public function sendPasswordSetup( int $userId, string $link ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		return wp_mail(
			$user->user_email,
			'Установка пароля — FS LMS',
			"Для установки пароля перейдите по ссылке: {$link}",
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function sendApplicationConfirmation( string $email, string $joinUrl, string $expiresAt ): bool {
		return wp_mail(
			$email,
			'Заявка принята — FS LMS',
			"Ваша заявка принята. Передайте родителю/представителю ссылку для заполнения данных: {$joinUrl}<br>Ссылка действительна до: {$expiresAt}",
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function sendApplicationReadyNotification( string $adminEmail ): bool {
		return wp_mail(
			$adminEmail,
			'Новая заявка требует проверки — FS LMS',
			'Новая заявка поступила и готова к проверке. Перейдите в панель управления для обработки.',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function sendRejectionNotification( string $email, string $reason ): bool {
		return wp_mail(
			$email,
			'Заявка отклонена — FS LMS',
			"Ваша заявка была отклонена. Причина: {$reason}",
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function sendNewRepresentativeNotification( int $userId, ?string $link ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		$body = 'В вашем профиле появился новый подопечный.';

		if ( null !== $link ) {
			$body .= " Для входа в систему перейдите по ссылке: {$link}";
		}

		return wp_mail(
			$user->user_email,
			'Новый подопечный — FS LMS',
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	public function sendOtpCode( string $email, string $code ): bool {
		return wp_mail(
			$email,
			'Код подтверждения — FS LMS',
			"Ваш код: {$code}. Действителен 10 минут.",
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}