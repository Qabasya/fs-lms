<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Task\TaskMetaDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\MetaBoxes\Fields\HintField;
use Inc\Managers\Wp\MetaBoxManager;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\MetaBoxRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskBundleService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

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
	use Sanitizer;
	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects        Репозиторий предметов
	 * @param MetaBoxRegistrar  $registrar       Регистратор метабоксов
	 * @param TemplateRegistry  $registry        Реестр шаблонов
	 * @param TemplateResolver  $resolver        Определитель шаблона для поста
	 * @param MetaBoxManager    $metaBoxManager  Менеджер мета-данных
	 * @param PostManager       $postManager     Менеджер постов (чтение меты задания)
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly MetaBoxRegistrar  $registrar,
		private readonly TemplateRegistry  $registry,
		private readonly TemplateResolver  $resolver,
		private readonly MetaBoxManager    $metaBoxManager,
		private readonly PostManager       $postManager,
		private readonly TaskBundleService $taskBundles,
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
		add_action( 'save_post', array( $this, 'handleMetaSave' ) );
		add_action( 'transition_post_status', array( $this, 'handleBundleStatusTransition' ), 10, 3 );
		add_filter( 'fs_lms_get_templates', array( $this, 'getTemplatesList' ) );
		add_filter( 'default_hidden_meta_boxes', array( $this, 'defaultHiddenMetaboxes' ), 10, 2 );
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

		foreach ( $task_post_types as $post_type ) {
			remove_meta_box( 'pageparentdiv', $post_type, 'side' );
		}

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
			array( PostTypeResolver::problems() )
		)->register();

		$this->registrar->add(
			'fs_lms_task_hint',
			'Подсказка',
			array( $this, 'renderHintMetabox' ),
			array_merge( $task_post_types, array( PostTypeResolver::problems() ) ),
			array( 'priority' => 'low' )
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
			$this->render( 'admin/components/admin-notice', array( 'type' => 'error', 'message' => 'Шаблон не найден.' ) );
			return;
		}

		// wp_nonce_field() — создаёт скрытое поле с nonce (токен для защиты от CSRF)
		// Значение Nonce::SaveMeta->value — 'fs_lms_save_meta'
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );

		// Отрисовку полей делегируем конкретному шаблону — обёртку печатает партиал.
		$this->render( 'admin/metaboxes/fields-wrapper', array(
			'wrapper_class' => 'fs-lms-metabox-wrapper',
			'post'          => $post,
			'template'      => $template,
			'values'        => $this->postManager->taskMeta( $post->ID ),
		) );
	}

	/**
	 * Отрисовка метабокса «Подсказка» (вынесен отдельно от основного шаблона).
	 *
	 * @param \WP_Post $post
	 */
	public function renderHintMetabox( \WP_Post $post ): void {
		$values = $this->postManager->taskMeta( $post->ID );

		( new HintField() )->render( $post, 'task_hint', 'Подсказка', $values['task_hint'] ?? '' );
	}

	/**
	 * Скрывает метабокс «Подсказка» по умолчанию (пользователь может включить через Screen Options).
	 *
	 * @param string[]   $hidden
	 * @param \WP_Screen $screen
	 * @return string[]
	 */
	public function defaultHiddenMetaboxes( array $hidden, \WP_Screen $screen ): array {
		if ( PostTypeResolver::isTaskPostType( $screen->post_type )
			|| PostTypeResolver::isProblemPostType( $screen->post_type ) ) {
			$hidden[] = 'fs_lms_task_hint';
		}

		return $hidden;
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

		$data = $this->unslashArray( PostMetaName::Meta->value );

		// Мержим (не заменяем) — hint сохраняется отдельным метабоксом и должен
		// оставаться нетронутым, если его метабокс скрыт через Screen Options.
		$all_fields = array_merge(
			$template->get_fields(),
			array( 'task_hint' => array( 'label' => 'Подсказка', 'object' => new HintField() ) )
		);

		$this->metaBoxManager->saveFieldsMerge(
			$post_id,
			PostMetaName::Meta->value,
			$data,
			$all_fields
		);

		// Связка 19-21: parent — единственный источник контента, children материализуются
		// автоматически при каждом сохранении (см. .docs/Tasks.md, §3.1).
		if ( TaskTemplate::Triple->value === $template_id ) {
			$this->taskBundles->syncChildren( $post_id );
		}
	}

	/**
	 * Переносит статус parent-поста связки на его children (draft/publish/trash) —
	 * children не должны остаться опубликованными при удалённом/задрафченном parent.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function handleBundleStatusTransition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( ! PostTypeResolver::isTaskPostType( $post->post_type ) && ! PostTypeResolver::isProblemPostType( $post->post_type ) ) {
			return;
		}
		if ( $this->resolver->resolveId( $post ) !== TaskTemplate::Triple->value ) {
			return;
		}

		$this->taskBundles->cascadeStatus( $post->ID, $new_status );
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
