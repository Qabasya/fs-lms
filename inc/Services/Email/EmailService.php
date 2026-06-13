<?php

declare( strict_types=1 );

namespace Inc\Services\Email;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EmailSentEvent;
use Inc\Enums\EmailTemplateType;
use Inc\Enums\LogEvent;
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
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Отправляет ссылку для установки пароля новому пользователю.
	 *
	 * @param int    $userId ID пользователя
	 * @param string $link   Ссылка для установки пароля
	 *
	 * @return bool
	 */
	public function sendPasswordSetup( int $userId, string $link, ?int $personId = null ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		$t      = $this->template->get( EmailTemplateType::PasswordSetup, array(
			'link'         => $link,
			'display_name' => $user->display_name,
		) );
		$result = $this->send( $user->user_email, $t->subject, $t->body );
		$this->logEvents->dispatch( LogEvent::EmailSent, new EmailSentEvent( get_current_user_id() ?: null, EmailTemplateType::PasswordSetup, $personId, $user->user_email, $result ) );
		return $result;
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
	/**
	 * Отправляет новому пользователю логин и пароль для входа.
	 *
	 * @param int    $userId   ID пользователя
	 * @param string $password Сгенерированный пароль в открытом виде
	 *
	 * @return bool
	 */
	public function sendWelcomeWithCredentials( int $userId, string $password, array $extraVars = [], ?int $personId = null ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		$t      = $this->template->get( EmailTemplateType::WelcomeWithCredentials, array_merge( array(
			'login'        => $user->user_login,
			'password'     => $password,
			'display_name' => $user->display_name,
			'login_url'    => wp_login_url(),
		), $extraVars ) );
		$result = $this->send( $user->user_email, $t->subject, $t->body );
		$this->logEvents->dispatch( LogEvent::EmailSent, new EmailSentEvent( get_current_user_id() ?: null, EmailTemplateType::WelcomeWithCredentials, $personId, $user->user_email, $result ) );
		return $result;
	}

	public function sendApplicationConfirmation( string $email, string $joinUrl, string $expiresAt, ?int $personId = null ): bool {
		$t      = $this->template->get( EmailTemplateType::ApplicationConfirmation, array(
			'join_url'   => $joinUrl,
			'expires_at' => $expiresAt,
		) );
		$result = $this->send( $email, $t->subject, $t->body );
		$this->logEvents->dispatch( LogEvent::EmailSent, new EmailSentEvent( null, EmailTemplateType::ApplicationConfirmation, $personId, $email, $result ) );
		return $result;
	}

	/**
	 * Отправляет уведомление сотруднику о новой заявке, готовой к проверке.
	 *
	 * @param string $adminEmail Email сотрудника
	 *
	 * @return bool
	 */
	public function sendApplicationReadyNotification( string $adminEmail ): bool {
		$t      = $this->template->get( EmailTemplateType::ApplicationReady );
		$result = $this->send( $adminEmail, $t->subject, $t->body );
		$this->logEvents->dispatch( LogEvent::EmailSent, new EmailSentEvent( null, EmailTemplateType::ApplicationReady, null, $adminEmail, $result ) );
		return $result;
	}

	/**
	 * Отправляет родителю уведомление о добавлении нового подопечного.
	 *
	 * @param int         $userId ID пользователя (родителя)
	 * @param string|null $link   Ссылка для установки пароля (если требуется)
	 *
	 * @return bool
	 */
	public function sendNewRepresentativeNotification( int $userId, ?string $link, ?int $personId = null ): bool {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			return false;
		}

		$t      = $this->template->get( EmailTemplateType::NewRepresentative, array(
			'link'         => $link ?? '',
			'display_name' => $user->display_name,
		) );
		$result = $this->send( $user->user_email, $t->subject, $t->body );
		$this->logEvents->dispatch( LogEvent::EmailSent, new EmailSentEvent( get_current_user_id() ?: null, EmailTemplateType::NewRepresentative, $personId, $user->user_email, $result ) );
		return $result;
	}

	/**
	 * Отправляет OTP-код для подтверждения email.
	 *
	 * @param string $email Email получателя
	 * @param string $code  6-значный OTP-код
	 *
	 * @return bool
	 */
	public function sendOtpCode( string $email, string $code, ?int $personId = null ): bool {
		$t      = $this->template->get( EmailTemplateType::OtpCode, array( 'code' => $code ) );
		$result = $this->send( $email, $t->subject, $t->body );
		$this->logEvents->dispatch( LogEvent::EmailSent, new EmailSentEvent( null, EmailTemplateType::OtpCode, $personId, $email, $result ) );
		return $result;
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