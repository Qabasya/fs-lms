<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BoilerplatePageController
 *
 * Контроллер отображения страниц управления типовыми условиями (boilerplate).
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Отображение списка boilerplate** — рендеринг таблицы с шаблонами для конкретного типа задания.
 * 2. **Отображение редактора** — форма создания/редактирования boilerplate с динамическими полями.
 * 3. **Маршрутизация** — определение action (list/new/edit) и вызов соответствующего метода.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение данных репозиториям, а отрисовку — трейту TemplateRenderer.
 */
class BoilerplatePageController extends BaseController implements ServiceInterface {
	use TemplateRenderer;

	public function __construct(
		private readonly BoilerplateRepository $boilerplates,
		private readonly MetaBoxRepository $metaboxes,
		private readonly SubjectRepository $subjects,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Регистрация хуков и фильтров (реализация в будущем)
	}

	// ============================ ПУБЛИЧНЫЕ МЕТОДЫ ============================ //

	/**
	 * Главная точка входа для отрисовки страницы (вызывается из AdminCallbacks).
	 *
	 * @return void
	 */
	public function displayPage(): void {
		// wp_unslash() — удаляет экранирование слешей из суперглобальных массивов
		// sanitize_text_field() — удаляет все теги и спецсимволы, оставляя безопасный текст
		$subject_key = sanitize_text_field( wp_unslash( $_GET['subject'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
		$action      = sanitize_text_field( wp_unslash( $_GET['action'] ?? 'list' ) );

		// Валидация обязательных параметров
		if ( empty( $subject_key ) || empty( $term_slug ) ) {
			echo '<div class="notice notice-error"><p>Ошибка: недостаточно данных.</p></div>';
			return;
		}

		// Маршрутизация на основе action (PHP 8.0 match expression)
		match ( $action ) {
			'new', 'edit' => $this->renderEditor( $subject_key, $term_slug ),
			default       => $this->renderList( $subject_key, $term_slug ),
		};
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Отрисовывает список шаблонов.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина
	 *
	 * @return void
	 */
	private function renderList( string $subject_key, string $term_slug ): void {
		// Получение списка boilerplate из репозитория
		$boilerplates = $this->boilerplates->getBoilerplates( $subject_key, $term_slug );

		// get_term_by() — получает объект термина таксономии по полю (slug, name, id)
		// Параметры: поле поиска, значение, название таксономии
		$taxonomy    = $subject_key . '_task_number';
		$term_object = get_term_by( 'slug', $term_slug, $taxonomy );

		// Отображаемое имя термина (приоритет у description)
		$display_name = ( $term_object && ! empty( $term_object->description ) )
			? $term_object->description
			: $term_slug;

		// Получение данных предмета
		$subject_dto = $this->subjects->getByKey( $subject_key );

		// admin_url() — возвращает полный URL к административной панели WordPress
		// Формирует ссылку для возврата к настройкам предмета (вкладка 5)
		$this->render(
			'boilerplate-list',
			array(
				'subject'              => $subject_key,
				'term'                 => $term_slug,
				'boilerplates'         => $boilerplates,
				'display_name'         => $display_name,
				'subject_display_name' => $subject_dto ? $subject_dto->name : $subject_key,
				'back_url'             => admin_url( "admin.php?page=fs_subject_{$subject_key}&tab=tab-5" ),
			)
		);
	}

	/**
	 * Отрисовывает редактор boilerplate.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина
	 *
	 * @return void
	 */
	private function renderEditor( string $subject_key, string $term_slug ): void {
		// Получение UID и данных шаблона
		$uid         = sanitize_text_field( wp_unslash( $_GET['uid'] ?? '' ) );
		$boilerplate = $uid ? $this->boilerplates->findBoilerplate( $subject_key, $term_slug, $uid ) : null;
		$assignment  = $this->metaboxes->getAssignment( $subject_key, $term_slug );

		// Определение ID шаблона (поддержка enum или строки)
		$template_id = ( $assignment && ! empty( $assignment->template_id ) )
			? ( $assignment->template_id instanceof \UnitEnum ? $assignment->template_id->name : $assignment->template_id )
			: 'standard_task';

		$is_edit = null !== $boilerplate;

		// uniqid() — генерирует уникальный идентификатор на основе микросекунд
		// Префикс 'bp_' добавляется для удобства идентификации
		$this->render(
			'boilerplate-editor',
			array(
				'subject'        => $subject_key,
				'term'           => $term_slug,
				'template_id'    => $template_id,
				'is_edit'        => $is_edit,
				'page_title'     => $is_edit ? 'Редактировать условие' : 'Добавить условие',
				'bp_uid'         => $is_edit ? $boilerplate->uid : uniqid( 'bp_' ),
				'bp_title'       => $is_edit ? $boilerplate->title : '',
				'content_fields' => $boilerplate ? $this->decodeContent( $boilerplate->content ) : array(),
				'fields'         => $this->getConditionFields( $template_id ),
			)
		);
	}

	/**
	 * Получает условные поля для указанного шаблона.
	 *
	 * @param string $template_id ID шаблона
	 *
	 * @return array
	 */
	private function getConditionFields( string $template_id ): array {
		// apply_filters() — вызывает все функции, зарегистрированные на указанный хук
		// Позволяет сторонним разработчикам добавлять/изменять список шаблонов
		$templates = apply_filters( 'fs_lms_get_templates', array() );

		foreach ( $templates as $tpl ) {
			if ( isset( $tpl->id ) && $tpl->id === $template_id ) {
				// array_filter() с ARRAY_FILTER_USE_KEY — фильтрует массив по ключам
				// str_contains() — проверяет наличие подстроки (PHP 8.0)
				$cond_fields = array_filter( $tpl->fields, fn( $k ) => str_contains( $k, '_condition' ), ARRAY_FILTER_USE_KEY );
				return ! empty( $cond_fields ) ? $cond_fields : array( 'task_condition' => array( 'label' => 'Условие' ) );
			}
		}

		// Значение по умолчанию
		return array( 'task_condition' => array( 'label' => 'Условие задания' ) );
	}

	/**
	 * Декодирует JSON-контент в массив полей.
	 *
	 * @param string $raw JSON-строка
	 *
	 * @return array
	 */
	private function decodeContent( string $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		// json_decode(, true) — преобразует JSON в ассоциативный массив
		$decoded = json_decode( $raw, true );

		// json_last_error() — возвращает последнюю ошибку JSON (константа JSON_ERROR_NONE = 0)
		// Если JSON валидный и декодирован в массив — используем его, иначе legacy-формат
		return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : array( 'task_condition' => $raw );
	}
}
