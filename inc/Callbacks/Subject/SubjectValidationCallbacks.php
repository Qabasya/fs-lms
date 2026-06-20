<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\Enums\Wp\PostMetaName;
use Inc\Services\PostTypeResolver;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class SubjectValidationCallbacks
 *
 * Коллбеки для валидации заданий перед публикацией и отображения предупреждений в админ-панели.
 *
 * @package Inc\Callbacks\Subject
 *
 * ### Основные обязанности:
 *
 * 1. **Валидация перед публикацией** — проверка наличия обязательных таксономий и мета-полей
 *    перед публикацией задания. Блокирует публикацию при ошибках.
 * 2. **Предупреждение на экране редактирования** — отображение уведомления, если обязательная
 *    таксономия не содержит термов (чтобы автор мог заранее их создать).
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику валидации TaskPublishValidator.
 * Используется в SubjectController для подключения к хукам 'wp_insert_post_data'
 * и 'admin_notices'.
 */
class SubjectValidationCallbacks extends BaseController {

	use Sanitizer;

	/**
	 * Конструктор коллбеков.
	 *
	 * @param TaskPublishValidator $validator Валидатор заданий перед публикацией
	 */
	public function __construct(
		private readonly TaskPublishValidator $validator,
	) {
		parent::__construct();
	}

	/**
	 * Вызывается на хуке 'wp_insert_post_data'. Блокирует публикацию,
	 * если отсутствуют обязательные таксономии или мета-поля.
	 *
	 * @param array $data    Очищенные данные поста
	 * @param array $postarr Неочищенные данные из $_POST
	 *
	 * @return array
	 */
	public function validateRequiredTaxonomies( array $data, array $postarr ): array {
		$postType = $data['post_type'] ?? '';

		// Только для типов постов заданий (суффикс '_tasks')
		if ( ! PostTypeResolver::isTaskPostType( $postType ) ) {
			return $data;
		}

		// Проверяем только при попытке публикации
		if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		// Пропускаем автосохранение
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		// Получение данных из запроса
		$postMeta   = (array) ( $_POST[ PostMetaName::Meta->value ] ?? array() );
		$templateId = $this->sanitizeKey( PostMetaName::TemplateType->value );
		$taxInput   = (array) ( $_POST['tax_input'] ?? array() );

		// Проверка на блокирующие ошибки
		$error = $this->validator->getBlockingError( $postType, $taxInput )
		         ?? $this->validator->getSoftError( $postMeta, $templateId );

		if ( null !== $error ) {
			// Откатываем статус обратно в draft, чтобы публикация не прошла
			$data['post_status'] = 'draft';
			// Сохраняем сообщение в транзиент, чтобы показать его в admin_notices после редиректа
			set_transient( 'fs_lms_publish_error_' . get_current_user_id(), $error, 60 );
		}

		return $data;
	}

	/**
	 * Вызывается на хуке 'admin_notices' на экране редактирования задания.
	 * Предупреждает, если обязательная таксономия не содержит термов.
	 *
	 * @return void
	 */
	public function showEmptyRequiredTaxNotice(): void {
		$screen = get_current_screen();

		// Показываем ошибку валидации публикации, если она была отложена
		$transientKey = 'fs_lms_publish_error_' . get_current_user_id();
		$publishError = get_transient( $transientKey );
		if ( $publishError ) {
			delete_transient( $transientKey );
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'Невозможно опубликовать задание', 'fs-lms' ),
				esc_html( $publishError )
			);
		}

		if ( ! $screen || ! PostTypeResolver::isTaskPostType( $screen->post_type ) ) {
			return;
		}

		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $screen->post_type );
		// Находим обязательные таксономии, в которых нет ни одного терма
		$emptyTaxes = $this->validator->findEmptyRequired( $subjectKey );

		if ( empty( $emptyTaxes ) ) {
			return;
		}

		foreach ( $emptyTaxes as $tax ) {
			// Проверка права на управление таксономиями
			$canManage = current_user_can( 'manage_categories' );

			if ( $canManage ) {
				$link = sprintf(
					' <a href="%s">Добавить термы &rarr;</a>',
					esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax->slug ) )
				);
			} else {
				$link = ' Обратитесь к администратору.';
			}

			printf(
				'<div class="notice notice-warning"><p><strong>Внимание:</strong> Обязательная таксономия «%s» не содержит термов — задание нельзя будет опубликовать.%s</p></div>',
				esc_html( $tax->name ),
				$canManage ? $link : esc_html( $link )
			);
		}
	}
}