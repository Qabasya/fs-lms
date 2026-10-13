<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Lead;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\LeadRepository;
use Inc\Services\Lead\LeadService;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LeadCallbacks
 *
 * Заявки с лид-форм сайта: приём от темы (хук) и удаление из таблицы
 * «Пользователи → Заявки с сайта».
 *
 * @package Inc\Callbacks\Lead
 */
class LeadCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly LeadService    $service,
		private readonly LeadRepository $leads,
	) {
		parent::__construct();
	}

	/**
	 * Фильтр `fs_lms_theme_lead_submitted`. Сбой записи не должен ломать ответ формы
	 * посетителю — ошибку только журналируем и отвечаем «не сохранено».
	 *
	 * @param mixed $stored Ответ предыдущих подписчиков.
	 * @param mixed $lead   Заявка из темы.
	 *
	 * @return bool Заявка сохранена в таблице.
	 */
	public function onThemeLead( mixed $stored, mixed $lead = null ): bool {
		if ( ! is_array( $lead ) ) {
			return (bool) $stored;
		}

		try {
			return $this->service->record( $lead ) > 0 || (bool) $stored;
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'Leads', $e, array(), true );

			return (bool) $stored;
		}
	}

	/**
	 * AJAX: удаление выбранных заявок. Params: ids[].
	 */
	public function ajaxDeleteLeads(): void {
		$this->authorize( Nonce::DeleteLeads, Capability::ManageLmsPlatform );

		$deleted = $this->leads->deleteByIds( $this->sanitizeIntList( 'ids' ) );

		PluginLogger::warning( 'Leads', 'Заявки с сайта удалены вручную', array( 'deleted' => $deleted ) );

		$this->success( array( 'deleted' => $deleted ) );
	}

	/**
	 * AJAX: удаление всех отклонённых заявок.
	 */
	public function ajaxDeleteRejectedLeads(): void {
		$this->authorize( Nonce::DeleteLeads, Capability::ManageLmsPlatform );

		$deleted = $this->leads->deleteRejected();

		PluginLogger::warning( 'Leads', 'Все отклонённые заявки с сайта удалены', array( 'deleted' => $deleted ) );

		$this->success( array( 'deleted' => $deleted ) );
	}
}
