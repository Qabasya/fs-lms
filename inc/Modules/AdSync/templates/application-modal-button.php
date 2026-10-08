<?php
/**
 * Кнопка «Создать учётку» в подвале окна заявки (хук `fs_lms_application_modal_actions`).
 * Скрыта, пока `assets/applications.js` не узнает состояние доменной учётки открытой заявки.
 *
 * @package Inc\Modules\AdSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<button type="button" class="button js-ad-account" hidden>
	<?php esc_html_e( 'Создать учётку', 'fs-lms' ); ?>
</button>
