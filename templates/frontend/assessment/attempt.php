<?php
/**
 * @var \Inc\DTO\Assessment\AssessmentDTO      $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $activeAttempt
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $lastAttempt    T13.7: последняя завершённая попытка
 * @var array<int, mixed>                      $resultPerTask  T13.7: per-task результат для ученика
 * @var \Inc\DTO\Person\PersonDTO|null         $person
 * @var array<int, array{template: string, materials: array}> $taskViews  T13.5: per-task тип шаблона + материалы
 * @var string                                 $introTemplate  D16.4: путь к партиалу интро-шага (стадия [intro])
 * @var string                                 $outcome        Задача 10: метка исхода попытки
 * @var string                                 $outcomeState   Задача 10: состояние плашки (ok/fail/review)
 */
declare( strict_types=1 );
?>
<div class="fs-page-wrapper">
	<div class="fs-assessment-page">

		<?php if ( ! $person ) : ?>
			<h1 class="fs-assessment-title"><?php echo esc_html( $assessment->title ); ?></h1>
			<p class="fs-assessment-notice"><?php echo esc_html( 'Для прохождения экзамена необходимо войти в систему.' ); ?></p>

		<?php elseif ( $activeAttempt && ! $activeAttempt->isExpired( $now ) ) : ?>
			<h1 class="fs-assessment-title"><?php echo esc_html( $assessment->title ); ?></h1>
			<?php /* ===== ФОРМА АКТИВНОЙ ПОПЫТКИ (стадия [tasks]) ===== */ ?>
			<div id="fs-assessment-form"
				data-attempt-id="<?php echo esc_attr( (string) $activeAttempt->id ); ?>"
				<?php if ( $assessment->timeLimit > 0 ) : ?>data-deadline="<?php echo esc_attr( $activeAttempt->deadlineAt ); ?>"<?php endif; ?>>

				<?php /* Таймер вынесен в липкую шапку .s-top (attempt-shell-header.php) — всегда виден при скролле. */ ?>

				<?php
				// D16.7: обычный Ege — станция-навигатор (одно задание на экран),
				// Control — одностраничный список. Разметка задания общая (партиал).
				if ( \Inc\Enums\Assessment\AssessmentKind::Ege === $assessment->kind ) {
					require __DIR__ . '/partials/attempt-form-nav.php';
				} else {
					require __DIR__ . '/partials/attempt-form-list.php';
				}
				?>

				<div id="fs-assessment-result" hidden>
					<h2>Результат</h2>
					<p class="fs-result-score"></p>
				</div>
			</div>

		<?php elseif ( $activeAttempt && $activeAttempt->isExpired( $now ) ) : ?>
			<h1 class="fs-assessment-title"><?php echo esc_html( $assessment->title ); ?></h1>
			<p class="fs-assessment-notice">Время попытки истекло.</p>
			<button class="fs-btn fs-btn--primary" id="fs-start-attempt-btn"
				data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>">
				Начать новую попытку
			</button>
			<p class="fs-start-notice" id="fs-start-notice" aria-live="polite"></p>

		<?php elseif ( $lastAttempt ) : ?>
			<h1 class="fs-assessment-title"><?php echo esc_html( $assessment->title ); ?></h1>
			<?php /* ===== T13.7: РЕЗУЛЬТАТ ЗАВЕРШЁННОЙ ПОПЫТКИ ===== */ ?>
			<?php
			// Максимум: из попытки (после автопроверки), иначе — из состава работы.
			$resultMax = ( null !== $lastAttempt->maxScore && $lastAttempt->maxScore > 0 )
				? (float) $lastAttempt->maxScore
				: $assessment->maxPrimary();
			// Задача 10: $outcome / $outcomeState вычислены в контроллере через
			// AttemptOutcomeService — для ЕГЭ/КЕГЭ порог сверяется по ВТОРИЧНОМУ баллу.
			?>
			<div class="fs-assessment-result-page fs-assessment-result-page--<?php echo esc_attr( $outcomeState ); ?>">
				<h2>Результат</h2>
				<p class="fs-result-score">
					Баллов: <?php echo esc_html( null !== $lastAttempt->totalScore ? (string) (float) $lastAttempt->totalScore : '—' ); ?>
					/ <?php echo esc_html( (string) $resultMax ); ?>
					&bull; <?php echo esc_html( $outcome ); ?>
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
										<?php
										// Задача 13: перевод вердикта на русский. Эталонные ответы на этом
										// экране НЕ показываем (только в «Мои оценки», п.13).
										$fs_verdict_ru = array(
											'correct'   => 'Решено верно',
											'incorrect' => 'Решено неверно',
											'pending'   => 'На проверке',
										);
										?>
										<span class="fs-result-task__verdict fs-result-task__verdict--<?php echo esc_attr( (string) $task['verdict'] ); ?>">
											<?php echo esc_html( $fs_verdict_ru[ $task['verdict'] ] ?? (string) $task['verdict'] ); ?>
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
			<div class="fs-result-actions">
				<?php if ( $canRetry ) : ?>
					<button class="fs-btn fs-btn--danger" id="fs-start-attempt-btn"
						data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>">
						Пройти ещё раз
					</button>
				<?php endif; ?>
				<a class="fs-btn fs-btn--primary" href="<?php echo esc_url( $backUrl ); ?>">
					Вернуться к курсу
				</a>
			</div>
			<p class="fs-start-notice" id="fs-start-notice" aria-live="polite"></p>

		<?php else : ?>
			<?php /* ===== СТАДИЯ [intro]: СТАРТОВЫЙ ЭКРАН (D16.4) ===== */ ?>
			<?php include $introTemplate; ?>
		<?php endif; ?>

	</div>
</div>
