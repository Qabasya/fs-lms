<?php
/**
 * Модальное окно добавления новой группы.
 *
 * @var array                       $subjects          Список предметов (передан из коллбека)
 * @var array                       $academic_periods  Список академических периодов (передан из коллбека)
 * @var \Inc\DTO\UserDTO[]           $teachers          Список преподавателей (передан из коллбека)
 */

use Inc\Enums\WeekDay;


?>

<div id="fs-lms-group-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Добавить новую группу</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<form id="fs-lms-add-group-form" autocomplete="off">

				<div class="fs-form-group">
					<label for="group-title">Название группы</label>
					<input type="text" id="group-title" name="title" placeholder="Например: Робо-1..." required>
				</div>

				<div class="fs-form-group">
					<label for="group-period">Учебный период</label>
					<select id="group-period" name="period_id" required>
						<option value="">-- Выберите период --</option>
						<?php foreach ( $academic_periods ?? [] as $id => $period ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>">
								<?php echo esc_html( $period['name'] ?? $id ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="fs-form-group">
					<label for="group-subject">Предмет</label>
					<select id="group-subject" name="subject_id" required>
						<option value="">-- Выберите предмет --</option>
						<?php foreach ( $subjects ?? [] as $id => $subject ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>">
								<?php echo esc_html( $subject->name ?? $id ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="fs-form-group">
					<label for="group-teacher">Преподаватель</label>
					<select id="group-teacher" name="teacher_id">
						<option value="">— Не назначен —</option>
						<?php foreach ( $teachers ?? [] as $teacher ) : ?>
							<option value="<?php echo esc_attr( (string) $teacher->id ); ?>">
								<?php echo esc_html( $teacher->displayName ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="fs-form-group">
					<label>Расписание работы группы</label>
					<div class="fs-schedule-days">
						<?php foreach ( WeekDay::cases() as $day ) : ?>
							<div class="fs-schedule-day-row" data-day="<?php echo esc_attr( $day->value ); ?>">
								<label class="fs-schedule-day-label">
									<input type="checkbox" class="js-schedule-day-cb" value="<?php echo esc_attr( $day->value ); ?>">
									<span><?php echo esc_html( $day->fullLabel() ); ?></span>
								</label>
								<div class="fs-schedule-day-times hidden">
									<select class="js-day-start">
										<option value="">ЧЧ:ММ</option>
										<?php for ( $h = 6; $h <= 22; $h++ ) : ?>
											<?php foreach ( [ 0, 30 ] as $m ) : ?>
												<?php $t = sprintf( '%02d:%02d', $h, $m ); ?>
												<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
											<?php endforeach; ?>
										<?php endfor; ?>
									</select>
									<span class="fs-schedule-separator">—</span>
									<select class="js-day-end">
										<option value="">ЧЧ:ММ</option>
										<?php for ( $h = 6; $h <= 23; $h++ ) : ?>
											<?php foreach ( [ 0, 30 ] as $m ) : ?>
												<?php $t = sprintf( '%02d:%02d', $h, $m ); ?>
												<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
											<?php endforeach; ?>
										<?php endfor; ?>
									</select>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

                <input type="hidden" name="action_type" value="add">
                <input type="hidden" name="id" value="">

                <div class="fs-lms-modal-footer">
                    <button type="button" class="button fs-lms-modal-cancel js-modal-close">Отмена</button>
                    <button type="submit" class="button button-primary">Создать группу</button>
                </div>

			</form>
		</div>
	</div>
</div>