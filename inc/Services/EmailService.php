<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Managers\UserManager;
use Inc\Services\Email\WpOptionsEmailTemplate;

/**
 * Class EmailService
 *
 * Отправляет письма через wp_mail(). Тексты писем — из стратегии WpOptionsEmailTemplate
 * (wp_options с fallback на PHP-шаблоны). EmailService не знает, откуда берутся тексты.
 */
readonly class EmailService {

	public function __construct(
		private UserManager             $userManager,
		private WpOptionsEmailTemplate  $template,
	) {}

	/**
	 * Ссылка установки пароля новому пользователю.
	 */
	public function sendPasswordSetup( int $userId, string $link ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		$t = $this->template->get( 'password_setup', array(
			'link'         => $link,
			'display_name' => $user->display_name,
		) );

		return $this->send( $user->user_email, $t->subject, $t->body );
	}

	/**
	 * Подтверждение заявки ученику с JOIN-ссылкой для родителя.
	 */
	public function sendApplicationConfirmation( string $email, string $joinUrl, string $expiresAt ): bool {
		$t = $this->template->get( 'application_confirmation', array(
			'join_url'   => $joinUrl,
			'expires_at' => $expiresAt,
		) );

		return $this->send( $email, $t->subject, $t->body );
	}

	/**
	 * Уведомление сотрудника о новой заявке, готовой к проверке.
	 */
	public function sendApplicationReadyNotification( string $adminEmail ): bool {
		$t = $this->template->get( 'application_ready' );

		return $this->send( $adminEmail, $t->subject, $t->body );
	}

	/**
	 * Уведомление об отклонении заявки.
	 */
	public function sendRejectionNotification( string $email, string $reason ): bool {
		$t = $this->template->get( 'rejection', array( 'reason' => $reason ) );

		return $this->send( $email, $t->subject, $t->body );
	}

	/**
	 * Уведомление родителю о добавлении нового подопечного.
	 */
	public function sendNewRepresentativeNotification( int $userId, ?string $link ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		$t = $this->template->get( 'new_representative', array(
			'link'         => $link ?? '',
			'display_name' => $user->display_name,
		) );

		return $this->send( $user->user_email, $t->subject, $t->body );
	}

	/**
	 * OTP-код подтверждения email.
	 */
	public function sendOtpCode( string $email, string $code ): bool {
		$t = $this->template->get( 'otp_code', array( 'code' => $code ) );

		return $this->send( $email, $t->subject, $t->body );
	}

	private function send( string $to, string $subject, string $body ): bool {
		return wp_mail(
			$to,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
