<?php
/**
 * Модальное окно временного доступа ученика до зачисления: выбор группы.
 * Открывается из userlist-1-applications.php по клику на .js-grant-trial.
 *
 * @package FS LMS
 */

use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Application\JoinCodeService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$trialPeriodRepo = new AcademicPeriodRepository();
$trialPeriod     = $trialPeriodRepo->getCurrentPeriod();

$trialPeriodsJson = (string) wp_json_encode(
	array_values( array_map(
		static fn( $p ) => array( 'id' => $p['id'], 'name' => $p['name'] ),
		$trialPeriodRepo->readAll()
	) )
);

$trialSubjectsJson = (string) wp_json_encode(
	array_values( array_map(
		static fn( $s ) => array( 'key' => $s->key, 'name' => $s->name ),
		( new SubjectRepository() )->readAll()
	) )
);
?>

<div id="fs-trial-access-modal"
	class="fs-lms-modal hidden"
	data-periods="<?php echo esc_attr( $trialPeriodsJson ); ?>"
	data-subjects="<?php echo esc_attr( $trialSubjectsJson ); ?>"
	data-current-period="<?php echo esc_attr( $trialPeriod ? $trialPeriod->id : '' ); ?>">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Временный доступ', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<p id="trial-access-student" class="fs-mb-md"></p>
			<p class="fs-text-muted fs-mb-md">
				<?php
				echo esc_html( sprintf(
					/* translators: %d: срок заявки в днях */
					__( 'Ученик войдёт по логину и паролю из заявки и будет заниматься в группе до зачисления. Доступ снимается, если заявка истечёт (%d дней без родителя) или уйдёт в корзину; при зачислении он заменяется обычной записью.', 'fs-lms' ),
					JoinCodeService::APPLICATION_TTL_DAYS
				) );
				?>
			</p>

			<form id="fs-trial-access-form" autocomplete="off">
				<input type="hidden" name="application_id" value="">

				<div class="fs-form-group">
					<label for="trial-period"><?php esc_html_e( 'Учебный период', 'fs-lms' ); ?></label>
					<select id="trial-period" name="period_key" required></select>
				</div>

				<div class="fs-form-group">
					<label for="trial-subject"><?php esc_html_e( 'Предмет', 'fs-lms' ); ?></label>
					<select id="trial-subject" name="subject_key" required></select>
				</div>

				<div class="fs-form-group">
					<label for="trial-group"><?php esc_html_e( 'Группа', 'fs-lms' ); ?></label>
					<select id="trial-group" name="group_id" required disabled>
						<option value=""><?php esc_html_e( '— Сначала выберите период и предмет —', 'fs-lms' ); ?></option>
					</select>
				</div>
			</form>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel"><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
			<button type="submit" form="fs-trial-access-form" class="button button-primary" id="trial-access-submit">
				<?php esc_html_e( 'Выдать доступ', 'fs-lms' ); ?>
			</button>
		</div>
	</div>
</div>
