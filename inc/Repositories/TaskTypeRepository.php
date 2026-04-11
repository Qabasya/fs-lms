<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;

class TaskTypeRepository extends BaseController implements RepositoryInterface
{
	private string $option_name = BaseController::BOILERPLATE_OPTION_NAME;

	// ============================ ЧТЕНИЕ ============================ //

	private function getRaw(): array
	{
		$data = get_option($this->option_name, []);
		return is_array($data) ? $data : [];
	}

	private function hydrateDTO(array $item, string $subject_key, string $term_slug): TaskTypeBoilerplateDTO
	{
		return new TaskTypeBoilerplateDTO(
			uid:         $item['uid'],
			subject_key: $subject_key,
			term_slug:   $term_slug,
			title:       $item['title']      ?? 'Без названия',
			content:     $item['content']    ?? '',
			is_default:  $item['is_default'] ?? false
		);
	}

	/** @return TaskTypeBoilerplateDTO[] */
	public function readAll(): array
	{
		$flat = [];
		foreach ($this->getRaw() as $subject_key => $terms) {
			foreach ($terms as $term_slug => $list) {
				foreach ($list as $item) {
					$flat[] = $this->hydrateDTO($item, $subject_key, $term_slug);
				}
			}
		}
		return $flat;
	}

	/** @return TaskTypeBoilerplateDTO[] */
	public function getBoilerplates(string $subject_key, string $term_slug): array
	{
		$raw_list = $this->getRaw()[$subject_key][$term_slug] ?? [];

		return array_map(
			fn(array $item) => $this->hydrateDTO($item, $subject_key, $term_slug),
			$raw_list
		);
	}

	public function findBoilerplate(string $subject_key, string $term_slug, string $uid): ?TaskTypeBoilerplateDTO
	{
		$raw_list = $this->getRaw()[$subject_key][$term_slug] ?? [];

		foreach ($raw_list as $item) {
			if (isset($item['uid']) && $item['uid'] === $uid) {
				return $this->hydrateDTO($item, $subject_key, $term_slug);
			}
		}

		return null;
	}

	// ============================ ЗАПИСЬ ============================ //

	public function updateBoilerplate(TaskTypeBoilerplateDTO $dto): bool
	{
		$all  = $this->getRaw();
		$list = &$all[$dto->subject_key][$dto->term_slug];

		if (!isset($list) || !is_array($list)) {
			$list = [];
		}

		$found = false;

		foreach ($list as &$item) {
			if ($item['uid'] === $dto->uid) {
				$item  = $dto->toArray();
				$found = true;
			} elseif ($dto->is_default) {
				// Сбрасываем флаг у остальных в одном проходе
				$item['is_default'] = false;
			}
		}
		unset($item);

		if (!$found) {
			$list[] = $dto->toArray();
		}

		return update_option($this->option_name, $all);
	}

	public function deleteBoilerplate(string $subject_key, string $term_slug, string $uid): bool
	{
		$all = $this->getRaw();

		if (!isset($all[$subject_key][$term_slug]) || !is_array($all[$subject_key][$term_slug])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log("FS LMS: Path not found in database: [$subject_key][$term_slug]");
			}
			return false;
		}

		$found = false;

		foreach ($all[$subject_key][$term_slug] as $index => $item) {
			if (isset($item['uid']) && $item['uid'] === $uid) {
				unset($all[$subject_key][$term_slug][$index]);
				$found = true;
				break;
			}
		}

		if (!$found) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log("FS LMS: UID $uid not found inside [$subject_key][$term_slug]");
			}
			return false;
		}

		// Переиндексация и чистка пустых веток
		$all[$subject_key][$term_slug] = array_values($all[$subject_key][$term_slug]);

		if (empty($all[$subject_key][$term_slug])) {
			unset($all[$subject_key][$term_slug]);
		}
		if (empty($all[$subject_key])) {
			unset($all[$subject_key]);
		}

		return update_option($this->option_name, $all);
	}

	// ============================ ИНТЕРФЕЙС RepositoryInterface ============================ //

	public function update(array $data): bool
	{
		$dto = new TaskTypeBoilerplateDTO(
			uid:         $data['uid']         ?? uniqid('bp_', true),
			subject_key: $data['subject_key'],
			term_slug:   $data['term_slug'],
			title:       $data['title']       ?? '',
			// TODO: поле 'text' — обратная совместимость, удалить после миграции данных
			content:     $data['content']     ?? $data['text'] ?? '',
		);

		return $this->updateBoilerplate($dto);
	}

	public function delete(array $data): bool
	{
		if (!isset($data['subject_key'], $data['term_slug'], $data['uid'])) {
			return false;
		}

		return $this->deleteBoilerplate($data['subject_key'], $data['term_slug'], $data['uid']);
	}
}