<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Nonce;
use Inc\Enums\TaskTemplate;
use Inc\Managers\PostManager;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TemplateManagerCallbacks
 *
 * AJAX-обработчики для управления шаблонами заданий.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Привязка шаблона к типу задания** — обновление шаблона для конкретного термина таксономии.
 * 2. **Получение структуры полей** — возврат списка ConditionField для фронтенд-редактора.
 * 3. **Сохранение boilerplate** — сохранение типового условия для типа задания (legacy режим).
 * 4. **Получение boilerplate** — возврат сохранённого типового условия для редактирования.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с шаблонами метабоксам и репозиториям, а валидацию — трейтам Authorizer/Sanitizer.
 */
class TemplateManagerCallbacks extends BaseController {
	
	use Authorizer;
	use Sanitizer;
	
	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param PostManager           $posts        Менеджер постов
	 */
	public function __construct(
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private PostManager $posts,
	) {
		parent::__construct();
	}
	
	// ============================ AJAX-КОЛЛБЕКИ ============================ //
	
	/**
	 * Обновляет привязку шаблона к конкретному типу задания (термину таксономии).
	 *
	 * @return void
	 */
	public function ajaxUpdateTermTemplate(): void {
		$this->authorize( Nonce::Subject );
		
		$term_id     = $this->requireInt( 'term_id', error: 'Недостаточно данных для обновления' );
		$template_id = $this->requireText( 'template', error: 'Недостаточно данных для обновления' );
		
		// get_term() — получает объект термина по ID
		$term = get_term( $term_id );
		
		// is_wp_error() — проверяет, является ли результат ошибкой WordPress
		if ( ! $term || is_wp_error( $term ) ) {
			$this->error( 'Тип задания не найден в WordPress', array( 'term_id' => $term_id ) );
		}
		
		// str_replace() — удаляет суффикс '_task_number' для получения ключа предмета
		$subject_key = str_replace( '_task_number', '', $term->taxonomy );
		
		// countByTerm() — подсчёт постов, привязанных к данному термину
		$post_count = $this->posts->countByTerm( "{$subject_key}_tasks", $term->taxonomy, $term->term_id );
		
		if ( $post_count > 0 ) {
			$this->error( "Нельзя изменить шаблон: по этому типу уже создано {$post_count} заданий." );
		}
		
		// updateAssignment() — сохраняет привязку шаблона к термину
		$result = $this->metaboxes->updateAssignment(
			$subject_key,
			(string) $term->slug,
			$template_id
		);
		
		$this->respond(
			$result,
			error_msg: 'Ошибка сохранения шаблона',
			success_msg: "Шаблон для задания №{$term->slug} успешно сохранён!"
		);
	}
	
	/**
	 * Возвращает структуру ConditionField-полей шаблона для конкретного типа задания.
	 *
	 * @return void
	 */
	public function ajaxGetTemplateStructure(): void {
		$this->authorize( Nonce::Subject );
		
		$subject_key = $this->requireKey( 'subject_key', 'GET', 'Недостаточно данных. Error code: #TMC108' );
		$term_slug   = $this->requireKey( 'term_slug', 'GET', 'Недостаточно данных. Error code: #TMC108' );
		
		// Получение ID шаблона из привязки
		$assignment = $this->metaboxes->getAssignment( $subject_key, $term_slug );
		
		// TaskTemplate::fromDatabase() — преобразует строку в enum TaskTemplate
		$template   = TaskTemplate::fromDatabase( $assignment->template_id ?? '' );
		$class_name = $template->class();
		
		try {
			// class_exists() — проверяет существование класса
			if ( ! class_exists( $class_name ) ) {
				throw new \Exception( "Класс шаблона {$class_name} не найден." );
			}
			
			/** @var \Inc\MetaBoxes\Templates\BaseTemplate $template_obj */
			$template_obj = new $class_name();
			
			$all_fields = $template_obj->get_fields();
			
			// instanceof — проверяет, принадлежит ли объект классу ConditionField
			$condition_fields = array_filter(
				$all_fields,
				static fn( $config ) => isset( $config['object'] )
				                        && $config['object'] instanceof ConditionField
			);
			
			// array_values() — сбрасывает ключи массива для преобразования в нумерованный
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
			
			$this->success( array( 'fields' => $structure ) );
			
		} catch ( \Throwable $e ) {
			$this->error( 'Ошибка загрузки структуры: ' . $e->getMessage() );
		}
	}
	
	/**
	 * Сохраняет boilerplate-текст для типа задания (legacy режим).
	 * Использует фиксированный UID 'default' для совместимости со старым кодом.
	 *
	 * @return void
	 */
	public function ajaxSaveTaskBoilerplate(): void {
		$this->authorize( Nonce::Subject );
		
		$subject_key = $this->requireKey( 'subject_key', error: 'Недостаточно данных. Error code: #TMC172' );
		$term_slug   = $this->requireKey( 'term_slug', error: 'Недостаточно данных. Error code: #TMC173' );
		// sanitizeHtml() — очищает HTML-контент через wp_kses_post()
		$text        = $this->sanitizeHtml( 'text' );
		
		$dto = new TaskTypeBoilerplateDTO(
			uid: 'default',
			subject_key: $subject_key,
			term_slug: $term_slug,
			title: 'Типовое условие',
			content: $text,
			is_default: true
		);
		
		$result = $this->boilerplates->updateBoilerplate( $dto );
		
		$this->respond(
			$result,
			error_msg: 'Ошибка сохранения типового условия',
			success_msg: 'Типовое условие сохранено'
		);
	}
	
	/**
	 * Возвращает дефолтный boilerplate для типа задания (legacy режим).
	 *
	 * @return void
	 */
	public function ajaxGetBoilerplate(): void {
		$this->authorize( Nonce::Subject );
		
		$subject_key = $this->requireKey( 'subject_key', 'GET', 'Недостаточно данных. Error code: #TMC206' );
		$term_slug   = $this->requireKey( 'term_slug', 'GET', 'Недостаточно данных. Error code: #TMC207' );
		
		// getDefaultBoilerplate() — возвращает один DTO с UID 'default'
		$result = $this->boilerplates->getDefaultBoilerplate( $subject_key, $term_slug );
		
		// Оператор ?-> (nullsafe) — безопасный доступ к свойству, если объект не null (PHP 8.0)
		$this->success(
			array(
				'text' => $result?->content ?? '',
				'uid'  => $result?->uid ?? null,
			)
		);
	}
}