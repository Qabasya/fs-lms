<?php
/**
 * Общее представление разбора для результата гостя и школьного отчёта; без данных оболочки страницы.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;

/**
 * Общее представление разбора для результата гостя и школьного отчёта; без данных оболочки страницы.
 *
 * Разбор берётся из той же проекции, что у ученика (`read_only`), и по тому же правилу раскрытия: гость видит сразу после сдачи.
 * Попытка определяется по участию из гостевой сессии — номер попытки из адреса не читается. Ручная часть ОГЭ показывается
 * с пометкой «Проверяется преподавателем» и без балла, итог до её завершения — предварительный.
 */
class ExamReviewViewService {

	/** Подписи вердиктов задания — те же, что в кабинете (`VERDICT_LABEL` в task-render.js). */
	private const VERDICT_LABELS = array(
		'correct'    => 'Верно',
		'corrected'  => 'Верно с исправлением',
		'incorrect'  => 'Неверно',
		'partial'    => 'Частично',
		'unanswered' => 'Не решено',
		'pending'    => 'Проверяется преподавателем',
	);

	/** Получает проекцию работы и шкалу результата; контакты добавляет гостевое представление. */
	public function __construct(
		private readonly ExamReviewProjection $reviews,
		private readonly ExamScoreService $scores,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamEventRepository $events,
	) {}

	/**
	 * Готовит общий разбор текущей попытки участия без контактов центра.
	 *
	 * @return array<string, mixed>|null null — участия нет; `revealed = false` — работа ещё не сдана.
	 */
	public function build( int $participationId ): ?array {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		$review        = null !== $participation ? $this->reviews->forParticipation( $participationId ) : null;
		if ( null === $event || null === $review ) {
			return null;
		}

		if ( true !== ( $review['revealed'] ?? false ) ) {
			return array(
				'revealed'    => false,
				'event_title' => $event->title,
			);
		}

		$summary = (array) ( $review['result'] ?? array() );
		$units   = array_values( (array) ( $review['units'] ?? array() ) );

		return array(
			'revealed'    => true,
			'subject_key' => $event->subjectKey,
			'event_title' => $event->title,
			'title'       => (string) ( $review['title'] ?? $event->title ),
			// Время попытки — местное, как и в `assessment_attempts`: перевода не нужно.
			'submitted'   => (string) ( $review['submitted_at'] ?? '' ),
			'duration_text' => isset( $review['duration_sec'] ) ? $this->clock( (int) $review['duration_sec'] ) : '',
			'caption'     => $this->scores->caption( $summary ),
			'preliminary' => ! empty( $summary ) && empty( $summary['final'] ),
			'units'       => $units,
			'counts'      => $this->counts( $units ),
			'tasks'       => $this->tasks( (array) ( $review['tasks'] ?? array() ) ),
		);
	}

	/**
	 * Три показателя шапки по единицам оценивания; «проверяется» — отдельно, чтобы не выдавать ручную часть ни за верную, ни за неверную.
	 *
	 * @param list<array<string, mixed>> $units
	 *
	 * @return array{correct: int, partial: int, wrong: int, pending: int}
	 */
	private function counts( array $units ): array {
		$counts = array(
			'correct' => 0,
			'partial' => 0,
			'wrong'   => 0,
			'pending' => 0,
		);
		foreach ( $units as $unit ) {
			$key = match ( (string) ( $unit['status'] ?? '' ) ) {
				'correct'             => 'correct',
				'partial'             => 'partial',
				'pending'             => 'pending',
				default               => 'wrong',
			};
			++$counts[ $key ];
		}

		return $counts;
	}

	/**
	 * Задания для шаблона: подпись и класс вердикта, балл только у проверенных, эталон только у ошибочных.
	 *
	 * @param array<int, array<string, mixed>> $tasks
	 *
	 * @return list<array<string, mixed>>
	 */
	private function tasks( array $tasks ): array {
		$result = array();
		foreach ( $tasks as $task ) {
			$verdict = ! empty( $task['corrected'] ) && 'correct' === ( $task['verdict'] ?? '' ) ? 'corrected' : (string) ( $task['verdict'] ?? 'pending' );
			$pending = 'pending' === $verdict;

			$result[] = array(
				'n'            => (int) ( $task['n'] ?? 0 ),
				'anchor'       => (string) ( $task['anchor'] ?? '' ),
				'verdict'      => $verdict,
				'verdict_text' => self::VERDICT_LABELS[ $verdict ] ?? $verdict,
				// Ручная часть: балл не выводится до проверки (сам по себе ноль ввёл бы в заблуждение).
				'score'        => $pending || ! isset( $task['score'] ) ? null : (float) $task['score'],
				'max_score'    => isset( $task['max_score'] ) ? (float) $task['max_score'] : null,
				'condition'    => $this->withoutOwnTitle( (string) ( $task['condition'] ?? '' ) ),
				'answer'       => (string) ( $task['answer'] ?? '' ),
				'code'         => (string) ( $task['code'] ?? '' ),
				'files'        => (array) ( $task['files'] ?? array() ),
				'correct'      => ! $pending && 'correct' !== $verdict && 'corrected' !== $verdict ? (string) ( $task['correct'] ?? '' ) : '',
				'solution'     => ! $pending && is_array( $task['solution'] ?? null ) ? $task['solution'] : null,
				'answer_time'  => $this->answerTime( $task['answer_first_sec'] ?? null, $task['answer_last_sec'] ?? null ),
			);
		}

		return $result;
	}

	/** Секунды → «ЧЧ:ММ:СС» (часов может быть больше двух цифр). */
	private function clock( int $sec ): string {
		$sec = max( 0, $sec );

		return sprintf( '%02d:%02d:%02d', intdiv( $sec, 3600 ), intdiv( $sec % 3600, 60 ), $sec % 60 );
	}

	/**
	 * Время ответа на задание для шапки карточки: «Ответ дан: ЧЧ:ММ:СС» или, если ответ менялся,
	 * «Ответ дан: ЧЧ:ММ:СС / ЧЧ:ММ:СС» (до первого ответа / до последнего, от начала экзамена).
	 *
	 * @return array{text: string, title: string}|null null — ответа не было.
	 */
	private function answerTime( mixed $first, mixed $last ): ?array {
		if ( null === $first && null === $last ) {
			return null;
		}
		$first = (int) ( $first ?? $last );
		$last  = (int) ( $last ?? $first );

		if ( $last === $first ) {
			return array(
				'text'  => 'Ответ дан: ' . $this->clock( $first ),
				'title' => 'Сколько времени прошло от начала экзамена до ответа на задание',
			);
		}

		return array(
			'text'  => 'Ответ дан: ' . $this->clock( $first ) . ' / ' . $this->clock( $last ),
			'title' => 'Первое время — сколько прошло до первого ответа, второе — сколько прошло от начала экзамена до последнего ответа',
		);
	}

	/**
	 * Убирает из начала условия собственный заголовок «Задание N» (у заданий 19–21 он набран абзацем): карточка разбора
	 * уже подписана номером, и второй заголовок дублирует первый.
	 */
	private function withoutOwnTitle( string $html ): string {
		return (string) preg_replace( '~^\s*<p[^>]*>\s*(?:<(?:strong|b)[^>]*>\s*)?Задание\s*№?\s*\d+\s*[.:]?\s*(?:</(?:strong|b)>\s*)?</p>\s*~iu', '', $html, 1 );
	}
}
