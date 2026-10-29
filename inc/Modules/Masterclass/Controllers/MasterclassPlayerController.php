<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Modules\Masterclass\Callbacks\MasterclassPlayerCallbacks;
use Inc\Modules\Masterclass\DTO\MasterclassViewDTO;
use Inc\Modules\Masterclass\Services\MasterclassAccessGuard;
use Inc\Modules\Masterclass\Services\MasterclassLoginTracker;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassPlayerController
 *
 * Плеер для временного ученика мастер-класса: подписки на швы Ш2 ядра (кнопка «Назад», окно заморозки, кнопки шапки,
 * баннер, окно «Связаться») и скрипт окна. У всех остальных — учителя, родителя, обычного ученика — швы возвращают
 * значение как пришло и ничего не выводят: вид строит {@see MasterclassAccessGuard::viewFor()}, а для чужого он `null`.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassPlayerController extends BaseController {

	public const CONTACT_ACTION = 'fs_lms_masterclass_contact';

	private const SCRIPT_HANDLE = 'fs-lms-masterclass-player';

	public function __construct(
		private readonly MasterclassAccessGuard $guard,
		private readonly MasterclassLoginTracker $tracker,
		private readonly MasterclassPlayerCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'onPlayerRoute' ), 20 );
		add_filter( 'fs_lms_player_back_url', array( $this, 'filterBackUrl' ), 10, 2 );
		add_filter( 'fs_lms_player_lock_html', array( $this, 'filterLockHtml' ), 10, 2 );
		add_action( 'fs_lms_player_header_actions', array( $this, 'renderActions' ), 10, 1 );
		add_action( 'fs_lms_player_before_content', array( $this, 'renderBanner' ), 10, 1 );
		add_action( 'fs_lms_player_footer', array( $this, 'renderContact' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueAssets' ), 20 );
		add_action( 'wp_ajax_' . self::CONTACT_ACTION, array( $this->callbacks, 'ajaxContact' ) );
	}

	/** Открыт плеер: после окна ребёнок «дома», даже если сессия жива с утра. Сбой учёта страницу не роняет. */
	public function onPlayerRoute(): void {
		if ( ! is_user_logged_in() || ! PageRoutes::LessonPlayer->isCurrent() ) {
			return;
		}

		try {
			$this->tracker->onPlayerOpen( get_current_user_id() );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassPlayer', $e, array( 'hook' => 'template_redirect' ), true );
		}
	}

	/** Ш2 `fs_lms_player_back_url`: у временного ученика нет кабинета, «Назад» некуда вести. */
	public function filterBackUrl( mixed $url, mixed $userId = 0 ): mixed {
		return null === $this->guard->viewFor( (int) $userId ) ? $url : '';
	}

	/** Ш2 `fs_lms_player_lock_html`: заморожен — окно модуля вместо стандартного замка. */
	public function filterLockHtml( mixed $html, mixed $userId = 0 ): mixed {
		$view = $this->guard->viewFor( (int) $userId );

		return null !== $view && $view->frozen ? $this->render( 'player-lock', array( 'view' => $view ) ) : $html;
	}

	/** Ш2 `fs_lms_player_header_actions`: «Связаться» и «Выйти». */
	public function renderActions( mixed $userId = 0 ): void {
		$view = $this->guard->viewFor( (int) $userId );
		if ( null !== $view ) {
			echo $this->render( 'player-actions', array( 'view' => $view ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Ш2 `fs_lms_player_before_content`: баннер «запишитесь» — не в заморозке и только после окна или «дома». */
	public function renderBanner( mixed $userId = 0 ): void {
		$view = $this->guard->viewFor( (int) $userId );
		if ( null !== $view && $view->showBanner ) {
			echo $this->render( 'player-banner', array( 'view' => $view ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Ш2 `fs_lms_player_footer`: скрытое окно «Связаться». */
	public function renderContact( mixed $userId = 0 ): void {
		$view = $this->guard->viewFor( (int) $userId );
		if ( null === $view ) {
			return;
		}

		echo $this->render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'player-contact',
			array(
				'view'   => $view,
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'action' => self::CONTACT_ACTION,
				'nonce'  => Nonce::LearnerActivity->create(),
			)
		);
	}

	/** Скрипт окна — только на маршруте плеера и только у живого аккаунта. */
	public function enqueueAssets(): void {
		if ( ! is_user_logged_in() || true !== apply_filters( 'fs_lms_is_player_route', false ) ) {
			return;
		}

		if ( null === $this->guard->viewFor( get_current_user_id() ) ) {
			return;
		}

		$rel  = 'inc/Modules/Masterclass/assets/player.js';
		$path = $this->path( $rel );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$this->url( $rel ),
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
	}

	/**
	 * Рендерит шаблон модуля в строку. Свои переменные в замыкании — пересечений с шаблоном темы и ядра нет.
	 *
	 * @param array{view: MasterclassViewDTO}&array<string, mixed> $vars
	 */
	private function render( string $template, array $vars ): string {
		$path = $this->path( 'inc/Modules/Masterclass/templates/' . $template . '.php' );

		ob_start();
		( static function () use ( $path, $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			require $path;
		} )();

		return (string) ob_get_clean();
	}
}
