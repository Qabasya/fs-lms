<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

/**
 * Class AttemptRegradeService
 *
 * Пересчёт автоматически проверяемых заданий уже сданных попыток станции порциями по возрастанию ID попытки.
 * Правила пересчёта (что не трогается, что пишется) — в {@see AutoGradeService::regradeAutomatic()}; здесь только обход.
 * Зовут одноразовая миграция {@see \Inc\Migrations\AttemptRegradeMigration} и WP-CLI `wp fs-lms exam regrade`.
 *
 * @package Inc\Services\Assessment
 */
class AttemptRegradeService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AutoGradeService $grader,
	) {}

	/**
	 * Обрабатывает одну порцию попыток.
	 *
	 * @param int  $afterId Обрабатываются попытки с ID больше этого
	 * @param int  $limit   Размер порции
	 * @param bool $dryRun  Только посчитать, ничего не записывая
	 *
	 * @return array{last_id: int, scanned: int, attempts: int, answers: int} `last_id` — ID последней просмотренной попытки
	 *                                                                      (то же значение, что `$afterId`, — порция пуста: всё пройдено)
	 */
	public function runBatch( int $afterId, int $limit, bool $dryRun = false ): array {
		$scanned  = 0;
		$attempts = 0;
		$answers  = 0;
		$lastId   = $afterId;

		foreach ( $this->attempts->listSubmittedAfter( $afterId, $limit ) as $attempt ) {
			++$scanned;
			$lastId  = $attempt->id;
			$changed = $this->grader->regradeAutomatic( $attempt, $dryRun );
			if ( $changed > 0 ) {
				++$attempts;
				$answers += $changed;
			}
		}

		return array(
			'last_id'  => $lastId,
			'scanned'  => $scanned,
			'attempts' => $attempts,
			'answers'  => $answers,
		);
	}
}
