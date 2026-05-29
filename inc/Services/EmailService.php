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
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Отправка email** — унифицированная отправка HTML-писем через wp_mail().
 * 2. **Типовые письма** — подготовка и отправка писем для конкретных ситуаций:
 *    - Установка пароля
 *    - Подтверждение заявки
 *    - Уведомление о новой заявке
 *    - Отклонение заявки
 *    - Добавление нового подопечного
 *    - OTP-код
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение шаблонов писем WpOptionsEmailTemplate,
 * а данные пользователей — UserManager.
 * Не содержит бизнес-логики — только подготовка и отправка.
 */
readonly class EmailService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param UserManager            $userManager Менеджер пользователей
	 * @param WpOptionsEmailTemplate $template    Провайдер шаблонов писем
	 */
	public function __construct(
		private UserManager             $userManager,
		private WpOptionsEmailTemplate  $template,
	) {}

	/**
	 * Отправляет ссылку для установки пароля новому пользователю.
	 *
	 * @param int    $userId ID пользователя
	 * @param string $link   Ссылка для установки пароля
	 *
	 * @return bool
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
	 * Отправляет ученику подтверждение заявки с JOIN-ссылкой для родителя.
	 *
	 * @param string $email     Email ученика
	 * @param string $joinUrl   URL для присоединения родителя
	 * @param string $expiresAt Дата истечения срока ссылки
	 *
	 * @return bool
	 */
	public function sendApplicationConfirmation( string $email, string $joinUrl, string $expiresAt ): bool {
		$t = $this->template->get( 'application_confirmation', array(
			'join_url'   => $joinUrl,
			'expires_at' => $expiresAt,
		) );

		return $this->send( $email, $t->subject, $t->body );
	}

	/**
	 * Отправляет уведомление сотруднику о новой заявке, готовой к проверке.
	 *
	 * @param string $adminEmail Email сотрудника
	 *
	 * @return bool
	 */
	public function sendApplicationReadyNotification( string $adminEmail ): bool {
		$t = $this->template->get( 'application_ready' );

		return $this->send( $adminEmail, $t->subject, $t->body );
	}

	/**
	 * Отправляет уведомление об отклонении заявки.
	 *
	 * @param string $email  Email заявителя
	 * @param string $reason Причина отклонения
	 *
	 * @return bool
	 */
	public function sendRejectionNotification( string $email, string $reason ): bool {
		$t = $this->template->get( 'rejection', array( 'reason' => $reason ) );

		return $this->send( $email, $t->subject, $t->body );
	}

	/**
	 * Отправляет родителю уведомление о добавлении нового подопечного.
	 *
	 * @param int         $userId ID пользователя (родителя)
	 * @param string|null $link   Ссылка для установки пароля (если требуется)
	 *
	 * @return bool
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
	 * Отправляет OTP-код для подтверждения email.
	 *
	 * @param string $email Email получателя
	 * @param string $code  6-значный OTP-код
	 *
	 * @return bool
	 */
	public function sendOtpCode( string $email, string $code ): bool {
		$t = $this->template->get( 'otp_code', array( 'code' => $code ) );

		return $this->send( $email, $t->subject, $t->body );
	}

	/**
	 * Базовый метод отправки письма через wp_mail().
	 *
	 * @param string $to      Email получателя
	 * @param string $subject Тема письма
	 * @param string $body    HTML-тело письма
	 *
	 * @return bool
	 */
	private function send( string $to, string $subject, string $body ): bool {
		// wp_mail() — встроенная функция WordPress для отправки email
		return wp_mail(
			$to,
			$subject,
			$body,
			// Content-Type: text/html — указывает, что письмо в HTML-формате
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}