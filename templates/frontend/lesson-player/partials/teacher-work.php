<?php
/**
 * Работа или контрольная в режиме преподавателя: условия задач и «Показать решение».
 *
 * Без полей ответа, чипов, баллов, прогресса и «Завершить работу» — преподаватель
 * показывает задачи классу и сверяется с эталоном, сдавать ему нечего. Общий для
 * work- и assessment-шагов (одинаковая вёрстка карточек).
 *
 * @var string $tw_type   Тип шага для бейджа: `work` | `assessment`.
 * @var string $tw_title  Название работы/контрольной.
 * @var string $tw_meta   Строка под названием в воркбаре.
 * @var array  $tw_tasks  Бандлы задач (title, condition_html, files, solution…).
 * @var string $back_url  Ссылка «К курсу» в воркбаре (может быть пустой).
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;
use Inc\Enums\Ui\Icon;

?>
<div class="work-static">
	<div class="a-workbar">
		<?php if ( ! empty( $back_url ) ) : ?>
			<a class="wb-back" href="<?php echo esc_url( $back_url ); ?>" title="<?php esc_attr_e( 'К курсу', 'fs-lms' ); ?>" aria-label="<?php esc_attr_e( 'К курсу', 'fs-lms' ); ?>">
				<?php echo Icon::Back->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
		<?php endif; ?>
		<span class="tbadge" data-step-type="<?php echo esc_attr( $tw_type ); ?>"><?php echo esc_html( StepType::fromValueOrDefault( $tw_type )->label() ); ?></span>
		<div class="wb-t">
			<b><?php echo esc_html( $tw_title ); ?></b>
			<?php if ( '' !== $tw_meta ) : ?>
				<span><?php echo esc_html( $tw_meta ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( array() === $tw_tasks ) : ?>
		<p class="step-muted"><?php esc_html_e( 'В работе нет задач.', 'fs-lms' ); ?></p>
	<?php else : ?>
		<div class="wstack">
			<?php foreach ( $tw_tasks as $tw_i => $tw_task ) : ?>
				<div class="a-task">
					<div class="th">
						<span class="tkn"><?php echo esc_html( (string) ( $tw_i + 1 ) ); ?></span>
						<b><?php echo esc_html( (string) $tw_task['title'] ); ?></b>
					</div>

					<?php
					$cond_task  = $tw_task;
					$cond_class = 'q wpc';
					include __DIR__ . '/teacher-condition.php';

					if ( ! empty( $tw_task['solution'] ) ) :
						$teacher_solution = $tw_task['solution'];
						include __DIR__ . '/teacher-solution.php';
					endif;
					?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
