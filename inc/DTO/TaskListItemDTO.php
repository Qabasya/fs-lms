<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class TaskListItemDTO
 *
 * Одна карточка задания на странице «Все задания».
 *
 * Классификаторы задания (год, источник/автор и т.п.) приходят как теги —
 * термины пользовательских таксономий предмета ($tags), а номер задания —
 * термин фиксированной таксономии {subject}_task_number ($task_number).
 *
 * @package Inc\DTO
 */
readonly class TaskListItemDTO {

	/**
	 * @param int    $id            ID задания (пост).
	 * @param string $title         Заголовок.
	 * @param string $url           Пермалинк задания.
	 * @param int    $task_number          Номер типа задания (термин task_number); 0 — не задан.
	 * @param string $task_type_url        Ссылка на архив термина типа задания.
	 * @param string $task_number_taxonomy Слаг таксономии типа задания ({subject}_task_number) — для фильтра.
	 * @param string $task_number_slug     Слаг термина типа задания — для фильтра (совпадает с опцией сайдбара).
	 * @param array  $tags                 Теги-классификаторы: [{taxonomy, taxonomy_name, label, slug, url}].
	 * @param string $condition            Условие задания (HTML).
	 * @param string $answer               Правильный ответ.
	 * @param array  $files                Файлы задания: [{name, url, size}].
	 */
	public function __construct(
		public int    $id,
		public string $title,
		public string $url,
		public int    $task_number,
		public string $task_type_url,
		public string $task_number_taxonomy,
		public string $task_number_slug,
		public array  $tags,
		public string $condition,
		public string $answer,
		public array  $files,
	) {}

	/**
	 * Плоское представление для AJAX-ответа (buildTaskCard в JS).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'            => $this->id,
			'title'         => $this->title,
			'url'           => $this->url,
			'task_number'          => $this->task_number,
			'task_type_url'        => $this->task_type_url,
			'task_number_taxonomy' => $this->task_number_taxonomy,
			'task_number_slug'     => $this->task_number_slug,
			'tags'                 => $this->tags,
			'condition'     => $this->condition,
			'answer'        => $this->answer,
			'files'         => $this->files,
		);
	}
}