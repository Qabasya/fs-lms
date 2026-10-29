<?php
/**
 * Окно заморозки вместо стандартного текста замка плеера. Шов `fs_lms_player_lock_html`.
 * Для поданной заявки — «Заявка принята», для остальных — «Доступ закрыт» с кнопками «Связаться» и «Заполнить заявку».
 *
 * @var \Inc\Modules\Masterclass\DTO\MasterclassViewDTO $view Вид плеера
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

use Inc\Modules\Masterclass\Enums\MasterclassAccountState;

defined( 'ABSPATH' ) || exit;

$mk_applied = MasterclassAccountState::Applied === $view->state;
?>
<div class="lock-title" id="fsLockTitle">
	<?php echo esc_html( $mk_applied ? 'Заявка принята' : 'Доступ к мастер-классу закрыт' ); ?>
</div>
<p class="lock-sub">
	<?php echo esc_html( $mk_applied ? 'С вами свяжутся, чтобы договориться о занятиях.' : 'Чтобы продолжить заниматься, свяжитесь с центром или заполните заявку.' ); ?>
</p>
<div class="lock-actions">
	<button type="button" class="b b-pri" data-mk-open>Связаться</button>
	<?php if ( ! $mk_applied ) : ?>
		<a class="b" href="<?php echo esc_url( $view->applyUrl ); ?>" data-mk-kind="apply">Заполнить заявку</a>
	<?php endif; ?>
	<a class="b b-gh" href="<?php echo esc_url( $view->logoutUrl ); ?>">Выйти</a>
</div>
