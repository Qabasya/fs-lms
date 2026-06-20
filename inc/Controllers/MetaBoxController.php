<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Task\TaskMetaDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use Inc\Shared\Traits\Authorizer;

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

	use Authorizer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects        Репозиторий предметов
	 * @param MetaBoxRegistrar  $registrar       Регистратор метабоксов
	 * @param TemplateRegistry  $registry        Реестр шаблонов
	 * @param TemplateResolver  $resolver        Определитель шаблона для поста
	 * @param MetaBoxManager    $metaBoxManager  Менеджер мета-данных
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly TemplateRegistry  $registry,
		private readonly TemplateResolver  $resolver,
		private readonly MetaBoxManager    $metaBoxManager,
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
			static fn( $subject ) => PostTypeResolver::tasks( $subject->key ),
			$all_subjects
		);

		$this->registrar->add(
			'fs_lms_task_metabox',
			'Данные задания',
			array( $this, 'renderMetaboxContent' ),
			$task_post_types
		)->register();

		$this->registrar->add(
			'fs_lms_task_metabox',
			'Данные задачи',
			array( $this, 'renderMetaboxContent' ),
			array( \Inc\Services\PostTypeResolver::problems() )
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
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		if ( ! PostTypeResolver::isTaskPostType( $post->post_type )
			&& ! PostTypeResolver::isProblemPostType( $post->post_type ) ) {
			return;
		}

		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		// Определение шаблона для сохранения
		$template_id = $this->resolver->resolveId( $post );
		$template    = $this->registry->get( $template_id );

		if ( ! $template ) {
			return;
		}

		$raw_data = wp_unslash( $_POST[ PostMetaName::Meta->value ] ?? array() );

		$this->metaBoxManager->saveFields(
			$post_id,
			PostMetaName::Meta->value,
			is_array( $raw_data ) ? $raw_data : array(),
			$template->get_fields()
		);
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
