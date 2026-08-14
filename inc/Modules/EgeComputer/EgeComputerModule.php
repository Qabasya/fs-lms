<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Core\Assets\BundleLoader;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks;
use Inc\Modules\EgeComputer\Config\EgeComputerConfig;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;

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
	) {}

	public function register(): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		add_filter( AssessmentPageController::RENDERER_FILTER, [ $this, 'resolveRenderer' ], 10, 3 );
		add_filter( self::SHEET_FILTER, [ $this, 'buildResultSheet' ], 10, 4 );

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
		return $this->resultSheet->build( $assessment, $attempt, $taskViews );
	}

	/** @param string $default Путь к дефолтному шаблону */
	public function resolveRenderer( string $default, string $kind, string $subjectKey ): string {
		if ( $kind !== AssessmentKind::EgeComputer->value ) {
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
