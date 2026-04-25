<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\TaskMetaDTO;
use Inc\Enums\Nonce;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Services\TemplateRegistry;
use Inc\Services\TemplateResolver;

/**
 * Class MetaBoxController
 *
 * Контроллер управления метабоксами заданий.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация метабоксов** — динамически создаёт метабоксы для всех типов постов заданий.
 * 2. **Отрисовка содержимого** — делегирует отрисовку полей конкретному шаблону.
 * 3. **Сохранение данных** — обрабатывает и валидирует сохранение мета-полей.
 * 4. **Предоставление списка шаблонов** — через фильтр fs_lms_get_templates.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу MetaBoxRegistrar (регистрация), TemplateRegistry (хранение шаблонов)
 * и TemplateResolver (определение нужного шаблона для поста).
 */
class MetaBoxController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects   Репозиторий предметов
	 * @param MetaBoxRegistrar  $registrar  Регистратор метабоксов
	 * @param TemplateRegistry  $registry   Реестр шаблонов
	 * @param TemplateResolver  $resolver   Определитель шаблона для поста
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar $registrar,
		private readonly TemplateRegistry $registry,
		private readonly TemplateResolver $resolver
	) {
		parent::__construct();
	}

	// ============================ РЕГИСТРАЦИЯ ============================ //

	/**
	 * Точка входа в сервис (вызывается из Init.php).
	 *
	 * @return void
	 */
	public function register(): void {
		// add_action() — регистрирует функцию-обработчик на указанное событие WordPress
		// 'add_meta_boxes' — хук, срабатывающий перед добавлением метабоксов
		add_action( 'add_meta_boxes', array( $this, 'handleAddMetaBoxes' ) );

		// 'save_post' — хук, срабатывающий при сохранении поста
		add_action( 'save_post', array( $this, 'handleMetaSave' ) );

		// add_filter() — регистрирует функцию для фильтрации данных
		// 'fs_lms_get_templates' — кастомный фильтр для получения списка шаблонов
		add_filter( 'fs_lms_get_templates', array( $this, 'getTemplatesList' ) );
	}

	/**
	 * Регистрирует метабокс для всех CPT заданий.
	 *
	 * @return void
	 */
	public function handleAddMetaBoxes(): void {
		// Получение всех предметов из БД
		$all_subjects = $this->subjects->readAll();
		if ( empty( $all_subjects ) ) {
			return;
		}

		// Формирование списка типов постов заданий (например: ['math_tasks', 'phys_tasks'])
		$task_post_types = array_map(
			static fn( $subject ) => "{$subject->key}_tasks",
			$all_subjects
		);

		// Метод add() принимает: ID метабокса, заголовок, коллбек отрисовки, типы постов
		$this->registrar->add(
			'fs_lms_task_metabox',
			'Данные задания',
			array( $this, 'renderMetaboxContent' ),
			$task_post_types
		)->register();
	}

	/**
	 * Отрисовка контента метабокса.
	 *
	 * @param \WP_Post $post Объект поста WordPress
	 *
	 * @return void
	 */
	public function renderMetaboxContent( \WP_Post $post ): void {
		// Определение ID шаблона для текущего поста
		$template_id = $this->resolver->resolveId( $post );
		$template    = $this->registry->get( $template_id );

		if ( ! $template ) {
			echo '<p>Ошибка: шаблон не найден.</p>';
			return;
		}

		// wp_nonce_field() — создаёт скрытое поле с nonce (токен для защиты от CSRF)
		// Значение Nonce::SaveMeta->value — 'fs_lms_save_meta'
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		echo '<div class="fs-lms-metabox-wrapper">';
		// Делегирование отрисовки полей конкретному шаблону
		$template->render( $post );
		echo '</div>';
	}

	/**
	 * Обработка сохранения данных метабокса.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function handleMetaSave( int $post_id ): void {
		// DOING_AUTOSAVE — константа, определяющая, выполняется ли автосохранение
		// Пропускаем автосохранение и проверяем наличие nonce
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['fs_lms_meta_nonce'] ) ) {
			return;
		}

		// get_post() — получает объект поста по ID
		// str_ends_with() — проверяет окончание строки (задания имеют суффикс '_tasks')
		$post = get_post( $post_id );
		if ( ! $post || ! str_ends_with( $post->post_type, '_tasks' ) ) {
			return;
		}

		// wp_verify_nonce() — проверяет валидность nonce (защита от CSRF)
		// current_user_can() — проверяет, есть ли у пользователя право редактировать пост
		if ( ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], Nonce::SaveMeta->value ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Определение шаблона для сохранения
		$template_id = $this->resolver->resolveId( $post );
		$template    = $this->registry->get( $template_id );

		if ( ! $template ) {
			return;
		}

		// Получение структуры полей шаблона
		$fields = $template->get_fields();

		// wp_unslash() — удаляет экранирование слешей (обратная операция для wp_slash)
		$raw_data  = wp_unslash( $_POST['fs_lms_meta'] ?? array() );
		$sanitized = array();

		// Санитизация каждого поля через его объект Field
		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ], $config['object'] ) ) {
				$sanitized[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		// update_post_meta() — обновляет или создаёт мета-поле поста
		// Сохраняем как ассоциативный массив (автоматически сериализуется)
		update_post_meta( $post_id, 'fs_lms_meta', $sanitized );
	}

	/**
	 * Возвращает список всех зарегистрированных шаблонов в виде DTO.
	 *
	 * @return array<TaskMetaDTO>
	 */
	public function getTemplatesList(): array {
		// array_values() — сбрасывает индексы массива (преобразует ассоциативный в нумерованный)
		// array_map() — преобразует каждый шаблон в DTO
		return array_map(
			static fn( $template ) => new TaskMetaDTO(
				id    : $template->get_id(),
				title : $template->get_name(),
				fields: $template->get_fields()
			),
			array_values( $this->registry->getAll() )
		);
	}
}
