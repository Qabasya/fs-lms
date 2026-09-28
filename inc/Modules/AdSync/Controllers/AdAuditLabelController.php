<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Modules\AdSync\Enums\AdAuditAction;

/**
 * Class AdAuditLabelController
 *
 * Подписи действий модуля в журнале «Зачисления» (generic-фильтр ядра
 * `fs_lms_audit_action_label`). Регистрируется и при выключенном модуле —
 * записи прошлых синхронизаций должны читаться всегда.
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdAuditLabelController {

	public function register(): void {
		add_filter( 'fs_lms_audit_action_label', array( $this, 'label' ), 10, 2 );
	}

	public function label( string $label, string $action ): string {
		return AdAuditAction::tryFrom( $action )?->label() ?? $label;
	}
}
