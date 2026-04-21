<?php

namespace Inc\Callbacks;

use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Nonce;
use Inc\Enums\TaskTemplate;
use Inc\Managers\PostManager;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Shared\Traits\Authorizer;


/**
 * Class TemplateManagerCallbacks
 *
 * AJAX-обработчики для Менеджера заданий:
 * - привязка шаблонов к типам заданий
 * - обновление шаблона конкретного термина
 * - получение структуры полей шаблона
 * - сохранение и получение boilerplate-текста (legacy режим)
 *
 * @package Inc\Callbacks
 */
class TemplateManagerCallbacks {
	use Authorizer;
	
	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов
	 * @param BoilerplateRepository $boilerplates Репозиторий типов заданий
	 */
	public function __construct(
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private PostManager $posts,
		private readonly AuthorizationValidator $authorization_validator,
	) {
	}
	
	// ============================ AJAX-КОЛЛБЕКИ ============================ //
	
	/**
	 * Обновляет привязку шаблона к конкретному типу задания (термину таксономии).
	 *
	 * Используется в интерфейсе управления предметами.
	 *
	 * @return void
	 */
	public function ajaxUpdateTermTemplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		// Получение и валидация данных
		$term_id     = absint( $_POST['term_id'] ?? 0 );
		$template_id = sanitize_text_field( wp_unslash( $_POST['template'] ?? '' ) );
		
		if ( ! $term_id || ! $template_id ) {
			wp_send_json_error( 'Недостаточно данных для обновления' );
		}
		
		// Получение объекта термина
		$term = get_term( $term_id );
		
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( 'Тип задания не найден в WordPress' );
		}
		
		// Извлечение ключа предмета из таксономии: "phys_task_number" → "phys"
		$subject_key = str_replace( '_task_number', '', $term->taxonomy );
		
		// Запрет изменения шаблона, если по этому типу уже созданы задания
		$post_count = $this->posts->countByTerm( "{$subject_key}_tasks", $term->taxonomy, $term->term_id );
		
		if ( $post_count > 0 ) {
			wp_send_json_error( "Нельзя изменить шаблон: по этому типу уже создано {$post_count} заданий." );
		}
		
		// Сохранение привязки через репозиторий
		$success = $this->metaboxes->updateAssignment(
			$subject_key,
			(string) $term->slug,
			$template_id
		);
		
		if ( ! $success ) {
			wp_send_json_error( 'Ошибка сохранения шаблона' );
		}
		
		wp_send_json_success( "Шаблон для задания №{$term->slug} успешно сохранён!" );
	}
	
	/**
	 * Возвращает структуру ConditionField-полей шаблона для конкретного типа задания.
	 *
	 * Используется на фронте для построения редактора boilerplate.
	 * Отдаёт только данные (id, label) — HTML строит JS на стороне клиента.
	 *
	 * @return void
	 */
	public function ajaxGetTemplateStructure(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		// Получение и валидация данных из GET-запроса
		$subject_key = sanitize_text_field( wp_unslash( $_GET['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_GET['term_slug'] ?? '' ) );
		
		if ( ! $subject_key || ! $term_slug ) {
			wp_send_json_error( 'Недостаточно данных. Error code: #TMC108' );
		}
		
		// Получаем объект привязки из БД
		$assignment = $this->metaboxes->getAssignment( $subject_key, $term_slug );
		
		// Определяем шаблон через Enum (метод fromDatabase обрабатывает строку или null)
		$template = TaskTemplate::fromDatabase( $assignment->template_id ?? '' );
		
		// Получаем имя класса из Enum
		$class_name = $template->class();
		
		try {
			// Прямое создание объекта (вместо поиска в фильтрах)
			if ( ! class_exists( $class_name ) ) {
				throw new \Exception( "Класс шаблона {$class_name} не найден." );
			}
			
			/** @var \Inc\MetaBoxes\Templates\BaseTemplate $template_obj */
			$template_obj = new $class_name();
			
			// Вызываем метод get_fields() прямо у объекта шаблона
			$all_fields = $template_obj->get_fields();
			
			// Фильтрация: оставляем только ConditionField-поля
			$condition_fields = array_filter(
				$all_fields,
				static fn( $config ) => isset( $config['object'] )
				                        && $config['object'] instanceof ConditionField
			);
			
			// Отдаём только данные (id, label)
			$structure = array_values(
				array_map(
					static fn( string $key, array $config ): array => array(
						'id'    => $key,
						'label' => $config['label'],
					),
					array_keys( $condition_fields ),
					$condition_fields
				)
			);
			
			wp_send_json_success( array( 'fields' => $structure ) );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'FS LMS TemplateManagerCallbacks: ' . $e->getMessage() );
			}
			wp_send_json_error( 'Ошибка загрузки структуры: ' . $e->getMessage() );
		}
	}
	
	/**
	 * Сохраняет boilerplate-текст для типа задания (legacy режим).
	 *
	 * Использует фиксированный uid 'default' — этот метод работает
	 * в режиме "один boilerplate на тип задания".
	 * Полноценный CRUD с несколькими вариантами — в BoilerplateCallbacks.
	 *
	 * @return void
	 */
	public function ajaxSaveTaskBoilerplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		// Получение и валидация данных
		$subject_key = sanitize_text_field( wp_unslash( $_POST['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_POST['term_slug'] ?? '' ) );
		// wp_unslash обязателен для корректного хранения HTML/JSON из редактора
		$text = wp_kses_post( wp_unslash( $_POST['text'] ?? '' ) );
		
		if ( ! $subject_key || ! $term_slug ) {
			wp_send_json_error( 'Недостаточно данных. Error code: #TMC180' );
		}
		
		// Фиксированный uid гарантирует обновление, а не создание нового варианта
		$dto = new TaskTypeBoilerplateDTO(
			uid        : 'default',
			subject_key: $subject_key,
			term_slug  : $term_slug,
			title      : 'Типовое условие',
			content    : $text,
			is_default : true
		);
		
		// Сохранение через репозиторий
		$success = $this->boilerplates->updateBoilerplate( $dto );
		
		if ( ! $success ) {
			wp_send_json_error( 'Ошибка сохранения типового условия' );
		}
		
		wp_send_json_success( array( 'message' => 'Типовое условие сохранено' ) );
	}
	
	/**
	 * Возвращает дефолтный boilerplate для типа задания (legacy режим).
	 *
	 * @return void
	 */
	public function ajaxGetBoilerplate(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );
		
		// Получение и валидация данных из GET-запроса
		$subject_key = sanitize_text_field( wp_unslash( $_GET['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_GET['term_slug'] ?? '' ) );
		
		if ( ! $subject_key || ! $term_slug ) {
			wp_send_json_error( 'Недостаточно данных. Error code: #TMC217' );
		}
		
		// Репозиторий сам знает, как найти дефолтный вариант
		$result = $this->boilerplates->getDefaultBoilerplate( $subject_key, $term_slug );
		
		wp_send_json_success(
			array(
				'text' => $result?->content ?? '',
				'uid'  => $result?->uid ?? null,
			)
		);
	}
}
