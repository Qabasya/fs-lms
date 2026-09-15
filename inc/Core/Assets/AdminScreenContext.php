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
	 * @param string $base       База экрана WP: `post` — редактирование записи, `edit` — список
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
		public string $base = '',
	) {}

	/** Скрытая страница конструктора курса (`CourseBuilderController::PAGE_SLUG`): у экрана нет CPT. */
	private const COURSE_BUILDER_PAGE = 'fs_lms_course_builder';

	/** Страница настроек плагина: загрузка логотипа кабинета через медиатеку. */
	private const SETTINGS_PAGE = 'fs_lms_settings';

	/**
	 * Считывает признаки текущего экрана админки.
	 *
	 * @param \WP_Screen|null $screen Текущий экран
	 * @param string          $page   Значение GET-параметра `page`
	 */
	public static function from( ?\WP_Screen $screen, string $page ): self {
		$postType = (string) ( $screen->post_type ?? '' );

		return new self(
			base:       (string) ( $screen->base ?? '' ),
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
	 * Экран редактирования записи (post.php / post-new.php), а не список.
	 */
	public function isEditScreen(): bool {
		return 'post' === $this->base;
	}

	/**
	 * Страница конструктора курса.
	 */
	public function isCourseBuilderPage(): bool {
		return self::COURSE_BUILDER_PAGE === $this->page;
	}

	/**
	 * Нужна ли медиатека WordPress (`wp.media`).
	 *
	 * Её используют поля задания (`task-fields.js`), редактор шагов урока и конструктор курса
	 * (`step-editors/`) и логотип в настройках. На экранах списков медиатека не нужна, а стоила
	 * ~25 скриптов и ~80 КБ HTML-шаблонов (см. .docs/Tasks.md, С4).
	 */
	public function needsMedia(): bool {
		$contentEdit = $this->isEditScreen()
			&& ( $this->task || $this->lesson || $this->work || $this->assessment || $this->course || $this->problems || $this->article );

		return $contentEdit || $this->isCourseBuilderPage() || self::SETTINGS_PAGE === $this->page;
	}

	/**
	 * Нужен ли inline-редактор задач: данные `fs_lms_task_editor_vars` — только там, где работает
	 * редактор шагов (урок, курс, конструктор курса), а не на каждом экране CPT.
	 */
	public function needsTaskEditor(): bool {
		return $this->needsEditor();
	}

	/**
	 * Нужен ли полный стек TinyMCE (`wp.editor`, Quicktags) — редактор шагов урока и курса.
	 */
	public function needsEditor(): bool {
		return ( $this->isEditScreen() && ( $this->lesson || $this->course ) ) || $this->isCourseBuilderPage();
	}
}
