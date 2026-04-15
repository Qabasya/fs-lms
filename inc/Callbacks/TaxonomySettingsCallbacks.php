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
	 * Итоговый slug = {subject_key}_{suffix}, где suffix — данные от клиента.
	 *
	 * @return void
	 */
	public function ajaxStoreTaxonomy(): void
	{
		$this->authorize();

		[$subject_key, $tax_suffix, $tax_name, $display_type] = $this->requireTaxonomyData();

		$tax_slug = "{$subject_key}_{$tax_suffix}";

		if (strlen($tax_slug) > 32) {
			wp_send_json_error('Ярлык слишком длинный (макс. ' . (32 - strlen($subject_key) - 1) . ' символов)');
		}

		if (taxonomy_exists($tax_slug)) {
			wp_send_json_error('Таксономия с таким ярлыком уже существует');
		}

		$this->taxonomies->update([
			'subject_key'  => $subject_key,
			'tax_slug'     => $tax_slug,
			'name'         => $tax_name,
			'display_type' => $display_type,
		]);

		$this->sendResult('Таксономия создана');
	}

	/**
	 * Обновляет существующую таксономию (название, тип отображения).
	 * Slug не меняется — передаётся полный slug из клиента.
	 *
	 * @return void
	 */
	public function ajaxUpdateTaxonomy(): void
	{
		$this->authorize();

		[$subject_key, $tax_slug, $tax_name, $display_type] = $this->requireTaxonomyData();

		$this->taxonomies->update([
			'subject_key'  => $subject_key,
			'tax_slug'     => $tax_slug,
			'name'         => $tax_name,
			'display_type' => $display_type,
		]);

		$this->sendResult('Таксономия обновлена');
	}

	/**
	 * Удаляет таксономию предмета и все её термины из базы данных.
	 *
	 * @return void
	 */
	public function ajaxDeleteTaxonomy(): void
	{
		$this->authorize();

		[$subject_key, $tax_slug] = $this->requireSubjectAndSlug();

		$terms = get_terms(['taxonomy' => $tax_slug, 'hide_empty' => false, 'fields' => 'ids']);
		if (!is_wp_error($terms)) {
			foreach ($terms as $term_id) {
				wp_delete_term((int) $term_id, $tax_slug);
			}
		}

		$this->taxonomies->delete([
			'subject_key' => $subject_key,
			'tax_slug'    => $tax_slug,
		]);

		$this->sendResult('Таксономия удалена');
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет nonce и права администратора.
	 *
	 * @return void
	 */
	private function authorize(): void
	{
		Nonce::Subject->verify('security');

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

		if (empty($subject_key) || empty($tax_slug)) {
			wp_send_json_error('Недостаточно данных для операции');
		}

		return [$subject_key, $tax_slug];
	}

	/**
	 * Читает и валидирует полный набор данных таксономии из POST.
	 * Используется в store (возвращает suffix) и update (возвращает полный slug).
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string}
	 *         [subject_key, tax_slug_or_suffix, tax_name, display_type]
	 */
	private function requireTaxonomyData(): array
	{
		[$subject_key, $tax_slug] = $this->requireSubjectAndSlug();

		$tax_name = sanitize_text_field(wp_unslash($_POST['tax_name'] ?? ''));

		if (empty($tax_name)) {
			wp_send_json_error('Название таксономии не может быть пустым');
		}

		$raw_display  = sanitize_text_field(wp_unslash($_POST['display_type'] ?? ''));
		$display_type = in_array($raw_display, ['select', 'radio', 'checkbox'], true)
			? $raw_display
			: 'select';

		return [$subject_key, $tax_slug, $tax_name, $display_type];
	}

	/**
	 * Сбрасывает правила перезаписи и отправляет успешный ответ.
	 *
	 * @param string $message
	 * @return void
	 */
	private function sendResult(string $message): void
	{
		flush_rewrite_rules();
		wp_send_json_success($message);
	}
}
