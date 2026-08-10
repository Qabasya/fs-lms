<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Builders\AllTasksDataBuilder;
use Inc\Controllers\Builders\ArticlesDataBuilder;
use Inc\Core\BaseController;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Wp\SubjectPageType;
use Inc\Repositories\OptionsRepositories\SubjectPagesRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\PublicCourseService;
use Inc\Services\Task\TaskFilterParser;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class SubjectLandingController
 *
 * Контроллер разделов публичного лендинга предмета: тренажёр, учебник, курсы.
 *
 * Регистрирует:
 *   - шорткоды разделов (SubjectPageType::shortcode) — их вставляет в страницы
 *     SubjectPagesService при создании предмета.
 *
 * @package Inc\Controllers\Pages
 */
class SubjectLandingController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	/** Сколько курсов показывает витрина курсов. */
	private const COURSES_LIMIT = 24;

	/** Прежний слаг раздела статей — с него уводим редиректом. */
	private const LEGACY_SECTION = 'textbook';

	public function __construct(
		private readonly SubjectRepository      $subjects,
		private readonly SubjectPagesRepository $pages,
		private readonly AllTasksDataBuilder    $tasks,
		private readonly TaskFilterParser       $taskFilters,
		private readonly ArticlesDataBuilder    $articles,
		private readonly PublicCourseService    $courses,
	) {
		parent::__construct();
	}

	public function register(): void {
		foreach ( SubjectPageType::cases() as $type ) {
			$shortcode = $type->shortcode();

			if ( null !== $shortcode ) {
				add_shortcode( $shortcode->value, array( $this, 'renderSection' ) );
			}
		}

		add_filter( 'template_include', array( $this, 'loadSectionTemplate' ) );
		add_action( 'template_redirect', array( $this, 'redirectLegacySection' ) );
	}

	/**
	 * Уводит старые адреса учебника на новые: `/{key}/textbook/…` →
	 * `/{key}/articles/…`.
	 *
	 * Слаг раздела сменился уже после публикации статей, поэтому их прежние
	 * адреса разошлись по ссылкам и закладкам. Отвечаем 301: адрес сменился
	 * навсегда, и поисковику незачем держать обе версии.
	 *
	 * @return void
	 */
	public function redirectLegacySection(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( ! preg_match( '#^/([^/]+)/' . self::LEGACY_SECTION . '(/.*)?$#', $path, $matches ) ) {
			return;
		}

		// Первый сегмент обязан быть ключом живого предмета: `/blog/textbook/`
		// к разделам лендинга отношения не имеет.
		if ( null === $this->subjects->getByKey( $matches[1] ) ) {
			return;
		}

		$target = sprintf(
			'/%s/%s%s',
			$matches[1],
			SubjectPageType::Articles->value,
			$matches[2] ?? '/'
		);

		wp_safe_redirect( home_url( $target ), 301 );
		exit;
	}

	/**
	 * Подключает полностраничный шаблон раздела.
	 *
	 * Витрине нужна вся полоса и никаких заголовков записи: блочная тема
	 * печатает `<h1>` страницы и сжимает контент в узкую колонку, из-за чего
	 * раздел выглядит обрезанным. Те же соображения, что и на архиве заданий.
	 *
	 * @param string $template Путь к текущему шаблону.
	 *
	 * @return string
	 */
	public function loadSectionTemplate( string $template ): string {
		if ( ! is_page() ) {
			return $template;
		}

		$location = $this->pages->locate( (int) get_queried_object_id() );
		$type     = $location['type'] ?? null;

		// Корневая страница предмета — обычная страница темы: её содержимое пишет редактор.
		if ( null === $type || null === $type->shortcode() ) {
			return $template;
		}

		$subject = $this->subjects->getByKey( $location['key'] );

		if ( null === $subject ) {
			return $template;
		}

		$section_template = $this->path( 'templates/frontend/subject/page.php' );

		if ( ! file_exists( $section_template ) ) {
			return $template;
		}

		set_query_var( 'fs_subject_section', $this->renderType( $type, $subject ) );

		return $section_template;
	}

	/**
	 * Рендерит раздел лендинга: один обработчик на все шорткоды разделов —
	 * какой именно раздел, говорит тег.
	 *
	 * @param array<string, string>|string $atts    Атрибуты шорткода.
	 * @param string|null                  $content Содержимое (у разделов не используется).
	 * @param string                       $tag     Тег вызванного шорткода.
	 *
	 * @return string HTML раздела; '' — предмет или раздел не определён.
	 */
	public function renderSection( array|string $atts = array(), ?string $content = null, string $tag = '' ): string {
		$type    = SubjectPageType::fromShortcode( $tag );
		$subject = $this->resolveSubject( $atts );

		return null === $type || null === $subject ? '' : $this->renderType( $type, $subject );
	}

	/**
	 * HTML одного раздела.
	 *
	 * @param SubjectPageType $type    Раздел лендинга.
	 * @param SubjectDTO      $subject Предмет.
	 *
	 * @return string
	 */
	private function renderType( SubjectPageType $type, SubjectDTO $subject ): string {
		ob_start();
		$this->render( "frontend/subject/{$type->value}", $this->sectionData( $type, $subject ) );

		return (string) ob_get_clean();
	}

	/**
	 * Предмет раздела: из атрибута `subject`, а если его нет — по самой странице.
	 *
	 * @param array<string, string>|string $atts Атрибуты шорткода.
	 *
	 * @return SubjectDTO|null Null — предмета с таким ключом нет.
	 */
	private function resolveSubject( array|string $atts ): ?SubjectDTO {
		$parsed = shortcode_atts( array( 'subject' => '' ), (array) $atts );
		$key    = sanitize_key( $parsed['subject'] );

		// Шорткод вставлен вручную, без атрибута — предмет определяем по странице.
		if ( '' === $key ) {
			$key = $this->pages->locate( (int) get_the_ID() )['key'] ?? '';
		}

		return '' === $key ? null : $this->subjects->getByKey( $key );
	}

	/**
	 * Данные шаблона раздела.
	 *
	 * @param SubjectPageType $type    Раздел лендинга.
	 * @param SubjectDTO      $subject Предмет.
	 *
	 * @return array<string, mixed>
	 */
	private function sectionData( SubjectPageType $type, SubjectDTO $subject ): array {
		return match ( $type ) {
			SubjectPageType::Trainer  => array(
				'page_data' => $this->tasks->getPageData( $subject->key, $this->taskFilters->fromRequest( 'GET' ) ),
			),
			SubjectPageType::Articles => array( 'page_data' => $this->articles->getPageData( $subject->key ) ),
			SubjectPageType::Courses  => array( 'courses' => $this->courses->getCourses( $subject->key, self::COURSES_LIMIT ) ),
			default                   => array(),
		};
	}
}