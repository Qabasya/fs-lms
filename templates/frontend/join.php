<?php
/**
 * Шаблон страницы завершения регистрации родителя и ученика (/lms/join/{code})
 * TODO: прикрутить стили
 *
 * @package FS LMS
 * @var \Inc\DTO\StudentDataDTO $student_data Расшифрованные данные ученика из заявки
 * @var string                  $join_code    Уникальный хэш-код из URL
 * @var int                     $app_id       ID заявки
 */

use Inc\DTO\StudentDataDTO;
use Inc\Enums\Nonce;
use Inc\Enums\RelationType;
use Inc\Services\ThemeCompatService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var StudentDataDTO $student_data */
$student_data = get_query_var( 'fs_lms_student_data' );
$join_code    = (string) get_query_var( 'fs_lms_join_code', '' );
$app_id       = (int) get_query_var( 'fs_lms_app_id', 0 );

ThemeCompatService::header();
?>

	<main class="fs-lms-join-page">
		<div class="fs-join-card">
			<h2 class="fs-join-card__title"><?php esc_html_e( 'Завершение регистрации', 'fs-lms' ); ?></h2>
			<p class="fs-join-card__subtitle"><?php esc_html_e( 'Пожалуйста, проверьте данные ученика и заполните анкету законного представителя для формирования договора.', 'fs-lms' ); ?></p>

			<form name="fs_lms_join_form" id="fs-lms-join-form" method="post" novalidate autocomplete="off">

				<?php wp_nonce_field( Nonce::ParentSubmit->value, 'security' ); ?>

				<!-- Скрытые технические данные -->
				<input type="hidden" name="join_code" value="<?php echo esc_attr( $join_code ); ?>">
				<input type="hidden" name="app_id" value="<?php echo esc_attr( (string) $app_id ); ?>">

				<!-- ========================================== -->
				<!-- БЛОК 1: Данные ученика (Редактируемые)       -->
				<!-- ========================================== -->
				<fieldset class="fs-join-card__section">
					<legend class="fs-join-card__section-title"><?php esc_html_e( '1. Данные ученика', 'fs-lms' ); ?></legend>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-admin-users"></span>
						<input type="text" name="student_last_name" id="fs_student_last_name"
								value="<?php echo esc_attr( $student_data->lastName ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'Фамилия ученика', 'fs-lms' ); ?>" required>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-admin-users"></span>
						<input type="text" name="student_first_name" id="fs_student_first_name"
								value="<?php echo esc_attr( $student_data->firstName ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'Имя ученика', 'fs-lms' ); ?>" required>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-admin-users"></span>
						<input type="text" name="student_middle_name" id="fs_student_middle_name"
								value="<?php echo esc_attr( $student_data->middleName ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'Отчество ученика', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-welcome-learn-more"></span>
						<input type="text" name="school" id="fs_school"
								value="<?php echo esc_attr( $student_data->school ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'Школа', 'fs-lms' ); ?>" required>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-list-view"></span>
						<select name="grade" id="fs_grade" required>
							<option value=""><?php esc_html_e( 'Класс', 'fs-lms' ); ?></option>
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

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-calendar-alt"></span>
						<input type="date" name="student_birth_date" id="fs_birth_date"
								value="<?php echo esc_attr( $student_data->birthDate ?? '' ); ?>" required
								aria-label="<?php esc_attr_e( 'Дата рождения ученика', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-phone"></span>
						<input type="tel" name="student_phone" id="fs_phone"
							value="<?php echo esc_attr( $student_data->phone ?? '' ); ?>"
							placeholder="<?php esc_attr_e( 'Телефон ученика', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-media-document"></span>
						<select name="student_doc_type" id="fs_student_doc_type" required>
							<option value="" disabled selected><?php esc_html_e( 'Тип документа ученика', 'fs-lms' ); ?></option>
							<option value="birth_certificate"><?php esc_html_e( 'Свидетельство о рождении', 'fs-lms' ); ?></option>
							<option value="pass"><?php esc_html_e( 'Паспорт', 'fs-lms' ); ?></option>
						</select>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-vicious"></span>
						<input type="text" name="student_doc_number" id="fs_student_doc_number"
								placeholder="<?php esc_attr_e( 'Серия и номер документа', 'fs-lms' ); ?>" required>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-awards"></span>
						<input type="text" name="student_inn" id="fs_student_inn" placeholder="<?php esc_attr_e( 'ИНН ученика', 'fs-lms' ); ?>">
					</div>
				</fieldset>

				<!-- ========================================== -->
				<!-- БЛОК 2: Данные представителя               -->
				<!-- ========================================== -->
				<fieldset class="fs-join-card__section">
					<legend class="fs-join-card__section-title"><?php esc_html_e( '2. Данные законного представителя', 'fs-lms' ); ?></legend>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-businessperson"></span>
						<input type="text" name="parent_last_name" id="fs_parent_last_name"
								placeholder="<?php esc_attr_e( 'Фамилия родителя / представителя', 'fs-lms' ); ?>" required>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-businessperson"></span>
						<input type="text" name="parent_first_name" id="fs_parent_first_name"
								placeholder="<?php esc_attr_e( 'Имя родителя / представителя', 'fs-lms' ); ?>" required>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-businessperson"></span>
						<input type="text" name="parent_middle_name" id="fs_parent_middle_name"
								placeholder="<?php esc_attr_e( 'Отчество родителя / представителя', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-calendar-alt"></span>
						<input type="date" name="parent_birth_date" id="fs_parent_birth_date" required aria-label="<?php esc_attr_e( 'Дата рождения родителя', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-groups"></span>
						<select name="relation_type" id="fs_relation_type" required>
							<option value="" disabled selected><?php esc_html_e( 'Кем приходитесь ученику?', 'fs-lms' ); ?></option>
							<?php if ( class_exists( 'Inc\Enums\RelationType' ) && method_exists( RelationType::class, 'cases' ) ) : ?>
								<?php foreach ( RelationType::cases() as $relation ) : ?>
									<option value="<?php echo esc_attr( $relation->value ); ?>">
										<?php echo esc_html( method_exists( $relation, 'label' ) ? $relation->label() : $relation->name ); ?>
									</option>
								<?php endforeach; ?>
							<?php else : ?>
								<!-- Резервный вариант, если Enum устроен иначе -->
								<option value="mother"><?php esc_html_e( 'Мать', 'fs-lms' ); ?></option>
								<option value="father"><?php esc_html_e( 'Отец', 'fs-lms' ); ?></option>
								<option value="guardian"><?php esc_html_e( 'Опекун', 'fs-lms' ); ?></option>
							<?php endif; ?>
						</select>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-media-document"></span>
						<select name="doc_type" id="fs_doc_type" required aria-label="<?php esc_attr_e( 'Тип документа представителя', 'fs-lms' ); ?>">
							<option value="" disabled selected><?php esc_html_e( 'Тип документа (паспорт)', 'fs-lms' ); ?></option>
							<option value="pass"><?php esc_html_e( 'Паспорт РФ', 'fs-lms' ); ?></option>
							<option value="foreign_pass"><?php esc_html_e( 'Иностранный паспорт', 'fs-lms' ); ?></option>
						</select>
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-id-alt"></span>
						<input type="text" name="doc_number" id="fs_doc_number"
								placeholder="<?php esc_attr_e( 'Серия и номер', 'fs-lms' ); ?>" required
								aria-label="<?php esc_attr_e( 'Серия и номер документа', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-building"></span>
						<input type="text" name="doc_issued_by" id="fs_doc_issued_by"
								placeholder="<?php esc_attr_e( 'Кем выдан (необязательно)', 'fs-lms' ); ?>"
								aria-label="<?php esc_attr_e( 'Кем выдан документ', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-calendar-alt"></span>
						<input type="date" name="doc_issued_date" id="fs_doc_issued_date"
								aria-label="<?php esc_attr_e( 'Дата выдачи документа (необязательно)', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-awards"></span>
						<input type="text" name="inn" id="fs_inn"
							placeholder="<?php esc_attr_e( 'ИНН представителя', 'fs-lms' ); ?>" required
							aria-label="<?php esc_attr_e( 'ИНН представителя', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-location"></span>
						<input type="text" name="address" id="fs_address"
							placeholder="<?php esc_attr_e( 'Адрес регистрации (по паспорту)', 'fs-lms' ); ?>" required
							aria-label="<?php esc_attr_e( 'Адрес регистрации', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-phone"></span>
						<input type="tel" name="phone" id="fs_parent_phone"
							placeholder="<?php esc_attr_e( 'Контактный телефон', 'fs-lms' ); ?>" required
							aria-label="<?php esc_attr_e( 'Контактный телефон представителя', 'fs-lms' ); ?>">
					</div>

					<div class="fs-join-card__field-group">
						<span class="dashicons dashicons-email"></span>
						<input type="email" name="email" id="fs_parent_email"
								placeholder="<?php esc_attr_e( 'Email для уведомлений', 'fs-lms' ); ?>" required
								aria-label="<?php esc_attr_e( 'Email для уведомлений', 'fs-lms' ); ?>">
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
							<a href="<?php echo esc_url( site_url( '/lms/consent/pd_processing/v1' ) ); ?>" class="button-link" target="_blank" rel="noopener">
								<?php esc_html_e( 'Прочитать', 'fs-lms' ); ?>
							</a>
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