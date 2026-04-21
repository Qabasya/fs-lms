<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Nonce;
use Inc\Repositories\BoilerplateRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class BoilerplateCallbacks
 *
 * AJAX-обработчики для CRUD-операций с типовыми условиями (boilerplate).
 *
 * Отвечает только за сохранение и удаление boilerplate через AJAX,
 * чтобы страница не перезагружалась лишний раз.
 *
 * @package Inc\Callbacks
 */
class BoilerplateCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	
	/**
	 * Конструктор.
	 *
	 * @param BoilerplateRepository $boilerplates Репозиторий типов заданий
	 */
	public function __construct(
		private readonly BoilerplateRepository $boilerplates,
	) {
		parent::__construct();
	}
	
	// ============================ AJAX-КОЛЛБЕКИ ============================ //
	
	/**
	 * Сохраняет (создаёт или обновляет) boilerplate-шаблон.
	 *
	 * @return void
	 */
	public function ajaxSaveBoilerplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::SaveBoilerplate );
		
		// Получение и валидация subject_key и term_slug
		$subject_key = $this->requireKey( 'subject_key', error: 'Предмет и тип задания обязательны' );
		$term_slug   = $this->requireKey( 'term_slug', error: 'Предмет и тип задания обязательны' );

		// Получение данных из POST
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$uid        = $this->sanitizeText( 'uid' );
		$title      = $this->sanitizeText( 'title' ) ?: 'Без названия';
		$is_default = $this->sanitizeInt( 'is_default' ) === 1;
		
		// Санитизация контента (массив полей из TinyMCE)
		$content = $this->sanitizeEditorContent( 'content' );
		
		// Создание DTO
		$dto = new TaskTypeBoilerplateDTO(
			uid        : $uid,
			subject_key: $subject_key,
			term_slug  : $term_slug,
			title      : $title,
			content    : $content,
			is_default : $is_default,
		);
		
		// Сохранение через репозиторий
		$result = $this->boilerplates->updateBoilerplate( $dto );
		
		if ( $result ) {
			wp_send_json_success(
				array(
					'message' => 'Шаблон успешно сохранён',
					'uid'     => $uid,
				)
			);
		} else {
			wp_send_json_error( 'Не удалось сохранить шаблон' );
		}
	}
	
	/**
	 * Удаляет boilerplate-шаблон по UID.
	 *
	 * @return void
	 */
	public function ajaxDeleteBoilerplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::SaveBoilerplate );
		
		// Получение и валидация subject_key и term_slug
		$subject_key = $this->sanitizeKey( 'subject_key' );
		$term_slug   = $this->sanitizeKey( 'term_slug' );
		$uid         = $this->requireText( 'uid', error: 'UID шаблона обязателен' );
		
		// Удаление через репозиторий
		$result = $this->boilerplates->deleteBoilerplate( $subject_key, $term_slug, $uid );
		
		if ( $result ) {
			wp_send_json_success( 'Шаблон успешно удалён' );
		} else {
			wp_send_json_error( 'Не удалось удалить шаблон' );
		}
	}
}
