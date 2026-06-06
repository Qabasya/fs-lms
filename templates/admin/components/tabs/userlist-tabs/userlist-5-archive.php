<?php
/**
 * Таб "Архив" — ученики с завершёнными/отчисленными зачислениями.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Capability;
use Inc\Enums\EnrollmentStatus;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$recordRepo  = new StudentRecordRepository();
$personRepo  = new PersonRepository();
$groupRepo   = new GroupsRepository();
$subjectRepo = new SubjectRepository();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$filters = array( 'status' => array(
	EnrollmentStatus::Expelled->value,
	EnrollmentStatus::Finished->value,
	EnrollmentStatus::Transferred->value,
) );

$records = $recordRepo->list( $filters, $page, $perPage );
$total   = $recordRepo->count( $filters );
$pages   = (int) ceil( $total / $perPage );

$allSubjects = array();
foreach ( $subjectRepo->readAll() as $dto ) {
	$allSubjects[ $dto->key ] = $dto->name;
}

?>

<div class="fs-lms-archive">

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="column-title column-primary">
				<?php esc_html_e( 'ФИО ученика', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Направление', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Группа', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Дата завершения', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Причина', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Действия', 'fs-lms' ); ?>
			</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $records ) ) : ?>
			<tr>
				<td colspan="7">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Архив пуст.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>

		<?php else : ?>
			<?php foreach ( $records as $row ) :
				$studentPersonId = $row->studentPersonId;
				$groupId         = (int) ( $row->groupId ?? 0 );
				$status          = $row->status;
				$expelledAt      = $row->expelledAt ? substr( $row->expelledAt, 0, 10 ) : '';
				$expelReason     = (string) ( $row->expelReason ?? '' );

				// Имя ученика: WP-пользователь → PersonDTO
				$studentName = '—';
				$person      = $personRepo->find( $studentPersonId );
				if ( $person !== null ) {
					if ( $person->wpUserId ) {
						$wpUser      = get_userdata( $person->wpUserId );
						$studentName = $wpUser ? $wpUser->display_name : $person->fullName();
					} else {
						$studentName = $person->fullName() ?: "Person #{$studentPersonId}";
					}
				}

				// Группа и направление
				$groupTitle  = '—';
				$subjectName = '—';
				$group       = $groupId ? $groupRepo->findById( $groupId ) : null;
				if ( $group !== null ) {
					$groupTitle  = $group->name;
					$subjectName = $allSubjects[ $group->subject_key ] ?? $group->subject_key;
				}

				// Данные для модалки
				$enrollmentData = array(
					'archive_id'      => $row->id,
					'subject'         => $subjectName,
					'group'           => $groupTitle,
					'status_label'    => $status->label(),
					'terminated_at'   => $expelledAt,
					'terminated_reason' => $expelReason,
					'contract_no'     => $row->contractNo   ?? '',
					'contract_date'   => $row->contractDate ?? '',
					'order_no'        => $row->orderNo      ?? '',
					'order_date'      => $row->orderDate    ?? '',
					'student'         => array(
						'last_name'   => $person?->lastName   ?? '',
						'first_name'  => $person?->firstName  ?? '',
						'middle_name' => $person?->middleName ?? '',
						'birth_date'  => $person?->birthDate  ?? '',
						'email'       => '',
						'phone'       => '',
						'school'      => '',
						'grade'       => '',
						'doc_type'    => '',
						'doc_number'  => '',
						'inn'         => '',
					),
					'guardian'        => array(
						'last_name'       => '',
						'first_name'      => '',
						'middle_name'     => '',
						'birth_date'      => '',
						'email'           => '',
						'phone'           => '',
						'doc_type'        => '',
						'doc_number'      => '',
						'doc_issued_by'   => '',
						'doc_issued_date' => '',
						'inn'             => '',
						'address'         => '',
					),
				);
			?>
			<tr data-enrollment="<?php echo esc_attr( (string) wp_json_encode( $enrollmentData ) ); ?>">

				<td class="column-title">
					<?php echo esc_html( $studentName ); ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $subjectName ); ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $groupTitle ); ?>
				</td>

				<td>
					<?php if ( $expelledAt ) : ?>
						<?php echo esc_html( $expelledAt ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td>
					<?php if ( $expelReason ) : ?>
						<?php echo esc_html( $expelReason ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#" class="js-view-archive">
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

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/archive-view-modal.php'; ?>
