<?php
/**
 * Таб "Архив" — ученики с завершёнными/отчисленными зачислениями.
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

$filters = array( 'status' => array(
	EnrollmentStatus::Expelled->value,
	EnrollmentStatus::Finished->value,
	EnrollmentStatus::Transferred->value,
) );

$enrollments = $enrollmentRepo->list( $filters, $page, $perPage );
$total       = $enrollmentRepo->count( $filters );
$pages       = (int) ceil( $total / $perPage );

// Все предметы один раз
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
		<?php if ( empty( $enrollments ) ) : ?>
			<tr>
				<td colspan="7">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Архив пуст.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>

		<?php else : ?>
			<?php foreach ( $enrollments as $row ) :
				$studentPersonId  = $row->studentPersonId;
				$groupId          = (string) ( $row->groupId ?? '' );
				$subjectKey       = $row->subjectKey;
				$status           = $row->status;
				$terminatedAt     = $row->terminatedAt ? substr( $row->terminatedAt, 0, 10 ) : '';
				$terminatedReason = (string) ( $row->terminatedReason ?? '' );

				// Расшифровка снапшота (до имени — оно может быть только здесь после отчисления)
				$snapshot = array();
				if ( ! empty( $row->snapshotEnc ) ) {
					try {
						$snapshot = json_decode( $crypto->decrypt( $row->snapshotEnc ), true ) ?? array();
					} catch ( \Throwable $e ) {
						// snapshot недоступен
					}
				}

				$sd = $snapshot['student']  ?? array();
				$gd = $snapshot['guardian'] ?? array();

				// Имя ученика: WP-пользователь → снапшот (после отчисления пользователь удалён)
				$studentName = '—';
				$person      = $personRepo->find( $studentPersonId );
				if ( $person && $person->wpUserId ) {
					$wpUser      = get_userdata( $person->wpUserId );
					$studentName = $wpUser ? $wpUser->display_name : '—';
				}
				if ( $studentName === '—' ) {
					$fromSnapshot = trim( implode( ' ', array_filter( [
						$sd['last_name']   ?? '',
						$sd['first_name']  ?? '',
						$sd['middle_name'] ?? '',
					] ) ) );
					if ( $fromSnapshot === '' ) {
						$fromSnapshot = trim( (string) ( $sd['full_name'] ?? '' ) );
					}
					if ( $fromSnapshot !== '' ) {
						$studentName = $fromSnapshot;
					}
				}

				// Группа
				$groupTitle = '—';
				$group      = $groupRepo->getById( $groupId );
				if ( $group ) {
					$groupTitle = $group->title;
				}

				// Направление
				$subjectName = $allSubjects[ $subjectKey ] ?? $subjectKey;

				// Разбить full_name на части (обратная совместимость)
				$sParts = explode( ' ', $sd['full_name'] ?? '', 3 );
				$gParts = explode( ' ', $gd['full_name'] ?? '', 3 );

				$enrollmentData = array(
					'subject'         => $subjectName,
					'group'           => $groupTitle,
					'status_label'    => $status->label(),
					'terminated_at'   => $terminatedAt,
					'terminated_reason' => $terminatedReason,
					'contract_no'     => $snapshot['contract_no']   ?? '',
					'contract_date'   => $snapshot['contract_date'] ?? '',
					'order_no'        => $snapshot['order_no']      ?? '',
					'order_date'      => $snapshot['order_date']    ?? '',
					'student'         => array(
						'last_name'   => $sd['last_name']   ?? $sParts[0] ?? '',
						'first_name'  => $sd['first_name']  ?? $sParts[1] ?? '',
						'middle_name' => $sd['middle_name'] ?? $sParts[2] ?? '',
						'birth_date'  => $sd['birth_date']  ?? '',
						'email'       => $sd['email']       ?? '',
						'phone'       => $sd['phone']       ?? '',
						'school'      => $sd['school']      ?? '',
						'grade'       => isset( $sd['grade'] ) ? (string) $sd['grade'] : '',
						'doc_type'    => DocumentType::tryFrom( $sd['doc_type'] ?? '' )?->label() ?? ( $sd['doc_type'] ?? '' ),
						'doc_number'  => $sd['doc_number']  ?? '',
						'inn'         => $sd['inn']         ?? '',
					),
					'guardian'        => array(
						'last_name'       => $gd['last_name']   ?? $gParts[0] ?? '',
						'first_name'      => $gd['first_name']  ?? $gParts[1] ?? '',
						'middle_name'     => $gd['middle_name'] ?? $gParts[2] ?? '',
						'birth_date'      => $gd['birth_date']      ?? '',
						'relation_type'   => RelationType::tryFrom( $gd['relation_type'] ?? '' )?->label() ?? ( $gd['relation_type'] ?? '' ),
						'email'           => $gd['email']           ?? '',
						'phone'           => $gd['phone']           ?? '',
						'doc_type'        => DocumentType::tryFrom( $gd['doc_type'] ?? '' )?->label() ?? ( $gd['doc_type'] ?? '' ),
						'doc_number'      => $gd['doc_number']      ?? '',
						'doc_issued_by'   => $gd['doc_issued_by']   ?? '',
						'doc_issued_date' => $gd['doc_issued_date'] ?? '',
						'inn'             => $gd['inn']             ?? '',
						'address'         => $gd['address']         ?? '',
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
					<?php if ( $terminatedAt ) : ?>
						<?php echo esc_html( $terminatedAt ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td>
					<?php if ( $terminatedReason ) : ?>
						<?php echo esc_html( $terminatedReason ); ?>
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
