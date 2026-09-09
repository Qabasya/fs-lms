<?php
/**
 * Интро-шаг экзамена (стадия [intro], D16.4) — стартовый экран перед заданиями.
 *
 * Самодостаточный партиал: заголовок + описание работы + блок правил + кнопка
 * «Начать». Подключается из attempt.php в ветке «нет активной/последней попытки»
 * и заменяется целиком через AssessmentPageController::INTRO_FILTER.
 *
 * Контент декуплирован (AssessmentIntroConfig): описание берётся из per-work
 * `intro_html`, при пустом — из дефолтов конфига; блок правил — авто из DTO.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var bool                              $previewMode Предпросмотр автора: попытку начать нельзя
 * @var int                               $attemptsUsed Израсходовано попыток (Tasks.md, п. 8)
 */
declare( strict_types=1 );

use Inc\Services\Assessment\AssessmentIntroConfig;

$intro_html = '' !== trim( $assessment->introHtml )
	? $assessment->introHtml
	: AssessmentIntroConfig::defaultDescription( $assessment->kind );

$rules = AssessmentIntroConfig::rules( $assessment );

// Tasks.md, п. 8: предупреждение о предпоследней/последней попытке. Работы с
// единственной попыткой не предупреждаем — там об этом говорит сам блок правил.
$attempt_warning = AssessmentIntroConfig::attemptWarning( $assessment, (int) ( $attemptsUsed ?? 0 ) );
?>
<div class="fs-assessment-intro">
	<h1 class="fs-assessment-title"><?php echo esc_html( $assessment->title ); ?></h1>

	<div class="fs-assessment-intro__desc wpc">
		<?php echo \Inc\Shared\SafeHtml::post( $intro_html ); ?>
	</div>

	<?php if ( ! empty( $rules ) ) : ?>
		<ul class="fs-assessment-intro__rules">
			<?php foreach ( $rules as $rule ) : ?>
				<li class="fs-assessment-intro__rule">
					<span class="fs-assessment-intro__rule-label"><?php echo esc_html( $rule['label'] ); ?></span>
					<span class="fs-assessment-intro__rule-value"><?php echo esc_html( $rule['value'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( '' !== $attempt_warning ) : ?>
		<p class="fs-assessment-intro__warn"><?php echo esc_html( $attempt_warning ); ?></p>
	<?php endif; ?>

	<div class="fs-assessment-intro__actions">
		<?php // Предпросмотр автора: попытка не заводится, поэтому старт заблокирован. ?>
		<button class="fs-btn fs-btn--primary" id="fs-start-attempt-btn"
			data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>"
			<?php echo $previewMode ? 'disabled' : ''; ?>>
			Начать
		</button>
	</div>
	<?php if ( $previewMode ) : ?>
		<p class="fs-assessment-notice">Предпросмотр: попытка не записывается, начать экзамен можно только из курса.</p>
	<?php endif; ?>
	<p class="fs-start-notice" id="fs-start-notice" aria-live="polite"></p>
</div>
