<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Menu;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Callbacks\AdAccountCallbacks;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdAccountController
 *
 * Кнопка «Создать учётку» в окне заявки (таб «Заявки»): учётку в домене заявке, поданной
 * не из доверенной сети, создаёт сотрудник. Кнопку модуль вписывает в подвал окна через
 * generic-сейм ядра `fs_lms_application_modal_actions`; поведение — собственный admin-JS
 * (`assets/applications.js`), ядро о кнопке не знает.
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdAccountController extends BaseController {

	use Sanitizer;

	/** Собственные имена AJAX-действий (вне core AjaxHook — изоляция). */
	public const STATE_ACTION     = 'fs_lms_ad_account_state';
	public const PROVISION_ACTION = 'fs_lms_ad_account_provision';

	private const SCRIPT = 'inc/Modules/AdSync/assets/applications.js';

	public function __construct(
		private readonly AdAccountCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::STATE_ACTION, array( $this->callbacks, 'ajaxState' ) );
		add_action( 'wp_ajax_' . self::PROVISION_ACTION, array( $this->callbacks, 'ajaxProvision' ) );
		add_action( 'fs_lms_application_modal_actions', array( $this, 'renderButton' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/** Кнопка в подвале окна заявки; показывает её JS — по состоянию учётки открытой заявки. */
	public function renderButton(): void {
		require $this->path( 'inc/Modules/AdSync/templates/application-modal-button.php' );
	}

	public function enqueueAssets(): void {
		if ( Menu::UserList->value !== $this->sanitizeGetKey( 'page' ) ) {
			return;
		}

		$path = $this->path( self::SCRIPT );
		wp_enqueue_script(
			'fs-lms-ad-account',
			$this->url( self::SCRIPT ),
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
		wp_localize_script( 'fs-lms-ad-account', 'fsLmsAdAccount', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'actions' => array(
				'state'     => self::STATE_ACTION,
				'provision' => self::PROVISION_ACTION,
			),
			'nonce'   => Nonce::Enroll->create(),
		) );
	}
}
