<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Repositories\TaskTypeRepository;

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
	private const NONCE_ACTION = 'save_boilerplate_nonce';
	private const NONCE_KEY = 'nonce';

	public function __construct(
		private readonly TaskTypeRepository $taskTypes,
	) {
		parent::__construct();
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Сохраняет (создаёт или обновляет) boilerplate-шаблон.
	 *
	 * @return void
	 */
	public function ajaxSave(): void {
		$this->authorize();

		[ $subject_key, $term_slug ] = $this->requireSubjectAndTerm( 'POST' );

		$uid        = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );
		$title      = sanitize_text_field( wp_unslash( $_POST['title'] ?? 'Без названия' ) );
		$is_default = isset( $_POST['is_default'] ) && $_POST['is_default'] === '1';

		$content = $this->sanitizeContent( $_POST['content'] ?? [] );

		$dto = new TaskTypeBoilerplateDTO(
			uid: $uid,
			subject_key: $subject_key,
			term_slug: $term_slug,
			title: $title,
			content: $content,
			is_default: $is_default,
		);

		$result = $this->taskTypes->updateBoilerplate( $dto );

		if ( $result ) {
			wp_send_json_success( [
				'message' => 'Шаблон успешно сохранён',
				'uid'     => $uid,
			] );
		} else {
			wp_send_json_error( 'Не удалось сохранить шаблон' );
		}
	}

	/**
	 * Удаляет boilerplate-шаблон по UID.
	 *
	 * @return void
	 */
	public function ajaxDelete(): void {
		$this->authorize();

		[ $subject_key, $term_slug ] = $this->requireSubjectAndTerm( 'POST' );

		$uid = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );

		if ( empty( $uid ) ) {
			wp_send_json_error( 'UID шаблона обязателен' );
		}

		$result = $this->taskTypes->deleteBoilerplate( $subject_key, $term_slug, $uid );

		if ( $result ) {
			wp_send_json_success( 'Шаблон успешно удалён' );
		} else {
			wp_send_json_error( 'Не удалось удалить шаблон или он не найден' );
		}
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет nonce и права администратора.
	 * Завершает выполнение через wp_send_json_error при неудаче.
	 *
	 * @return void
	 */
	private function authorize(): void {
		check_ajax_referer( self::NONCE_ACTION, self::NONCE_KEY );

		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( 'У вас недостаточно прав', 403 );
		}
	}

	/**
	 * Читает и валидирует subject_key + term_slug из указанного супер-глобального массива.
	 * Завершает выполнение, если одно из значений пустое.
	 *
	 * Единый метод для POST (сохранение/удаление) — устраняет прежнюю
	 * несогласованность ключей между ajaxSave ('subject_key'/'term_slug')
	 * и ajaxDelete ('subject'/'term').
	 *
	 * @param 'POST'|'GET' $method Источник данных
	 *
	 * @return array{0: string, 1: string} [subject_key, term_slug]
	 */
	private function requireSubjectAndTerm( string $method = 'POST' ): array {
		$source = $method === 'GET' ? $_GET : $_POST;

		$subject_key = sanitize_text_field( wp_unslash( $source['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $source['term_slug'] ?? '' ) );

		if ( empty( $subject_key ) || empty( $term_slug ) ) {
			wp_send_json_error( 'Предмет и тип задания обязательны' );
		}

		return [ $subject_key, $term_slug ];
	}

	/**
	 * Санирует массив полей контента из TinyMCE.
	 *
	 * Если передано одно поле — возвращает его значение строкой (простой формат).
	 * Если несколько полей — кодирует в JSON (сложный шаблон).
	 *
	 * @param mixed $raw Сырые данные из $_POST['content']
	 *
	 * @return string Готовый контент для сохранения
	 */
	private function sanitizeContent( mixed $raw ): string {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return '';
		}

		$sanitized = [];
		foreach ( $raw as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = wp_kses_post( $value );
		}

		return count( $sanitized ) === 1
			? reset( $sanitized )
			: json_encode( $sanitized, JSON_UNESCAPED_UNICODE );
	}
}