<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaskTypeRepository;

/**
 * Class TaskTypeCallbacks
 *
 * Обработчик AJAX-запросов для управления настройками типов заданий.
 * В частности — сохранение "типовых условий" (boilerplate) для заданий.
 *
 * @package Inc\Callbacks
 */
class TaskTypeCallbacks extends BaseController {
	/**
	 * Репозиторий для работы с текстами условий (boilerplate).
	 *
	 * @var TaskTypeRepository
	 */
	private TaskTypeRepository $repository;

	/**
	 * Конструктор.
	 *
	 * @param TaskTypeRepository $repository Репозиторий типов заданий
	 */
	public function __construct( TaskTypeRepository $repository ) {
		parent::__construct();
		$this->repository = $repository;
	}

	/**
	 * Сохраняет типовое условие для конкретного подтипа задания.
	 *
	 * Вызывается при нажатии кнопки "Сохранить" в модальном окне
	 * настройки типа задания.
	 *
	 * @return void
	 */
	public function ajaxSaveBoilerplate(): void {
		// Проверка nonce для защиты от CSRF
		check_ajax_referer( 'fs_lms_manager_nonce', 'nonce' );

		// Проверка прав доступа (управление настройками)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'У вас недостаточно прав', 403 );

			return;
		}

		// Получение и санитизация данных
		$subject_key = sanitize_text_field( wp_unslash( $_POST['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_POST['term_slug'] ?? '' ) );
		$text        = wp_unslash( $_POST['text'] ?? '' ); // Текст может содержать HTML, не санитизируем как plain text

		// Валидация обязательных полей
		if ( ! $this->hasRequiredKeys( $subject_key, $term_slug ) ) {
			wp_send_json_error( 'Не указан предмет или тип задания' );

			return;
		}

		// Сохранение через репозиторий
		$success = $this->repository->update( [
			'subject_key' => $subject_key,
			'term_slug'   => $term_slug,
			'text'        => $text,
		] );

		// Отправка ответа
		if ( $success ) {
			wp_send_json_success( [ 'message' => 'Типовое условие сохранено' ] );
		} else {
			wp_send_json_error( 'Не удалось сохранить данные' );
		}
	}

	/**
	 * Возвращает типовое условие для конкретного подтипа задания.
	 *
	 * @return void
	 */
	public function ajaxGetBoilerplate(): void {
		// Проверка nonce для защиты от CSRF
		check_ajax_referer( 'fs_lms_manager_nonce', 'nonce' );

		// Проверка прав доступа (управление настройками)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'У вас недостаточно прав', 403 );

			return;
		}

		// Получение данных из GET-запроса
		$subject_key = sanitize_text_field( wp_unslash( $_GET['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_GET['term_slug'] ?? '' ) );

		// Валидация обязательных полей
		if ( ! $this->hasRequiredKeys( $subject_key, $term_slug ) ) {
			wp_send_json_error( 'Недостаточно данных' );

			return;
		}

		// Получение данных из репозитория
		$boilerplate = $this->repository->getBoilerplate( $subject_key, $term_slug );

		// Отправка ответа
		wp_send_json_success( [
			'text' => $boilerplate->text ?? '',
		] );
	}

	// ============================ ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет наличие обязательных ключей subject_key и term_slug.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug Слаг термина (номер задания)
	 *
	 * @return bool true, если оба ключа не пустые
	 */
	private function hasRequiredKeys( string $subject_key, string $term_slug ): bool {
		return ! empty( $subject_key ) && ! empty( $term_slug );
	}
}