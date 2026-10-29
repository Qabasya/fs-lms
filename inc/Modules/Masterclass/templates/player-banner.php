<?php
/**
 * Баннер над уроком: после мастер-класса ребёнку предлагают записаться. Шов `fs_lms_player_before_content`.
 * После подачи заявки предложения нет — только сообщение, что она принята.
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
<div class="s-banner" role="region" aria-label="Запись на занятия">
	<div class="s-banner__text">
		<?php echo esc_html( $mk_applied ? 'Заявка принята — с вами свяжутся.' : $view->bannerText ); ?>
	</div>
	<div class="s-banner__actions">
		<button type="button" class="b b-pri b-sm" data-mk-open>Связаться</button>
		<?php if ( ! $mk_applied ) : ?>
			<a class="b b-sm" href="<?php echo esc_url( $view->applyUrl ); ?>" data-mk-kind="apply">Заполнить заявку</a>
		<?php endif; ?>
	</div>
</div>
