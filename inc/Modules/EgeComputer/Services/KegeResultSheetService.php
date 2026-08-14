<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Services;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Services\Assessment\SecondaryScoreService;
use Inc\Services\Task\CorrectAnswerResolver;

/**
 * Class KegeResultSheetService
 *
 * Лист ответов станции КЕГЭ: строка на каждую ПОЗИЦИЮ ответа (номер задания,
 * балл, ответ ученика, эталон) плюс сводка баллов. Строится по ПОЛНОМУ списку
 * заданий работы, а не по сохранённым ответам: пропущенное задание в реальном
 * листе тоже занимает строку — с пустой колонкой ответа.
 *
 * Позиций больше, чем заданий: №26 и №27 требуют по два ответа
 * ({@see KegeScaleConfig::answerSlots()}), поэтому 27 заданий дают 29 строк и
 * 29 первичных баллов. Задание занимает ровно одну позицию на свой номер — в
 * том числе составное (Triple), из которого берётся подпункт, совпадающий с
 * номером задания в банке.
 *
 * Балл позиции считается здесь сличением ответа с эталоном, а не берётся из
 * попытки: у попытки один балл на весь task_id (и своя, авторская, шкала весов),
 * а лист показывает построчный разбор — иначе сумма колонки «Балл» не сходилась
 * бы с итогом экрана.
 *
 * Эталонные ответы здесь сознательно доходят до ученика (сам смысл экрана), в
 * отличие от {@see \Inc\Services\Assessment\AttemptResultService} — générique-экран
 * результата их по-прежнему не отдаёт.
 *
 * @package Inc\Modules\EgeComputer\Services
 */
readonly class KegeResultSheetService {

	/**
	 * @param AssessmentAnswerRepository $answers        Ответы попытки
	 * @param CorrectAnswerResolver      $correctAnswers Эталонный ответ задания
	 * @param SecondaryScoreService      $secondaryScore Перевод первичного балла во вторичный
	 * @param PostManager                $posts          Доступ к записям заданий
	 */
	public function __construct(
		private AssessmentAnswerRepository $answers,
		private CorrectAnswerResolver      $correctAnswers,
		private SecondaryScoreService      $secondaryScore,
		private PostManager                $posts,
	) {}

	/**
	 * @param AssessmentDTO   $assessment Контрольная
	 * @param AttemptDTO|null $attempt    Последняя сданная попытка; null — предпросмотр автора
	 * @param array           $taskViews  Per-task view-данные страницы (нужен номер задания)
	 */
	public function build( AssessmentDTO $assessment, ?AttemptDTO $attempt, array $taskViews ): KegeSheetDTO {
		$answerText = array();
		if ( null !== $attempt ) {
			foreach ( $this->answers->listByAttempt( $attempt->id ) as $row ) {
				$answerText[ $row->taskId ] = (string) ( $row->answerText ?? '' );
			}
		}

		return $this->assemble( $assessment, $answerText, $taskViews, null !== $attempt );
	}

	/**
	 * Лист по ответам, которых нет в БД, — предпросмотр автора (T15.10):
	 * `AttemptPageService::buildPreview()` не заводит попытку, и страница станции
	 * ничего не пишет в `assessment_answers` — накопленные ответы живут только в
	 * памяти вкладки браузера ({@see \Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks}).
	 * Как только автор жмёт «Завершить экзамен» в предпросмотре, JS шлёт эти
	 * ответы сюда напрямую — расчёт баллов и сравнение с эталоном идут по тому же
	 * коду, что и настоящий лист, разница только в источнике ответов.
	 *
	 * @param AssessmentDTO       $assessment Контрольная
	 * @param array<int, string>  $answerText Ответ ученика по task_id — как он лёг бы в answer_text
	 * @param array               $taskViews  Per-task view-данные страницы (нужен номер задания)
	 */
	public function buildFromAnswers( AssessmentDTO $assessment, array $answerText, array $taskViews ): KegeSheetDTO {
		return $this->assemble( $assessment, $answerText, $taskViews, true );
	}

	/**
	 * @param AssessmentDTO      $assessment Контрольная
	 * @param array<int, string> $answerText Ответ ученика по task_id
	 * @param array              $taskViews  Per-task view-данные страницы
	 * @param bool               $scored     Считать баллы (есть с чем сличать) — false только
	 *                                        для générique-предпросмотра без ответов вообще
	 */
	private function assemble( AssessmentDTO $assessment, array $answerText, array $taskViews, bool $scored ): KegeSheetDTO {
		// ID приходят из таблицы контрольной, не из WP_Query — без прогрева каждое
		// чтение меты и записи в цикле шло бы отдельным запросом.
		$this->posts->primeMetaCache( $assessment->taskIds );
		$this->posts->primePostCache( $assessment->taskIds );

		$rows       = array();
		$primary    = 0.0;
		$primaryMax = 0.0;

		foreach ( $assessment->taskIds as $position => $taskId ) {
			$taskId = (int) $taskId;
			$number = $this->number( $assessment, $taskViews, $taskId, (int) $position );
			$slots  = KegeScaleConfig::answerSlots( (int) $number );

			$given   = $this->slots( $this->studentAnswer( $answerText[ $taskId ] ?? '', $number ), $slots );
			$correct = $this->slots( $this->correctAnswer( $taskId, $number ), $slots );

			$primaryMax += $slots;

			for ( $slot = 0; $slot < $slots; $slot++ ) {
				$score = $this->slotScore( $scored, $given[ $slot ], $correct[ $slot ] );

				$rows[] = array(
					'number'  => $number,
					'score'   => $score,
					'answer'  => $given[ $slot ],
					'correct' => $correct[ $slot ],
				);

				$primary += (float) ( $score ?? 0.0 );
			}
		}

		// Шкала перевода станции фиксирована (см. KegeScaleConfig): у реального
		// КЕГЭ максимум вторичного балла — 100, авторская таблица работы его не меняет.
		$secondary = $this->secondaryScore->translate( $primary, KegeScaleConfig::scale() ) ?? 0;

		$answered = count( array_filter( $rows, static fn( array $row ): bool => '' !== $row['answer'] ) );

		return new KegeSheetDTO(
			rows        : $rows,
			answered    : $answered,
			primary     : $primary,
			primaryMax  : $primaryMax,
			secondary   : $secondary,
			secondaryMax: KegeScaleConfig::secondaryMax(),
		);
	}

	/**
	 * Балл позиции: 1 за совпадение с эталоном, 0 за расхождение. Null («—») —
	 * когда сличать не с чем: задание без эталонного ответа (ручная проверка
	 * преподавателем) либо générique-вызов без ответов вообще ($scored = false).
	 *
	 * @param bool   $scored  Есть с чем сличать (реальная попытка или предпросмотр с ответами)
	 * @param string $given   Ответ ученика в этой позиции
	 * @param string $correct Эталон этой позиции
	 */
	private function slotScore( bool $scored, string $given, string $correct ): ?float {
		if ( ! $scored || '' === $correct ) {
			return null;
		}

		return $this->same( $given, $correct ) ? 1.0 : 0.0;
	}

	/**
	 * Сравнение ответа с эталоном: регистр не важен, лишние пробелы тоже —
	 * ученик набирает ответ руками, а эталон приходит из меты задания.
	 */
	private function same( string $given, string $correct ): bool {
		$normalize = static fn( string $value ): string => mb_strtolower(
			(string) preg_replace( '/\s+/u', ' ', trim( $value ) )
		);

		return '' !== $given && $normalize( $given ) === $normalize( $correct );
	}

	/**
	 * Разбор ответа по позициям задания. Значения разделены пробелами (и в
	 * ответе ученика, и в эталоне), поэтому режем на токены и раскладываем их
	 * поровну: №26 — «1159 57» → «1159» + «57», №27 — четыре числа таблицы →
	 * по паре на позицию.
	 *
	 * @param string $plain Ответ одной строкой
	 * @param int    $slots Сколько позиций занимает задание
	 *
	 * @return list<string> Ровно $slots элементов
	 */
	private function slots( string $plain, int $slots ): array {
		if ( $slots < 2 ) {
			return array( $plain );
		}

		$tokens = preg_split( '/\s+/u', trim( $plain ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = is_array( $tokens ) ? $tokens : array();

		$parts = count( $tokens ) > $slots
			? array_map(
				static fn( array $chunk ): string => implode( ' ', $chunk ),
				array_chunk( $tokens, (int) ceil( count( $tokens ) / $slots ) )
			)
			: $tokens;

		return array_pad( array_slice( $parts, 0, $slots ), $slots, '' );
	}

	/**
	 * Эталонный ответ задания под его номер. Составной шаблон (Triple 19-21)
	 * хранит по эталону на подпункт — берём тот, чей ключ совпал с номером
	 * задания в банке; для остальных шаблонов эталон собирает резолвер.
	 *
	 * @param int    $taskId Задание
	 * @param string $number Номер задания
	 */
	private function correctAnswer( int $taskId, string $number ): string {
		$metaRaw = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$meta    = is_array( $metaRaw ) ? $metaRaw : array();
		$subKey  = "task_{$number}_answer";

		$raw = isset( $meta[ $subKey ] )
			? trim( (string) $meta[ $subKey ] )
			: trim( (string) ( $this->correctAnswers->resolve( $taskId ) ?? '' ) );

		return $this->readableTable( $raw );
	}

	/**
	 * Ответ ученика в виде читаемой строки: табличный ответ (№25/№27) хранится
	 * ячейками через '|', «Развёрнутый ответ» — JSON с текстом и файлами,
	 * составное задание — JSON с ответом на каждый подпункт (берём подпункт под
	 * номер задания, как и эталон).
	 *
	 * @param string $raw    Сохранённый ответ (answer_text попытки либо строка из предпросмотра)
	 * @param string $number Номер задания
	 */
	private function studentAnswer( string $raw, string $number ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( str_starts_with( $raw, '{' ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['text'] ) ) {
					return trim( (string) $decoded['text'] );
				}
				if ( isset( $decoded[ $number ] ) ) {
					return trim( (string) $decoded[ $number ] );
				}
			}
		}

		return $this->readableTable( $raw );
	}

	/**
	 * Табличный ответ (№25/№27, T15.10) в читаемый вид: '|' — колонки одной
	 * строки таблицы, схлопываются в пробел; перевод строки — граница строк
	 * таблицы, а не мусор — сохраняется как есть. Раньше оба разделителя
	 * схлопывались в пробел, и вся таблица (до 5 строк, №25) превращалась в
	 * одну нечитаемую строку на листе ответов. Перенос строки в ячейке листа
	 * рендерится реальной строкой — разметка `.kege-fin-tbl__ans` держит
	 * `white-space: pre-line` (см. `src/scss/kege/components/_finish.scss`).
	 *
	 * Текста без '|' и переносов (обычный однострочный ответ) не касается.
	 *
	 * @param string $raw Ответ или эталон одной строкой
	 */
	private function readableTable( string $raw ): string {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines      = array_map(
			static fn( string $line ): string => trim( str_replace( '|', ' ', $line ) ),
			explode( "\n", $normalized )
		);

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Номер задания для колонки «№»: терм таксономии {key}_task_number, иначе
	 * номер, проставленный на самой работе, иначе позиция в работе.
	 *
	 * @param AssessmentDTO $assessment Контрольная
	 * @param array         $taskViews  Per-task view-данные страницы
	 * @param int           $taskId     Задание
	 * @param int           $position   Позиция задания в работе (с нуля)
	 */
	private function number( AssessmentDTO $assessment, array $taskViews, int $taskId, int $position ): string {
		$fromTaxonomy = (int) ( $taskViews[ $taskId ]['taskNumber'] ?? 0 );
		if ( $fromTaxonomy > 0 ) {
			return (string) $fromTaxonomy;
		}

		$fromAssessment = trim( (string) ( $assessment->taskNumbers[ $taskId ] ?? '' ) );

		return '' !== $fromAssessment ? $fromAssessment : (string) ( $position + 1 );
	}
}