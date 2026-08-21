<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Controllers\Problems\ProblemsController;
use Inc\Core\Assets\BundleLoader;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks;
use Inc\Modules\EgeComputer\Config\EgeComputerConfig;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\Config\OgeCriteriaConfig;
use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use Inc\Modules\EgeComputer\Config\StationExamConfig;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;
use Inc\Services\Assessment\AttemptRevealPolicy;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Course\WorkDetailService;

/**
 * Class EgeComputerModule
 *
 * Опциональный модуль — плеер ЕГЭ (Компьютер).
 * Ядро о модуле не знает: связь только через фильтр fs_lms_assessment_renderer (T7.19).
 *
 * Выключение:
 *  1) константа FS_LMS_EGE_COMPUTER = false в wp-config.php;
 *  2) удаление каталога `inc/Modules/EgeComputer/` + строки в Init::getServices().
 *
 * @package Inc\Modules\EgeComputer
 */
class EgeComputerModule implements ServiceInterface {

	/**
	 * WP filter: лист ответов экрана завершения станции (kege/finish.php).
	 * Шаблону сервис с репозиториями напрямую недоступен, поэтому данные модуль
	 * отдаёт фильтром — тем же способом, каким публикует ядру свой рендерер:
	 *   apply_filters( self::SHEET_FILTER, null, $assessment, $lastAttempt, $taskViews )
	 */
	public const SHEET_FILTER = 'fs_lms_kege_result_sheet';

	public function __construct(
		private readonly EgeComputerConfig      $config,
		private readonly KegeResultSheetService $resultSheet,
		private readonly PreviewResultCallbacks $previewResult,
		private readonly AttemptRevealPolicy    $revealPolicy,
	) {}

	public function register(): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		add_filter( AssessmentPageController::RENDERER_FILTER, [ $this, 'resolveRenderer' ], 10, 3 );
		add_filter( self::SHEET_FILTER, [ $this, 'buildResultSheet' ], 10, 4 );
		add_filter( AssessmentManager::STATION_SETTINGS_FILTER, [ $this, 'applyStationSettings' ] );
		add_filter( WorkDetailService::OGE_RUBRIC_FILTER, [ $this, 'resolveOgeRubric' ], 10, 3 );
		add_filter( WorkDetailService::TABLE_ANSWER_FILTER, [ $this, 'resolveTableAnswer' ], 10, 3 );
		add_filter( EgeCompletenessChecker::EXTRA_POSITIONS_FILTER, [ $this, 'resolveExtraPositions' ], 10, 3 );
		add_filter( ProblemsController::NUMBER_OPTIONS_FILTER, [ $this, 'appendOgeManualPositions' ], 10, 2 );

		// Лист ответов предпросмотра (T15.10-preview): попытки в БД нет, поэтому
		// накопленные в JS ответы приходят на этот эндпоинт напрямую — см. PreviewResultCallbacks.
		add_action( 'wp_ajax_' . PreviewResultCallbacks::ACTION, [ $this->previewResult, 'ajaxPreviewResult' ] );
		// Публикуем имя экшена ядру (BundleLoader::enqueueKege): свой AJAX-экшен
		// живёт вне core AjaxHook, поэтому связь — фильтром, а не импортом класса
		// модуля в core-слой (см. CLAUDE.md, «модуль публикует ядру фильтрами»).
		add_filter( BundleLoader::KEGE_PREVIEW_RESULT_FILTER, static fn(): string => PreviewResultCallbacks::ACTION );
	}

	/**
	 * Лист ответов завершённой попытки.
	 *
	 * @param mixed           $sheet      Значение по умолчанию фильтра (не используется)
	 * @param AssessmentDTO   $assessment Контрольная
	 * @param AttemptDTO|null $attempt    Последняя сданная попытка; null — предпросмотр автора
	 * @param array           $taskViews  Per-task view-данные страницы
	 */
	public function buildResultSheet( mixed $sheet, AssessmentDTO $assessment, ?AttemptDTO $attempt, array $taskViews ): KegeSheetDTO {
		// D18: предпросмотр автора ($attempt === null) не гейтится — ответы видит
		// сам автор, подтверждать нечего и не перед кем.
		$revealed = null === $attempt || $this->revealPolicy->isRevealed( $assessment, $attempt );

		return $this->resultSheet->build( $assessment, $attempt, $taskViews, $revealed );
	}

	/**
	 * Подменяет время/попытки/проходной балл/шкалу/баллы за задание станции
	 * значениями конфига — для остальных `kind` DTO не трогается. `AssessmentDTO` —
	 * readonly, поэтому override только через новую копию.
	 */
	public function applyStationSettings( AssessmentDTO $dto ): AssessmentDTO {
		$settings = StationExamConfig::for( $dto->kind );
		if ( null === $settings ) {
			return $dto;
		}

		return new AssessmentDTO(
			id            : $dto->id,
			subjectKey    : $dto->subjectKey,
			title         : $dto->title,
			taskIds       : $dto->taskIds,
			timeLimit     : $settings['timeLimit'],
			attemptsAllowed: $settings['maxAttempts'],
			passScore     : $settings['passScore'],
			scoringPolicy : $dto->scoringPolicy,
			status        : $dto->status,
			kind          : $dto->kind,
			taskPoints    : $this->computeTaskPoints( $dto ),
			scoreMap      : $settings['scoreMap'],
			taskNumbers   : $dto->taskNumbers,
			introHtml     : $dto->introHtml,
		);
	}

	/**
	 * Баллы за задание (§3.5, .docs/Tasks.md) — фиксированная таблица «номер →
	 * баллы» вместо per-assessment `task_points` из меты (builder UI больше не
	 * даёт её редактировать, см. assessment-builder.js). Позиция задания
	 * резолвится так же, как {@see \Inc\Services\Assessment\EgeCompletenessChecker}:
	 * терм таксономии — для предметных заданий, ручной номер (`taskNumbers`,
	 * Задача 8) — фолбэк для банковских и обязательное поле для ОГЭ 13-16
	 * (единый путь для всех четырёх позиций ручной проверки, см. докблок
	 * {@see OgeCriteriaConfig}).
	 *
	 * @return array<int, float>
	 */
	private function computeTaskPoints( AssessmentDTO $dto ): array {
		$taxonomy = $dto->subjectKey . '_task_number';
		$points   = array();

		foreach ( $dto->taskIds as $taskId ) {
			$taskId   = (int) $taskId;
			$position = $this->resolveTaskPosition( $taskId, $taxonomy, $dto->taskNumbers );
			if ( '' === $position ) {
				continue;
			}

			$points[ $taskId ] = (float) match ( $dto->kind ) {
				AssessmentKind::EgeComputer => KegeScaleConfig::answerSlots( (int) $position ),
				AssessmentKind::OgeComputer => OgeScaleConfig::pointsForPosition( $position ),
				default                     => 1,
			};
		}

		return $points;
	}

	/**
	 * Номер/позиция задания: терм таксономии (предметное задание) с фолбэком на
	 * ручной номер (банковское задание либо ОГЭ 13-16, где терм невозможен).
	 */
	private function resolveTaskPosition( int $taskId, string $taxonomy, array $taskNumbers ): string {
		$terms = wp_get_post_terms( $taskId, $taxonomy, array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return (string) $terms[0];
		}

		return (string) ( $taskNumbers[ $taskId ] ?? '' );
	}

	/**
	 * Рубрика ручной проверки для задания 13/14/15/16 «Компьютерного ОГЭ». Для
	 * позиции «13» (альтернатива 13.1/13.2, один пост —
	 * {@see \Inc\MetaBoxes\Templates\AlternativeConditionsTemplate}) рубрика
	 * содержит критерии ОБОИХ вариантов, проверяющий выбирает подходящий сам.
	 * Позиция берётся ИСКЛЮЧИТЕЛЬНО из ручного номера (`taskNumbers`, Задача 8) —
	 * не из таксономии `{key}_task_number`, см. докблок {@see OgeCriteriaConfig}.
	 *
	 * @param mixed         $default    Значение фильтра по умолчанию (не используется)
	 * @param AssessmentDTO $assessment Экзамен, к которому относится задание
	 * @param int           $taskId     ID задания
	 *
	 * @return array{max_points: int, html: string}|null
	 */
	public function resolveOgeRubric( mixed $default, AssessmentDTO $assessment, int $taskId ): ?array {
		if ( AssessmentKind::OgeComputer !== $assessment->kind ) {
			return $default;
		}

		$position = $assessment->taskNumbers[ $taskId ] ?? '';
		if ( '' === $position ) {
			return $default;
		}

		return OgeCriteriaConfig::rubricFor( $position ) ?? $default;
	}

	/**
	 * Позиции ОГЭ №13-16 (ручная проверка) — они не имеют терма таксономии
	 * `{key}_task_number` по замыслу (см. докблок {@see OgeCriteriaConfig}), поэтому
	 * `EgeCompletenessChecker` не увидел бы их без этого фильтра и никогда не признал
	 * бы ОГЭ-работу укомплектованной. Для остальных `kind` список не трогаем.
	 *
	 * @param string[]      $positions  Список позиций по умолчанию (не используется)
	 * @param AssessmentDTO $assessment Экзамен, к которому относится проверка
	 * @param string        $subjectKey Ключ предмета (не используется — kind уже на DTO)
	 *
	 * @return string[]
	 */
	public function resolveExtraPositions( array $positions, AssessmentDTO $assessment, string $subjectKey = '' ): array {
		if ( AssessmentKind::OgeComputer !== $assessment->kind ) {
			return $positions;
		}

		return array_merge( $positions, OgeCriteriaConfig::positions() );
	}

	/**
	 * Табличный ответ станции (№17/18/20/25/26/27, {@see KegeScaleConfig::TABLE_TASK_NUMBERS})
	 * на экране «Работы» учителя: `answer_text` там хранится сериализованным
	 * (`|` между столбцами, `\n` между строками — {@see WorkDetailService::TABLE_ANSWER_FILTER}),
	 * приводим к тому же читаемому виду, что и лист ответов станции
	 * ({@see KegeResultSheetService::readableTable()}). ОГЭ такой формы ответа
	 * не имеет вовсе (см. exam.php `$isTable`), поэтому не трогаем.
	 *
	 * @param string        $answerText Сырой ответ ученика
	 * @param AssessmentDTO $assessment Экзамен, к которому относится задание
	 * @param int           $taskId     ID задания
	 */
	public function resolveTableAnswer( string $answerText, AssessmentDTO $assessment, int $taskId ): string {
		if ( '' === $answerText || AssessmentKind::EgeComputer !== $assessment->kind ) {
			return $answerText;
		}

		$taxonomy = $assessment->subjectKey . '_task_number';
		$position = $this->resolveTaskPosition( $taskId, $taxonomy, $assessment->taskNumbers );
		if ( '' === $position || ! in_array( (int) $position, KegeScaleConfig::TABLE_TASK_NUMBERS, true ) ) {
			return $answerText;
		}

		return KegeResultSheetService::readableTable( $answerText );
	}

	/**
	 * WP filter: те же позиции ОГЭ №13-16 — но для списка номеров в метабоксе
	 * «Предмет и номер задания» банковской задачи ({@see \Inc\Controllers\Problems\ProblemsController}),
	 * где нет контекста конкретной контрольной (только предмет), поэтому
	 * добавляются безусловно — автор сам решает, нужен ли этот номер его предмету.
	 *
	 * @param string[] $numbers
	 *
	 * @return string[]
	 */
	public function appendOgeManualPositions( array $numbers, string $subjectKey = '' ): array {
		return array_merge( $numbers, OgeCriteriaConfig::positions() );
	}

	/** @param string $default Путь к дефолтному шаблону */
	public function resolveRenderer( string $default, string $kind, string $subjectKey ): string {
		if ( $kind !== AssessmentKind::EgeComputer->value && $kind !== AssessmentKind::OgeComputer->value ) {
			return $default;
		}

		$template = plugin_dir_path( __FILE__ )
			. '../../..' // → plugins/fs-lms
			. '/templates/frontend/assessment/ege-computer.php';

		$resolved = realpath( $template );
		if ( ! $resolved ) {
			return $default;
		}

		// Своя станция КЕГЭ рендерится как bare-документ (собственная шапка/
		// таймер/сайдбар — не совпадает с générique-шеллом Эпика 15), см.
		// AssessmentPageController::KEGE_ROUTE_FILTER + Enqueue::enqueue_kege_assets().
		add_filter( AssessmentPageController::KEGE_ROUTE_FILTER, '__return_true' );

		return $resolved;
	}
}
