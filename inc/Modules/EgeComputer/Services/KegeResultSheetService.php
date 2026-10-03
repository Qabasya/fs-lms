<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Services;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Services\Assessment\ArchiveTaskNumber;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Services\Assessment\SecondaryScoreService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Shared\Traits\AnswerNormalizer;

/**
 * Class KegeResultSheetService
 *
 * Лист ответов станции КЕГЭ: строка на каждое ЗАДАНИЕ работы (номер задания, балл,
 * ответ ученика, эталон) плюс сводка баллов. Строится по ПОЛНОМУ списку заданий, а не
 * по сохранённым ответам: пропущенное задание в реальном листе тоже занимает строку —
 * с пустой колонкой ответа. Заданий может быть больше 27 (повторы типа, архивные
 * варианты), а первичный балл при этом остаётся 29.
 *
 * Баллы: №26 и №27 — одна строка до двух баллов (два числа ответа в одной ячейке,
 * {@see KegeScaleConfig::answerSlots()}); задания одного типа — одна единица зачёта
 * ({@see \Inc\Services\Assessment\ScoringUnits}): балл типа стоит в первой его строке.
 * Составное (Triple) задание даёт подпункт, совпадающий с номером задания в банке.
 *
 * Балл строки считается здесь сличением ответа с эталоном, а не берётся из
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

	use AnswerNormalizer;

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
		private ArchiveTaskNumber          $archive,
		private ScoringUnits               $units,
	) {}

	/**
	 * @param AssessmentDTO   $assessment Контрольная
	 * @param AttemptDTO|null $attempt    Последняя сданная попытка; null — предпросмотр автора
	 * @param array           $taskViews  Per-task view-данные страницы (нужен номер задания)
	 * @param bool            $revealed   D18: можно ли показывать ответы/баллы ученику —
	 *                                    предпросмотр автора ($attempt === null) всегда true
	 */
	public function build( AssessmentDTO $assessment, ?AttemptDTO $attempt, array $taskViews, bool $revealed = true ): KegeSheetDTO {
		$answerText = array();
		$graded     = array();
		$overridden = array();
		if ( null !== $attempt ) {
			foreach ( $this->answers->listByAttempt( $attempt->id ) as $row ) {
				$answerText[ $row->taskId ] = (string) ( $row->answerText ?? '' );
				// Ручная проверка (ОГЭ 13-16, D18): task_answer в мете задания нет —
				// балл сличением текста не посчитать, берём то, что выставил учитель.
				if ( null !== $row->isCorrect ) {
					$graded[ $row->taskId ] = $row->score;
				}
				// Tasks.md, п. 6: преподаватель засчитал задание вручную — его балл
				// авторитетнее сличения с эталоном. Маркер — `graded_by_user_id`:
				// авто-оценка ({@see \Inc\Services\Assessment\AutoGradeService}) его
				// не пишет. Без этого лист станции пересчитал бы задание по эталону
				// и разошёлся с журналом на одной и той же попытке.
				if ( null !== $row->gradedByUserId ) {
					$overridden[ $row->taskId ] = (float) ( $row->score ?? 0.0 );
				}
			}
		}

		return $this->assemble( $assessment, $answerText, $taskViews, null !== $attempt, $graded, $revealed, $overridden );
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
		return $this->assemble( $assessment, $answerText, $taskViews, true, array(), true );
	}

	/**
	 * @param AssessmentDTO       $assessment Контрольная
	 * @param array<int, string>  $answerText Ответ ученика по task_id
	 * @param array               $taskViews  Per-task view-данные страницы
	 * @param bool                $scored     Считать баллы (есть с чем сличать) — false только
	 *                                         для générique-предпросмотра без ответов вообще
	 * @param array<int, ?float>  $graded     task_id => балл ручной проверки (D18, ОГЭ 13-16);
	 *                                         только для заданий, где `correctAnswer()` пуст
	 * @param bool                $revealed   D18: можно ли показывать ответы/баллы ученику
	 * @param array<int, float>   $overridden task_id => балл, выставленный преподавателем вручную
	 *                                         (Tasks.md, п. 6) — побеждает сличение с эталоном
	 */
	private function assemble( AssessmentDTO $assessment, array $answerText, array $taskViews, bool $scored, array $graded, bool $revealed, array $overridden = array() ): KegeSheetDTO {
		// ID приходят из таблицы контрольной, не из WP_Query — без прогрева каждое
		// чтение меты и записи в цикле шло бы отдельным запросом.
		$this->posts->primeMetaCache( $assessment->taskIds );
		$this->posts->primePostCache( $assessment->taskIds );

		$rows    = array();
		$taskRef = array(); // task_id => ['max' => балл задания, 'row' => индекс его строки]

		// Строка листа — задание (как на реальной станции): №26 и №27 стоят одной строкой,
		// два числа ответа — в одной ячейке, балл строки — до двух. Позиции ответа нужны
		// только для подсчёта частичного балла (одно из двух чисел верно).
		foreach ( $assessment->taskIds as $position => $taskId ) {
			$taskId = (int) $taskId;
			// Два номера: `$number` — «живой» номер задания (архивное №117 → 17), от него
			// форма ответа и эталон; `$label` — номер задания как он записан (архивное
			// остаётся №117), под ним строка листа: так задание узнают, и три задания №14
			// подряд подписаны тремя «14». Любое задание может стоять на любой позиции —
			// за соответствием формы следит автор работы.
			$number = $this->number( $assessment, $taskViews, $taskId, (int) $position );
			$label  = $this->label( $assessment, $taskViews, $taskId, (int) $position );
			$slots  = $this->answerSlots( $assessment->kind, (int) $number );

			$givenPlain   = $this->studentAnswer( $answerText[ $taskId ] ?? '', $number );
			$correctPlain = $this->correctAnswer( $taskId, $number );
			$given        = $this->slots( $givenPlain, $slots );
			$correct      = $this->slots( $correctPlain, $slots );

			// Балл задания целиком (D18) — не всегда «1 на слот»: ручная проверка ОГЭ
			// 13-16 стоит 2-3 балла на ОДИН слот (см. OgeCriteriaConfig::rubricFor()).
			// Источник истины — та же таблица, что и у станции (applyStationSettings());
			// без неё (générique-предпросмотр) — фолбэк «1 балл на слот», как раньше.
			$taskMax = isset( $assessment->taskPoints[ $taskId ] ) ? (float) $assessment->taskPoints[ $taskId ] : (float) $slots;
			$slotMax = $slots > 0 ? $taskMax / $slots : 0.0;

			if ( array_key_exists( $taskId, $overridden ) ) {
				// Зачёт преподавателя стоит на задании целиком и побеждает сличение с эталоном.
				$score = $overridden[ $taskId ];
			} else {
				$score = null;
				for ( $slot = 0; $slot < $slots; $slot++ ) {
					// Ручная проверка (D18): эталона для сличения нет, балл — от учителя.
					$slotScore = ( '' === $correct[ $slot ] && array_key_exists( $taskId, $graded ) )
						? $graded[ $taskId ]
						: $this->slotScore( $scored, $given[ $slot ], $correct[ $slot ], $slotMax );

					if ( null !== $slotScore ) {
						$score = ( $score ?? 0.0 ) + $slotScore;
					}
				}
			}

			$taskRef[ $taskId ] = array( 'max' => $taskMax, 'row' => count( $rows ) );
			$rows[]             = array(
				'number'  => $label,
				'score'   => $score,
				'answer'  => $givenPlain,
				'correct' => $correctPlain,
				'url'     => $revealed ? $this->publicUrl( $taskId ) : '',
			);
		}

		$primaryMax = $this->applyScoringUnits( $assessment, $taskRef, $rows );
		if ( AssessmentKind::EgeComputer === $assessment->kind ) {
			// Максимум КЕГЭ фиксирован — 29 первичных (в работе всегда есть все 27 типов),
			// каким бы ни был состав заданий.
			$primaryMax = (float) KegeScaleConfig::primaryMax();
		}
		$primary    = 0.0;
		$answered   = 0;
		foreach ( $rows as $i => $row ) {
			$primary += (float) ( $row['score'] ?? 0.0 );
			if ( '' !== $row['answer'] ) {
				++$answered;
			}

			// D18: до подтверждения учителем ученик не видит ни баллов, ни эталона.
			$rows[ $i ]['score']   = $revealed ? $row['score'] : null;
			$rows[ $i ]['correct'] = $revealed ? $row['correct'] : '';
		}

		// Шкала перевода станции фиксирована (см. KegeScaleConfig/OgeScaleConfig) и
		// зависит от вида станции: у КЕГЭ максимум вторичного балла — 100, у ОГЭ —
		// отметка 2-5; авторская таблица работы её не меняет ни там, ни там.
		$secondary = $this->secondaryScore->translate( $primary, $this->scale( $assessment->kind ) ) ?? 0;

		return new KegeSheetDTO(
			rows        : $rows,
			answered    : $answered,
			// D18: сумма баллов доходит до ученика только после подтверждения учителем —
			// строки уже зачищены выше, но и сводный балл на всякий случай тоже.
			primary     : $revealed ? $primary : 0.0,
			primaryMax  : $primaryMax,
			secondary   : $revealed ? $secondary : null,
			secondaryMax: $this->secondaryMax( $assessment->kind ),
			revealed    : $revealed,
		);
	}

	/**
	 * Сколько позиций ответа занимает задание — диспетчеризуется по виду станции
	 * (см. докблоки {@see KegeScaleConfig::answerSlots()} / {@see OgeScaleConfig::answerSlots()}).
	 */
	private function answerSlots( AssessmentKind $kind, int $number ): int {
		return AssessmentKind::OgeComputer === $kind
			? OgeScaleConfig::answerSlots()
			: KegeScaleConfig::answerSlots( $number );
	}

	/** Таблица перевода первичного балла во вторичный/отметку — по виду станции. */
	private function scale( AssessmentKind $kind ): array {
		return AssessmentKind::OgeComputer === $kind ? OgeScaleConfig::scale() : KegeScaleConfig::scale();
	}

	/** Максимум вторичного балла/отметки — по виду станции. */
	private function secondaryMax( AssessmentKind $kind ): int {
		return AssessmentKind::OgeComputer === $kind ? OgeScaleConfig::secondaryMax() : KegeScaleConfig::secondaryMax();
	}

	/**
	 * Балл позиции: $slotMax за совпадение с эталоном, 0 за расхождение. Null («—») —
	 * когда сличать не с чем: задание без эталонного ответа (ручная проверка
	 * преподавателем — обрабатывается отдельно вызывающим кодом через `$graded`)
	 * либо générique-вызов без ответов вообще ($scored = false).
	 *
	 * @param bool   $scored  Есть с чем сличать (реальная попытка или предпросмотр с ответами)
	 * @param string $given   Ответ ученика в этой позиции
	 * @param string $correct Эталон этой позиции
	 * @param float  $slotMax Балл за позицию при совпадении (D18: не всегда 1.0)
	 */
	private function slotScore( bool $scored, string $given, string $correct, float $slotMax = 1.0 ): ?float {
		if ( ! $scored || '' === $correct ) {
			return null;
		}

		return $this->same( $given, $correct ) ? $slotMax : 0.0;
	}

	/**
	 * Сравнение ответа с эталоном: регистр не важен, пробелы тоже — ученик
	 * набирает ответ руками, а эталон приходит из меты задания. Нормализация
	 * общая с чекерами ({@see AnswerNormalizer}), иначе лист станции и
	 * авто-проверка расходились бы в вердикте на одном и том же ответе
	 * (Tasks.md, п. 4).
	 */
	private function same( string $given, string $correct ): bool {
		return '' !== $given && self::normalizeAnswer( $given ) === self::normalizeStoredAnswer( $correct );
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

		return self::readableTable( $raw );
	}

	/**
	 * Ответ ученика в виде читаемой строки: табличный ответ (№25/№27) хранится
	 * ячейками через '|', «Развёрнутый ответ» — JSON с текстом и файлами (задания
	 * ОГЭ 13-16 обычно приложены файлом, а не текстом — тогда `text` пуст, и
	 * показываем название вложения вместо пустой ячейки), составное задание —
	 * JSON с ответом на каждый подпункт (берём подпункт под номер задания, как
	 * и эталон).
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
				$text = isset( $decoded['text'] ) ? trim( (string) $decoded['text'] ) : '';
				if ( '' !== $text ) {
					return $text;
				}
				if ( ! empty( $decoded['files'] ) && is_array( $decoded['files'] ) ) {
					return $this->fileNames( $decoded['files'] );
				}
				if ( isset( $decoded[ $number ] ) ) {
					return trim( (string) $decoded[ $number ] );
				}
			}
		}

		return self::readableTable( $raw );
	}

	/**
	 * Названия вложений «Развёрнутого ответа» через запятую — вместо пустой
	 * ячейки для заданий ОГЭ 13-16, где ответ обычно приложен файлом, а не
	 * набран текстом (см. {@see \Inc\Services\Course\WorkDetailService::parseFileAnswer()},
	 * тот же формат резолва имени, но без URL — лист ответов текстовый).
	 *
	 * @param array<int, mixed> $attachmentIds
	 */
	private function fileNames( array $attachmentIds ): string {
		$names = array();
		foreach ( $attachmentIds as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			if ( $attachmentId > 0 ) {
				$names[] = get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}";
			}
		}

		return implode( ', ', $names );
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
	 * Публичный статический метод — переиспользуется
	 * {@see \Inc\Modules\EgeComputer\EgeComputerModule::resolveTableAnswer()}
	 * для того же самого сериализованного вида ответа на экране «Работы»
	 * учителя ({@see \Inc\Services\Course\WorkDetailService}), которая читает
	 * ответ из той же таблицы БД, но не знает о табличных заданиях сама
	 * (ядро не знает о модулях).
	 *
	 * @param string $raw Ответ или эталон одной строкой
	 */
	public static function readableTable( string $raw ): string {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines      = array_map(
			static fn( string $line ): string => trim( str_replace( '|', ' ', $line ) ),
			explode( "\n", $normalized )
		);

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Задания одного типа — одна единица зачёта ({@see ScoringUnits}): верны все — полный
	 * балл типа, ошибка в любом — 0, максимум работы от повторов не растёт (20 заданий
	 * №1 весят как одно). Балл единицы стоит в первой её строке, у остальных «—»: сумма
	 * строк сходится с итогом. Одиночные типы (и другие виды) не меняются.
	 *
	 * @param array<int, array{max: float, row: int}>                                                  $taskRef Строка и максимум каждого задания
	 * @param list<array{number: string, score: ?float, answer: string, correct: string, url: string}> $rows    Строки листа (балл правится на месте)
	 *
	 * @return float Максимум первичного балла работы
	 */
	private function applyScoringUnits( AssessmentDTO $assessment, array $taskRef, array &$rows ): float {
		if ( ! $assessment->kind->groupsEqualNumbers() ) {
			return (float) array_sum( array_column( $taskRef, 'max' ) );
		}

		$keys   = $this->units->keysFor( $assessment );
		$groups = array();
		foreach ( $taskRef as $taskId => $ref ) {
			$groups[ $keys[ $taskId ] ?? 't:' . $taskId ][] = $taskId;
		}

		$max = 0.0;
		foreach ( $groups as $taskIds ) {
			$unitMax = (float) max( array_map( static fn( int $id ): float => $taskRef[ $id ]['max'], $taskIds ) );
			$max    += $unitMax;

			if ( count( $taskIds ) < 2 ) {
				continue;
			}

			$fraction = 1.0;
			$scored   = false;
			foreach ( $taskIds as $id ) {
				$score    = $rows[ $taskRef[ $id ]['row'] ]['score'];
				$scored   = $scored || null !== $score;
				$fraction = min( $fraction, $taskRef[ $id ]['max'] > 0.0 ? min( 1.0, (float) ( $score ?? 0.0 ) / $taskRef[ $id ]['max'] ) : 0.0 );
			}

			// Без оценки (предпросмотр без ответов) баллы остаются пустыми.
			foreach ( $taskIds as $n => $id ) {
				$rows[ $taskRef[ $id ]['row'] ]['score'] = ( $scored && 0 === $n ) ? $unitMax * $fraction : null;
			}
		}

		return $max;
	}

	/**
	 * Подпись строки листа — номер задания как он записан (архивное №117 остаётся №117):
	 * терм таксономии, иначе номер, проставленный на самой работе, иначе место в работе.
	 */
	private function label( AssessmentDTO $assessment, array $taskViews, int $taskId, int $position ): string {
		$fromTaxonomy = (int) ( $taskViews[ $taskId ]['taskNumber'] ?? 0 );
		if ( $fromTaxonomy > 0 ) {
			return (string) $fromTaxonomy;
		}

		$fromAssessment = trim( (string) ( $assessment->taskNumbers[ $taskId ] ?? '' ) );

		return '' !== $fromAssessment ? $fromAssessment : (string) ( $position + 1 );
	}

	/**
	 * Ссылка на задание, если оно публичное — из предметного банка (trainer), а не из
	 * закрытой базы: у такого задания есть адрес на сайте. Черновик и чужая запись — ''.
	 */
	private function publicUrl( int $taskId ): string {
		$post = $this->posts->get( $taskId );
		if ( ! $post || 'publish' !== $post->post_status || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return '';
		}

		return (string) get_permalink( $post );
	}

	/**
	 * Номер задания для расчётов (форма ответа, число позиций): терм таксономии
	 * {key}_task_number в «живом» виде (архивное №117 → 17), иначе номер, проставленный
	 * на самой работе, иначе позиция в работе.
	 *
	 * @param AssessmentDTO $assessment Контрольная
	 * @param array         $taskViews  Per-task view-данные страницы
	 * @param int           $taskId     Задание
	 * @param int           $position   Позиция задания в работе (с нуля)
	 */
	private function number( AssessmentDTO $assessment, array $taskViews, int $taskId, int $position ): string {
		// Номер для расчётов (форма ответа, число позиций, подпункт составного
		// блока): архивное №117 считается как №17, см. {@see ArchiveTaskNumber}.
		$fromTaxonomy = (int) ( $taskViews[ $taskId ]['baseNumber'] ?? $taskViews[ $taskId ]['taskNumber'] ?? 0 );
		if ( $fromTaxonomy > 0 ) {
			return (string) $fromTaxonomy;
		}

		$fromAssessment = $this->archive->baseOf( (string) ( $assessment->taskNumbers[ $taskId ] ?? '' ) );

		return '' !== $fromAssessment ? $fromAssessment : (string) ( $position + 1 );
	}
}
