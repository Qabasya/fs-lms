<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Services;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Services\Assessment\AttemptTaskViewBuilder;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class KegeSheetResponseBuilder
 *
 * Лист ответов станции КЕГЭ по ответам, присланным браузером (`answers[task_id]`),
 * — общий кусок для предпросмотра автора и публичного экзамена: попытки в БД нет
 * ни там, ни там, ответы приходят прямо в запросе. Права доступа проверяет
 * вызывающий колбэк, сюда попадает уже допущенный запрос.
 *
 * @package Inc\Modules\EgeComputer\Services
 */
class KegeSheetResponseBuilder {

	use Sanitizer;

	public function __construct(
		private readonly AttemptTaskViewBuilder $taskViews,
		private readonly KegeResultSheetService $resultSheet,
	) {}

	/**
	 * @return array<string, mixed> Данные листа в формате `renderKegeSheet()` (kege-entry.js)
	 */
	public function fromRequest( AssessmentDTO $assessment ): array {
		$answerText = array();
		foreach ( $this->unslashArray( 'answers' ) as $taskId => $value ) {
			$taskId = absint( $taskId );
			if ( $taskId > 0 ) {
				$answerText[ $taskId ] = $this->sanitizeAnswerTextValue( $value );
			}
		}

		$taskViews = $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind );
		$sheet     = $this->resultSheet->buildFromAnswers( $assessment, $answerText, $taskViews );

		return array(
			'rows'          => $sheet->rows,
			'answered'      => $sheet->answered,
			'total'         => $sheet->total(),
			'primary'       => $sheet->primary,
			'primary_max'   => $sheet->primaryMax,
			'secondary'     => $sheet->secondary,
			'secondary_max' => $sheet->secondaryMax,
		);
	}
}
