<?php
/**
 * Урок недоступен ученику (гейт: дата/видимость/предусловие). T1.5.12.
 *
 * #3b (D-4): если урок заблокирован по дате и до начала занятия ≤ часа —
 * показываем «Занятие скоро начнётся» + обратный отсчёт (lesson-countdown.js),
 * страница сама перезагрузится в момент старта. Если больше часа — «Урок ещё
 * не доступен» + дата открытия. Иначе (экзамен-блок/предусловие/без даты) —
 * общий текст.
 *
 * @var int    $groupId Идентификатор группы (для ссылки на кокпит).
 * @var object $row     GroupLessonDTO текущего занятия ($row->scheduledAt).
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cockpit_url = add_query_arg( array( 'gid' => $groupId ), \Inc\Enums\Wp\PageRoutes::GroupCockpit->url() );

// Секунды до старта: обе метки в локальном времени WP (как WpClock::now()),
// поэтому разница strtotime корректна независимо от TZ сервера.
$locked_scheduled = $row->scheduledAt ?? null;
$locked_seconds   = ( null !== $locked_scheduled && '' !== $locked_scheduled )
	? strtotime( $locked_scheduled ) - strtotime( current_time( 'mysql' ) )
	: null;
$locked_soon = null !== $locked_seconds && $locked_seconds > 0 && $locked_seconds <= HOUR_IN_SECONDS;
?>
<div class="wrap fs-player fs-player--locked">
	<?php if ( $locked_soon ) : ?>
		<div class="fs-player__lock">
			<div class="fs-player__lock-title"><?php esc_html_e( 'Занятие скоро начнётся', 'fs-lms' ); ?></div>
			<p class="fs-player__lock-sub">
				<?php
				printf(
					/* translators: %s: время начала занятия (ЧЧ:ММ). */
					esc_html__( 'Начало в %s — страница обновится автоматически.', 'fs-lms' ),
					esc_html( mysql2date( 'H:i', $locked_scheduled ) )
				);
				?>
			</p>
			<div class="fs-player__countdown" data-lesson-countdown data-seconds="<?php echo esc_attr( (string) $locked_seconds ); ?>">
				<span data-countdown-value><?php echo esc_html( sprintf( '%02d:%02d', intdiv( $locked_seconds, 60 ), $locked_seconds % 60 ) ); ?></span>
			</div>
		</div>
	<?php elseif ( null !== $locked_seconds && $locked_seconds > 0 ) : ?>
		<div class="fs-player__lock">
			<div class="fs-player__lock-title"><?php esc_html_e( 'Урок ещё не доступен', 'fs-lms' ); ?></div>
			<p class="fs-player__lock-sub">
				<?php
				printf(
					/* translators: 1: дата открытия, 2: время начала (ЧЧ:ММ). */
					esc_html__( 'Откроется %1$s в %2$s.', 'fs-lms' ),
					esc_html( mysql2date( 'j F', $locked_scheduled ) ),
					esc_html( mysql2date( 'H:i', $locked_scheduled ) )
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Этот урок пока недоступен — он откроется по дате или после выполнения предыдущих шагов.', 'fs-lms' ); ?></p>
		</div>
	<?php endif; ?>

	<a class="button" href="<?php echo esc_url( $cockpit_url ); ?>">← <?php esc_html_e( 'К программе группы', 'fs-lms' ); ?></a>
</div>
