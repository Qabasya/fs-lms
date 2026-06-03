<?php
/**
 * Таб "Родители" — таблица зарегистрированных родителей/представителей.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Capability;
use Inc\Enums\DocumentType;
use Inc\Enums\PiiField;
use Inc\Enums\RelationType;
use Inc\Enums\UserRole;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\RelationshipRepository;
use Inc\Services\Person\PiiMaskingService;
use Inc\Services\PiiCryptoService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$personRepo       = new PersonRepository();
$relationshipRepo = new RelationshipRepository();
$enrollmentRepo   = new EnrollmentRepository();
$crypto           = new PiiCryptoService();
$masker           = new PiiMaskingService();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$parentUsers = get_users( array(
	'role'    => UserRole::FSParent->value,
	'number'  => $perPage,
	'offset'  => ( $page - 1 ) * $perPage,
	'orderby' => 'display_name',
	'order'   => 'ASC',
) );
$total = (int) ( count_users()['avail_roles'][ UserRole::FSParent->value ] ?? 0 );
$pages = $total > 0 ? (int) ceil( $total / $perPage ) : 1;

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
				<?php esc_html_e( 'Телефон', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Email', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Действия', 'fs-lms' ); ?>
			</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $parentUsers ) ) : ?>
			<tr>
				<td colspan="5">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Родителей пока нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $parentUsers as $user ) :
				$personId = (int) get_user_meta( $user->ID, 'fs_lms_person_id', true );
				$person   = $personId ? $personRepo->find( $personId ) : null;

				// Email из таблицы persons (plain text) или fallback на WP user email
				$email = $person?->email ?? $user->user_email ?? '';

				$phone        = '';
				$childNames   = array();
				$guardianData = array();
				$studentData  = array();

				if ( $personId ) {
					$relationships = $relationshipRepo->findActiveByGuardian( $personId );

					foreach ( $relationships as $rel ) {
						$studentPerson = $personRepo->find( $rel->studentPersonId );
						if ( $studentPerson?->wpUserId ) {
							$studentUser = get_userdata( $studentPerson->wpUserId );
							if ( $studentUser ) {
								$childNames[] = $studentUser->display_name;
							}
						}

						// Снапшот из первого доступного зачисления ребёнка
						if ( empty( $guardianData ) ) {
							$enrollments = $enrollmentRepo->findActiveByStudent( $rel->studentPersonId );
							foreach ( $enrollments as $enrollment ) {
								if ( ! empty( $enrollment->snapshotEnc ) ) {
									try {
										$snapshot = json_decode( $crypto->decrypt( $enrollment->snapshotEnc ), true );
										$gd       = $snapshot['guardian'] ?? array();
										if ( ! empty( $gd ) ) {
											$guardianData = $gd;
											$studentData  = $snapshot['student'] ?? array();
											$phone        = $gd['phone'] ?? '';
											break;
										}
									} catch ( \Throwable $e ) {
										// snapshot недоступен
									}
								}
							}
						}
					}
				}

				$childNamesStr = implode( ', ', $childNames ) ?: '—';

				$parentModalData = array(
					'full_name'       => $user->display_name,
					'relation_type'   => RelationType::tryFrom( $guardianData['relation_type'] ?? '' )?->label() ?? ( $guardianData['relation_type'] ?? '' ),
					'birth_date'      => $guardianData['birth_date']      ?? '',
					'email'           => $guardianData['email']           ?? $email,
					'phone'           => $guardianData['phone']           ?? $phone,
					'doc_type'        => DocumentType::tryFrom( $guardianData['doc_type'] ?? '' )?->label() ?? ( $guardianData['doc_type'] ?? '' ),
					'doc_number'      => $masker->mask( $guardianData['doc_number'] ?? '', PiiField::Pass ),
					'doc_issued_by'   => $guardianData['doc_issued_by']   ?? '',
					'doc_issued_date' => $guardianData['doc_issued_date'] ?? '',
					'inn'             => $masker->mask( $guardianData['inn']      ?? '', PiiField::Inn ),
					'address'         => $masker->mask( $guardianData['address']  ?? '', PiiField::Address ),
					'children'        => $childNamesStr,
					'child_birth_date'  => $studentData['birth_date'] ?? '',
					'child_doc_number'  => $masker->mask( $studentData['doc_number'] ?? '', PiiField::Pass ),
					'child_inn'         => $masker->mask( $studentData['inn']        ?? '', PiiField::Inn ),
				);
			?>
			<tr data-parent="<?php echo esc_attr( (string) wp_json_encode( $parentModalData ) ); ?>">

				<td class="column-title">
					<?php echo esc_html( $user->display_name ); ?>
				</td>

				<td class="column-title">
					<?php echo esc_html( $childNamesStr ); ?>
				</td>

				<td>
					<?php if ( $phone ) : ?>
						<?php echo esc_html( $phone ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td>
					<?php if ( $email ) : ?>
						<?php echo esc_html( $email ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#"
							   class="js-view-person"
							   data-person-id="<?php echo esc_attr( (string) $personId ); ?>"
							   data-wp-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
							   data-person-type="parent"
							   data-display-name="<?php echo esc_attr( $user->display_name ); ?>"
							   data-email="<?php echo esc_attr( $email ); ?>">
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
