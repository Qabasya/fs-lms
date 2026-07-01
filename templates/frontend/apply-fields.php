<?php
/**
 * Партиал формы заявки (/lms/apply): Этап 1 (поля) + Этап 2 (OTP).
 *
 * Рендерится двумя путями:
 *  - инлайн из apply.php, когда привязка к направлению выключена;
 *  - по AJAX (ValidateDirectionCode) после верного кода направления, когда гейт
 *    включён — до этого момента разметки формы в браузере нет, поэтому снять
 *    запрос кода через devtools/adblock нельзя (раскрывать нечего).
 *
 * @package FS LMS
 */

use Inc\Enums\Wp\Nonce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Текущая дата для ограничения выбора даты рождения (нельзя выбрать будущее)
$max_birth_date = gmdate( 'Y-m-d' );
?>

<!-- ЭТАП 1: Форма ввода данных -->
<div id="apply-form" class="fs-apply-card__step fs-apply-card__step--active">
    <!-- Убран autocomplete="off" с формы, чтобы браузер мог предлагать автозаполнение -->
    <form name="fs_lms_apply_form" id="fs-lms-apply-form" method="post" novalidate autocomplete="on">

        <?php wp_nonce_field( Nonce::Apply->value, 'security' ); ?>

        <?php /* Honeypot-ловушка для ботов (имя поля = FormGuardService::HONEYPOT_FIELD). Скрыто через CSS .fs-hp, люди не заполняют. */ ?>
        <div class="fs-hp" aria-hidden="true">
            <label for="fs_company"><?php esc_html_e( 'Компания', 'fs-lms' ); ?></label>
            <input type="text" name="fs_company" id="fs_company" tabindex="-1" autocomplete="off">
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_last_name"><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
            <input
                    type="text"
                    name="last_name"
                    id="fs_last_name"
                    placeholder="<?php esc_attr_e( 'Иванов', 'fs-lms' ); ?>"
                    required
                    aria-required="true"
                    autocomplete="family-name"
                    autocapitalize="words"
                    data-validate="cyrillicName"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_first_name"><?php esc_html_e( 'Имя', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
            <input
                    type="text"
                    name="first_name"
                    id="fs_first_name"
                    placeholder="<?php esc_attr_e( 'Иван', 'fs-lms' ); ?>"
                    required
                    aria-required="true"
                    autocomplete="given-name"
                    autocapitalize="words"
                    data-validate="cyrillicName"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_middle_name"><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
            <input
                    type="text"
                    name="middle_name"
                    id="fs_middle_name"
                    placeholder="<?php esc_attr_e( 'Иванович', 'fs-lms' ); ?>"
                    autocomplete="additional-name"
                    autocapitalize="words"
                    data-validate="cyrillicName"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_email"><?php esc_html_e( 'Почта', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-email" aria-hidden="true"></span>
            <input
                    type="email"
                    name="email"
                    id="fs_email"
                    placeholder="<?php esc_attr_e( 'ivanov@mail.ru', 'fs-lms' ); ?>"
                    required
                    aria-required="true"
                    autocomplete="email"
                    inputmode="email"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group" id="fs-phone-group">
            <label for="fs_phone"><?php esc_html_e( 'Номер телефона', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-phone" aria-hidden="true"></span>
            <input
                    type="tel"
                    name="phone"
                    id="fs_phone"
                    placeholder="+7 (999) 000-00-00"
                    required
                    aria-required="true"
                    autocomplete="tel"
                    inputmode="tel"
                    pattern="^\+?[0-9\s\-\(\)]{10,18}$"
                    data-validate="phone"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_birth_date"><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
            <input
                    type="date"
                    name="birth_date"
                    id="fs_birth_date"
                    required
                    aria-required="true"
                    autocomplete="bday"
                    max="<?php echo esc_attr( $max_birth_date ); ?>"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_school"><?php esc_html_e( 'Школа', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
            <input
                    type="text"
                    name="school"
                    id="fs_school"
                    placeholder="<?php esc_attr_e( 'МАОУ СОШ №', 'fs-lms' ); ?>"
                    required
                    aria-required="true"
                    autocomplete="organization"
                    data-validate="schoolName"
                    minlength="3"
                    maxlength="100"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_grade"><?php esc_html_e( 'Класс', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
            <select
                    name="grade"
                    id="fs_grade"
                    required
                    aria-required="true"
                    autocomplete="off"
            >
                <option value="" disabled selected><?php esc_html_e( 'Выберите класс', 'fs-lms' ); ?></option>
                <?php for ( $i = 1; $i <= 11; $i++ ) : ?>
                    <option value="<?php echo esc_attr( (string) $i ); ?>">
                        <?php echo esc_html( $i . ' класс' ); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="fs-apply-card__field-group fs-form-group">
            <label for="fs_username"><?php esc_html_e( 'Логин', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-id" aria-hidden="true"></span>
            <input
                    type="text"
                    name="username"
                    id="fs_username"
                    placeholder="<?php esc_attr_e( 'Придумайте логин', 'fs-lms' ); ?>"
                    required
                    aria-required="true"
                    autocomplete="username"
                    autocapitalize="none"
                    autocorrect="off"
                    spellcheck="false"
                    data-validate="latinOnly"
                    minlength="3"
                    maxlength="20"
            >
        </div>

        <div class="fs-apply-card__field-group fs-form-group fs-lms-secret-field">
            <label for="fs_password"><?php esc_html_e( 'Пароль', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
            <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
            <input
                    type="password"
                    name="password"
                    id="fs_password"
                    placeholder="<?php esc_attr_e( 'Придумайте пароль', 'fs-lms' ); ?>"
                    required
                    aria-required="true"
                    autocomplete="new-password"
                    autocapitalize="none"
                    autocorrect="off"
                    spellcheck="false"
                    data-validate="latinOnly"
                    minlength="3"
                    maxlength="16"
            >

            <button type="button" class="js-toggle-secret" aria-label="<?php esc_attr_e( 'Показать пароль', 'fs-lms' ); ?>" aria-controls="fs_password" aria-pressed="false">
                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e( 'Показать пароль', 'fs-lms' ); ?></span>
            </button>
        </div>

        <!-- Слот капчи — заполняется JS -->
        <div id="fs-captcha-slot" class="fs-apply-card__captcha" role="region" aria-label="<?php esc_attr_e( 'Проверка безопасности', 'fs-lms' ); ?>"></div>

        <button type="submit" id="fs-apply-submit" class="button button-primary button-large fs-apply-card__submit">
            <?php esc_html_e( 'Подать заявку', 'fs-lms' ); ?>
        </button>
    </form>
</div>

<!-- ЭТАП 2: OTP-подтверждение -->
<div id="otp-step" class="fs-apply-card__step" aria-live="polite">

    <div class="js-otp-input-block">
        <p class="fs-apply-card__description">
            <?php esc_html_e( 'Код отправлен на', 'fs-lms' ); ?>
            <strong class="js-masked-email"></strong>
        </p>

        <form name="fs_lms_otp_form" id="fs-lms-otp-form" method="post" novalidate autocomplete="on">
            <div class="fs-apply-card__field-group fs-apply-card__field-group--otp">
                <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                <label for="fs_otp_code" class="screen-reader-text"><?php esc_html_e( 'Код из СМС', 'fs-lms' ); ?></label>
                <input
                        type="text"
                        name="otp_code"
                        id="fs_otp_code"
                        placeholder="000000"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        required
                        aria-required="true"
                        autocomplete="one-time-code"
                >
            </div>

            <button type="submit" id="fs-otp-submit" class="button button-primary button-large fs-apply-card__submit">
                <?php esc_html_e( 'Подтвердить', 'fs-lms' ); ?>
            </button>

            <div class="fs-apply-card__resend">
                <button type="button" id="fs-resend-otp-btn" class="button-link js-resend-otp" disabled aria-disabled="true">
                    <?php esc_html_e( 'Отправить ещё раз', 'fs-lms' ); ?>
                    <span class="js-otp-countdown" aria-live="polite">(60)</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Показывается вместо формы OTP после успешной отправки -->
    <div class="js-otp-success-block fs-apply-card__success" style="display:none" role="status">
        <span class="dashicons dashicons-yes-alt fs-apply-card__success-icon" aria-hidden="true"></span>
        <p class="fs-apply-card__success-title">
            <?php esc_html_e( 'Заявка успешно отправлена!', 'fs-lms' ); ?>
        </p>
        <?php /* Необязательное серверное сообщение + спиннер ожидания (статус создания учётки в домене). */ ?>
        <div class="fs-apply-card__status js-apply-status" hidden>
            <span class="fs-apply-card__spinner js-apply-spinner" aria-hidden="true"></span>
            <p class="fs-apply-card__success-notice js-apply-notice"></p>
        </div>
    </div>
</div>
