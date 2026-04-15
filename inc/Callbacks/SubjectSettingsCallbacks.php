<?php

namespace Inc\Callbacks;

use Inc\Enums\Capability;
use Inc\Repositories\SubjectRepository;
use Inc\Services\TaxonomySeeder;

/**
 * Class SubjectSettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с предметами.
 *
 * Отвечает только за предметы: создание, обновление, удаление.
 * Управление шаблонами (update_term_template) перенесено в TemplateManagerCallbacks.
 *
 * @package Inc\Callbacks
 */
class SubjectSettingsCallbacks
{
	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param TaxonomySeeder    $seeder   Сервис заполнения таксономий
	 */
	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomySeeder $seeder,
	) {
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Создаёт новый предмет и засевает таксономию номеров заданий.
	 *
	 * @return void
	 */
	public function ajaxStoreSubject(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа и названия предмета
		[$key, $name] = $this->requireKeyAndName();

		// Получение количества заданий (по умолчанию 0)
		$count = absint(wp_unslash($_POST['tasks_count'] ?? 0));

		// Сохранение предмета через репозиторий
		$success = $this->subjects->update(['key' => $key, 'name' => $name]);

		if (!$success) {
			wp_send_json_error('Ошибка при создании предмета');
			return;
		}

		// Засев таксономии номерами заданий
		$this->seeder->seedTaskNumbers("{$key}_task_number", $count, $key);

		// Сброс правил перезаписи для активации новых CPT
		flush_rewrite_rules();

		wp_send_json_success("Предмет «{$name}» успешно создан!");
	}

	/**
	 * Обновляет название существующего предмета.
	 *
	 * @return void
	 */
	public function ajaxUpdateSubject(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа и названия предмета
		[$key, $name] = $this->requireKeyAndName();

		// Проверка существования предмета
		$this->requireExists($key);

		// Обновление предмета через репозиторий
		$success = $this->subjects->update(['key' => $key, 'name' => $name]);

		if (!$success) {
			wp_send_json_error('Ошибка при обновлении предмета');
			return;
		}

		wp_send_json_success("Предмет «{$name}» обновлён");
	}

	/**
	 * Удаляет предмет из базы данных.
	 *
	 * @return void
	 */
	public function ajaxDeleteSubject(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа предмета
		$key = $this->requireKey();

		// Проверка существования предмета
		$this->requireExists($key);

		// Удаление предмета через репозиторий
		$success = $this->subjects->delete(['key' => $key]);

		if (!$success) {
			wp_send_json_error('Ошибка при удалении предмета');
			return;
		}

		// Сброс правил перезаписи после удаления CPT
		flush_rewrite_rules();

		wp_send_json_success('Предмет удалён');
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
		if (!current_user_can(Capability::ADMIN->value)) {
			wp_send_json_error('Нет прав', 403);
		}
	}

	/**
	 * Читает и валидирует ключ предмета из POST.
	 * Завершает выполнение, если ключ пустой.
	 *
	 * @return string Санированный ключ предмета
	 */
	private function requireKey(): string
	{
		$key = sanitize_title(wp_unslash($_POST['key'] ?? ''));

		if (empty($key)) {
			wp_send_json_error('ID предмета обязателен');
		}

		return $key;
	}

	/**
	 * Читает и валидирует ключ + название предмета из POST.
	 * Завершает выполнение, если одно из значений пустое.
	 *
	 * @return array{0: string, 1: string} [key, name]
	 */
	private function requireKeyAndName(): array
	{
		$key  = $this->requireKey();
		$name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

		if (empty($name)) {
			wp_send_json_error('Название предмета обязательно');
		}

		return [$key, $name];
	}

	/**
	 * Проверяет существование предмета в БД.
	 * Завершает выполнение, если предмет не найден.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function requireExists(string $key): void
	{
		if (!$this->subjects->getByKey($key)) {
			wp_send_json_error('Предмет не найден в базе данных');
		}
	}
}