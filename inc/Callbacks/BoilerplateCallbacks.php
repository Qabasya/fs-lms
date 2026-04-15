<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
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
	/**
	 * Конструктор.
	 *
	 * @param TaskTypeRepository $taskTypes Репозиторий типов заданий
	 */
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
	public function ajaxSaveBoilerplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация subject_key и term_slug
		[ $subject_key, $term_slug ] = $this->requireSubjectAndTerm( 'POST' );

		// Получение данных из POST
		$uid        = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );
		$title      = sanitize_text_field( wp_unslash( $_POST['title'] ?? 'Без названия' ) );
		$is_default = isset( $_POST['is_default'] ) && $_POST['is_default'] === '1';

		// Санитизация контента (массив полей из TinyMCE)
		$content = $this->sanitizeContent( $_POST['content'] ?? [] );

		// Создание DTO
		$dto = new TaskTypeBoilerplateDTO(
			uid: $uid,
			subject_key: $subject_key,
			term_slug: $term_slug,
			title: $title,
			content: $content,
			is_default: $is_default,
		);

		// Сохранение через репозиторий
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
	public function ajaxDeleteBoilerplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация subject_key и term_slug
		[ $subject_key, $term_slug ] = $this->requireSubjectAndTerm( 'POST' );

		// Получение UID из POST
		$uid = sanitize_text_field( wp_unslash( $_POST['uid'] ?? '' ) );

		if ( empty( $uid ) ) {
			wp_send_json_error( 'UID шаблона обязателен' );
		}

		// Удаление через репозиторий
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
		// Проверка nonce для защиты от CSRF
		Nonce::SaveBoilerplate->verify( 'nonce' );

		// Проверка прав доступа (только администраторы)
		if ( ! current_user_can( Capability::ADMIN->value ) ) {
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
		// Если данные не являются массивом или пусты — возвращаем пустую строку
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return '';
		}

		// Санитизация каждого поля контента
		$sanitized = [];
		foreach ( $raw as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = wp_kses_post( $value );
		}

		// Если только одно поле — возвращаем его как строку (простой формат)
		// Если несколько полей — кодируем в JSON (сложный шаблон)
		return count( $sanitized ) === 1
			? reset( $sanitized )
			: json_encode( $sanitized, JSON_UNESCAPED_UNICODE );
	}
}