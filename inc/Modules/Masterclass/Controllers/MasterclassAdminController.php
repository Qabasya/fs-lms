<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Menu;
use Inc\Managers\Wp\MenuManager;

/**
 * Class MasterclassAdminController
 *
 * Страница модуля «FS LMS → Мастер-классы». Регистрируется только при включённом модуле.
 * Каркас этапа 1: пункт меню и заголовок; список, форма, журнал и их AJAX — этапы 2 и далее.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassAdminController extends BaseController {

	/** Слаг страницы (`?page=`). */
	public const PAGE_SLUG = 'fs_lms_masterclasses';

	public function __construct(
		private readonly MenuManager $menu,
	) {
		parent::__construct();
	}

	public function register(): void {
		$this->menu->register(
			array(),
			array(
				array(
					'parent_slug' => Menu::Main->value,
					'page_title'  => 'Мастер-классы',
					'menu_title'  => 'Мастер-классы',
					'capability'  => Capability::ManageLmsPlatform->value,
					'menu_slug'   => self::PAGE_SLUG,
					'callback'    => array( $this, 'renderPage' ),
				),
			)
		);
	}

	public function renderPage(): void {
		require $this->path( 'inc/Modules/Masterclass/templates/admin-page.php' );
	}
}
