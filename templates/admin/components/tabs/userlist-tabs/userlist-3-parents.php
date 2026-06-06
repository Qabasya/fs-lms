<?php
/**
 * Таб "Родители" — таблица зарегистрированных родителей/представителей.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Capability;
use Inc\Repositories\WPDBRepositories\ArchiveRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$personRepo  = new PersonRepository();
$archiveRepo = new ArchiveRepository();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$allParents    = $personRepo->findByRole( 'parent' );
$total         = count( $allParents );
$pages         = $total > 0 ? (int) ceil( $total / $perPage ) : 1;
$parentPersons = array_slice( $allParents, ( $page - 1 ) * $perPage, $perPage );

?>

<div class="fs-lms-parents">

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
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
				<td colspan="3">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Родителей пока нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $parentPersons as $parentPerson ) :
				$childNames    = array();
				$childPersonId = 0;

				$archiveRecords = $archiveRepo->findActiveByParent( $parentPerson->id );
				foreach ( $archiveRecords as $rel ) {
					$studentPerson = $personRepo->find( $rel->studentPersonId );
					if ( $studentPerson ) {
						$childNames[]  = $studentPerson->fullName;
						$childPersonId = $rel->studentPersonId;
					}
				}

				$childNamesStr = implode( ', ', $childNames ) ?: '—';

				$parentModalData = array(
					'person_id'       => $parentPerson->id,
					'wp_user_id'      => $parentPerson->wpUserId ?? 0,
					'full_name'       => $parentPerson->fullName,
					'children'        => $childNamesStr,
					'child_person_id' => $childPersonId,
				);
			?>
			<tr data-parent="<?php echo esc_attr( (string) wp_json_encode( $parentModalData ) ); ?>">

				<td class="column-title">
					<?php echo esc_html( $parentPerson->fullName ); ?>
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
							   data-display-name="<?php echo esc_attr( $parentPerson->fullName ); ?>"
							   data-email="">
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

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/parent-person-modal.php'; ?>
