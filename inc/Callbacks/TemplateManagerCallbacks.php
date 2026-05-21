<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTemplateAssignmentDTO;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Nonce;
use Inc\Enums\TaskTemplate;
use Inc\Managers\PostManager;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Services\PostTypeResolver;
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

	use Authorizer;  // Трейт с методами authorize(), requireInt(), respond() и др.
	use Sanitizer;   // Трейт с методами sanitizeHtml(), requireKey() и др.

	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param PostManager           $posts        Менеджер постов
	 */
	public function __construct(
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly PostManager $posts,
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

		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			$this->error( 'Тип задания не найден в WordPress', array( 'term_id' => $term_id ) );
		}

		$subject_key = str_replace( '_task_number', '', $term->taxonomy );

		// Подсчёт заданий, уже созданных для этого типа (чтобы нельзя было менять шаблон)
		$post_count = $this->posts->countByTerm( PostTypeResolver::tasks( $subject_key ), $term->taxonomy, $term->term_id );

		if ( $post_count > 0 ) {
			$this->error( "Нельзя изменить шаблон: по этому типу уже создано {$post_count} заданий." );
		}

		$result = $this->metaboxes->save(
			new TaskTemplateAssignmentDTO( $subject_key, (string) $term->slug, $template_id )
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

		$assignment = $this->metaboxes->getAssignment( $subject_key, $term_slug );
		$template   = TaskTemplate::fromDatabase( $assignment->template_id ?? '' );
		$class_name = $template->class();

		try {
			if ( ! class_exists( $class_name ) ) {
				throw new \Exception( "Класс шаблона {$class_name} не найден." );
			}

			/** @var \Inc\MetaBoxes\Templates\BaseTemplate $template_obj */
			$template_obj = new $class_name();
			$all_fields   = $template_obj->get_fields();

			// Оставляем только ConditionField (условные поля для редактора boilerplate)
			$condition_fields = array_filter(
				$all_fields,
				static fn( $config ) => isset( $config['object'] )
				                        && $config['object'] instanceof ConditionField
			);

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
		$text        = $this->sanitizeHtml( 'text' );

		$dto = new TaskTypeBoilerplateDTO(
			uid: 'default',
			subject_key: $subject_key,
			term_slug: $term_slug,
			title: 'Типовое условие',
			content: $text,
			is_default: true
		);

		$result = $this->boilerplates->save( $dto );

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

		$result = $this->boilerplates->getDefaultBoilerplate( $subject_key, $term_slug );

		$this->success(
			array(
				'text' => $result?->content ?? '',
				'uid'  => $result?->uid ?? null,
			)
		);
	}
}