<?php

declare(strict_types=1);

namespace Inc\Callbacks;

use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaskTypeRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Services\TaxonomySeeder;

class SubjectSettingsCallbacks
{
	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomySeeder    $seeder,
		private TaxonomyRepository $taxonomies,
		private MetaBoxRepository  $metaboxes,
		private TaskTypeRepository $boilerplates,
	) {
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	public function ajaxStoreSubject(): void
	{
		$this->authorize();

		[$key, $name] = $this->requireKeyAndName();

		$count = absint(wp_unslash($_POST['tasks_count'] ?? 0));

		$success = $this->subjects->update(['key' => $key, 'name' => $name]);

		if (!$success) {
			wp_send_json_error('Ошибка при создании предмета');
			return;
		}

		$this->seeder->seedTaskNumbers("{$key}_task_number", $count, $key);

		flush_rewrite_rules();

		wp_send_json_success("Предмет «{$name}» успешно создан!");
	}

	public function ajaxUpdateSubject(): void
	{
		$this->authorize();

		[$key, $name] = $this->requireKeyAndName();

		$this->requireExists($key);

		$success = $this->subjects->update(['key' => $key, 'name' => $name]);

		if (!$success) {
			wp_send_json_error('Ошибка при обновлении предмета');
			return;
		}

		wp_send_json_success("Предмет «{$name}» обновлён");
	}

	public function ajaxDeleteSubject(): void
	{
		$this->authorize();

		$key = $this->requireKey();

		$this->requireExists($key);

		$this->cascadeDelete($key);

		$success = $this->subjects->delete(['key' => $key]);

		if (!$success) {
			wp_send_json_error('Ошибка при удалении предмета');
			return;
		}

		flush_rewrite_rules();

		wp_send_json_success('Предмет удалён');
	}

	public function ajaxExportSubject(): void
	{
		$this->authorize();

		$key     = $this->requireKey();
		$subject = $this->subjects->getByKey($key);

		if (!$subject) {
			wp_send_json_error('Предмет не найден');
			return;
		}

		wp_send_json_success([
			'subject'      => ['key' => $subject->key, 'name' => $subject->name],
			'taxonomies'   => $this->taxonomies->getRawForSubject($key),
			'metaboxes'    => $this->metaboxes->getRawForSubject($key),
			'boilerplates' => $this->boilerplates->getRawForSubject($key),
		]);
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	private function authorize(): void
	{
		Nonce::Subject->verify('security');

		if (!current_user_can(Capability::ADMIN->value)) {
			wp_send_json_error('У вас недостаточно прав', 403);
		}
	}

	private function requireKey(): string
	{
		$key = sanitize_title(wp_unslash($_POST['key'] ?? ''));

		if (empty($key)) {
			wp_send_json_error('ID предмета обязателен');
		}

		return $key;
	}

	private function requireKeyAndName(): array
	{
		$key  = $this->requireKey();
		$name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

		if (empty($name)) {
			wp_send_json_error('Название предмета обязательно');
		}

		return [$key, $name];
	}

	private function requireExists(string $key): void
	{
		if (!$this->subjects->getByKey($key)) {
			wp_send_json_error('Предмет не найден в базе данных');
		}
	}

	private function cascadeDelete(string $key): void
	{
		foreach ($this->taxonomies->getBySubject($key) as $tax_dto) {
			$this->deleteWpTerms($tax_dto->slug);
		}

		$this->deleteWpTerms("{$key}_task_number");

		foreach (["{$key}_tasks", "{$key}_articles"] as $post_type) {
			$ids = get_posts(['post_type' => $post_type, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']);
			foreach ($ids as $id) {
				wp_delete_post((int) $id, true);
			}
		}

		$this->taxonomies->deleteBySubject($key);
		$this->metaboxes->deleteBySubject($key);
		$this->boilerplates->deleteBySubject($key);
	}

	private function deleteWpTerms(string $taxonomy): void
	{
		$ids = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids']);

		if (is_wp_error($ids)) {
			return;
		}

		foreach ($ids as $id) {
			wp_delete_term((int) $id, $taxonomy);
		}
	}
}