<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TaskPageDTO
 *
 * Данные frontend-страницы одного задания (templates/frontend/single-task.php).
 *
 * @package Inc\DTO\Task
 */
readonly class TaskPageDTO {

	/**
	 * @param PostViewDTO|null $post         Запись задания; null — задания нет.
	 * @param string           $subject_key  Ключ предмета.
	 * @param string           $subject_name Отображаемое имя предмета.
	 * @param TaskContentDTO   $content      Содержимое задания из меты.
	 * @param array            $files        Файлы задания: [{name, url, size}].
	 * @param TagDTO[]         $tags         Чипы-классификаторы задания.
	 * @param array            $articles     Статьи сайдбара и карусели:
	 *                                       related / recommended / archive_url.
	 * @param array            $courses      Опубликованные курсы предмета: CourseCardDTO[];
	 *                                       пусто — блок сайдбара не выводится.
	 * @param string           $courses_url  Витрина курсов предмета — ссылка «Все курсы»;
	 *                                       '' — ссылки нет.
	 * @param NavigationDTO    $navigation   Крошки, архив, соседние задания.
	 * @param TabDTO[]         $tabs         Табы карточки (ответ, решение, пояснение).
	 */
	public function __construct(
		public ?PostViewDTO $post,
		public string $subject_key,
		public string $subject_name,
		public TaskContentDTO $content,
		public array $files,
		public array $tags,
		public array $articles,
		public array $courses,
		public string $courses_url,
		public NavigationDTO $navigation,
		public array $tabs,
	) {}

	/**
	 * Пустой DTO: запись не найдена или не является заданием.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self(
			post:         null,
			subject_key:  '',
			subject_name: '',
			content:      new TaskContentDTO(),
			files:        array(),
			tags:         array(),
			articles:     array(
				'related'     => array(),
				'recommended' => array(),
				'archive_url' => '',
			),
			courses:      array(),
			courses_url:  '',
			navigation:   new NavigationDTO(),
			tabs:         array(),
		);
	}
}