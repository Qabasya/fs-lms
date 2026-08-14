<?php

declare( strict_types=1 );

namespace Inc\Core\Assets;

use Inc\Core\BaseController;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Profile\ProfileViewResolver;

/**
 * Class BundleLoader
 *
 * Общие примитивы подключения ассетов + изолированные SPA-бандлы
 * (profile / player / assessment / kege): шрифт интерфейса, Font Awesome,
 * MathJax, скелет style+script+localize.
 *
 * Выделен из Core\Enqueue (Т14.4). Правило «wp_localize_script — только в слое
 * Core/Assets» сохраняется: все вызовы живут здесь, в AdminAssets и FrontendAssets.
 *
 * @package Inc\Core\Assets
 */
class BundleLoader extends BaseController {

	public function __construct(
		private readonly ProfileViewResolver $profileResolver,
	) {
		parent::__construct();
	}

	/**
	 * Ранние подключения к CDN шрифтов для публичных страниц.
	 *
	 * @param string[] $hints    Текущие подсказки
	 * @param string   $relation Тип отношения (preconnect/dns-prefetch/…)
	 *
	 * @return string[]
	 */
	public function fontResourceHints( array $hints, string $relation ): array {
		if ( 'preconnect' === $relation && ! is_admin() ) {
			$hints[] = 'https://fonts.googleapis.com';
			$hints[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );
		}

		return $hints;
	}

	/**
	 * Шрифт интерфейса (Ubuntu, $font-ui) — один на все бандлы.
	 *
	 * Грузим стилем, а не CSS `@import`: последний блокирует рендер и не даёт
	 * воспользоваться preconnect (см. fontResourceHints()).
	 *
	 * @return void
	 */
	public function enqueueUiFont(): void {
		wp_enqueue_style(
			'fs-lms-ubuntu',
			'https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap',
			array(),
			null
		);
	}

	/**
	 * Font Awesome — иконки интерфейса (общий для admin- и frontend-базы; Т14.4:
	 * дедуп задублированного блока из Enqueue).
	 *
	 * @return void
	 */
	public function enqueueFontAwesome(): void {
		wp_enqueue_style(
			'fs-lms-fontawesome',
			'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css',
			array(),
			null
		);
	}

	/**
	 * Единый скелет подключения изолированного бандла: style + script + localize.
	 * Дедуп почти одинаковых блоков (profile/player/assessment/kege) — Р2.6.
	 * Хендлы фиксированы паттерном `fs-lms-{$slug}-style` / `fs-lms-{$slug}-script`.
	 *
	 * @param string               $slug    базовое имя файлов и хендлов (profile/player/…)
	 * @param string               $varName имя window-переменной для wp_localize_script
	 * @param array<string, mixed> $data    данные локализации
	 * @param string[]             $deps    зависимости скрипта (напр. ['jquery'])
	 *
	 * @return void
	 */
	public function enqueueBundle( string $slug, string $varName, array $data, array $deps = array() ): void {
		// Изолированные SPA (кабинет, плеер, контрольная, станция) рендерятся на
		// bare-шелле без общего фронт-стека — шрифт интерфейса подключаем здесь.
		$this->enqueueUiFont();

		wp_enqueue_style(
			"fs-lms-{$slug}-style",
			$this->url( "assets/css/{$slug}.min.css" ),
			array(),
			filemtime( $this->path( "assets/css/{$slug}.min.css" ) )
		);

		wp_enqueue_script(
			"fs-lms-{$slug}-script",
			$this->url( "assets/js/{$slug}.min.js" ),
			$deps,
			filemtime( $this->path( "assets/js/{$slug}.min.js" ) ),
			true
		);

		wp_localize_script( "fs-lms-{$slug}-script", $varName, $data );
	}

	/**
	 * MathJax v3 (tex-chtml) + инлайн-конфиг делимитеров \(…\)/\[…\]. Общий для
	 * плеера, контрольной и станции КЕГЭ (Р2.6).
	 *
	 * @return void
	 */
	public function enqueueMathJax(): void {
		wp_enqueue_script(
			'fs-lms-mathjax',
			'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js',
			array(),
			null,
			true
		);
		wp_add_inline_script(
			'fs-lms-mathjax',
			'window.MathJax = { tex: { inlineMath: [["\\\\(", "\\\\)"]], displayMath: [["\\\\[", "\\\\]"]] } };',
			'before'
		);
	}

	/**
	 * Подключение изолированного бандла личного кабинета (/profile/).
	 *
	 * Грузит только profile.min.css/js и локализует window.fsProfile
	 * (роль → состав кабинета + режим доступа) через ProfileViewResolver.
	 *
	 * @return void
	 */
	public function enqueueProfile(): void {
		$this->enqueueBundle( 'profile', 'fsProfile', $this->profileResolver->jsConfig( get_current_user_id() ) );
	}

	/**
	 * Подключение изолированного бандла плеера курса (Эпик 14, D18).
	 *
	 * Грузит только player.min.css/js + MathJax и локализует fs_lms_player_vars.
	 * Общий для двух маршрутов, оба взводят фильтр `fs_lms_is_player_route`
	 * перед рендером player.php: LessonPlayerController (маршрут плеера + ?gl=, ученик)
	 * и CoursePreviewController (/course-preview/?course=, Фаза 5 — предпросмотр
	 * для преподавателя/офиса/автора, без ученика и сохранения прогресса).
	 *
	 * @return void
	 */
	public function enqueuePlayer(): void {
		$this->enqueueBundle(
			'player',
			'fs_lms_player_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'actions'  => array(
					'markStep'          => AjaxHook::MarkStepProgress->jsAction(),
					'submitTask'        => AjaxHook::SubmitTaskAnswer->jsAction(),
					'submitBatchWork'   => AjaxHook::SubmitBatchWork->jsAction(),
					// #5: dry-run проверка в предпросмотре (гейт canSolvePreview в коллбеке).
					'previewCheckTask'       => AjaxHook::PreviewCheckTask->jsAction(),
					'previewCheckWork'       => AjaxHook::PreviewCheckWork->jsAction(),
					'previewCheckAssessment' => AjaxHook::PreviewCheckAssessment->jsAction(),
				),
				'nonces'   => array(
					'markStep'        => Nonce::MarkStepProgress->create(),
					'submitTask'      => Nonce::SubmitTaskAnswer->create(),
					'submitBatchWork' => Nonce::SubmitBatchWork->create(),
					'previewSolve'    => Nonce::PreviewSolve->create(),
				),
			)
		);

		$this->enqueueMathJax();
	}

	/**
	 * Переменные попытки контрольной/экзамена — ЕДИНСТВЕННЫЙ провайдер
	 * (Т14.4: дедуп enqueue_assessment_assets ↔ assessmentVars из Enqueue).
	 * Используется и bare-шелл-бандлом (enqueueAssessment), и старым
	 * frontend-стеком модульных скинов (FrontendAssets).
	 *
	 * @return array<string, mixed>
	 */
	public function assessmentVars(): array {
		return array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'actions'  => array(
				'startAttempt'      => AjaxHook::StartAttempt->jsAction(),
				'saveAttemptAnswer' => AjaxHook::SaveAttemptAnswer->jsAction(),
				'submitAttempt'     => AjaxHook::SubmitAttempt->jsAction(),
				'getAttemptResult'  => AjaxHook::GetAttemptResult->jsAction(),
				// Эпик 13 (D16): двухшаговая загрузка файла ответа («Развёрнутый ответ»).
				'uploadAnswerFile'  => AjaxHook::UploadAnswerFile->jsAction(),
			),
			'nonces'   => array(
				'startAttempt'     => Nonce::StartAttempt->create(),
				'submitAttempt'    => Nonce::SubmitAttempt->create(),
				'uploadAnswerFile' => Nonce::UploadAnswerFile->create(),
			),
		);
	}

	/**
	 * Подключение изолированного бандла страницы контрольной/экзамена (Эпик 15,
	 * T15.1/T15.2) — bare-шелл на токенах плеера, без темы сайта. Взводится
	 * только для дефолтного рендерера `AssessmentPageController` (см. ROUTE_FILTER);
	 * модульные скины (EgeComputer и т.п.) остаются на старом frontend-стеке —
	 * см. FrontendAssets::localizations().
	 *
	 * @return void
	 */
	public function enqueueAssessment(): void {
		$this->enqueueBundle( 'assessment', 'fs_lms_assessment_vars', $this->assessmentVars() );

		$this->enqueueMathJax();
	}

	/**
	 * WP filter: имя AJAX-экшена листа ответов предпросмотра КЕГЭ. Модульный
	 * `PreviewResultCallbacks::ACTION` живёт вне core `AjaxHook` (изоляция модуля —
	 * см. CLAUDE.md), поэтому публикуется ядру фильтром, а не импортом класса
	 * модуля сюда. Пустая строка — модуль выключен/удалён: кнопка «Завершить
	 * экзамен» в предпросмотре просто не сможет посчитать лист (JS это не ломает,
	 * запрос уйдёт на пустой action и получит -1 от admin-ajax.php).
	 */
	public const KEGE_PREVIEW_RESULT_FILTER = 'fs_lms_kege_preview_result_action';

	/**
	 * Подключение изолированного бандла станции КЕГЭ (T15.10) — bare-документ
	 * на токенах плеера, свой JS/CSS. Модуль EgeComputer (опциональный, см.
	 * inc/Modules/EgeComputer/) взводит fs_lms_is_kege_route в
	 * AssessmentPageController; ядро о модуле не знает, только читает фильтр.
	 *
	 * @return void
	 */
	public function enqueueKege(): void {
		$this->enqueueBundle(
			'kege',
			'fs_lms_kege_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'actions'  => array(
					'startAttempt'      => AjaxHook::StartAttempt->jsAction(),
					'saveAttemptAnswer' => AjaxHook::SaveAttemptAnswer->jsAction(),
					'submitAttempt'     => AjaxHook::SubmitAttempt->jsAction(),
					'getAttemptResult'  => AjaxHook::GetAttemptResult->jsAction(),
					'previewResult'     => (string) apply_filters( self::KEGE_PREVIEW_RESULT_FILTER, '' ),
				),
				'nonces'   => array(
					'startAttempt'  => Nonce::StartAttempt->create(),
					'submitAttempt' => Nonce::SubmitAttempt->create(),
				),
			)
		);

		$this->enqueueMathJax();
	}
}
