<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;
use Inc\Enums\Course\WorkType;
$ranking_view = $load_ranking_settings_view();
$ranking_settings = $config['ranking'];
$ranking_fields = WorkType::options() + array( 'assessment' => 'Экзамены и контрольные', 'attendance_bonus' => 'Бонус за серию посещений', 'attendance_streak_length' => 'Занятий подряд для бонуса' );
if ( ! empty( $ranking_view['subject_settings'] ) ) {
	include __DIR__ . '/settings-ranking-subjects.php';
	return;
}
?>
		<form id="fs-ranking-settings-form" class="fs-card fs-card--flat">
			<div class="fs-card__header"><h2 class="fs-card__title">Рейтинг</h2></div>
			<div class="fs-card__body">
				<p class="fs-card__desc">Баллы за одну решённую задачу, включая повторные решения. Ноль отключает начисление за этот источник. Изменения пересчитывают рейтинг текущего триместра.</p>
				<?php if ( array_filter( $ranking_settings, static fn( $value ) => is_numeric( $value ) && (float) $value !== floor( (float) $value ) ) ) : ?>
					<p class="fs-field__desc">Ранее сохранены дробные значения. Перед сохранением замените их целыми числами.</p>
				<?php endif; ?>
				<?php foreach ( $ranking_fields as $key => $label ) : ?>
					<?php $is_streak = 'attendance_streak_length' === $key; ?>
					<div class="fs-field">
						<label for="fs-ranking-<?php echo esc_attr( $key ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
						<div class="fs-field__control">
							<input type="number" id="fs-ranking-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" data-ranking-field class="small-text" required
								min="<?php echo $is_streak ? '2' : '0'; ?>" max="<?php echo $is_streak ? '100' : '1000'; ?>" step="1"
								value="<?php echo esc_attr( (string) $ranking_settings[ $key ] ); ?>" />
						</div>
						<?php if ( 'attendance_bonus' === $key ) : ?>
							<p class="fs-field__desc">Начисляется за каждые N занятий подряд без пропуска. Например, при серии из 6 занятий и N = 3 бонус начисляется дважды.</p>
						<?php elseif ( $is_streak ) : ?>
							<p class="fs-field__desc">От 2 до 100 занятий. Пропуск начинает новую серию; при нулевом бонусе посещаемость не добавляет баллы.</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<div class="fs-field">
					<span class="fs-field__label">Отображать рейтинг:</span>
					<p class="fs-field__desc">Для учеников выбранных предметов. Сотрудникам рейтинг доступен по их правам.</p>
					<?php foreach ( $ranking_view['subjects'] as $subject ) : ?>
						<div class="fs-ranking-subject" data-ranking-subject="<?php echo esc_attr( $subject->key ); ?>">
							<?php render_fs_toggle( 'subject_' . $subject->key, ! in_array( $subject->key, $ranking_view['hidden_subjects'], true ), array( 'id' => 'fs-ranking-subject-' . $subject->key ) ); ?>
							<label for="fs-ranking-subject-<?php echo esc_attr( $subject->key ); ?>"><?php echo esc_html( $subject->name . ( $subject->archived ? ' (архив)' : '' ) ); ?></label>
						</div>
					<?php endforeach; ?>
				</div>
				<?php include __DIR__ . '/settings-ranking-exclusions.php'; ?>

			</div>
			<div class="fs-card__footer">
				<button type="submit" class="button button-primary js-ranking-save">Сохранить</button>
				<span class="fs-config-status js-ranking-status" role="status" aria-live="polite"></span>
			</div>
		</form>

