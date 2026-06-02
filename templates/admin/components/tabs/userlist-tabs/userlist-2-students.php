<?php
/**
 * Таб "Ученики" — таблица зачисленных учеников.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Capability;
use Inc\Enums\DocumentType;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\RelationType;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\PiiCryptoService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$enrollmentRepo = new EnrollmentRepository();
$personRepo     = new PersonRepository();
$groupRepo      = new StudentGroupRepository();
$subjectRepo    = new SubjectRepository();
$crypto         = new PiiCryptoService();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$filters     = array( 'status' => EnrollmentStatus::Active->value );
$enrollments = $enrollmentRepo->list( $filters, $page, $perPage );
$total       = $enrollmentRepo->count( $filters );
$pages       = (int) ceil( $total / $perPage );

?>

<div class="fs-lms-students">

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="column-title column-primary">
				<?php esc_html_e( 'ФИО ученика', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Телефон', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Предмет', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Группа', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Расписание', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Номер договора', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Действия', 'fs-lms' ); ?>
			</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $enrollments ) ) : ?>
			<tr>
				<td colspan="7">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Зачисленных учеников пока нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>

		<?php else : ?>
			<?php foreach ( $enrollments as $row ) :
				$studentPersonId = (int) $row['student_person_id'];
				$groupId         = (string) $row['group_id'];
				$subjectKey      = (string) $row['subject_key'];

				// Имя ученика из WP-пользователя
				$studentName = '—';
				$person      = $personRepo->find( $studentPersonId );
				if ( $person && $person->wpUserId ) {
					$wpUser      = get_userdata( $person->wpUserId );
					$studentName = $wpUser ? $wpUser->display_name : '—';
				}

				// Группа
				$groupTitle = '—';
				$group      = $groupRepo->getById( $groupId );
				if ( $group ) {
					$groupTitle = $group->title;
				}

				// Направление
				$subjectName = $subjectKey;
				$subject     = $subjectRepo->getByKey( $subjectKey );
				if ( $subject ) {
					$subjectName = $subject->name;
				}

				// Расшифровка снапшота
				$snapshot    = array();
				$contractNo  = '—';
				if ( ! empty( $row['snapshot_enc'] ) ) {
					try {
						$snapshot   = json_decode( $crypto->decrypt( $row['snapshot_enc'] ), true ) ?? array();
						$contractNo = $snapshot['contract_no'] ?? '—';
					} catch ( \Throwable $e ) {
						// snapshot недоступен
					}
				}

				$sd            = $snapshot['student']  ?? array();
				$gd            = $snapshot['guardian'] ?? array();
				$studentPhone  = $sd['phone'] ?? '';

				$enrollmentData = array(
					'subject'                  => $subjectName,
					'group'                    => $groupTitle,
					'contract_no'              => $snapshot['contract_no']   ?? '',
					'contract_date'            => $snapshot['contract_date'] ?? '',
					'order_no'                 => $snapshot['order_no']      ?? '',
					'order_date'               => $snapshot['order_date']    ?? '',
					'enrolled_at'              => substr( (string) $row['enrolled_at'], 0, 10 ),
					'student_full_name'        => $sd['full_name']  ?? $studentName,
					'student_birth_date'       => $sd['birth_date'] ?? '',
					'student_email'            => $sd['email']      ?? '',
					'student_phone'            => $sd['phone']      ?? '',
					'student_school'           => $sd['school']     ?? '',
					'student_grade'            => isset( $sd['grade'] ) ? (string) $sd['grade'] : '',
					'student_doc_type'         => DocumentType::tryFrom( $sd['doc_type'] ?? '' )?->label() ?? ( $sd['doc_type'] ?? '' ),
					'student_doc_number'       => $sd['doc_number'] ?? '',
					'student_inn'              => $sd['inn']        ?? '',
					'guardian_full_name'       => $gd['full_name']      ?? '',
					'guardian_relation_type'   => RelationType::tryFrom( $gd['relation_type'] ?? '' )?->label() ?? ( $gd['relation_type'] ?? '' ),
					'guardian_birth_date'      => $gd['birth_date']      ?? '',
					'guardian_email'           => $gd['email']           ?? '',
					'guardian_phone'           => $gd['phone']           ?? '',
					'guardian_doc_type'        => DocumentType::tryFrom( $gd['doc_type'] ?? '' )?->label() ?? ( $gd['doc_type'] ?? '' ),
					'guardian_doc_number'      => $gd['doc_number']      ?? '',
					'guardian_doc_issued_by'   => $gd['doc_issued_by']   ?? '',
					'guardian_doc_issued_date' => $gd['doc_issued_date'] ?? '',
					'guardian_inn'             => $gd['inn']             ?? '',
					'guardian_address'         => $gd['address']         ?? '',
				);
			?>
			<tr data-enrollment="<?php echo esc_attr( (string) wp_json_encode( $enrollmentData ) ); ?>">

				<td class="column-title">
					<?php echo esc_html( $studentName ); ?>
				</td>

				<td>
					<?php if ( $studentPhone ) : ?>
						<?php echo esc_html( $studentPhone ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $subjectName ); ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $groupTitle ); ?>
				</td>

				<td>
					<span class="fs-table__empty-value">—</span>
				</td>

				<td>
					<?php if ( '—' !== $contractNo ) : ?>
						<?php echo esc_html( $contractNo ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#"
							   class="js-view-person"
							   data-person-id="<?php echo esc_attr( (string) $studentPersonId ); ?>"
							   data-wp-user-id="<?php echo esc_attr( (string) ( $person?->wpUserId ?? 0 ) ); ?>"
							   data-person-type="student"
							   data-display-name="<?php echo esc_attr( $studentName ); ?>"
							   data-email="<?php echo esc_attr( $person?->email ?? '' ); ?>">
								<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
							</a>
						</span>
					</div>
				</td>

			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php for ( $p = 1; $p <= $pages; $p++ ) :
					$url = add_query_arg( array( 'paged' => $p ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>"
						class="button button-small <?php echo $p === $page ? 'button-primary' : ''; ?>">
						<?php echo esc_html( (string) $p ); ?>
					</a>
				<?php endfor; ?>
			</div>
		</div>
	<?php endif; ?>

</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/student-person-modal.php'; ?>
