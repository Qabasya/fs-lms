<?php
/**
 * Кнопки шапки плеера для временного ученика: «Связаться» и «Выйти». Шов `fs_lms_player_header_actions`.
 *
 * @var \Inc\Modules\Masterclass\DTO\MasterclassViewDTO $view Вид плеера
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

use Inc\Enums\Ui\Icon;

defined( 'ABSPATH' ) || exit;
?>
<button type="button" class="s-ibtn" data-mk-open title="Связаться" aria-label="Связаться">
	<?php echo Icon::Phone->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</button>
<a class="s-ibtn" href="<?php echo esc_url( $view->logoutUrl ); ?>" title="Выйти" aria-label="Выйти">
	<?php echo Icon::Logout->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</a>
