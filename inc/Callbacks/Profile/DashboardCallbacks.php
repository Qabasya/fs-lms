<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Profile\DashboardService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;

/**
 * AJAX «Главной» кабинета (Эпик 6): кросс-групповой агрегат текущего пользователя.
 *
 * @package Inc\Callbacks\Profile
 */
class DashboardCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;

	public function __construct(
		private readonly DashboardService $service,
	) {
		parent::__construct();
	}

	public function ajaxGetProfileDashboard(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );

		$userId   = get_current_user_id();
		$isOffice = user_can( $userId, Capability::ManageLmsPlatform->value );

		$this->success( $this->service->build( $userId, $isOffice ) );
	}
}
