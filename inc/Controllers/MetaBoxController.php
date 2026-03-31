<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\Repositories\SubjectRepository;
use Inc\Registrars\PluginRegistrar;

/**
 * Class MetaBoxController
 *
 * Контроллер управления метабоксами заданий.
 * Отвечает за динамическую загрузку шаблонов метабоксов,
 * их регистрацию для каждого предмета и обработку сохранения данных.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class MetaBoxController extends BaseController implements ServiceInterface {
	/**
	 * Список доступных шаблонов метабоксов.
	 * Структура: [ 'template_id' => TemplateObject ]
	 *
	 * @var array<string, object>
	 */
	private array $templates = [];

	/**
	 * Репозиторий предметов.
	 *
	 * @var SubjectRepository
	 */
	private SubjectRepository $subjects;

	/**
	 * Композитный регистратор плагина.
	 *
	 * @var PluginRegistrar
	 */
	private PluginRegistrar $registrar;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозиторий, регистратор и загружает все шаблоны метабоксов.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param PluginRegistrar $registrar Композитный регистратор
	 */
	public function __construct( SubjectRepository $subjects, PluginRegistrar $registrar ) {
		parent::__construct();

		$this->subjects  = $subjects;
		$this->registrar = $registrar;

		// Динамически загружаем все доступные шаблоны из папки Templates
		$this->loadTemplatesDynamically();
	}

	/**
	 * Загружает все шаблоны из папки Inc/MetaBoxes/Templates.
	 *
	 * Использует glob() для поиска файлов *Template.php,
	 * создаёт экземпляры классов и проверяет наличие необходимых методов.
	 *
	 * @return void
	 */
	private function loadTemplatesDynamically(): void {
		// Формируем абсолютный путь к папке с шаблонами
		$template_path = $this->path( 'inc/MetaBoxes/Templates/' );

		// Проверяем существование директории
		if ( ! is_dir( $template_path ) ) {
			error_log( 'FS LMS: Templates directory not found: ' . $template_path );

			return;
		}

		// Находим все файлы, оканчивающиеся на "Template.php"
		$files = glob( $template_path . '*Template.php' );

		if ( ! $files ) {
			return;
		}

		// Перебираем найденные файлы
		foreach ( $files as $file ) {
			$basename   = basename( $file, '.php' );
			$class_name = "\\Inc\\MetaBoxes\\Templates\\" . $basename;

			// Пропускаем абстрактный базовый класс BaseTemplate
			if ( $basename === 'BaseTemplate' ) {
				continue;
			}

			// Проверяем существование класса и создаём экземпляр
			if ( class_exists( $class_name ) ) {
				$template = new $class_name();

				// Валидация: проверяем наличие обязательных методов
				if ( method_exists( $template, 'get_id' ) && method_exists( $template, 'render' ) ) {
					$this->templates[ $template->get_id() ] = $template;
				}
			}
		}

		// Логируем, если шаблоны не загрузились
		if ( empty( $this->templates ) ) {
			error_log( 'FS LMS: No valid templates found in Inc/MetaBoxes/Templates/' );
		}
	}

	/**
	 * Точка входа в сервис (вызывается из Init.php).
	 *
	 * Регистрирует метабоксы для каждого предмета и подключает обработчик сохранения.
	 *
	 * @return void
	 */
	public function register(): void {
		// Получаем все предметы
		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		// Определяем шаблон по умолчанию (standard_task или первый в списке)
		$default_template = $this->templates['standard_task']
		                    ?? reset( $this->templates );

		if ( ! $default_template ) {
			error_log( 'FS LMS: No default template available for metaboxes' );

			return;
		}

		// Для каждого предмета регистрируем метабокс
		foreach ( $all_subjects as $key => $data ) {
			$task_cpt = "{$key}_tasks";

			$this->registrar->metabox()
			                ->addTemplateBox(
				                $default_template,                          // Шаблон метабокса
				                $task_cpt,                                  // Тип поста
				                [ $this, 'renderMetaboxContent' ]             // Коллбек для отрисовки
			                );
		}

		// Выполняем регистрацию всех метабоксов
		$this->registrar->metabox()->register();

		// Подключаем обработчик сохранения мета-данных
		add_action( 'save_post', [ $this, 'handleMetaSave' ] );
	}

// ============================ КОЛЛБЕКИ И ОБРАБОТКА ============================ //

	/**
	 * Отрисовка контента метабокса.
	 *
	 * Коллбек, вызываемый WordPress при отображении метабокса.
	 * Определяет тип шаблона из мета-поля поста и рендерит соответствующий интерфейс.
	 *
	 * @param WP_Post $post Текущий пост
	 * @param array $callback_args Дополнительные аргументы из add_meta_box
	 *
	 * @return void
	 */
	public function renderMetaboxContent( $post, $callback_args ): void {
		// Получаем тип шаблона из мета-поля (устанавливается при создании задания)
		$template_id = get_post_meta( $post->ID, '_fs_lms_template_type', true ) ?: 'standard_task';

		// Находим объект шаблона в загруженном списке
		$template = $this->templates[ $template_id ] ?? $this->templates['standard_task']
		                                                ?? reset( $this->templates );

		// Если шаблон не найден — выводим ошибку
		if ( ! $template ) {
			echo '<p>Шаблон не найден.</p>';

			return;
		}

		// Добавляем nonce-поле для безопасности
		wp_nonce_field( 'fs_lms_save_meta', 'fs_lms_meta_nonce' );

		// Получаем текущие сохранённые значения мета-данных
		$values = get_post_meta( $post->ID, 'fs_lms_meta', true ) ?: [];

		// Рендерим контент метабокса
		echo '<div class="fs-lms-metabox-wrapper">';
		$template->render( $post, $values );
		echo '</div>';
	}

	/**
	 * Обработка сохранения мета-данных поста.
	 *
	 * Вызывается при сохранении поста. Проверяет nonce, права доступа,
	 * и на основе типа шаблона санитизирует и сохраняет мета-поля.
	 *
	 * @param int $post_id ID сохраняемого поста
	 *
	 * @return void
	 */
	public function handleMetaSave( $post_id ): void {
		// Проверка nonce
		if ( ! isset( $_POST['fs_lms_meta_nonce'] )
		     || ! wp_verify_nonce( $_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta' ) ) {
			return;
		}

		// Пропускаем автосохранение
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Проверка прав текущего пользователя
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Определяем тип шаблона для выбора полей санитизации
		$template_id = get_post_meta( $post_id, '_fs_lms_template_type', true ) ?: 'standard_task';
		$template    = $this->templates[ $template_id ] ?? null;

		// Если шаблон не найден или не имеет метода get_fields — выходим
		if ( ! $template || ! method_exists( $template, 'get_fields' ) ) {
			return;
		}

		// Получаем список полей из шаблона
		$fields = $template->get_fields();

		// Получаем сырые данные из POST
		$raw_data = $_POST['fs_lms_meta'] ?? [];

		// Санитизация каждого поля в соответствии с его типом
		$sanitized = [];
		foreach ( $fields as $id => $config ) {
			if ( isset( $raw_data[ $id ] ) && isset( $config['object'] ) ) {
				$sanitized[ $id ] = $config['object']->sanitize( $raw_data[ $id ] );
			}
		}

		// Сохраняем очищенные мета-данные
		update_post_meta( $post_id, 'fs_lms_meta', $sanitized );
	}
}