<?php
/**
 * Экран завершения экзамена станции КЕГЭ — рендерится, когда активной попытки
 * нет, но есть последняя сданная ($lastAttempt). Включается на страницу вместе
 * со скрытым ритуалом входа (kege/entry.php); кнопка «Пройти ещё раз» прячет
 * этот экран и открывает ритуал заново (kege-entry.js), который в итоге снова
 * вызывает реальный StartAttempt AJAX.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO   $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO      $lastAttempt
 * @var string                              $outcome     Задача 10: исход по вторичному баллу
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="kege-fin-wrap" id="kegeFinish" data-attempt-id="<?php echo esc_attr( (string) $lastAttempt->id ); ?>">
	<div class="kege-fin-card">
		<div class="kege-fin-head">Единый государственный экзамен · <b><?php echo esc_html( $assessment->title ); ?></b></div>
		<div class="kege-fin-body">
			<h2>Экзамен закончен</h2>
			<?php
			$kegeMax = ( null !== $lastAttempt->maxScore && $lastAttempt->maxScore > 0 )
				? (float) $lastAttempt->maxScore
				: $assessment->maxPrimary();
			?>
			<div class="kege-fin-cnt">
				Баллов: <b><?php echo esc_html( null !== $lastAttempt->totalScore ? (string) (float) $lastAttempt->totalScore : '—' ); ?></b>
				/ <?php echo esc_html( (string) $kegeMax ); ?>
				&bull; <?php echo esc_html( $outcome ); // Задача 10: исход по вторичному баллу (AttemptOutcomeService). ?>
			</div>
			<div class="kege-fin-sum">
				<div class="kege-fin-sum__lbl">Контрольная сумма</div>
				<div class="kege-fin-sum__val" id="kegeFinSum">—</div>
			</div>
			<p class="kege-fin-warn">Запишите значение контрольной суммы в бланк регистрации.</p>
		</div>
	</div>
	<button type="button" class="kege-reset-link" id="kegeRetryBtn">Пройти ещё раз</button>
</div>
