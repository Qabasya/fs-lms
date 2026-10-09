<?php
/**
 * Страница входа гостя на экзамен (этап 11b.1.6): имя, проведение, окно старта и одна кнопка по моменту.
 *
 * Шорткод `[fs_lms_exam_entry]` внутри страницы темы. Чекбокса подтверждения данных и восстановления доступа нет:
 * потерял ссылку — сотрудник перевыпускает. Открытие страницы таймер не запускает.
 *
 * @package FS LMS
 *
 * @var string               $state       before | open | in_progress | submitted | expired
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
?>

<main class="fs-lms-join-page fs-exam-entry" id="fs-exam-entry" data-state="<?php echo esc_attr( $state ); ?>">
	<div class="fs-join-card">
		<h2 class="fs-join-card__title"><?php echo esc_html( '' !== $name ? $name : __( 'Участник', 'fs-lms' ) ); ?></h2>
		<p class="fs-join-card__subtitle"><?php echo esc_html( $event_title ); ?></p>
		<p class="fs-exam-entry__when"><?php echo esc_html( sprintf( '%s · начать можно с %s до %s', $when, $window_from, $window_to ) ); ?></p>

		<?php if ( 'before' === $state ) : ?>
			<button type="button" class="fs-join-btn" disabled><?php esc_html_e( 'Приступить', 'fs-lms' ); ?></button>
			<p class="fs-exam-entry__hint"><?php echo esc_html( sprintf( 'Вход откроется в %s.', $window_from ) ); ?></p>
		<?php elseif ( 'open' === $state ) : ?>
			<a class="fs-join-btn" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Приступить', 'fs-lms' ); ?></a>
		<?php elseif ( 'in_progress' === $state ) : ?>
			<a class="fs-join-btn" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Продолжить', 'fs-lms' ); ?></a>
			<?php if ( '' !== $deadline ) : ?>
				<p class="fs-exam-entry__hint"><?php echo esc_html( sprintf( 'Завершение в %s.', $deadline ) ); ?></p>
			<?php endif; ?>
		<?php elseif ( 'submitted' === $state ) : ?>
			<a class="fs-join-btn" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Посмотреть результат', 'fs-lms' ); ?></a>
			<p class="fs-exam-entry__hint"><button type="button" class="fs-join-btn" data-exam-end-session><?php esc_html_e( 'Завершить сеанс', 'fs-lms' ); ?></button></p>
		<?php else : ?>
			<p class="fs-exam-entry__state" role="status">
				<?php esc_html_e( 'Время начала истекло. Обратитесь к сотруднику.', 'fs-lms' ); ?>
				<?php if ( '' !== $phone ) : ?>
					<?php echo esc_html( $phone ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
</main>
