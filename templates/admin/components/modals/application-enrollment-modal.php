<?php
/**
 * Модальное окно зачисления (статус Enrolling).
 * Открывается из userlist-1-applications.php по клику на .js-enrollment-application.
 *
 * @package FS LMS
 */

use Inc\Enums\OptionName;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$periodRepo      = new AcademicPeriodRepository();
$allPeriods      = $periodRepo->readAll();
$currentPeriod   = $periodRepo->getCurrentPeriod();
$currentPeriodId = $currentPeriod ? $currentPeriod->id : '';

$periodsJson = (string) wp_json_encode(
	array_values( array_map(
		static fn( $p ) => array( 'id' => $p['id'], 'name' => $p['name'], 'is_current' => $p['is_current'] ?? false ),
		$allPeriods
	) )
);

$subjectsRaw  = get_option( OptionName::Subjects->value, array() );
$subjectsJson = (string) wp_json_encode(
	array_values( array_map(
		static fn( $s ) => array( 'key' => $s['key'] ?? '', 'name' => $s['name'] ?? '' ),
		is_array( $subjectsRaw ) ? $subjectsRaw : array()
	) )
);

$today = current_time( 'Y-m-d' );
?>

<div id="fs-application-enrollment-modal"
	class="fs-lms-modal hidden"
	data-periods="<?php echo esc_attr( $periodsJson ); ?>"
	data-subjects="<?php echo esc_attr( $subjectsJson ); ?>"
	data-current-period="<?php echo esc_attr( $currentPeriodId ); ?>">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Зачисление', 'fs-lms' ); ?> <span id="enrollment-modal-id"></span></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<!-- Основное содержимое -->
			<div id="enrollment-main-content">
				<form id="fs-application-enrollment-form" autocomplete="off">
					<input type="hidden" name="application_id" value="">

					<div class="fs-modal-accordion">

						<!-- Секция 1: Данные ученика -->
						<div class="fs-modal-accordion__item">
							<button type="button" class="fs-modal-accordion__header" aria-expanded="true" aria-controls="enroll-acc-student">
								<h3><?php esc_html_e( 'Данные ученика', 'fs-lms' ); ?></h3>
								<span class="dashicons dashicons-arrow-down-alt2"></span>
							</button>
							<div class="fs-modal-accordion__body" id="enroll-acc-student">
								<div class="fs-detail-grid">
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_last_name">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Имя', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_first_name">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_middle_name">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_birth_date">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Email', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_email">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_phone">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Школа', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_school">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Класс', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_grade">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Документ', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_doc">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="s_inn">—</span></div>
								</div>
							</div>
						</div>

						<!-- Секция 2: Данные родителя -->
						<div class="fs-modal-accordion__item">
							<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="enroll-acc-parent">
								<h3><?php esc_html_e( 'Данные родителя', 'fs-lms' ); ?></h3>
								<span class="dashicons dashicons-arrow-down-alt2"></span>
							</button>
							<div class="fs-modal-accordion__body" id="enroll-acc-parent" hidden>
								<div class="fs-detail-grid">
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Фамилия', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_last_name">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Имя', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_first_name">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Отчество', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_middle_name">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Дата рождения', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_birth_date">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Роль', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_relation_type">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Email', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_email">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_phone">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'Документ', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_doc">—</span></div>
									<div class="fs-detail-row"><span class="fs-detail-label"><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_inn">—</span></div>
									<div class="fs-detail-row fs-detail-row--full"><span class="fs-detail-label"><?php esc_html_e( 'Адрес', 'fs-lms' ); ?></span><span class="fs-detail-value" data-field="p_address">—</span></div>
								</div>
							</div>
						</div>

						<!-- Секция 3: Данные зачисления -->
						<div class="fs-modal-accordion__item">
							<button type="button" class="fs-modal-accordion__header" aria-expanded="false" aria-controls="enroll-acc-form">
								<h3><?php esc_html_e( 'Данные зачисления', 'fs-lms' ); ?></h3>
								<span class="dashicons dashicons-arrow-down-alt2"></span>
							</button>
							<div class="fs-modal-accordion__body" id="enroll-acc-form" hidden>

								<div class="fs-form-row">
									<div class="fs-form-group">
										<label for="enroll-contract-no"><?php esc_html_e( 'Номер договора', 'fs-lms' ); ?></label>
										<input type="text" id="enroll-contract-no" name="contract_no"
											placeholder="<?php esc_attr_e( 'б/н', 'fs-lms' ); ?>">
									</div>
									<div class="fs-form-group">
										<label for="enroll-contract-date"><?php esc_html_e( 'Дата договора', 'fs-lms' ); ?></label>
										<input type="date" id="enroll-contract-date" name="contract_date"
											value="<?php echo esc_attr( $today ); ?>" required>
									</div>
								</div>

								<div class="fs-form-row">
									<div class="fs-form-group">
										<label for="enroll-order-no"><?php esc_html_e( 'Номер приказа', 'fs-lms' ); ?></label>
										<input type="text" id="enroll-order-no" name="order_no"
											placeholder="<?php esc_attr_e( 'б/н', 'fs-lms' ); ?>">
									</div>
									<div class="fs-form-group">
										<label for="enroll-order-date"><?php esc_html_e( 'Дата приказа', 'fs-lms' ); ?></label>
										<input type="date" id="enroll-order-date" name="order_date"
											value="<?php echo esc_attr( $today ); ?>" required>
									</div>
								</div>

								<div class="fs-form-group">
									<label for="enroll-period"><?php esc_html_e( 'Учебный период', 'fs-lms' ); ?></label>
									<select id="enroll-period" name="period_key" required>
										<option value=""><?php esc_html_e( '— Выберите период —', 'fs-lms' ); ?></option>
									</select>
								</div>

								<div class="fs-form-group">
									<label for="enroll-subject"><?php esc_html_e( 'Предмет', 'fs-lms' ); ?></label>
									<select id="enroll-subject" name="subject_key" required>
										<option value=""><?php esc_html_e( '— Выберите предмет —', 'fs-lms' ); ?></option>
									</select>
								</div>

								<div class="fs-form-group">
									<label for="enroll-group"><?php esc_html_e( 'Группа', 'fs-lms' ); ?></label>
									<select id="enroll-group" name="group_id" required disabled>
										<option value=""><?php esc_html_e( '— Сначала выберите период и предмет —', 'fs-lms' ); ?></option>
									</select>
								</div>


							</div>
						</div>

					</div><!-- /.fs-modal-accordion -->
				</form>
			</div><!-- /#enrollment-main-content -->

			</div><!-- /.fs-lms-modal-body -->

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel"><?php esc_html_e( 'Закрыть', 'fs-lms' ); ?></button>
			<button type="submit" form="fs-application-enrollment-form" class="button button-primary" id="enrollment-modal-enroll-btn">
				<?php esc_html_e( 'Зачислить', 'fs-lms' ); ?>
			</button>
		</div>

	</div>
</div>