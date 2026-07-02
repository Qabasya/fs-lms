<?php
/**
 * @var \Inc\DTO\Assessment\AssessmentDTO      $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $activeAttempt
 * @var \Inc\DTO\Person\PersonDTO|null         $person
 * @var array<int, array{template: string, materials: array}> $taskViews  T13.5: per-task тип шаблона + материалы
 */
declare( strict_types=1 );

use Inc\Enums\Assessment\AttemptStatus;
?>
<div class="fs-page-wrapper">
	<div class="fs-assessment-page">

		<h1 class="fs-assessment-title"><?php echo esc_html( $assessment->title ); ?></h1>

		<div class="fs-assessment-meta">
			<?php if ( $assessment->timeLimit > 0 ) : ?>
				<span class="fs-assessment-meta-item">
					Время: <?php echo esc_html( (string) $assessment->timeLimit ); ?> мин
				</span>
			<?php endif; ?>
			<?php if ( $assessment->attemptsAllowed > 0 ) : ?>
				<span class="fs-assessment-meta-item">
					Попыток: <?php echo esc_html( (string) $assessment->attemptsAllowed ); ?>
				</span>
			<?php endif; ?>
			<span class="fs-assessment-meta-item">
				Заданий: <?php echo esc_html( (string) count( $assessment->taskIds ) ); ?>
			</span>
		</div>

		<?php if ( ! $person ) : ?>
			<p class="fs-assessment-notice"><?php echo esc_html( 'Для прохождения контрольной необходимо войти в систему.' ); ?></p>

		<?php elseif ( $activeAttempt && ! $activeAttempt->isExpired( $now ) ) : ?>
			<?php /* ===== ФОРМА АКТИВНОЙ ПОПЫТКИ ===== */ ?>
			<div id="fs-assessment-form"
				data-attempt-id="<?php echo esc_attr( (string) $activeAttempt->id ); ?>"
				data-deadline="<?php echo esc_attr( $activeAttempt->deadlineAt ); ?>">

				<div class="fs-assessment-timer" id="fs-assessment-timer">
					<span id="fs-timer-display">—</span>
				</div>

				<form class="fs-attempt-form" novalidate>
					<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
						<?php $task = get_post( $taskId ); ?>
						<?php if ( ! $task ) : continue; endif; ?>
						<?php
						// T13.5 (Эпик 13, D16): «Развёрнутый ответ» — файловый блок + материалы.
						$taskView     = $taskViews[ (int) $taskId ] ?? array( 'template' => '', 'materials' => array() );
						$isFileAnswer = 'file_answer_task' === $taskView['template'];
						?>
						<div class="fs-attempt-question"
							data-task-id="<?php echo esc_attr( (string) $taskId ); ?>"
							<?php echo $isFileAnswer ? 'data-template="file_answer"' : ''; ?>>
							<div class="fs-attempt-question-number"><?php echo esc_html( (string) ( $i + 1 ) ); ?>.</div>
							<div class="fs-attempt-question-content">
								<?php echo wp_kses_post( apply_filters( 'the_content', $task->post_content ) ); ?>
							</div>

							<?php if ( $isFileAnswer && ! empty( $taskView['materials'] ) ) : ?>
								<div class="fs-attempt-materials">
									<div class="fs-attempt-materials__title">Материалы задания:</div>
									<?php foreach ( $taskView['materials'] as $material ) : ?>
										<a class="fs-attempt-materials__link"
											href="<?php echo esc_url( $material['url'] ); ?>"
											target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( $material['name'] ); ?>
										</a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>

							<div class="fs-form-group">
								<textarea
									class="fs-attempt-answer"
									name="answer_<?php echo esc_attr( (string) $taskId ); ?>"
									rows="<?php echo $isFileAnswer ? 5 : 3; ?>"
									placeholder="<?php echo $isFileAnswer ? 'Текст решения (необязательно, если прикладываете файл)…' : 'Ваш ответ…'; ?>"
								></textarea>

								<?php if ( $isFileAnswer ) : ?>
									<div class="fs-attempt-files">
										<div class="fs-attempt-files__chips"></div>
										<div class="fs-attempt-files__controls">
											<input type="file" class="fs-attempt-files__input" hidden multiple
												accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.pdf,.doc,.docx,.pptx,.txt,.py">
											<button type="button" class="fs-btn fs-btn--secondary fs-attempt-files__add">
												📎 Прикрепить файлы
											</button>
											<span class="fs-attempt-files__status" aria-live="polite"></span>
										</div>
										<p class="fs-attempt-files__hint">
											Проверяется преподавателем вручную. Фото/PDF/документ/презентация/.py, до 20 МБ.
										</p>
									</div>
								<?php endif; ?>

								<button type="button" class="fs-btn fs-btn--secondary fs-autosave-btn">
									Сохранить
								</button>
								<span class="fs-save-status" aria-live="polite"></span>
							</div>
						</div>
					<?php endforeach; ?>

					<div class="fs-attempt-actions">
						<button type="submit" class="fs-btn fs-btn--primary fs-submit-attempt-btn">
							Сдать контрольную
						</button>
					</div>
				</form>

				<div id="fs-assessment-result" hidden>
					<h2>Результат</h2>
					<p class="fs-result-score"></p>
				</div>
			</div>

		<?php elseif ( $activeAttempt && $activeAttempt->isExpired( $now ) ) : ?>
			<p class="fs-assessment-notice">Время попытки истекло.</p>
			<button class="fs-btn fs-btn--primary" id="fs-start-attempt-btn">
				Начать новую попытку
			</button>

		<?php else : ?>
			<?php /* ===== СТАРТОВАЯ СТРАНИЦА ===== */ ?>
			<button class="fs-btn fs-btn--primary" id="fs-start-attempt-btn"
				data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>">
				Начать контрольную
			</button>
			<p class="fs-start-notice" id="fs-start-notice" aria-live="polite"></p>
		<?php endif; ?>

	</div>
</div>
