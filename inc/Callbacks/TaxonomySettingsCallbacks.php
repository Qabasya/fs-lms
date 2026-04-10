<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;

/**
 * Class TaxonomySettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с таксономиями.
 * Отвечает за создание, обновление и удаление кастомных таксономий предметов.
 *
 * @package Inc\Callbacks
 */
class TaxonomySettingsCallbacks extends BaseController
{
	/**
	 * Конструктор.
	 *
	 * @param TaxonomyRepository $taxonomies Репозиторий таксономий
	 */
	public function __construct(
		private TaxonomyRepository $taxonomies,
	) {
		parent::__construct();
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Создаёт новую таксономию для предмета.
	 *
	 * @return void
	 */
	public function storeTaxonomy(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных таксономии
		[$subject_key, $tax_slug, $tax_name] = $this->requireTaxonomyData();

		// Сохранение через репозиторий
		$success = $this->taxonomies->update([
			'subject_key' => $subject_key,
			'tax_slug'    => $tax_slug,
			'name'        => $tax_name,
		]);

		// Отправка результата
		$this->sendResult($success, 'Таксономия создана');
	}

	/**
	 * Обновляет существующую таксономию (Quick Edit).
	 *
	 * @return void
	 */
	public function updateTaxonomy(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных таксономии
		[$subject_key, $tax_slug, $tax_name] = $this->requireTaxonomyData();

		// Обновление через репозиторий
		$success = $this->taxonomies->update([
			'subject_key' => $subject_key,
			'tax_slug'    => $tax_slug,
			'name'        => $tax_name,
		]);

		// Отправка результата
		$this->sendResult($success, 'Таксономия обновлена');
	}

	/**
	 * Удаляет таксономию предмета.
	 *
	 * @return void
	 */
	public function deleteTaxonomy(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа предмета и слага таксономии
		[$subject_key, $tax_slug] = $this->requireSubjectAndSlug();

		// Удаление через репозиторий
		$success = $this->taxonomies->delete([
			'subject_key' => $subject_key,
			'tax_slug'    => $tax_slug,
		]);

		// Отправка результата
		$this->sendResult($success, 'Таксономия удалена');
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
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_subject_nonce', 'security');

		// Проверка прав доступа (только администраторы)
		if (!current_user_can(self::ADMIN_CAPABILITY)) {
			wp_send_json_error('Нет прав', 403);
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
	 * @return array{0: string, 1: string, 2: string} [subject_key, tax_slug, tax_name]
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

		return [$subject_key, $tax_slug, $tax_name];
	}

	/**
	 * Отправляет результат операции клиенту и при успехе сбрасывает правила перезаписи.
	 *
	 * @param bool   $success Результат операции репозитория
	 * @param string $message Сообщение для клиента при успехе
	 *
	 * @return void
	 */
	private function sendResult(bool $success, string $message): void
	{
		// В случае ошибки репозитория
		if (!$success) {
			wp_send_json_error('Ошибка репозитория при выполнении операции');
			return;
		}

		// Обновление правил перезаписи после изменений таксономий
		flush_rewrite_rules();

		wp_send_json_success($message);
	}
}