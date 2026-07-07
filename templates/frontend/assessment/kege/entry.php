<?php
/**
 * Ритуал станции КЕГЭ: вход (номер бланка) → инструкция (слайды) →
 * регистрация (номер КИМ) → активация. Полностью на клиенте (localStorage,
 * см. src/js/kege/kege-entry.js) — бэкенду ничего не сообщается до реального
 * старта попытки (StartAttempt AJAX). Все стадии отрендерены сразу; JS
 * переключает видимость (см. lesson-player .pstep — тот же приём).
 *
 * Если есть $lastAttempt (сюда включается вместе с kege/finish.php), этот
 * ритуал изначально скрыт — раскрывается кнопкой «Пройти ещё раз».
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var bool                              $isFinished
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Modules\EgeComputer\Config\KegeSlidesConfig;

$slides = KegeSlidesConfig::slides();
?>
<div class="kege-entry" id="kegeEntry"<?php echo ! empty( $isFinished ) ? ' hidden' : ''; ?>>

	<!-- Этап: вход (номер бланка регистрации) -->
	<section class="kege-stage" data-kege-stage="entry">
		<div class="kege-stage__title">Единый государственный экзамен · <?php echo esc_html( $assessment->title ); ?></div>

		<div class="kege-entry-grid">
			<div>
				<div class="kege-field-label">Введите номер вашего бланка регистрации</div>
				<div class="kege-dbox">
					<input class="kege-dcell kege-w1" id="kegeBr0" maxlength="1" inputmode="numeric" autocomplete="off">
					<input class="kege-dcell kege-w6" id="kegeBr1" maxlength="6" inputmode="numeric" autocomplete="off">
					<input class="kege-dcell kege-w6" id="kegeBr2" maxlength="6" inputmode="numeric" autocomplete="off">
				</div>
				<p class="kege-hint">
					Введите с клавиатуры номер бланка регистрации (13 цифр) и нажмите «Далее».
					Это тренажёр — подойдёт любой набор цифр.
				</p>
				<button type="button" class="kege-ghost-link" id="kegeFillDemo">Заполнить демо-номером</button>
			</div>
		</div>

		<button type="button" class="kege-btn kege-btn--cyan kege-next-fab" id="kegeEntryNext" disabled>Далее</button>
	</section>

	<!-- Этап: инструкция (слайды) -->
	<section class="kege-stage" data-kege-stage="instr">
		<div class="kege-stage__title">Инструкция</div>
		<div class="kege-slides" id="kegeSlides">
			<?php foreach ( $slides as $i => $slide ) : ?>
				<div class="kege-slide" data-slide-index="<?php echo esc_attr( (string) $i ); ?>"<?php echo 0 !== $i ? ' hidden' : ''; ?>>
					<h3><?php echo esc_html( $slide['title'] ); ?></h3>
					<?php echo wp_kses_post( $slide['body'] ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="kege-nav-c kege-nav-c--left" id="kegeSlidePrev" hidden>‹</button>
		<button type="button" class="kege-nav-c kege-nav-c--right" id="kegeSlideNext">›</button>
		<button type="button" class="kege-btn kege-btn--cyan kege-next-fab" id="kegeInstrNext">Далее</button>
	</section>

	<!-- Этап: регистрация участника -->
	<section class="kege-stage" data-kege-stage="reg">
		<div class="kege-stage__title">Регистрация участника</div>

		<div class="kege-field-label kege-field-label--sm">Бланк регистрации</div>
		<div class="kege-dbox">
			<span class="kege-dcell kege-w1" id="kegeRegBr0"></span>
			<span class="kege-dcell kege-w6" id="kegeRegBr1"></span>
			<span class="kege-dcell kege-w6" id="kegeRegBr2"></span>
			<button type="button" class="kege-btn kege-btn--red kege-btn--up" id="kegeRegEdit">Изменить</button>
		</div>

		<div class="kege-field-label kege-field-label--sm">Номер КИМ</div>
		<div class="kege-dbox" id="kegeKimBox">
			<span class="kege-dcell kege-w4"><span id="kegeKim0"></span></span>
			<span class="kege-dcell kege-w3"><span id="kegeKim1"></span></span>
			<span class="kege-dcell kege-w1"><span id="kegeKim2"></span></span>
		</div>

		<div class="kege-steps">
			<div class="kege-step"><span class="kege-step-n">1</span><span class="kege-step-t">Сверьте номер бланка регистрации с указанным на бланке.</span></div>
			<div class="kege-step"><span class="kege-step-n">2</span><span class="kege-step-t">Если ошиблись — нажмите «Изменить» рядом с номером бланка.</span></div>
			<div class="kege-step"><span class="kege-step-n">3</span><span class="kege-step-t">Если номер верный — нажмите «Данные корректны».</span></div>
		</div>

		<div class="kege-reg-foot">
			<button type="button" class="kege-btn kege-btn--green kege-btn--up" id="kegeRegOk" disabled>Данные корректны</button>
			<span class="kege-warn-ico">!</span>
			<div class="kege-warn-t"><b>Тренажёр.</b><br>Подтвердите корректность данных самостоятельно.</div>
		</div>
	</section>

	<!-- Этап: активация экзамена -->
	<section class="kege-stage" data-kege-stage="act">
		<div class="kege-stage__title">Активация экзамена</div>

		<div class="kege-field-label kege-field-label--sm">Бланк регистрации</div>
		<div class="kege-dbox">
			<span class="kege-dcell kege-w1" id="kegeActBr0"></span>
			<span class="kege-dcell kege-w6" id="kegeActBr1"></span>
			<span class="kege-dcell kege-w6" id="kegeActBr2"></span>
			<button type="button" class="kege-btn kege-btn--red kege-btn--up" id="kegeActEdit">Изменить</button>
		</div>

		<div class="kege-field-label kege-field-label--sm">Номер КИМ</div>
		<div class="kege-dbox">
			<span class="kege-dcell kege-w4"><span id="kegeActKim0"></span></span>
			<span class="kege-dcell kege-w3"><span id="kegeActKim1"></span></span>
			<span class="kege-dcell kege-w1"><span id="kegeActKim2"></span></span>
		</div>

		<div class="kege-steps">
			<div class="kege-step"><span class="kege-step-n">1</span><span class="kege-step-t">Введите код активации.</span></div>
			<div class="kege-step"><span class="kege-step-n">2</span><span class="kege-step-t">Нажмите «Начать экзамен» после объявления о начале экзамена в аудитории.</span></div>
		</div>

		<div class="kege-reg-foot">
			<span class="kege-warn-ico">!</span>
			<div class="kege-warn-t">Код активации — <b id="kegeActCode"></b> (подсказка тренажёра).</div>
			<div class="kege-act-right">
				<input class="kege-code-in" id="kegeCodeInput" maxlength="4" inputmode="numeric" placeholder="····" autocomplete="off">
				<span class="kege-code-err" id="kegeCodeErr" hidden>Неверный код</span>
				<button type="button" class="kege-btn kege-btn--cyan" id="kegeStartBtn">Начать экзамен</button>
			</div>
		</div>
	</section>

</div>
