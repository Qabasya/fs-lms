<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\TaxonomyRepository;

/**
 * Class TaxonomySettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с таксономиями.
 * Отвечает за создание, обновление и удаление кастомных таксономий предметов.
 *
 * @package Inc\Callbacks
 */
class TaxonomySettingsCallbacks
{
	/**
	 * Конструктор.
	 *
	 * @param TaxonomyRepository $taxonomies Репозиторий таксономий
	 */
	public function __construct(
		private TaxonomyRepository $taxonomies,
	) {
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Создаёт новую таксономию для предмета.
	 *
	 * @return void
	 */
	public function ajaxStoreTaxonomy(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных таксономии
		[$subject_key, $tax_slug, $tax_name, $display_type, $default_term] = $this->requireTaxonomyData();

		// Сохранение через репозиторий
		$this->taxonomies->update([
			'subject_key'  => $subject_key,
			'tax_slug'     => $tax_slug,
			'name'         => $tax_name,
			'display_type' => $display_type,
			'default_term' => $default_term,
		]);

		// Отправка результата
		$this->sendResult('Таксономия создана');
	}

	/**
	 * Обновляет существующую таксономию (Quick Edit).
	 *
	 * @return void
	 */
	public function ajaxUpdateTaxonomy(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных таксономии
		[$subject_key, $tax_slug, $tax_name, $display_type, $default_term] = $this->requireTaxonomyData();

		// Обновление через репозиторий
		$this->taxonomies->update([
			'subject_key'  => $subject_key,
			'tax_slug'     => $tax_slug,
			'name'         => $tax_name,
			'display_type' => $display_type,
			'default_term' => $default_term,
		]);

		// Отправка результата
		$this->sendResult('Таксономия обновлена');
	}

	/**
	 * Удаляет таксономию предмета.
	 *
	 * @return void
	 */
	public function ajaxDeleteTaxonomy(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа предмета и слага таксономии
		[$subject_key, $tax_slug] = $this->requireSubjectAndSlug();

		// Удаление через репозиторий
		$this->taxonomies->delete([
			'subject_key' => $subject_key,
			'tax_slug'    => $tax_slug,
		]);

		// Отправка результата
		$this->sendResult('Таксономия удалена');
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет nonce и права администратора.
	 * Завершает выполнение через wp_send_json_error при неудаче.
	 *
	 * @return void
	 */
	private function authorize(): void
	{
		Nonce::Subject->verify('security');

		// Проверка прав доступа (только администраторы)
		if (!current_user_can(Capability::ADMIN->value)) {
			wp_send_json_error('У вас недостаточно прав', 403);
		}
	}

	/**
	 * Читает и валидирует subject_key и tax_slug из POST.
	 *
	 * @return array{0: string, 1: string} [subject_key, tax_slug]
	 */
	private function requireSubjectAndSlug(): array
	{
		$subject_key = sanitize_title(wp_unslash($_POST['subject_key'] ?? ''));
		$tax_slug    = sanitize_title(wp_unslash($_POST['tax_slug'] ?? ''));

		// Валидация обязательных полей
		if (empty($subject_key) || empty($tax_slug)) {
			wp_send_json_error('Недостаточно данных для операции');
		}

		return [$subject_key, $tax_slug];
	}

	/**
	 * Читает и валидирует полный набор данных таксономии из POST.
	 * Используется в store и update.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
	 *         [subject_key, tax_slug, tax_name, display_type, default_term]
	 */
	private function requireTaxonomyData(): array
	{
		// Получаем ключ предмета и слаг таксономии
		[$subject_key, $tax_slug] = $this->requireSubjectAndSlug();

		// Получение названия таксономии
		$tax_name = sanitize_text_field(wp_unslash($_POST['tax_name'] ?? ''));

		// Валидация названия
		if (empty($tax_name)) {
			wp_send_json_error('Название таксономии не может быть пустым');
		}

		// Получение и валидация типа отображения
		$raw_display  = sanitize_text_field(wp_unslash($_POST['display_type'] ?? ''));
		$display_type = in_array($raw_display, ['select', 'radio', 'checkbox'], true)
			? $raw_display
			: 'select';

		// Ярлык термина по умолчанию (опционально)
		$default_term = sanitize_title(wp_unslash($_POST['default_term'] ?? ''));

		return [$subject_key, $tax_slug, $tax_name, $display_type, $default_term];
	}

	/**
	 * Сбрасывает правила перезаписи и отправляет успешный ответ клиенту.
	 *
	 * Примечание: update_option() возвращает false как при ошибке БД, так и когда
	 * значение не изменилось — оба случая являются допустимым исходом для CRUD таксономий.
	 *
	 * @param string $message Сообщение для клиента
	 *
	 * @return void
	 */
	private function sendResult(string $message): void
	{
		// Сброс правил перезаписи после изменений таксономий
		flush_rewrite_rules();

		wp_send_json_success($message);
	}
}