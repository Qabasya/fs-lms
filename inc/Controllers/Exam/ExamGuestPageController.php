<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Callbacks\Exam\ExamReportPageCallbacks;
use Inc\Callbacks\Exam\GuestApplicationCallbacks;
use Inc\Callbacks\Exam\GuestEntryCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Services\Exam\GuestPageResponder;

/**
 * Публичные страницы гостя: запись по ссылке школы (этап 11a), вход и результат (этап 11b).
 *
 * Только вешает хуки: проверки и решения — в коллбеках, исполнение (заголовки, куки, редирект, 404) — в `GuestPageResponder`.
 * Страницы не индексируются (`noindex` в `wp_head`, исключение из карты сайта и из поиска) и не кешируются.
 */
class ExamGuestPageController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly GuestApplicationCallbacks $application,
		private readonly GuestEntryCallbacks $entry,
		private readonly ExamReportPageCallbacks $report,
		private readonly GuestPageResponder $responder,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_shortcode( ShortCode::ExamSignup->value, array( $this->application, 'renderSignupPage' ) );
		add_action( 'template_redirect', array( $this, 'handleSignupPage' ), 5 );
		add_shortcode( ShortCode::ExamEntry->value, array( $this->entry, 'renderEntryPage' ) );
		add_action( 'template_redirect', array( $this, 'handleEntryPage' ), 5 );
		add_shortcode( ShortCode::ExamResult->value, array( $this->entry, 'renderResultPage' ) );
		add_action( 'template_redirect', array( $this, 'handleResultPage' ), 5 );
		add_shortcode( ShortCode::ExamReport->value, array( $this->report, 'renderReportPage' ) );
		add_action( 'template_redirect', array( $this, 'handleReportPage' ), 5 );
		add_action( 'wp_head', array( $this, 'printNoindex' ), 1 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'excludeFromSitemap' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'excludeFromSearch' ) );
	}

	/** Страница формы: заголовки, затем исход (отрисовать / редирект без ключа / 404). */
	public function handleSignupPage(): void {
		if ( ! PageRoutes::ExamSignup->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->application->handleInvitationPage() );
	}

	/** Страница входа: заголовки, затем исход (отрисовать / редирект без ключа / 404). */
	public function handleEntryPage(): void {
		if ( ! PageRoutes::ExamEntry->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->entry->handleEntryPage() );
	}

	/** Страница результата: заголовки, затем исход. */
	public function handleResultPage(): void {
		if ( ! PageRoutes::ExamResult->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->entry->handleResultPage() );
	}

	/** Страница школьного отчёта: заголовки, затем исход. */
	public function handleReportPage(): void {
		if ( ! PageRoutes::ExamReport->isCurrent() ) {
			return;
		}

		$this->responder->sendHeaders();
		$this->responder->apply( $this->report->handleReportPage() );
	}

	/** `<meta name="robots">` на гостевых страницах: к HTTP-заголовку добавляется разметка (для краулеров, читающих только её). */
	public function printNoindex(): void {
		if ( $this->isGuestPage() ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		}
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
		foreach ( $this->guestRoutes() as $route ) {
			if ( $route->isCurrent() ) {
				return true;
			}
		}

		return false;
	}

	/** @return list<int> */
	private function guestPageIds(): array {
		$ids = array();
		foreach ( $this->guestRoutes() as $route ) {
			$page = get_page_by_path( $route->value );
			if ( $page instanceof \WP_Post ) {
				$ids[] = $page->ID;
			}
		}

		return $ids;
	}

	/** @return list<PageRoutes> */
	private function guestRoutes(): array {
		return array( PageRoutes::ExamSignup, PageRoutes::ExamEntry, PageRoutes::ExamResult, PageRoutes::ExamReport );
	}
}
