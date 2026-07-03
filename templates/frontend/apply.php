<?php
/**
 * Шаблон страницы подачи заявки на зачисление (/lms/apply)
 *
 * @package FS LMS
 *
 * @var bool $gated Включена ли привязка к направлению (серверный гейт кода).
 *                  Если true — форма НЕ рендерится здесь, а приходит по AJAX
 *                  после верного кода (см. apply-fields.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$gated = ! empty( $gated );
?>

<main class="fs-lms-apply-page">
    <div class="fs-apply-card">
        <h2 class="fs-apply-card__title"><?php esc_html_e( 'Подать заявку на обучение', 'fs-lms' ); ?></h2>
        <?php /* #6: имя направления (заполняет JS после верного кода направления). */ ?>
        <p class="fs-apply-card__direction" id="fs-apply-direction" hidden></p>

        <?php if ( $gated ) : ?>

            <?php /*
                Этап 0: серверный гейт кода направления. Форму здесь не выводим —
                её HTML отдаёт сервер по AJAX (AjaxHook::ValidateDirectionCode) и только
                после верного кода. До этого момента разметки формы в браузере нет,
                поэтому снять запрос кода через DevTools/adblock невозможно.
            */ ?>
            <div class="fs-apply-gate" id="fs-apply-gate">
                <p class="fs-apply-gate__hint">
                    <?php esc_html_e( 'Доступ к заявке — по коду направления. Код выдаётся в учебном центре.', 'fs-lms' ); ?>
                </p>

                <div class="fs-apply-card__field-group fs-form-group">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    <label for="fs-direction-code-input" class="screen-reader-text"><?php esc_html_e( 'Код направления', 'fs-lms' ); ?></label>
                    <input
                            type="text"
                            id="fs-direction-code-input"
                            placeholder="<?php esc_attr_e( 'Код направления', 'fs-lms' ); ?>"
                            autocomplete="off"
                            autocapitalize="characters"
                            autocorrect="off"
                            spellcheck="false"
                    >
                </div>

                <div class="fs-apply-gate__error" id="fs-direction-gate-error" role="alert" hidden></div>

                <button type="button" id="fs-direction-gate-submit" class="button button-primary button-large fs-apply-card__submit">
                    <?php esc_html_e( 'Открыть форму', 'fs-lms' ); ?>
                </button>
            </div>

            <?php /* Слот: сюда JS вставит HTML формы, полученный по AJAX после верного кода. */ ?>
            <div id="fs-apply-form-slot" class="fs-apply-card__form-slot"></div>

        <?php else : ?>

            <?php /* Привязка выключена — форма доступна сразу, без гейта. */ ?>
            <?php require __DIR__ . '/apply-fields.php'; ?>

        <?php endif; ?>
    </div>
</main>
