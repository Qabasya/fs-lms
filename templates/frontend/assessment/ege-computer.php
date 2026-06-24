<?php
/**
 * Шаблон плеера ЕГЭ (Компьютер) — Inc\Modules\EgeComputer.
 * Переменные идентичны attempt.php (T7.19 — тот же контроллер).
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO      $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $activeAttempt
 * @var \Inc\DTO\Person\PersonDTO|null         $person
 */
declare( strict_types=1 );

use Inc\Enums\Assessment\AttemptStatus;
use Inc\Services\Shared\ThemeCompatService;

// guard: неавторизованный пользователь
if ( ! $person ) {
	wp_redirect( wp_login_url( get_permalink() ) );
	exit;
}
?>
<div class="fs-page-wrapper">
	<div class="fs-assessment-page fs-assessment-page--ege-computer">

		<h1 class="fs-assessment-title">
			<?php echo esc_html( $assessment->title ); ?>
			<span class="fs-assessment-badge">ЕГЭ Компьютер</span>
		</h1>

		<div class="fs-assessment-meta">
			<?php if ( $assessment->timeLimit > 0 ) : ?>
				<span class="fs-assessment-meta-item">
					Время: <?php echo esc_html( (string) $assessment->timeLimit ); ?> мин
				</span>
			<?php endif; ?>
			<span class="fs-assessment-meta-item">
				Заданий: <?php echo esc_html( (string) count( $assessment->taskIds ) ); ?>
			</span>
		</div>

		<?php if ( $activeAttempt && $activeAttempt->status === AttemptStatus::InProgress ) : ?>

			<div
				id="fs-ege-computer-player"
				class="fs-ege-computer-player"
				data-attempt-id="<?php echo esc_attr( (string) $activeAttempt->id ); ?>"
				data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>"
				data-subject-key="<?php echo esc_attr( $assessment->subjectKey ); ?>"
				data-time-limit="<?php echo esc_attr( (string) $assessment->timeLimit ); ?>"
				data-task-count="<?php echo esc_attr( (string) count( $assessment->taskIds ) ); ?>"
			>
				<div class="fs-ege-computer-player__loading">Загрузка…</div>
			</div>

		<?php elseif ( $activeAttempt && $activeAttempt->status === AttemptStatus::Submitted ) : ?>

			<div class="fs-assessment-result fs-assessment-result--pending">
				<p>Работа сдана. Результаты будут доступны после проверки.</p>
			</div>

		<?php else : ?>

			<div class="fs-assessment-start">
				<p>
					Это компьютерный вариант ЕГЭ. После начала вы не сможете открыть другие страницы
					до завершения работы.
				</p>
				<button
					type="button"
					class="fs-btn fs-btn--primary js-fs-start-attempt"
					data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>"
				>
					Начать работу
				</button>
			</div>

		<?php endif; ?>

	</div>
</div>
