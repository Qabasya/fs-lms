<?php

namespace Inc\Controllers;

use Inc\Callbacks\BoilerplateCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\TaskTypeRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BoilerplateController
 *
 * Контроллер управления типовыми условиями заданий (boilerplate).
 *
 * Отвечает за:
 * - Регистрацию AJAX-хуков (делегирует обработку в BoilerplateCallbacks)
 * - Отображение страницы списка и редактора boilerplate
 *
 * Вызов страницы идёт из AdminCallbacks::boilerplatePage.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class BoilerplateController extends BaseController implements ServiceInterface
{
	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param TaskTypeRepository   $taskTypes  Репозиторий типов заданий
	 * @param MetaBoxRepository    $metaboxes  Репозиторий метабоксов
	 * @param BoilerplateCallbacks $callbacks  Коллбеки для AJAX-операций
	 */
	public function __construct(
		private readonly TaskTypeRepository $taskTypes,
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateCallbacks $callbacks,
	) {
		parent::__construct();
	}

	// ============================ РЕГИСТРАЦИЯ ============================ //

	/**
	 * Точка входа контроллера — регистрирует AJAX-хуки.
	 *
	 * @return void
	 */
	public function register(): void
	{
		// Регистрация AJAX-обработчика для сохранения boilerplate
		add_action('wp_ajax_save_boilerplate', [$this->callbacks, 'ajaxSave']);

		// Регистрация AJAX-обработчика для удаления boilerplate
		add_action('wp_ajax_delete_boilerplate', [$this->callbacks, 'ajaxDelete']);
	}

	// ============================ ОТОБРАЖЕНИЕ ============================ //

	/**
	 * Главная точка входа для отрисовки страницы.
	 * Вызывается из AdminCallbacks::boilerplatePage.
	 *
	 * @return void
	 */
	public function displayPage(): void
	{
		// Получение параметров из URL
		$subject_key = sanitize_text_field(wp_unslash($_GET['subject'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_GET['term'] ?? ''));
		$action      = sanitize_text_field(wp_unslash($_GET['action'] ?? 'list'));

		// Валидация обязательных параметров
		if (empty($subject_key) || empty($term_slug)) {
			echo '<div class="notice notice-error"><p>Ошибка: недостаточно данных для загрузки страницы.</p></div>';
			return;
		}

		// Рендеринг соответствующего представления в зависимости от действия
		match ($action) {
			'new', 'edit' => $this->renderEditor($subject_key, $term_slug),
			default       => $this->renderList($subject_key, $term_slug),
		};
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Отрисовывает список boilerplate-шаблонов для указанного типа задания.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг типа задания
	 *
	 * @return void
	 */
	private function renderList(string $subject_key, string $term_slug): void
	{
		// Получение всех boilerplate для указанного типа задания
		$boilerplates = $this->taskTypes->getBoilerplates($subject_key, $term_slug);

		// Рендеринг шаблона списка
		$this->render('boilerplate-list', [
			'subject'      => $subject_key,
			'term'         => $term_slug,
			'boilerplates' => $boilerplates,
		]);
	}

	/**
	 * Отрисовывает редактор boilerplate-шаблона.
	 *
	 * При наличии UID — загружает существующий шаблон для редактирования.
	 * При отсутствии — открывает форму создания нового.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг типа задания
	 *
	 * @return void
	 */
	private function renderEditor(string $subject_key, string $term_slug): void
	{
		// Получение UID из GET-параметра
		$uid = sanitize_text_field(wp_unslash($_GET['uid'] ?? ''));

		// Загрузка существующего boilerplate (если UID указан)
		$boilerplate = $uid
			? $this->taskTypes->findBoilerplate($subject_key, $term_slug, $uid)
			: null;

		// Декодирование контента для отображения в форме
		$decoded_content = $boilerplate
			? $this->decodeContent($boilerplate->content)
			: [];

		// Получение ID шаблона для определения доступных полей условий
		$assignment  = $this->metaboxes->getAssignment($subject_key, $term_slug);
		$template_id = ($assignment && !empty($assignment->template_id))
			? $assignment->template_id
			: 'standard_task';

		// Рендеринг шаблона редактора
		$this->render('boilerplate-editor', [
			'subject'        => $subject_key,
			'term'           => $term_slug,
			'template_id'    => $template_id,
			'boilerplate'    => $boilerplate,
			'content_fields' => $decoded_content,
			'fields'         => $this->getConditionFields($template_id),
		]);
	}

	/**
	 * Возвращает только поля с суффиксом '_condition' для указанного шаблона.
	 *
	 * Использует фильтр fs_lms_get_templates, который наполняется в MetaBoxController.
	 * Если шаблон не найден или полей нет — возвращает дефолтное поле условия.
	 *
	 * @param string $template_id ID шаблона
	 *
	 * @return array<string, array{label: string}> Поля условий
	 */
	private function getConditionFields(string $template_id): array
	{
		// Получение списка всех шаблонов через фильтр
		$templates = apply_filters('fs_lms_get_templates', []);

		// Поиск нужного шаблона по ID
		foreach ($templates as $tpl) {
			if (isset($tpl->id) && $tpl->id === $template_id) {
				// Фильтрация полей: оставляем только те, что содержат '_condition'
				$condition_fields = array_filter(
					$tpl->fields,
					static fn(string $key): bool => str_contains($key, '_condition'),
					ARRAY_FILTER_USE_KEY
				);

				// Возвращаем найденные поля условий
				if (!empty($condition_fields)) {
					return $condition_fields;
				}

				break;
			}
		}

		// Фолбек: стандартное поле условия
		return ['task_condition' => ['label' => 'Условие задания']];
	}

	/**
	 * Декодирует сохранённый контент boilerplate.
	 *
	 * Если содержимое — валидный JSON-массив, возвращает его.
	 * Иначе оборачивает строку в массив с ключом task_condition (фолбэк).
	 *
	 * @param string $raw Сырое содержимое из базы
	 *
	 * @return array<string, string> Декодированный массив контента
	 */
	private function decodeContent(string $raw): array
	{
		if (empty($raw)) {
			return [];
		}

		// Попытка декодировать JSON
		$decoded = json_decode($raw, true);

		// Если JSON валидный и является массивом — возвращаем его
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			return $decoded;
		}

		// Иначе — оборачиваем строку в массив с ключом по умолчанию
		return ['task_condition' => $raw];
	}
}