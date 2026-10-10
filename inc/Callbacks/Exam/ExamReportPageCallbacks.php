<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Wp\PageRoutes;
use Inc\Services\Exam\ExamReportViewService;
use Inc\Services\Exam\GuestPageAccessService;
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
		private readonly ExamReportViewService $view,
		private readonly GuestPageAccessService $access,
	) {
		parent::__construct();
	}

	public function handleReportPage(): GuestPageOutcome {
		return $this->access->reportPage( strtolower( trim( $this->sanitizeText( 'k', 'GET' ) ) ), $this->requestContext()->ip );
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
		return $this->access->currentReport();
	}


}
