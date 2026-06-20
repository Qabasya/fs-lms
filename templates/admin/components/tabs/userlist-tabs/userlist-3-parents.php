<?php

declare( strict_types=1 );
/**
 * Таб "Родители" — таблица зарегистрированных родителей/представителей.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$personRepo = new PersonRepository();
$recordRepo = new StudentRecordRepository();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$allParents    = $personRepo->findByIsStudent( false );
$total         = count( $allParents );
$pages         = $total > 0 ? (int) ceil( $total / $perPage ) : 1;
$parentPersons = array_slice( $allParents, ( $page - 1 ) * $perPage, $perPage );

?>

<div class="fs-lms-parents">

	<div class="tablenav top fs-students-bulk-bar">
		<div class="alignleft actions bulkactions">
			<label for="js-parents-bulk-action" class="screen-reader-text">Выберите действие</label>
			<select id="js-parents-bulk-action">
				<option value="">— Массовые действия —</option>
				<option value="export">Экспортировать</option>
			</select>
			<button type="button" id="js-parents-bulk-apply" class="button action">Применить</button>
		</div>
	</div>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="check-column">
				<input type="checkbox" id="js-select-all-parents">
			</th>
			<th class="column-title column-primary">
				<?php esc_html_e( 'ФИО родителя', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'ФИО ребёнка', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Действия', 'fs-lms' ); ?>
			</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $parentPersons ) ) : ?>
			<tr>
				<td colspan="4">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Родителей пока нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $parentPersons as $parentPerson ) :
				$childNames    = array();
				$childPersonId = 0;

				$activeRecords   = $recordRepo->findActiveByParent( $parentPerson->id );
				$seenStudentIds  = array();
				foreach ( $activeRecords as $record ) {
					if ( in_array( $record->studentPersonId, $seenStudentIds, true ) ) { continue; }
					$seenStudentIds[] = $record->studentPersonId;
					$studentPerson    = $personRepo->find( $record->studentPersonId );
					if ( $studentPerson !== null ) {
						$childNames[]  = $studentPerson->fullName();
						$childPersonId = $record->studentPersonId;
					}
				}

				$childNamesStr  = implode( ', ', $childNames ) ?: '—';
				$parentFullName = $parentPerson->fullName();
				$parentWpUser   = $parentPerson->wpUserId ? get_userdata( $parentPerson->wpUserId ) : null;

				$childPerson = $childPersonId ? $personRepo->find( $childPersonId ) : null;

				$parentModalData = array(
					'person_id'        => $parentPerson->id,
					'wp_user_id'       => $parentPerson->wpUserId ?? 0,
					'full_name'        => $parentFullName,
					'last_name'        => $parentPerson->lastName,
					'first_name'       => $parentPerson->firstName,
					'middle_name'      => $parentPerson->middleName ?? '',
					'birth_date'       => $parentPerson->birthDate ?? '',
					'children'         => $childNamesStr,
					'child_person_id'  => $childPersonId,
					'child_birth_date' => $childPerson?->birthDate ?? '',
					'phone'            => '',
				);
			?>
			<tr data-parent="<?php echo esc_attr( (string) wp_json_encode( $parentModalData ) ); ?>">

				<td class="check-column">
					<input type="checkbox" class="js-parent-cb" value="<?php echo esc_attr( (string) $parentPerson->id ); ?>">
				</td>

				<td class="column-title">
					<?php echo esc_html( $parentFullName ); ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $childNamesStr ); ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#"
							   class="js-view-person"
							   data-person-id="<?php echo esc_attr( (string) $parentPerson->id ); ?>"
							   data-wp-user-id="<?php echo esc_attr( (string) ( $parentPerson->wpUserId ?? 0 ) ); ?>"
							   data-person-type="parent"
							   data-display-name="<?php echo esc_attr( $parentFullName ); ?>"
							   data-email="<?php echo esc_attr( $parentWpUser ? $parentWpUser->user_email : '' ); ?>">
								<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
							</a>
						</span>
						<span class="export">
							<a href="#"
							   class="js-export-person"
							   data-person-id="<?php echo esc_attr( (string) $parentPerson->id ); ?>"
							   data-person-type="parent">
								<?php esc_html_e( ' | Экспорт', 'fs-lms' ); ?>
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

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/person/parent-person-modal.php'; ?>
