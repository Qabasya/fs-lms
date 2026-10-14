<?php
/**
 * Страница входа гостя на экзамен (этап 11b.1.6): имя, проведение, окно старта и одна кнопка по моменту.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

/**
 * Страница входа гостя на экзамен (этап 11b.1.6): имя, проведение, окно старта и одна кнопка по моменту.
 *
 * Шорткод `[fs_lms_exam_entry]` внутри страницы темы. Чекбокса подтверждения данных и восстановления доступа нет:
 * потерял ссылку — сотрудник перевыпускает. Открытие страницы таймер не запускает.
 *
 * @package FS LMS
 *
 * @var string               $state       before | open | in_progress | submitted | expired
 * @var string               $phase       far | soon | running | ended (момент старта: тексты оповещений)
 * @var int                  $starts_in   Секунд до начала сеанса на момент ответа
 * @var int                  $ends_in     Секунд до конца окна сеанса на момент ответа
 * @var int                  $soon_seconds Порог «экзамен скоро начнётся», секунд
 * @var string               $name
 * @var string               $event_title
 * @var string               $date        Y-m-d
 * @var string               $window_from H:i
 * @var string               $window_to   H:i
 * @var string               $deadline    H:i (личный дедлайн идущей попытки)
 * @var string               $action_url
 * @var array<string, string> $contacts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$phone = (string) ( $contacts['phone'] ?? '' );
$when  = '' !== $date ? date_i18n( 'j F Y', (int) strtotime( $date ) ) : '';

// Оповещения по моменту старта: до начала текст меняется на клиенте (data-text-*), остальные фазы — перезагрузкой страницы.
$phase_texts = array(
	'far'     => sprintf( 'Экзамен начнётся %s, %s', $when, $window_from ),
	'soon'    => __( 'Экзамен скоро начнётся', 'fs-lms' ),
	'running' => __( 'Экзамен уже идёт. Срочно приступайте к работе!', 'fs-lms' ),
	'ended'   => '' !== $phone
		? sprintf( 'Экзамен завершён. Приступить к работе невозможно. Свяжитесь с администратором: %s', $phone )
		: __( 'Экзамен завершён. Приступить к работе невозможно. Свяжитесь с администратором', 'fs-lms' ),
);
?>

<main class="fs-lms-join-page fs-exam-entry" id="fs-exam-entry" data-state="<?php echo esc_attr( $state ); ?>"
	data-phase="<?php echo esc_attr( $phase ); ?>" data-starts-in="<?php echo esc_attr( (string) $starts_in ); ?>"
	data-ends-in="<?php echo esc_attr( (string) $ends_in ); ?>" data-soon-seconds="<?php echo esc_attr( (string) $soon_seconds ); ?>"
	data-text-far="<?php echo esc_attr( $phase_texts['far'] ); ?>" data-text-soon="<?php echo esc_attr( $phase_texts['soon'] ); ?>">
	<div class="fs-join-card">
		<h2 class="fs-join-card__title"><?php echo esc_html( '' !== $name ? $name : __( 'Участник', 'fs-lms' ) ); ?></h2>
		<p class="fs-join-card__subtitle"><?php echo esc_html( $event_title ); ?></p>
		<p class="fs-exam-entry__when"><?php echo esc_html( sprintf( '%s · начать можно с %s до %s', $when, $window_from, $window_to ) ); ?></p>

		<?php if ( 'before' === $state ) : ?>
			<p class="fs-exam-entry__state" role="status" data-entry-phase><?php echo esc_html( $phase_texts['soon' === $phase ? 'soon' : 'far'] ); ?></p>
			<button type="button" class="fs-join-btn" disabled><?php esc_html_e( 'Приступить', 'fs-lms' ); ?></button>
		<?php elseif ( 'open' === $state ) : ?>
			<p class="fs-exam-entry__state" role="status"><?php echo esc_html( $phase_texts['running'] ); ?></p>
			<a class="fs-join-btn" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Приступить', 'fs-lms' ); ?></a>
		<?php elseif ( 'in_progress' === $state ) : ?>
			<a class="fs-join-btn" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Продолжить', 'fs-lms' ); ?></a>
			<?php if ( '' !== $deadline ) : ?>
				<p class="fs-exam-entry__hint"><?php echo esc_html( sprintf( 'Завершение в %s.', $deadline ) ); ?></p>
			<?php endif; ?>
		<?php elseif ( 'submitted' === $state ) : ?>
			<a class="fs-join-btn" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Посмотреть результат', 'fs-lms' ); ?></a>
			<p class="fs-exam-entry__hint"><button type="button" class="fs-join-btn" data-exam-end-session><?php esc_html_e( 'Завершить сеанс', 'fs-lms' ); ?></button></p>
		<?php elseif ( 'ended' === $phase ) : ?>
			<p class="fs-exam-entry__state" role="status"><?php echo esc_html( $phase_texts['ended'] ); ?></p>
		<?php else : ?>
			<p class="fs-exam-entry__state" role="status">
				<?php esc_html_e( 'Сеанс отменён. Обратитесь к сотруднику.', 'fs-lms' ); ?>
				<?php if ( '' !== $phone ) : ?>
					<?php echo esc_html( $phone ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
</main>
