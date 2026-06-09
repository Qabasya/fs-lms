<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\EmailTemplatesRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class EmailTemplateSettingsCallbacks
 *
 * AJAX-обработчики для управления шаблонами email-писем.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Сохранение шаблона** — сохранение кастомного шаблона письма в репозиторий.
 * 2. **Сброс шаблона** — удаление кастомного шаблона, возврат к значению по умолчанию.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с шаблонами EmailTemplatesRepository.
 * Используется в административной панели для настройки текстов email-уведомлений.
 *
 * ### Типы шаблонов:
 *
 * - otp_code — код подтверждения
 * - password_setup — установка пароля
 * - application_confirmation — подтверждение заявки
 * - application_ready — новая заявка для проверки
 * - rejection — отклонение заявки
 * - new_representative — добавление представителя
 * - welcome_with_credentials — приветствие с учётными данными
 */
class EmailTemplateSettingsCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), success(), error()
	use Sanitizer;   // Трейт с методами requireKey(), requireText()

	/**
	 * Список допустимых типов шаблонов.
	 * TODO: перекинуть в enum
	 *
	 * @var array<string>
	 */
	private const ALLOWED_TYPES = array(
		'otp_code',                 // OTP-код подтверждения email
		'password_setup',           // Ссылка на установку пароля
		'application_confirmation', // Подтверждение заявки ученику
		'application_ready',        // Уведомление сотрудника о новой заявке
		'rejection',                // Уведомление об отклонении заявки
		'new_representative',       // Уведомление о добавлении представителя
		'welcome_with_credentials', // Приветственное письмо с учётными данными
	);

	/**
	 * Конструктор коллбеков.
	 *
	 * @param EmailTemplatesRepository $templates Репозиторий шаблонов email
	 */
	public function __construct(
		private readonly EmailTemplatesRepository $templates,
	) {
		parent::__construct();
	}

	/**
	 * Сохраняет кастомный шаблон email-письма.
	 *
	 * @return void
	 */
	public function ajaxSaveEmailTemplate(): void {
		// Проверка прав доступа
		$this->authorize( Nonce::Manager );

		// Валидация входных данных
		$type    = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );
		$subject = $this->requireText( 'subject', error: 'Тема письма обязательна.' );

		// Проверка допустимости типа шаблона
		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		// wp_kses_post() — очистка HTML-контента (разрешает безопасные теги)
		// wp_unslash() — удаляет экранирование слешей
		$body = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

		// Сохранение шаблона в репозиторий (wp_options)
		$this->templates->saveTemplate( $type, $subject, $body );

		$this->success( array( 'message' => 'Шаблон сохранён.' ) );
	}

	/**
	 * Сбрасывает кастомный шаблон к значению по умолчанию.
	 *
	 * @return void
	 */
	public function ajaxResetEmailTemplate(): void {
		$this->authorize( Nonce::Manager );

		$type = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		// Удаление кастомного шаблона (при чтении будет использован fallback)
		$this->templates->deleteTemplate( $type );

		$this->success( array( 'message' => 'Шаблон сброшен к умолчанию.' ) );
	}
}