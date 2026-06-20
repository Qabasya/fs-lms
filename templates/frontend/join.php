<?php
/**
 * Шаблон страницы завершения регистрации родителя и ученика (/lms/join/{code})
 *
 * @package FS LMS
 * @var \Inc\DTO\Enrollment\StudentDataDTO $student_data Расшифрованные данные ученика из заявки
 * @var string                  $join_code    Уникальный хэш-код из URL
 * @var int                     $app_id       ID заявки
 */

use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Services\ThemeCompatService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var StudentDataDTO $student_data */
$student_data  = get_query_var( 'fs_lms_student_data' );
$join_code     = (string) get_query_var( 'fs_lms_join_code', '' );
$app_id        = (int) get_query_var( 'fs_lms_app_id', 0 );
$parent_data   = get_query_var( 'fs_lms_parent_data', null );
$parent_locked = (bool) get_query_var( 'fs_lms_parent_locked', false );

// Текущая дата для ограничения выбора дат рождения и выдачи документов
$max_date = gmdate( 'Y-m-d' );

ThemeCompatService::header();
?>

    <main class="fs-lms-join-page">
        <div class="fs-join-card">
            <h2 class="fs-join-card__title"><?php esc_html_e( 'Завершение регистрации', 'fs-lms' ); ?></h2>
            <p class="fs-join-card__subtitle"><?php esc_html_e( 'Пожалуйста, проверьте данные ученика и заполните анкету законного представителя для формирования договора.', 'fs-lms' ); ?></p>

            <!-- Убран autocomplete="off", чтобы браузер мог предлагать автозаполнение -->
            <form name="fs_lms_join_form" id="fs-lms-join-form" method="post" novalidate autocomplete="on">

                <?php wp_nonce_field( Nonce::ParentSubmit->value, 'security' ); ?>

                <!-- Скрытые технические данные -->
                <input type="hidden" name="join_code" value="<?php echo esc_attr( $join_code ); ?>">
                <input type="hidden" name="app_id" value="<?php echo esc_attr( (string) $app_id ); ?>">

                <!-- ========================================== -->
                <!-- БЛОК 1: Данные ученика (Редактируемые)       -->
                <!-- ========================================== -->
                <fieldset class="fs-join-card__section">
                    <legend class="fs-join-card__section-title"><?php esc_html_e( '1. Данные ученика', 'fs-lms' ); ?></legend>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_student_last_name"><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="student_last_name"
                                id="fs_student_last_name"
                                value="<?php echo esc_attr( $student_data->lastName ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( 'Фамилия ученика', 'fs-lms' ); ?>"
                                required
                                aria-required="true"
                                autocomplete="family-name"
                                autocapitalize="words"
                                autocorrect="off"
                                data-validate="cyrillicName"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_student_first_name"><?php esc_html_e( 'Имя', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="student_first_name"
                                id="fs_student_first_name"
                                value="<?php echo esc_attr( $student_data->firstName ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( 'Имя ученика', 'fs-lms' ); ?>"
                                required
                                aria-required="true"
                                autocomplete="given-name"
                                autocapitalize="words"
                                autocorrect="off"
                                data-validate="cyrillicName"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_student_middle_name"><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="student_middle_name"
                                id="fs_student_middle_name"
                                value="<?php echo esc_attr( $student_data->middleName ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( 'Отчество ученика', 'fs-lms' ); ?>"
                                autocomplete="additional-name"
                                autocapitalize="words"
                                autocorrect="off"
                                data-validate="cyrillicName"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_school"><?php esc_html_e( 'Школа', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="school"
                                id="fs_school"
                                value="<?php echo esc_attr( $student_data->school ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( 'Школа', 'fs-lms' ); ?>"
                                required
                                aria-required="true"
                                autocomplete="organization"
                                autocapitalize="words"
                                data-validate="schoolName"
                                minlength="3"
                                maxlength="100"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_grade"><?php esc_html_e( 'Класс', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                        <select
                                name="grade"
                                id="fs_grade"
                                required
                                aria-required="true"
                                autocomplete="off"
                        >
                            <option value="" disabled <?php selected( empty( $student_data->grade ) ); ?>><?php esc_html_e( 'Выберите класс', 'fs-lms' ); ?></option>
                            <?php
                            $current_grade = $student_data->grade ?? 0;
                            for ( $i = 1; $i <= 11; $i++ ) :
                                ?>
                                <option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( $current_grade, $i ); ?>>
                                    <?php echo esc_html( $i . ' класс' ); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_birth_date"><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <input
                                type="date"
                                name="student_birth_date"
                                id="fs_birth_date"
                                value="<?php echo esc_attr( $student_data->birthDate ?? '' ); ?>"
                                required
                                aria-required="true"
                                autocomplete="bday"
                                max="<?php echo esc_attr( $max_date ); ?>"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_phone"><?php esc_html_e( 'Номер телефона', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-phone" aria-hidden="true"></span>
                        <input
                                type="tel"
                                name="student_phone"
                                id="fs_phone"
                                value="<?php echo esc_attr( $student_data->phone ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( '+7 (999) 000-00-00', 'fs-lms' ); ?>"
                                required
                                aria-required="true"
                                autocomplete="tel"
                                inputmode="tel"
                                data-validate="phone"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_student_doc_type"><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-media-document" aria-hidden="true"></span>
                        <select
                                name="student_doc_type"
                                id="fs_student_doc_type"
                                required
                                aria-required="true"
                                autocomplete="off"
                        >
                            <option value="pass" <?php selected( ( $student_data->docType ?? '' ), 'pass' ); ?>><?php esc_html_e( 'Паспорт', 'fs-lms' ); ?></option>
                            <option value="birth_certificate" <?php selected( ( $student_data->docType ?? '' ), 'birth_certificate' ); ?>><?php esc_html_e( 'Свидетельство о рождении', 'fs-lms' ); ?></option>
                        </select>
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_student_doc_number" id="fs_student_doc_number_label">
                            <?php esc_html_e( 'Данные паспорта ученика', 'fs-lms' ); ?> <span aria-hidden="true">*</span>
                        </label>
                        <span class="dashicons dashicons-vicious" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="student_doc_number"
                                id="fs_student_doc_number"
                                data-validate="passportSN"
                                value="<?php echo esc_attr( $student_data->docNumber ?? '' ); ?>"
                                placeholder="1234 567890"
                                inputmode="numeric"
                                autocomplete="off"
                                autocapitalize="none"
                                autocorrect="off"
                                spellcheck="false"
                                required
                                aria-required="true"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_student_inn"><?php esc_html_e( 'ИНН ученика', 'fs-lms' ); ?> <span aria-hidden="true">*</span></label>
                        <span class="dashicons dashicons-awards" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="student_inn"
                                id="fs_student_inn"
                                value="<?php echo esc_attr( $student_data->inn ?? '' ); ?>"
                                placeholder="<?php esc_attr_e( '12 цифр ИНН', 'fs-lms' ); ?>"
                                data-validate="inn"
                                inputmode="numeric"
                                autocomplete="off"
                                autocapitalize="none"
                                autocorrect="off"
                                spellcheck="false"
                                required
                                aria-required="true"
                                minlength="12"
                                maxlength="12"
                        >
                    </div>
                </fieldset>

                <!-- ========================================== -->
                <!-- БЛОК 2: Данные представителя               -->
                <!-- ========================================== -->
                <?php
                $p_locked      = $parent_locked && is_array( $parent_data );
                $p             = $p_locked ? $parent_data : array();
                $p_last_name   = $p['last_name']    ?? '';
                $p_first_name  = $p['first_name']   ?? '';
                $p_middle_name = $p['middle_name']  ?? '';
                $p_birth_date  = $p['birth_date']   ?? '';
                $p_doc_type    = $p['doc_type']     ?? 'pass';
                $p_doc_number  = $p['doc_number']   ?? '';
                $p_issued_by   = $p['doc_issued_by']  ?? '';
                $p_issued_date = $p['doc_issued_date'] ?? '';
                $p_inn         = $p['inn']          ?? '';
                $p_address     = $p['address']      ?? '';
                $p_phone       = $p['phone']        ?? '';
                $p_email       = $p['email']        ?? '';
                ?>
                <fieldset class="fs-join-card__section">
                    <legend class="fs-join-card__section-title"><?php esc_html_e( '2. Данные законного представителя', 'fs-lms' ); ?></legend>

                    <?php if ( $p_locked ) : ?>
                        <p class="fs-join-card__locked-notice">
                            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                            <?php esc_html_e( 'Данные представителя уже заполнены и зафиксированы.', 'fs-lms' ); ?>
                        </p>
                    <?php endif; ?>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_parent_last_name"><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-businessperson" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="parent_last_name"
                                id="fs_parent_last_name"
                                value="<?php echo esc_attr( $p_last_name ); ?>"
                                placeholder="<?php esc_attr_e( 'Фамилия родителя / представителя', 'fs-lms' ); ?>"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true"<?php endif; ?>
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                                autocomplete="family-name"
                                autocapitalize="words"
                                autocorrect="off"
                                <?php if ( ! $p_locked ) : ?>data-validate="cyrillicName"<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_parent_first_name"><?php esc_html_e( 'Имя', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-businessperson" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="parent_first_name"
                                id="fs_parent_first_name"
                                value="<?php echo esc_attr( $p_first_name ); ?>"
                                placeholder="<?php esc_attr_e( 'Имя родителя / представителя', 'fs-lms' ); ?>"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true"<?php endif; ?>
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                                autocomplete="given-name"
                                autocapitalize="words"
                                autocorrect="off"
                                <?php if ( ! $p_locked ) : ?>data-validate="cyrillicName"<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_parent_middle_name"><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></label>
                        <span class="dashicons dashicons-businessperson" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="parent_middle_name"
                                id="fs_parent_middle_name"
                                value="<?php echo esc_attr( $p_middle_name ); ?>"
                                placeholder="<?php esc_attr_e( 'Отчество родителя / представителя', 'fs-lms' ); ?>"
                                autocomplete="additional-name"
                                autocapitalize="words"
                                autocorrect="off"
                                <?php if ( $p_locked ) : ?>readonly<?php else : ?>data-validate="cyrillicName"<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_parent_birth_date"><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <input
                                type="date"
                                name="parent_birth_date"
                                id="fs_parent_birth_date"
                                value="<?php echo esc_attr( $p_birth_date ); ?>"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true"<?php endif; ?>
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                                autocomplete="bday"
                                max="<?php echo esc_attr( $max_date ); ?>"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_doc_type"><?php esc_html_e( 'Тип документа', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-media-document" aria-hidden="true"></span>
                        <?php if ( $p_locked ) : ?>
                            <input type="hidden" name="doc_type" value="<?php echo esc_attr( $p_doc_type ); ?>">
                            <input type="text" id="fs_doc_type" readonly
                                   value="<?php echo 'foreign_pass' === $p_doc_type ? esc_attr__( 'Иностранный паспорт', 'fs-lms' ) : esc_attr__( 'Паспорт РФ', 'fs-lms' ); ?>">
                        <?php else : ?>
                            <select
                                    name="doc_type"
                                    id="fs_doc_type"
                                    required
                                    aria-required="true"
                                    autocomplete="off"
                            >
                                <option value="pass" selected><?php esc_html_e( 'Паспорт РФ', 'fs-lms' ); ?></option>
                                <option value="foreign_pass"><?php esc_html_e( 'Иностранный паспорт', 'fs-lms' ); ?></option>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_doc_number" id="fs_doc_number_label">
                            <?php esc_html_e( 'Серия и номер паспорта', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?>
                        </label>
                        <span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="doc_number"
                                id="fs_doc_number"
                                value="<?php echo esc_attr( $p_doc_number ); ?>"
                                placeholder="1234 567890"
                                <?php if ( ! $p_locked ) : ?>data-validate="passportSN"<?php endif; ?>
                                inputmode="numeric"
                                autocomplete="off"
                                autocapitalize="none"
                                autocorrect="off"
                                spellcheck="false"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true"<?php endif; ?>
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_doc_issued_by"><?php esc_html_e( 'Кем выдан документ', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-building" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="doc_issued_by"
                                id="fs_doc_issued_by"
                                value="<?php echo esc_attr( $p_issued_by ); ?>"
                                placeholder="<?php esc_attr_e( 'Паспорт выдан', 'fs-lms' ); ?>"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true" data-validate="address" minlength="4"<?php endif; ?>
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                                autocomplete="off"
                                autocapitalize="sentences"
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_doc_issued_date"><?php esc_html_e( 'Дата выдачи документа', 'fs-lms' ); ?></label>
                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <input
                                type="date"
                                name="doc_issued_date"
                                id="fs_doc_issued_date"
                                value="<?php echo esc_attr( $p_issued_date ); ?>"
                                autocomplete="off"
                                max="<?php echo esc_attr( $max_date ); ?>"
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_inn"><?php esc_html_e( 'ИНН', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-awards" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="inn"
                                id="fs_inn"
                                value="<?php echo esc_attr( $p_inn ); ?>"
                                placeholder="<?php esc_attr_e( '12 цифр ИНН', 'fs-lms' ); ?>"
                                <?php if ( ! $p_locked ) : ?>data-validate="inn" required aria-required="true" minlength="12"<?php endif; ?>
                                inputmode="numeric"
                                autocomplete="off"
                                autocapitalize="none"
                                autocorrect="off"
                                spellcheck="false"
                                maxlength="12"
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_address"><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-location" aria-hidden="true"></span>
                        <input
                                type="text"
                                name="address"
                                id="fs_address"
                                value="<?php echo esc_attr( $p_address ); ?>"
                                placeholder="<?php esc_attr_e( 'Адрес регистрации (по паспорту)', 'fs-lms' ); ?>"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true" minlength="5" data-validate="address"<?php endif; ?>
                                autocomplete="off"
                                autocapitalize="sentences"
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_parent_phone"><?php esc_html_e( 'Контактный телефон', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-phone" aria-hidden="true"></span>
                        <input
                                type="tel"
                                name="phone"
                                id="fs_parent_phone"
                                value="<?php echo esc_attr( $p_phone ); ?>"
                                placeholder="+7 (999) 000-00-00"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true" data-validate="phone"<?php endif; ?>
                                autocomplete="tel"
                                inputmode="tel"
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                        >
                    </div>

                    <div class="fs-join-card__field-group fs-form-group">
                        <label for="fs_parent_email"><?php esc_html_e( 'Электронная почта', 'fs-lms' ); ?><?php if ( ! $p_locked ) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                        <span class="dashicons dashicons-email" aria-hidden="true"></span>
                        <input
                                type="email"
                                name="email"
                                id="fs_parent_email"
                                value="<?php echo esc_attr( $p_email ); ?>"
                                placeholder="<?php esc_attr_e( 'Email для уведомлений', 'fs-lms' ); ?>"
                                <?php if ( ! $p_locked ) : ?>required aria-required="true"<?php endif; ?>
                                autocomplete="email"
                                inputmode="email"
                                <?php if ( $p_locked ) : ?>readonly<?php endif; ?>
                        >
                    </div>
                </fieldset>

                <!-- ========================================== -->
                <!-- БЛОК 3: Согласие                           -->
                <!-- ========================================== -->
                <fieldset class="fs-join-card__section fs-join-card__section--consents">
                    <legend class="screen-reader-text"><?php esc_html_e( 'Согласия и подтверждения', 'fs-lms' ); ?></legend>

                    <div class="fs-join-card__consent">
                        <label for="fs_consent_parent">
                            <input type="checkbox" name="consent_parent" id="fs_consent_parent" value="1" required>
                            <span>
							<?php esc_html_e( 'Я даю согласие на обработку персональных данных.', 'fs-lms' ); ?>
							<?php $consent_url = (string) get_query_var( 'fs_lms_consent_url', '' ); ?>
							<?php if ( $consent_url ) : ?>
								<a href="<?php echo esc_url( $consent_url ); ?>" class="button-link" target="_blank" rel="noopener">
									<?php esc_html_e( 'Прочитать', 'fs-lms' ); ?>
								</a>
							<?php endif; ?>
						</span>
                        </label>
                    </div>

                </fieldset>

                <button type="submit" id="fs-join-submit" class="button button-primary button-large fs-join-card__submit">
                    <?php esc_html_e( 'Заключить договор', 'fs-lms' ); ?>
                </button>
            </form>

            <!-- Блок успешного ответа (будет активирован через JS) -->
            <div class="js-join-success-block fs-join-card__success" style="display:none">
                <span class="dashicons dashicons-yes-alt fs-join-card__success-icon"></span>
                <p class="fs-join-card__success-title">
                    <?php esc_html_e( 'Регистрация успешно завершена!', 'fs-lms' ); ?>
                </p>
                <p><?php esc_html_e( 'Договор сформирован. Логин и пароль для доступа в личный кабинет отправлен на указанный Email.', 'fs-lms' ); ?></p>
            </div>
        </div>
    </main>

<?php ThemeCompatService::footer(); ?>


