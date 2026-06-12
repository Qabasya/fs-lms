<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\EmailTemplateType;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\EmailTemplatesRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class EmailTemplateSettingsCallbacks
 *
 * AJAX-обработчики для управления шаблонами email-писем в административной панели.
 *
 * @package Inc\Callbacks\Settings
 *
 * ### Основные обязанности:
 *
 * 1. **Сохранение кастомного шаблона** — запись пользовательского шаблона письма в репозиторий.
 * 2. **Сброс шаблона к значению по умолчанию** — удаление кастомного шаблона.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с шаблонами EmailTemplatesRepository.
 * Использует enum EmailTemplateType для типобезопасной идентификации типа письма.
 * Требует права Nonce::Manager (доступно менеджерам).
 */
class EmailTemplateSettingsCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), success(), error()
	use Sanitizer;   // Трейт с методами requireKey(), requireText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param EmailTemplatesRepository $templates Репозиторий шаблонов email-писем
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
		$this->authorize( Nonce::Manager );

		$rawType = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );
		$subject = $this->requireText( 'subject', error: 'Тема письма обязательна.' );

		// EmailTemplateType::tryFrom() — безопасное преобразование строки в enum
		$type = EmailTemplateType::tryFrom( $rawType );

		if ( null === $type ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		$body = $this->sanitizeHtml( 'body' );

		// Сохранение шаблона в репозиторий (wp_options)
		$this->templates->saveTemplate( $type->value, $subject, $body );

		$this->success( array( 'message' => 'Шаблон сохранён.' ) );
	}

	/**
	 * Сбрасывает кастомный шаблон email-письма к значению по умолчанию.
	 *
	 * @return void
	 */
	public function ajaxResetEmailTemplate(): void {
		$this->authorize( Nonce::Manager );

		$rawType = $this->requireKey( 'type', error: 'Тип шаблона обязателен.' );
		$type    = EmailTemplateType::tryFrom( $rawType );

		if ( null === $type ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}

		// Удаление кастомного шаблона (при чтении будет использован встроенный дефолт)
		$this->templates->deleteTemplate( $type->value );

		$this->success( array( 'message' => 'Шаблон сброшен к умолчанию.' ) );
	}
}