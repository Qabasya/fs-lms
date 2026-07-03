<?php
/**
 * @var \Inc\DTO\Assessment\AssessmentDTO      $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $activeAttempt
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $lastAttempt    T13.7: последняя завершённая попытка
 * @var array<int, mixed>                      $resultPerTask  T13.7: per-task результат для ученика
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

		<?php elseif ( $lastAttempt ) : ?>
			<?php /* ===== T13.7: РЕЗУЛЬТАТ ЗАВЕРШЁННОЙ ПОПЫТКИ ===== */ ?>
			<div class="fs-assessment-result-page">
				<h2>Результат</h2>
				<p class="fs-result-score">
					Баллов: <?php echo esc_html( null !== $lastAttempt->totalScore ? (string) $lastAttempt->totalScore : '—' ); ?>
					/ <?php echo esc_html( null !== $lastAttempt->maxScore ? (string) $lastAttempt->maxScore : '—' ); ?>
					&bull; Статус: <?php echo esc_html( $lastAttempt->status->value ); ?>
				</p>
				<?php if ( ! empty( $resultPerTask ) ) : ?>
					<div class="fs-result-tasks">
						<?php foreach ( $resultPerTask as $task ) : ?>
							<div class="fs-result-task">
								<div class="fs-result-task__n"><?php echo esc_html( (string) $task['n'] ); ?>.</div>
								<div class="fs-result-task__body">
									<?php if ( ! empty( $task['criteria'] ) ) : ?>
										<ul class="fs-result-criteria">
											<?php foreach ( $task['criteria'] as $c ) : ?>
												<li>
													<?php echo esc_html( $c['label'] ); ?>:
													<?php echo null !== $c['awarded'] ? esc_html( rtrim( rtrim( number_format( $c['awarded'], 2, '.', '' ), '0' ), '.' ) ) : '—'; ?>
													/ <?php echo esc_html( rtrim( rtrim( number_format( $c['max_points'], 2, '.', '' ), '0' ), '.' ) ); ?>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php elseif ( $task['score'] !== null ) : ?>
										<span class="fs-result-task__score">
											Баллов: <?php echo esc_html( (string) $task['score'] ); ?>
											/ <?php echo esc_html( null !== $task['max_score'] ? (string) $task['max_score'] : '?' ); ?>
										</span>
									<?php else : ?>
										<span class="fs-result-task__verdict">
											<?php echo esc_html( $task['verdict'] ); ?>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $task['files'] ) ) : ?>
										<div class="fs-result-files">
											<div class="fs-result-files__title">Ваши файлы:</div>
											<?php foreach ( $task['files'] as $file ) : ?>
												<?php if ( str_starts_with( $file['mime'], 'image/' ) ) : ?>
													<a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener noreferrer">
														<img class="fs-result-files__preview" src="<?php echo esc_url( $file['url'] ); ?>" alt="<?php echo esc_attr( $file['name'] ); ?>">
													</a>
												<?php else : ?>
													<a class="fs-result-files__link" href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener noreferrer">
														<?php echo esc_html( $file['name'] ); ?>
													</a>
												<?php endif; ?>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<button class="fs-btn fs-btn--secondary" id="fs-start-attempt-btn"
				data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>">
				Пройти ещё раз
			</button>
			<p class="fs-start-notice" id="fs-start-notice" aria-live="polite"></p>

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
