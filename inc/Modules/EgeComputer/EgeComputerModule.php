<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Modules\EgeComputer\Config\EgeComputerConfig;

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

	public function __construct(
		private readonly EgeComputerConfig $config,
	) {}

	public function register(): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		add_filter( AssessmentPageController::RENDERER_FILTER, [ $this, 'resolveRenderer' ], 10, 3 );
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
