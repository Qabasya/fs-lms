<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;
use Inc\Enums\Course\WorkType;
$ranking_view = $load_ranking_settings_view();
$ranking_settings = $config['ranking'];
$ranking_fields = WorkType::options() + array( 'assessment' => 'Экзамены и контрольные', 'attendance_bonus' => 'Бонус за серию посещений', 'attendance_streak_length' => 'Занятий подряд для бонуса' );
?>
		<form id="fs-ranking-settings-form" class="fs-card fs-card--flat">
			<div class="fs-card__header"><h2 class="fs-card__title">Рейтинг</h2></div>
			<div class="fs-card__body">
				<p class="fs-card__desc">Баллы за одну решённую задачу, включая повторные решения. Ноль отключает начисление за этот источник. Изменения пересчитывают рейтинг текущего триместра.</p>
				<?php foreach ( $ranking_fields as $key => $label ) : ?>
					<?php $is_streak = 'attendance_streak_length' === $key; ?>
					<div class="fs-field">
						<label for="fs-ranking-<?php echo esc_attr( $key ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
						<div class="fs-field__control">
							<input type="number" id="fs-ranking-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" data-ranking-field class="small-text" required
								min="<?php echo $is_streak ? '2' : '0'; ?>" max="<?php echo $is_streak ? '100' : '1000'; ?>" step="<?php echo $is_streak ? '1' : '0.01'; ?>"
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
				<div class="fs-field">
					<label class="fs-field__label" for="fs-ranking-student-search">Не отображать в рейтинге</label>
					<div class="fs-field__control fs-print-center__search">
						<input type="text" id="fs-ranking-student-search" placeholder="Начните вводить ФИО" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="fs-ranking-student-list" aria-autocomplete="list">
						<ul id="fs-ranking-student-list" class="fs-print-center__suggest" role="listbox" hidden></ul>
					</div>
					<select id="fs-ranking-excluded" multiple hidden tabindex="-1" aria-hidden="true">
						<?php foreach ( $ranking_view['students'] as $student ) : ?>
							<option value="<?php echo esc_attr( (string) $student['id'] ); ?>" <?php selected( in_array( (int) $student['id'], $ranking_view['excluded_students'], true ) ); ?>><?php echo esc_html( $student['name'] . ' (ID ' . $student['id'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="fs-ranking-selected" aria-label="Исключённые ученики" aria-live="polite">
						<?php foreach ( $ranking_view['students'] as $student ) : ?>
							<?php if ( in_array( (int) $student['id'], $ranking_view['excluded_students'], true ) ) : ?>
								<span class="fs-ranking-selected__item" data-ranking-selected="<?php echo esc_attr( (string) $student['id'] ); ?>"><?php echo esc_html( $student['name'] . ' (ID ' . $student['id'] . ')' ); ?> <button type="button" class="fs-ranking-selected__remove" aria-label="<?php echo esc_attr( 'Удалить ' . $student['name'] ); ?>">×</button></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<p class="fs-field__desc">Выбранные ученики исключаются из всех рейтингов.</p>
				</div>

			</div>
			<div class="fs-card__footer">
				<button type="submit" class="button button-primary js-ranking-save">Сохранить</button>
				<span class="fs-config-status js-ranking-status" role="status" aria-live="polite"></span>
			</div>
		</form>

