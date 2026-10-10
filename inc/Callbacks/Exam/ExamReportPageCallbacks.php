<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\ExamReportRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamReportService;
use Inc\Services\Exam\ExamReportViewService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Страница школьного отчёта (этап 12.3): ключ → кука → редирект без ключа; затем таблица и разбор выбранной строки.
 *
 * Школьный преподаватель не пользователь WordPress: личность — сессия отчёта (`scope = report`), и принимает её только эта страница.
 * Сервер на **каждом** запросе проверяет: сессия действительна, поколение ключа актуально, отчёт не отозван и не истёк. Идентификаторам
 * попытки и участия из адреса страница не доверяет — читается лишь порядковый номер строки `row` внутри текущего отчёта.
 */
class ExamReportPageCallbacks extends BaseController {

	use RequestContextProvider;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly ExamAccessTokenService $tokens,
		private readonly GuestSessionService $sessions,
		private readonly ExamReportRepository $reports,
		private readonly ExamReportService $reportService,
		private readonly ExamReportViewService $view,
		private readonly RateLimitService $rateLimit,
		private readonly ExamTime $time,
	) {
		parent::__construct();
	}

	public function handleReportPage(): GuestPageOutcome {
		$ip  = $this->requestContext()->ip;
		$key = strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) );

		if ( '' !== $key ) {
			return $this->exchange( $key, $ip );
		}

		return null !== $this->currentReport() ? GuestPageOutcome::render() : GuestPageOutcome::notFound();
	}

	public function renderReportPage(): string {
		$report = $this->currentReport();
		if ( null === $report ) {
			return '';
		}

		$row  = $this->hasParam( 'row', 'GET' ) ? $this->sanitizeGetInt( 'row' ) : 0;
		$data = $this->view->build( $report, $row > 0 ? $row : null );
		$data['crumbs'] = array(
			array( 'label' => __( 'Главная', 'fs-lms' ), 'url' => home_url( '/' ) ),
			array( 'label' => __( 'Отчёт по экзамену', 'fs-lms' ), 'current' => true ),
		);
		$data['page_url'] = PageRoutes::ExamReport->url();

		ob_start();
		$this->render( 'frontend/exam-report', $data );

		return (string) ob_get_clean();
	}

	/** Отчёт, открытый сессией: действующий (не отозван, не истёк) и по актуальному ключу. */
	public function currentReport(): ?ExamReportDTO {
		$id     = $this->sessions->currentReportId();
		$report = null !== $id ? $this->reports->find( $id ) : null;

		return null !== $report && $this->reportService->isOpen( $report ) ? $report : null;
	}

	private function exchange( string $key, string $ip ): GuestPageOutcome {
		if ( $this->rateLimit->isInvitationLocked( $ip ) ) {
			return GuestPageOutcome::notFound();
		}

		$token  = $this->tokens->exchange( ExamTokenPurpose::Report, $key );
		$report = null !== $token ? $this->reports->find( $token->targetId ) : null;
		if ( null === $token || null === $report || ! $this->reportService->isOpen( $report ) ) {
			$this->rateLimit->registerInvitationFailure( $ip );

			return GuestPageOutcome::notFound();
		}

		// Сессия живёт не дольше отчёта и не дольше суток: ссылка остаётся у школы, кука — нет.
		$day     = $this->time->addMinutes( $this->time->nowUtc(), 1440 );
		$cookie  = $this->sessions->openReport( $token, min( $report->expiresAt, $day ) );

		return GuestPageOutcome::redirect( PageRoutes::ExamReport->url(), array( GuestSessionService::COOKIE_REPORT => $cookie ) );
	}
}
