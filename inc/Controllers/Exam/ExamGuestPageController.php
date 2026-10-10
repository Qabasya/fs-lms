<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Exam\GuestPageResponder;
use Inc\Services\Exam\GuestPageAccessService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Exam\GuestSignupViewService;
use Inc\Services\Exam\GuestEntryViewService;
use Inc\Services\Exam\GuestResultViewService;
use Inc\Services\Exam\GuestResultSidebarService;
use Inc\Services\Exam\ExamReportViewService;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Публичные страницы гостя: запись по ссылке школы (этап 11a), вход и результат (этап 11b).
 *
 * Адаптирует HTTP и шорткоды: решения доступа — в GuestPageAccessService, исполнение — в GuestPageResponder.
 * Страницы не индексируются (штатная robots meta, исключение из карты сайта и из поиска) и не кешируются.
 */
class ExamGuestPageController extends BaseController implements ServiceInterface {

	use Sanitizer;
	use RequestContextProvider;
	use TemplateRenderer;

	public function __construct(
		private readonly GuestPageAccessService $access,
		private readonly GuestSessionService $sessions,
		private readonly GuestSignupViewService $signup,
		private readonly GuestEntryViewService $entry,
		private readonly GuestResultViewService $result,
		private readonly GuestResultSidebarService $sidebar,
		private readonly ExamReportViewService $reportView,
		private readonly GuestPageResponder $responder,
		private readonly PostManager $posts,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_shortcode( PageRoutes::ExamSignup->guestExamShortCode()->value, array( $this, 'renderSignupPage' ) );
		add_action( 'template_redirect', array( $this, 'handleSignupPage' ), 5 );
		add_shortcode( PageRoutes::ExamEntry->guestExamShortCode()->value, array( $this, 'renderEntryPage' ) );
		add_action( 'template_redirect', array( $this, 'handleEntryPage' ), 5 );
		add_shortcode( PageRoutes::ExamResult->guestExamShortCode()->value, array( $this, 'renderResultPage' ) );
		add_action( 'template_redirect', array( $this, 'handleResultPage' ), 5 );
		add_shortcode( PageRoutes::ExamReport->guestExamShortCode()->value, array( $this, 'renderReportPage' ) );
		add_action( 'template_redirect', array( $this, 'handleReportPage' ), 5 );
		add_filter( 'wp_robots', array( $this, 'filterRobots' ) );
		add_filter( 'get_canonical_url', array( $this, 'filterCanonicalUrl' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'excludeFromSitemap' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'excludeFromSearch' ) );
	}

	/** Страница формы: заголовки, затем исход (отрисовать / редирект без ключа / 404). */
	public function handleSignupPage(): void {
		if ( ! PageRoutes::ExamSignup->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->access->invitationPage(
			strtolower( trim( $this->sanitizeGetText( 'k' ) ) ),
			strtolower( trim( $this->sanitizeGetText( 'pay' ) ) ),
			strtolower( $this->sanitizeCookieText( GuestSessionService::COOKIE_INVITATION ) ),
			$this->requestContext()->ip,
		) );
	}

	/** Страница входа: заголовки, затем исход (отрисовать / редирект без ключа / 404). */
	public function handleEntryPage(): void {
		if ( ! PageRoutes::ExamEntry->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->access->entryPage( strtolower( trim( $this->sanitizeGetText( 'k' ) ) ), $this->requestContext()->ip ) );
	}

	/** Страница результата: заголовки, затем исход. */
	public function handleResultPage(): void {
		if ( ! PageRoutes::ExamResult->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->access->resultPage( strtolower( trim( $this->sanitizeGetText( 'k' ) ) ), $this->requestContext()->ip ) );
	}

	/** Страница школьного отчёта: заголовки, затем исход. */
	public function handleReportPage(): void {
		if ( ! PageRoutes::ExamReport->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->access->reportPage( strtolower( trim( $this->sanitizeGetText( 'k' ) ) ), $this->requestContext()->ip ) );
	}

	/** Шорткод формы записи. */
	public function renderSignupPage(): string {
		$source = $this->access->currentSource( strtolower( $this->sanitizeCookieText( GuestSessionService::COOKIE_INVITATION ) ) );
		if ( null === $source ) {
			return '';
		}

		ob_start();
		$this->render( 'frontend/exam-signup', array_merge( $this->signup->build( $source ), array( 'form_url' => PageRoutes::ExamSignup->url() ) ) );

		return (string) ob_get_clean();
	}

	/** Шорткод входа на экзамен. */
	public function renderEntryPage(): string {
		$ctx  = $this->sessions->current();
		$data = null !== $ctx ? $this->entry->build( $ctx ) : null;
		if ( null === $data ) {
			return '';
		}

		ob_start();
		$this->render( 'frontend/exam-entry', $data );

		return (string) ob_get_clean();
	}

	/** Шорткод результата. */
	public function renderResultPage(): string {
		$participationId = $this->sessions->viewableParticipationId();
		$data            = null !== $participationId ? $this->result->build( $participationId ) : null;
		if ( null === $data || true !== $data['revealed'] ) {
			return '';
		}

		$data['can_end_session'] = true;
		$data['sidebar']         = $this->sidebar->build( (string) ( $data['subject_key'] ?? '' ), (array) ( $data['units'] ?? array() ), $participationId );
		$data['crumbs']          = array(
			array(
'label' => __( 'Главная', 'fs-lms' ),
'url'   => home_url( '/' )
),
			array(
'label'   => __( 'Результат экзамена', 'fs-lms' ),
'current' => true
),
		);

		ob_start();
		$this->render( 'frontend/exam-result', $data );

		return (string) ob_get_clean();
	}

	/** Шорткод школьного отчёта. */
	public function renderReportPage(): string {
		$report = $this->access->currentReport();
		if ( null === $report ) {
			return '';
		}

		$row              = $this->hasParam( 'row', 'GET' ) ? $this->sanitizeGetInt( 'row' ) : 0;
		$data             = $this->reportView->build( $report, $row > 0 ? $row : null );
		$data['crumbs']   = array(
			array(
'label' => __( 'Главная', 'fs-lms' ),
'url'   => home_url( '/' )
),
			array(
'label'   => __( 'Отчёт по экзамену', 'fs-lms' ),
'current' => true
),
		);
		$data['page_url'] = PageRoutes::ExamReport->url();

		ob_start();
		$this->render( 'frontend/exam-report', $data );

		return (string) ob_get_clean();
	}

	/**
	 * Штатная robots meta дополняет HTTP-заголовок гостевой страницы.
	 *
	 * @param array<string, bool|string> $robots Директивы WordPress и других фильтров.
	 * @return array<string, bool|string>
	 */
	public function filterRobots( array $robots ): array {
		if ( $this->isGuestPage() ) {
			unset( $robots['index'], $robots['follow'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Защищённые служебные страницы не публикуют canonical.
	 *
	 * @param string|false $url Ссылка, подготовленная WordPress.
	 * @return string|false
	 */
	public function filterCanonicalUrl( string|false $url ): string|false {
		return $this->isGuestPage() ? false : $url;
	}

	/**
	 * Гостевые страницы не попадают в карту сайта.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function excludeFromSitemap( array $args, string $postType ): array {
		if ( 'page' === $postType ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $this->guestPageIds() );
		}

		return $args;
	}

	/** Гостевые страницы не находятся поиском по сайту. */
	public function excludeFromSearch( \WP_Query $query ): void {
		if ( $query->is_main_query() && $query->is_search() && ! is_admin() ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $this->guestPageIds() ) );
		}
	}

	private function isGuestPage(): bool {
		foreach ( PageRoutes::guestExamPages() as $route ) {
			if ( $route->isCurrent() ) {
				return true;
			}
		}

		return false;
	}

	/** @return list<int> */
	private function guestPageIds(): array {
		$ids = array();
		foreach ( PageRoutes::guestExamPages() as $route ) {
			$page = $this->posts->findByPath( $route->value );
			if ( $page instanceof \WP_Post ) {
				$ids[] = $page->ID;
			}
		}

		return $ids;
	}
}
