<?php

declare( strict_types=1 );
/**
 * Таб "Архив" — ученики с завершёнными/отчисленными зачислениями.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Access\Capability;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Enrollment\ExpulsionReasons;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Log\LogNameResolver;
use Inc\Services\Security\PiiCryptoService;

defined( 'ABSPATH' ) || exit;

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

$subjectFilter = sanitize_key( wp_unslash( $_GET['subject_key'] ?? '' ) );
$groupFilter   = (int) ( $_GET['group_id'] ?? 0 );
$reasonFilter  = sanitize_text_field( wp_unslash( $_GET['reason'] ?? '' ) );

$sortableColumns  = array( 'student_name', 'status', 'subject', 'group' );
$requestedOrderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
$orderby          = in_array( $requestedOrderby, $sortableColumns, true ) ? $requestedOrderby : 'enrolled_at';
$order            = 'asc' === sanitize_key( wp_unslash( $_GET['order'] ?? '' ) ) ? 'ASC' : 'DESC';

$terminalStatuses = array(
	EnrollmentStatus::Expelled->value,
	EnrollmentStatus::Finished->value,
	EnrollmentStatus::Transferred->value,
);

$allStatuses = array_merge( array( EnrollmentStatus::Active->value ), $terminalStatuses );

$sideFilters = array_filter( array(
	'subject_key' => $subjectFilter,
	'group_id'    => $groupFilter ?: '',
	'reason'      => $reasonFilter,
) );

if ( '' === $statusFilter || ! in_array( $statusFilter, array_column( EnrollmentStatus::cases(), 'value' ), true ) ) {
	$filters = array_merge( $sideFilters, array( 'status' => $allStatuses ) );
} else {
	$filters = array_merge( $sideFilters, array( 'status' => array( $statusFilter ) ) );
}

$records = $recordRepo->list( $filters, $page, $perPage, $orderby, $order );
$total   = $recordRepo->count( $filters );
$pages   = (int) ceil( $total / $perPage );

$allSubjects = array();
foreach ( $subjectRepo->readAll() as $dto ) {
	$allSubjects[ $dto->key ] = $dto->name;
}

$groupOptions = array();
foreach ( $groupRepo->findAll() as $g ) {
	$groupOptions[ $g->id ] = $g->name . ' (' . ( $allSubjects[ $g->subject_key ] ?? $g->subject_key ) . ')';
}

$reasonOptions = array();
foreach ( ExpulsionReasons::values() as $reasonValue ) {
	$reasonOptions[ $reasonValue ] = $reasonValue;
}

$pageSlug     = sanitize_key( $_GET['page'] ?? '' );
$baseUrl      = add_query_arg( array( 'page' => $pageSlug, 'tab' => 'tab-5' ), admin_url( 'admin.php' ) );
$statusLabels = array(
	''                              => 'Все',
	EnrollmentStatus::Active->value      => 'Обучается',
	EnrollmentStatus::Finished->value    => 'Завершено',
	EnrollmentStatus::Transferred->value => 'Переведён',
	EnrollmentStatus::Expelled->value    => 'Отчислен',
);

$activeFilters = array_merge( $sideFilters, array_filter( array( 'arc_status' => $statusFilter ) ) );
$sortUrl       = add_query_arg( $activeFilters, $baseUrl );
$sortParams    = 'enrolled_at' !== $orderby
	? array( 'orderby' => $orderby, 'order' => strtolower( $order ) )
	: array();
$filterUrl     = add_query_arg( array_merge( $activeFilters, $sortParams ), $baseUrl );

?>

<div class="fs-lms-archive fs-logs-tab">

	<!-- Фильтры по статусу -->
	<ul class="subsubsub">
		<?php
		$filterKeys = array_keys( $statusLabels );
		$lastKey    = end( $filterKeys );
		foreach ( $statusLabels as $val => $label ) :
			$url      = '' === $val
				? add_query_arg( $sideFilters, $baseUrl )
				: add_query_arg( array_merge( $sideFilters, array( 'arc_status' => $val ) ), $baseUrl );
			$isCurrent = $statusFilter === $val;
			$countFilters = '' === $val
				? array_merge( $sideFilters, array( 'status' => $allStatuses ) )
				: array_merge( $sideFilters, array( 'status' => array( $val ) ) );
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

	<div class="tablenav top fs-students-bulk-bar">
		<div class="alignleft actions bulkactions">
			<label for="js-archive-bulk-action" class="screen-reader-text">Выберите действие</label>
			<select id="js-archive-bulk-action">
				<option value="">— Массовые действия —</option>
				<option value="export">Экспортировать</option>
				<option value="restore">Вернуть в заявки</option>
			</select>
			<button type="button" id="js-archive-bulk-apply" class="button action">Применить</button>
		</div>
	</div>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $pageSlug ); ?>">
		<input type="hidden" name="tab"  value="tab-5">
		<?php if ( '' !== $statusFilter ) : ?>
			<input type="hidden" name="arc_status" value="<?php echo esc_attr( $statusFilter ); ?>">
		<?php endif; ?>

		<?php render_fs_select( array(
			'name'      => 'subject_key',
			'options'   => $allSubjects,
			'selected'  => $subjectFilter,
			'all_label' => 'Все направления',
		) ); ?>

		<?php render_fs_select( array(
			'name'      => 'group_id',
			'options'   => $groupOptions,
			'selected'  => $groupFilter ?: '',
			'all_label' => 'Все группы',
		) ); ?>

		<?php render_fs_select( array(
			'name'      => 'reason',
			'options'   => $reasonOptions,
			'selected'  => $reasonFilter,
			'all_label' => 'Все причины',
		) ); ?>

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $sideFilters ) ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array_filter( array( 'arc_status' => $statusFilter ) ), $baseUrl ) ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>

	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $total ); ?></strong>
		<?php if ( ! empty( $sideFilters ) ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="check-column"><input type="checkbox" id="js-select-all-archive"></th>
			<th class="column-title column-primary">
				<?php echo LogNameResolver::sortableHeader( 'ФИО ученика', 'student_name', $orderby, strtolower( $order ), $sortUrl ); // phpcs:ignore ?>
			</th>
			<th class="column-title">
				<?php echo LogNameResolver::sortableHeader( 'Статус', 'status', $orderby, strtolower( $order ), $sortUrl ); // phpcs:ignore ?>
			</th>
			<th class="column-title">
				<?php echo LogNameResolver::sortableHeader( 'Направление', 'subject', $orderby, strtolower( $order ), $sortUrl ); // phpcs:ignore ?>
			</th>
			<th class="column-title">
				<?php echo LogNameResolver::sortableHeader( 'Группа', 'group', $orderby, strtolower( $order ), $sortUrl ); // phpcs:ignore ?>
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
				<td colspan="8">
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
					'guardian'          => ( function () use ( $row, $personRepo, $docsRepo, $crypto ): array {
						$parentPersonId = $row->parentPersonId > 0 ? (int) $row->parentPersonId : null;
						$empty = array(
							'last_name' => '', 'first_name' => '', 'middle_name' => '',
							'birth_date' => '', 'email' => '', 'phone' => '',
							'doc_type' => '', 'doc_number' => '', 'doc_issued_by' => '',
							'doc_issued_date' => '', 'inn' => '', 'address' => '',
						);
						if ( ! $parentPersonId ) {
							return $empty;
						}
						$gPerson = $personRepo->find( $parentPersonId );
						$gDocs   = $docsRepo->findByPersonId( $parentPersonId );
						$g = array(
							'last_name'       => $gPerson?->lastName   ?? '',
							'first_name'      => $gPerson?->firstName  ?? '',
							'middle_name'     => $gPerson?->middleName ?? '',
							'birth_date'      => $gPerson?->birthDate  ?? '',
							'email'           => '',
							'phone'           => '',
							'doc_type'        => $gDocs?->docType       ?? '',
							'doc_number'      => '',
							'doc_issued_by'   => '',
							'doc_issued_date' => $gDocs?->docIssuedDate ?? '',
							'inn'             => '',
							'address'         => '',
						);
						if ( $gDocs ) {
							foreach ( array(
								'email'         => $gDocs->emailEnc,
								'phone'         => $gDocs->phoneEnc,
								'doc_number'    => $gDocs->docNumberEnc,
								'doc_issued_by' => $gDocs->docIssuedByEnc,
								'inn'           => $gDocs->innEnc,
								'address'       => $gDocs->addressEnc,
							) as $key => $enc ) {
								if ( ! $enc ) { continue; }
								try { $g[ $key ] = $crypto->decrypt( $enc ); } catch ( \Throwable ) {}
							}
						}
						return $g;
					} )(),
				);
			?>
			<tr data-enrollment="<?php echo esc_attr( (string) wp_json_encode( $enrollmentData ) ); ?>">

				<td class="check-column">
					<input type="checkbox" class="js-archive-cb"
						value="<?php echo esc_attr( (string) $row->id ); ?>"
						data-has-parent="<?php echo $row->parentPersonId > 0 ? '1' : '0'; ?>">
				</td>

				<td class="column-title">
					<?php echo esc_html( $studentName ); ?>
				</td>

				<td>
					<?php
					$badgeColor = match ( $status ) {
						EnrollmentStatus::Active      => 'green',
						EnrollmentStatus::Finished    => 'blue',
						EnrollmentStatus::Transferred => 'yellow',
						EnrollmentStatus::Expelled    => 'red',
					};
					render_fs_badge( $status->label(), $badgeColor );
					?>
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

	<?php render_fs_pagination( $page, $pages, add_query_arg( 'paged', '%#%', $filterUrl ) ); ?>

</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/archive-view-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/restore-archive-modal.php'; ?>
