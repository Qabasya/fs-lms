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
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Сохранение boilerplate** — создание или обновление шаблона через AJAX.
 * 2. **Удаление boilerplate** — удаление шаблона по UID через AJAX.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику BoilerplateRepository, а сам занимается только валидацией,
 * авторизацией и форматированием ответа.
 */
class BoilerplateCallbacks extends BaseController {

	use Authorizer;   // Трейт с методами authorize(), requireKey(), requireText() и др.
	use Sanitizer;    // Трейт с методами sanitizeText(), sanitizeInt(), sanitizeEditorContent()

	/**
	 * Конструктор.
	 *
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
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
		// authorize() — метод трейта Authorizer
		// Проверяет nonce (wp_verify_nonce) и права текущего пользователя
		$this->authorize( Nonce::SaveBoilerplate );

		// requireKey() — требует наличия непустого ключа в POST-данных
		$subject_key = $this->requireKey( 'subject_key', error: 'Предмет и тип задания обязательны' );
		$term_slug   = $this->requireKey( 'term_slug', error: 'Предмет и тип задания обязательны' );

		// sanitizeText() — очищает строку от тегов и спецсимволов
		$uid = $this->sanitizeText( 'uid' );
		// ?: — оператор Elvis, возвращает 'Без названия' если $title пустой
		$title = $this->sanitizeText( 'title' ) ?: 'Без названия';
		// sanitizeInt() — преобразует значение в целое число
		$is_default = $this->sanitizeInt( 'is_default' ) === 1;
		// sanitizeEditorContent() — очищает HTML от вредоносных тегов (wp_kses_post)
		$content = $this->sanitizeEditorContent( 'content' );

		// Создание DTO (Data Transfer Object) для передачи данных в репозиторий
		$dto = new TaskTypeBoilerplateDTO(
			uid: $uid,
			subject_key: $subject_key,
			term_slug: $term_slug,
			title: $title,
			content: $content,
			is_default: $is_default,
		);

		// Сохранение через репозиторий
		$result = $this->boilerplates->updateBoilerplate( $dto );

		// respond() — метод трейта Authorizer, отправляет JSON-ответ
		// При успехе — wp_send_json_success(), при ошибке — wp_send_json_error()
		$this->respond(
			$result,
			error_msg: 'Не удалось сохранить шаблон',
			success_msg: 'Шаблон успешно сохранён',
			extra_data: array( 'uid' => $uid )
		);
	}

	/**
	 * Удаляет boilerplate-шаблон по UID.
	 *
	 * @return void
	 */
	public function ajaxDeleteBoilerplate(): void {
		// Проверка прав доступа
		$this->authorize( Nonce::SaveBoilerplate );

		// sanitizeKey() — очищает строку для использования в качестве ключа/слага
		$subject_key = $this->sanitizeKey( 'subject_key' );
		$term_slug   = $this->sanitizeKey( 'term_slug' );
		// requireText() — требует наличия текстового значения
		$uid = $this->requireText( 'uid', error: 'UID шаблона обязателен' );

		// Каскадное удаление через репозиторий
		$result = $this->boilerplates->deleteBoilerplate( $subject_key, $term_slug, $uid );

		// Отправка ответа
		$this->respond(
			$result,
			error_msg:'Не удалось удалить шаблон',
			success_msg: 'Шаблон успешно удалён'
		);
	}
}
