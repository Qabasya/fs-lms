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
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\PiiCryptoService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$recordRepo  = new StudentRecordRepository();
$personRepo  = new PersonRepository();
$docsRepo    = new PersonDocumentsRepository();
$groupRepo   = new GroupsRepository();
$subjectRepo = new SubjectRepository();
$crypto      = new PiiCryptoService();

$page         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage      = 20;
$statusFilter = sanitize_key( $_GET['arc_status'] ?? '' );

$terminalStatuses = array(
	EnrollmentStatus::Expelled->value,
	EnrollmentStatus::Finished->value,
	EnrollmentStatus::Transferred->value,
);

$allStatuses = array_merge( array( EnrollmentStatus::Active->value ), $terminalStatuses );

if ( '' === $statusFilter || ! in_array( $statusFilter, array_column( EnrollmentStatus::cases(), 'value' ), true ) ) {
	$filters = array( 'status' => $allStatuses );
} else {
	$filters = array( 'status' => array( $statusFilter ) );
}

$records = $recordRepo->list( $filters, $page, $perPage );
$total   = $recordRepo->count( $filters );
$pages   = (int) ceil( $total / $perPage );

$allSubjects = array();
foreach ( $subjectRepo->readAll() as $dto ) {
	$allSubjects[ $dto->key ] = $dto->name;
}

$baseUrl      = add_query_arg( array( 'page' => 'fs_lms_userlist', 'tab' => 'tab-5' ), admin_url( 'admin.php' ) );
$statusLabels = array(
	''                              => 'Все',
	EnrollmentStatus::Active->value      => 'Обучается',
	EnrollmentStatus::Finished->value    => 'Завершено',
	EnrollmentStatus::Transferred->value => 'Переведён',
	EnrollmentStatus::Expelled->value    => 'Отчислен',
);

?>

<div class="fs-lms-archive">

	<!-- Фильтры по статусу -->
	<ul class="subsubsub">
		<?php
		$filterKeys = array_keys( $statusLabels );
		$lastKey    = end( $filterKeys );
		foreach ( $statusLabels as $val => $label ) :
			$url      = '' === $val
				? $baseUrl
				: add_query_arg( array( 'arc_status' => $val ), $baseUrl );
			$isCurrent = $statusFilter === $val;
			$countFilters = '' === $val
				? array( 'status' => $allStatuses )
				: array( 'status' => array( $val ) );
			$cnt = $recordRepo->count( $countFilters );
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>"
					class="<?php echo $isCurrent ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?>
					<span class="count">(<?php echo esc_html( (string) $cnt ); ?>)</span>
				</a><?php echo $val !== $lastKey ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="column-title column-primary">
				<?php esc_html_e( 'ФИО ученика', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Статус', 'fs-lms' ); ?>
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
						<p><?php esc_html_e( 'Записей нет.', 'fs-lms' ); ?></p>
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
				$isTerminal      = $status->isTerminal();

				$person      = $personRepo->find( $studentPersonId );
				$sDocs       = $docsRepo->findByPersonId( $studentPersonId );

				if ( $person !== null ) {
					if ( $person->wpUserId ) {
						$wpUser      = get_userdata( $person->wpUserId );
						$studentName = $wpUser ? $wpUser->display_name : $person->fullName();
					} else {
						$studentName = $person->fullName() ?: "Person #{$studentPersonId}";
					}
				} else {
					$studentName = trim(
						$row->snapshotLastName . ' ' .
						$row->snapshotFirstName . ' ' .
						( $row->snapshotMiddleName ?? '' )
					) ?: "Person #{$studentPersonId}";
				}

				$groupTitle  = '—';
				$subjectName = '—';
				$group       = $groupId ? $groupRepo->findById( $groupId ) : null;
				if ( $group !== null ) {
					$groupTitle  = $group->name;
					$subjectName = $allSubjects[ $group->subject_key ] ?? $group->subject_key;
				}

				$enrollmentData = array(
					'archive_id'        => $row->id,
					'parent_person_id'  => $row->parentPersonId > 0 ? $row->parentPersonId : null,
					'subject'           => $subjectName,
					'group'             => $groupTitle,
					'status_label'      => $status->label(),
					'terminated_at'     => $expelledAt,
					'terminated_reason' => $expelReason,
					'contract_no'       => $row->contractNo   ?? '',
					'contract_date'     => $row->contractDate ?? '',
					'order_no'          => $row->orderNo      ?? '',
					'order_date'        => $row->orderDate    ?? '',
					'student'           => ( function () use ( $person, $row, $sDocs, $crypto ): array {
						$s = array(
							'last_name'   => $person?->lastName   ?? $row->snapshotLastName,
							'first_name'  => $person?->firstName  ?? $row->snapshotFirstName,
							'middle_name' => $person?->middleName ?? ( $row->snapshotMiddleName ?? '' ),
							'birth_date'  => $person?->birthDate  ?? '',
							'email'       => '',
							'phone'       => '',
							'school'      => $person?->school ?? ( $row->snapshotSchool ?? '' ),
							'grade'       => $person?->grade  ?? ( $row->snapshotGrade  ?? '' ),
							'doc_type'    => $sDocs?->docType ?? '',
							'doc_number'  => '',
							'inn'         => '',
						);
						if ( $sDocs ) {
							foreach ( array(
								'email'      => $sDocs->emailEnc,
								'phone'      => $sDocs->phoneEnc,
								'doc_number' => $sDocs->docNumberEnc,
								'inn'        => $sDocs->innEnc,
							) as $key => $enc ) {
								if ( ! $enc ) { continue; }
								try { $s[ $key ] = $crypto->decrypt( $enc ); } catch ( \Throwable ) {}
							}
						}
						return $s;
					} )(),
					'guardian'          => array(
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

				<td>
					<span class="fs-status-badge fs-status-badge--<?php echo esc_attr( $status->value ); ?>">
						<?php echo esc_html( $status->label() ); ?>
					</span>
				</td>

				<td class="column-title">
					<?php echo esc_html( $subjectName ); ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $groupTitle ); ?>
				</td>

				<td>
					<?php echo $expelledAt ? esc_html( $expelledAt ) : '<span class="fs-table__empty-value">—</span>'; ?>
				</td>

				<td>
					<?php echo $expelReason ? esc_html( $expelReason ) : '<span class="fs-table__empty-value">—</span>'; ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#" class="js-view-archive">
								<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
							</a>
						</span>
						<span class="restore"> |
							<a href="#"
								class="js-restore-from-archive"
								data-archive-id="<?php echo esc_attr( (string) $row->id ); ?>"
								data-has-parent="<?php echo $row->parentPersonId > 0 ? '1' : '0'; ?>">
								<?php esc_html_e( 'Вернуть в заявки', 'fs-lms' ); ?>
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
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/restore-archive-modal.php'; ?>
