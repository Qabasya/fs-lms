<?php

declare(strict_types=1);

namespace Inc\Callbacks;

use Inc\DTO\TaskTypeBoilerplateDTO;
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
		private SubjectRepository  $subjects,
		private TaxonomySeeder     $seeder,
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
			'terms'        => $this->collectTerms($key),
			'posts'        => $this->collectPosts($key),
		]);
	}

	public function ajaxImportSubject(): void
	{
		$this->authorize();

		$raw = wp_unslash($_POST['json'] ?? '');

		if (empty($raw)) {
			wp_send_json_error('JSON не передан');
			return;
		}

		$data = json_decode($raw, true);

		if (!is_array($data) || !isset($data['subject']['key'], $data['subject']['name'])) {
			wp_send_json_error('Неверный формат файла');
			return;
		}

		$key  = sanitize_title($data['subject']['key']);
		$name = sanitize_text_field($data['subject']['name']);

		if (empty($key) || empty($name)) {
			wp_send_json_error('Ключ или название предмета пусты');
			return;
		}

		if ($this->subjects->getByKey($key)) {
			wp_send_json_error("Предмет с ключом «{$key}» уже существует");
			return;
		}

		$this->subjects->update(['key' => $key, 'name' => $name]);

		foreach ($data['taxonomies'] ?? [] as $tax_slug => $tax_data) {
			$this->taxonomies->update([
				'subject_key'  => $key,
				'tax_slug'     => sanitize_title((string) $tax_slug),
				'name'         => sanitize_text_field($tax_data['name'] ?? ''),
				'display_type' => sanitize_text_field($tax_data['display_type'] ?? 'select'),
			]);
		}

		foreach ($data['metaboxes'] ?? [] as $task_number => $template_id) {
			$this->metaboxes->update([
				'subject'     => $key,
				'task_number' => sanitize_text_field((string) $task_number),
				'template_id' => sanitize_text_field((string) $template_id),
			]);
		}

		foreach ($data['boilerplates'] ?? [] as $term_slug => $bp_list) {
			foreach ((array) $bp_list as $bp) {
				$this->boilerplates->updateBoilerplate(new TaskTypeBoilerplateDTO(
					uid:         sanitize_text_field($bp['uid'] ?? uniqid('bp_', true)),
					subject_key: $key,
					term_slug:   sanitize_text_field((string) $term_slug),
					title:       sanitize_text_field($bp['title'] ?? ''),
					content:     wp_kses_post($bp['content'] ?? ''),
					is_default:  (bool) ($bp['is_default'] ?? false),
				));
			}
		}

		foreach ($data['terms'] ?? [] as $tax_slug => $term_list) {
			$this->importTerms(sanitize_title((string) $tax_slug), (array) $term_list);
		}

		$this->importPosts($data['posts'] ?? []);

		flush_rewrite_rules();

		wp_send_json_success("Предмет «{$name}» успешно импортирован");
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

	private function collectTerms(string $subject_key): array
	{
		$slugs = array_merge(
			["{$subject_key}_task_number"],
			array_map(fn($dto) => $dto->slug, $this->taxonomies->getBySubject($subject_key))
		);

		$result = [];

		foreach ($slugs as $tax_slug) {
			$terms = get_terms(['taxonomy' => $tax_slug, 'hide_empty' => false]);

			if (!is_wp_error($terms)) {
				$result[$tax_slug] = array_map(fn($t) => [
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'parent'      => $t->parent,
				], $terms);
			}
		}

		return $result;
	}

	private function collectPosts(string $subject_key): array
	{
		$tax_slugs = array_merge(
			["{$subject_key}_task_number"],
			array_map(fn($dto) => $dto->slug, $this->taxonomies->getBySubject($subject_key))
		);

		$result = [];

		foreach (["{$subject_key}_tasks", "{$subject_key}_articles"] as $post_type) {
			$posts = get_posts(['post_type' => $post_type, 'numberposts' => -1, 'post_status' => 'any']);

			$result[$post_type] = array_map(function ($post) use ($tax_slugs) {
				$terms = [];

				foreach ($tax_slugs as $tax_slug) {
					$assigned = wp_get_post_terms($post->ID, $tax_slug, ['fields' => 'slugs']);
					if (!is_wp_error($assigned) && !empty($assigned)) {
						$terms[$tax_slug] = $assigned;
					}
				}

				$meta = [];
				foreach (get_post_meta($post->ID) as $meta_key => $_) {
					$meta[$meta_key] = get_post_meta($post->ID, $meta_key, true);
				}

				return [
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_status'  => $post->post_status,
					'post_date'    => $post->post_date,
					'menu_order'   => (int) $post->menu_order,
					'meta'         => $meta,
					'terms'        => $terms,
				];
			}, $posts);
		}

		return $result;
	}

	private function importTerms(string $taxonomy, array $terms): void
	{
		if (!taxonomy_exists($taxonomy)) {
			register_taxonomy($taxonomy, []);
		}

		foreach ($terms as $term_data) {
			$name = sanitize_text_field($term_data['name'] ?? '');

			if (empty($name) || term_exists($name, $taxonomy)) {
				continue;
			}

			wp_insert_term($name, $taxonomy, [
				'slug'        => sanitize_title($term_data['slug'] ?? $name),
				'description' => sanitize_text_field($term_data['description'] ?? ''),
			]);
		}
	}

	private function importPosts(array $posts_data): void
	{
		foreach ($posts_data as $post_type => $post_list) {
			$clean_type = sanitize_key((string) $post_type);

			foreach ((array) $post_list as $post_data) {
				$post_id = wp_insert_post([
					'post_type'    => $clean_type,
					'post_title'   => sanitize_text_field($post_data['post_title'] ?? ''),
					'post_content' => wp_kses_post($post_data['post_content'] ?? ''),
					'post_excerpt' => sanitize_text_field($post_data['post_excerpt'] ?? ''),
					'post_status'  => sanitize_text_field($post_data['post_status'] ?? 'publish'),
					'post_date'    => sanitize_text_field($post_data['post_date'] ?? ''),
					'menu_order'   => absint($post_data['menu_order'] ?? 0),
				]);

				if (is_wp_error($post_id) || !$post_id) {
					continue;
				}

				foreach ($post_data['meta'] ?? [] as $meta_key => $meta_value) {
					update_post_meta($post_id, sanitize_key((string) $meta_key), $meta_value);
				}

				foreach ($post_data['terms'] ?? [] as $tax_slug => $term_slugs) {
					wp_set_post_terms($post_id, (array) $term_slugs, sanitize_title((string) $tax_slug));
				}
			}
		}
	}
}