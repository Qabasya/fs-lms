<?php

declare( strict_types=1 );

namespace Inc\Core\Assets;

use Inc\Services\Subject\PostTypeResolver;

/**
 * Class AdminScreenContext
 *
 * Ответ на вопрос «на каком экране админки мы находимся» — один раз посчитанные
 * признаки, по которым {@see \Inc\Core\Enqueue} решает, что подключать.
 *
 * @package Inc\Core\Assets
 */
readonly class AdminScreenContext {

	/**
	 * @param string $page       Значение GET-параметра `page`
	 * @param string $postType   CPT текущего экрана ('' — экран без записи)
	 * @param bool   $pluginPage Страница плагина (`fs_*` / `student_*`)
	 * @param bool   $task       Экран CPT заданий
	 * @param bool   $lesson     Экран CPT уроков
	 * @param bool   $work       Экран CPT работ
	 * @param bool   $assessment Экран CPT контрольных
	 * @param bool   $course     Экран CPT курсов
	 * @param bool   $problems   Экран банка задач
	 * @param bool   $article    Экран CPT статей
	 */
	private function __construct(
		public string $page,
		public string $postType,
		public bool   $pluginPage,
		public bool   $task,
		public bool   $lesson,
		public bool   $work,
		public bool   $assessment,
		public bool   $course,
		public bool   $problems,
		public bool   $article,
	) {}

	/**
	 * Считывает признаки текущего экрана админки.
	 *
	 * @param \WP_Screen|null $screen Текущий экран
	 * @param string          $page   Значение GET-параметра `page`
	 */
	public static function from( ?\WP_Screen $screen, string $page ): self {
		$postType = (string) ( $screen->post_type ?? '' );

		return new self(
			page:       $page,
			postType:   $postType,
			// str_starts_with() — проверяет начало строки (PHP 8.0)
			pluginPage: str_starts_with( $page, 'fs_' ) || str_starts_with( $page, 'student_' ),
			task:       null !== $screen && PostTypeResolver::isTaskPostType( $postType ),
			lesson:     null !== $screen && PostTypeResolver::isLessonPostType( $postType ),
			work:       null !== $screen && PostTypeResolver::isWorkPostType( $postType ),
			assessment: null !== $screen && PostTypeResolver::isAssessmentPostType( $postType ),
			course:     null !== $screen && PostTypeResolver::isCoursePostType( $postType ),
			problems:   null !== $screen && PostTypeResolver::problems() === $postType,
			article:    null !== $screen && PostTypeResolver::isArticlePostType( $postType ),
		);
	}

	/**
	 * Нужны ли на этом экране ресурсы плагина вообще.
	 */
	public function needsAssets(): bool {
		return $this->pluginPage || $this->task || $this->lesson || $this->work
			|| $this->assessment || $this->course || $this->problems || $this->article;
	}

	/**
	 * Экран страницы предмета (`fs_subject_{key}`).
	 */
	public function isSubjectPage(): bool {
		return str_starts_with( $this->page, 'fs_subject_' );
	}

	/**
	 * Ключ предмета страницы `fs_subject_{key}` ('' — не та страница).
	 */
	public function subjectPageKey(): string {
		return $this->isSubjectPage() ? substr( $this->page, strlen( 'fs_subject_' ) ) : '';
	}

	/**
	 * Нужен ли inline-редактор задач (Phase F, Этап 6).
	 */
	public function needsTaskEditor(): bool {
		return $this->task || $this->lesson || $this->work || $this->course || $this->isSubjectPage();
	}

	/**
	 * Нужен ли полный стек TinyMCE (редактор шагов в уроках и курсах).
	 */
	public function needsEditor(): bool {
		return $this->lesson || $this->course;
	}
}
