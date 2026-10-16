<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\ExamLogDTO;
use Inc\DTO\Log\ExamLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class ExamLogRepository
 *
 * Журнал «Экзамены» (exam_log): действия с проведениями, сеансами, заявками и
 * попытками. Чтение — в {@see AbstractLogRepository}.
 *
 * ### Фильтры
 *
 * - action — действие ({@see \Inc\Enums\Exam\ExamLogAction})
 * - result — success | failure
 * - event_id / session_id / registration_id / attempt_id — объект действия
 * - person — подстрока ФИО участника
 *
 * @method ExamLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method ExamLogDTO[] listAll( array $filters )
 */
class ExamLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::Exams;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'action'          => array( 'action', LogFilterType::Text ),
			'result'          => array( 'result', LogFilterType::Text ),
			'event_id'        => array( 'event_id', LogFilterType::Number ),
			'session_id'      => array( 'session_id', LogFilterType::Number ),
			'registration_id' => array( 'registration_id', LogFilterType::Number ),
			'attempt_id'      => array( 'attempt_id', LogFilterType::Number ),
			'person'          => array( 'subject_label', LogFilterType::Like ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): ExamLogDTO {
		return ExamLogDTO::fromArray( $row );
	}

	public function create( ExamLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Действия, встречающиеся в журнале, — словарь фильтра UI.
	 *
	 * @return string[]
	 */
	public function distinctActions(): array {
		return $this->distinctValues( 'action' );
	}
}
