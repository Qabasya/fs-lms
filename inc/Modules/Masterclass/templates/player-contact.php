<?php
/**
 * Окно «Связаться» (скрыто, открывает `assets/player.js`). Шов `fs_lms_player_footer`.
 * Телефон и мессенджер показываются, только если заданы; клики уходят в журнал через `data-ajax` / `data-action` / `data-nonce`.
 *
 * @var \Inc\Modules\Masterclass\DTO\MasterclassViewDTO $view   Вид плеера
 * @var string                                          $ajax   Адрес admin-ajax.php
 * @var string                                          $action Имя AJAX-экшена
 * @var string                                          $nonce  Nonce `Nonce::LearnerActivity`
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

use Inc\Enums\Ui\Icon;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;

defined( 'ABSPATH' ) || exit;

$mk_applied = MasterclassAccountState::Applied === $view->state;
?>
<div class="cp-dim" data-mk-contact data-ajax="<?php echo esc_url( $ajax ); ?>" data-action="<?php echo esc_attr( $action ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" hidden>
	<div class="cp-modal" role="dialog" aria-modal="true" aria-labelledby="fsMkContactTitle">
		<div class="mi"><?php echo Icon::Phone->svg( 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<h3 id="fsMkContactTitle">Связаться с центром</h3>
		<p>Позвоните или напишите — расскажем про занятия и запишем в группу.</p>
		<div class="macts">
			<?php if ( '' !== $view->phone ) : ?>
				<a class="b b-pri" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $view->phone ) ); ?>" data-mk-kind="phone">
					<?php echo Icon::Phone->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $view->phone ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( '' !== $view->messengerUrl ) : ?>
				<a class="b" href="<?php echo esc_url( $view->messengerUrl ); ?>" target="_blank" rel="noopener noreferrer" data-mk-kind="messenger">
					<?php echo Icon::Message->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span>Написать в мессенджер</span>
				</a>
			<?php endif; ?>
			<?php if ( ! $mk_applied ) : ?>
				<a class="b" href="<?php echo esc_url( $view->applyUrl ); ?>" data-mk-kind="apply">Заполнить заявку</a>
			<?php endif; ?>
			<button type="button" class="b b-gh" data-mk-close>Закрыть</button>
		</div>
	</div>
</div>
