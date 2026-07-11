<?php
/**
 * Станция-навигатор попытки (обычный Ege, D16.7): одно задание на экран +
 * боковое меню номеров. Разметка заданий — тот же .fs-attempt-question, что и в
 * списке (общий партиал), поэтому autosave/файлы/сдача работают без изменений;
 * поведение «одно за раз» навешивает ege-navigator.js (прогрессивное улучшение —
 * без JS все задания просто показываются списком).
 *
 * Номер в меню — taskNumber из таксономии {key}_task_number (buildTaskViews),
 * при отсутствии терма — порядковый индекс.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var array<int, array{taskNumber: int}> $taskViews
 */
declare( strict_types=1 );
?>
<div class="fs-ege-nav">
	<aside class="fs-ege-nav__menu" aria-label="Номера заданий">
		<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
			<?php
			if ( ! get_post( $taskId ) ) {
				continue;
			}
			$subparts = is_array( $taskViews[ (int) $taskId ]['subparts'] ?? null ) ? $taskViews[ (int) $taskId ]['subparts'] : array();
			if ( ! empty( $subparts ) ) {
				// Задача 3: составное задание — диапазон номеров (19–21).
				$first = (int) $subparts[0]['number'];
				$last  = (int) $subparts[ count( $subparts ) - 1 ]['number'];
				$label = $first . '–' . $last;
			} else {
				$num   = (int) ( $taskViews[ (int) $taskId ]['taskNumber'] ?? 0 );
				$label = (string) ( $num > 0 ? $num : ( $i + 1 ) );
			}
			?>
			<button type="button" class="fs-ege-nav__num" data-nav-index="<?php echo esc_attr( (string) $i ); ?>">
				<?php echo esc_html( $label ); ?>
			</button>
		<?php endforeach; ?>
	</aside>

	<div class="fs-ege-nav__stage">
		<form class="fs-attempt-form" novalidate>
			<?php $fs_seq = 0; // Сквозная нумерация (Triple разворачивается на 3 позиции, задача 3). ?>
			<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
				<?php require __DIR__ . '/attempt-question.php'; ?>
			<?php endforeach; ?>

			<div class="fs-ege-nav__controls">
				<button type="button" class="fs-btn fs-btn--secondary" data-nav-prev>← Назад</button>
				<button type="button" class="fs-btn fs-btn--secondary" data-nav-next>Вперёд →</button>
				<button type="submit" class="fs-btn fs-btn--primary fs-submit-attempt-btn">
					Завершить
				</button>
			</div>
		</form>
	</div>
</div>
