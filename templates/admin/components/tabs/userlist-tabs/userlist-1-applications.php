<?php
/**
 * Таб "Заявки" — таблица заявок на зачисление.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\ApplicationStatus;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\PiiCryptoService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$repo   = new ApplicationRepository();
$crypto = new PiiCryptoService();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$statusFilter = sanitize_key( $_GET['status'] ?? '' );
$filters      = $statusFilter ? array( 'status' => $statusFilter ) : array();

$apps  = $repo->list( $filters, $page, $perPage );
$total = $repo->count( $filters );
$pages = (int) ceil( $total / $perPage );

$trashNonce = wp_create_nonce( Nonce::TrashApplication->value );

$statusLabels = array_combine(
	array_map( fn( $s ) => $s->value, ApplicationStatus::cases() ),
	array_map( fn( $s ) => $s->label(), ApplicationStatus::cases() )
);

?>

<div class="fs-lms-applications">

	<!-- Фильтры по статусу -->
	<ul class="subsubsub">
		<li>
			<a href="?page=fs_lms_userlist&tab=tab-1"
				class="<?php echo ! $statusFilter ? 'current' : ''; ?>">
				Все <span class="count">(<?php echo esc_html( (string) $repo->count( array() ) ); ?>)</span>
			</a> |
		</li>
		<?php foreach ( ApplicationStatus::cases() as $s ) :
			$cnt = $repo->count( array( 'status' => $s->value ) );
			if ( 0 === $cnt ) { continue; }
			?>
			<li>
				<a href="?page=fs_lms_userlist&tab=tab-1&status=<?php echo esc_attr( $s->value ); ?>"
					class="<?php echo $statusFilter === $s->value ? 'current' : ''; ?>">
					<?php echo esc_html( $s->label() ); ?>
					<span class="count">(<?php echo esc_html( (string) $cnt ); ?>)</span>
				</a>
				<?php if ( $s !== ApplicationStatus::Trash ) { echo ' | '; } ?>
			</li>
		<?php endforeach; ?>
	</ul>

    <table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

        <thead>
        <tr>
            <th class=" column-title column-primary">
                <?php esc_html_e( 'ФИО ученика', 'fs-lms' ); ?>
            </th>

            <th class=" column-title">
                <?php esc_html_e( 'ФИО родителя', 'fs-lms' ); ?>
            </th>

            <th class=" column-title">
                <?php esc_html_e( 'Статус', 'fs-lms' ); ?>
            </th>

            <th class=" column-title">
                <?php esc_html_e( 'JOIN-ссылка', 'fs-lms' ); ?>
            </th>

            <th class=" column-title">
                <?php esc_html_e( 'Создана', 'fs-lms' ); ?>
            </th>

            <th class=" column-title">
                <?php esc_html_e( 'Действия', 'fs-lms' ); ?>
            </th>
        </tr>
        </thead>

        <tbody id="the-list">
        <?php if ( empty( $apps ) ) : ?>
            <tr>
                <td colspan="6">
                    <div class="notice notice-info inline fs-table__no-items">
                        <p><?php esc_html_e( 'Заявок пока нет.', 'fs-lms' ); ?></p>
                    </div>
                </td>
            </tr>

        <?php else : ?>
			<?php foreach ( $apps as $app ) :
				// Расшифровка данных ученика
				$studentName       = '';
				$studentLastName   = '';
				$studentFirstName  = '';
				$studentMiddleName = '';
				$studentEmail      = '';
				$studentPhone      = '';
				$studentSchool     = '';
				$studentGrade      = '';
				$studentBirthDate  = '';

				if ( ! empty( $app->studentDataEnc ) ) {
					try {
						$sd          = json_decode( $crypto->decrypt( $app->studentDataEnc ), true );
						$studentName = $sd['full_name'] ?? '—';

						$nameParts         = explode( ' ', $sd['full_name'] ?? '', 3 );
						$studentLastName   = $nameParts[0] ?? '';
						$studentFirstName  = $nameParts[1] ?? '';
						$studentMiddleName = $nameParts[2] ?? '';
						$studentEmail      = $sd['email']      ?? '';
						$studentPhone      = $sd['phone']      ?? '';
						$studentSchool     = $sd['school']     ?? '';
						$studentGrade      = (string) ( $sd['grade'] ?? '' );
						$studentBirthDate  = $sd['birth_date'] ?? '';
						$studentDocType    = $sd['doc_type']   ?? '';
						$studentDocNumber  = $sd['doc_number'] ?? '';
						$studentInn        = $sd['inn']        ?? '';
					} catch ( \Throwable $e ) {
						$studentName = '<em>Ошибка расшифровки</em>';
					}
				}

				// Расшифровка данных родителя (если заполнена)
				$parentName        = '—';
				$parentLastName    = '';
				$parentFirstName   = '';
				$parentMiddleName  = '';
				$parentBirthDate   = '';
				$parentRelationType  = '';
				$parentDocType     = '';
				$parentDocNumber   = '';
				$parentDocIssuedBy = '';
				$parentDocIssuedDate = '';
				$parentInn         = '';
				$parentAddress     = '';
				$parentPhone       = '';
				$parentEmail       = '';

				if ( ! empty( $app->parentDataEnc ) ) {
					try {
						$pd         = json_decode( $crypto->decrypt( $app->parentDataEnc ), true );
						$parentName = $pd['parent_full_name'] ?? $pd['full_name'] ?? '—';

						$pParts             = explode( ' ', $pd['full_name'] ?? '', 3 );
						$parentLastName     = $pParts[0] ?? '';
						$parentFirstName    = $pParts[1] ?? '';
						$parentMiddleName   = $pParts[2] ?? '';
						$parentBirthDate    = $pd['birth_date']      ?? '';
						$parentRelationType = $pd['relation_type']   ?? '';
						$parentDocType      = $pd['doc_type']        ?? '';
						$parentDocNumber    = $pd['doc_number']      ?? '';
						$parentDocIssuedBy  = $pd['doc_issued_by']   ?? '';
						$parentDocIssuedDate = $pd['doc_issued_date'] ?? '';
						$parentInn          = $pd['inn']             ?? '';
						$parentAddress      = $pd['address']         ?? '';
						$parentPhone        = $pd['phone']           ?? '';
						$parentEmail        = $pd['email']           ?? '';
					} catch ( \Throwable $e ) {
						$parentName = '<em>Ошибка расшифровки</em>';
					}
				}

				// Расшифровка JOIN-кода
				$joinCode = null;
				if ( ! empty( $app->joinCodeEnc ) ) {
					try {
						$joinCode = $crypto->decrypt( $app->joinCodeEnc );
					} catch ( \Throwable $e ) {
						$joinCode = null;
					}
				}
				$joinUrl     = $joinCode ? home_url( '/lms/join/' . $joinCode ) : null;
				$joinDisplay = $joinCode ?? '—';

				$statusVal   = $app->status->value;
				$statusLabel = $statusLabels[ $statusVal ] ?? $statusVal;
				$statusClass = 'fs-lms-status--' . str_replace( '_', '-', $statusVal );

				$detailUrl = admin_url( 'admin.php?page=fs-lms-application-detail&id=' . $app->id );
				$canEnroll = in_array( $app->status, [ ApplicationStatus::ReadyForReview, ApplicationStatus::Enrolling ], true );
				$canTrash  = $app->status->isTrashable();
			?>
			<tr data-app-id="<?php echo esc_attr( (string) $app->id ); ?>">

				   <td class="column-title">

                        <?php echo esc_html( $studentName ); ?>

                </td>

				<td class="column-title">

                    <?php echo esc_html( $parentName ); ?></td>

				<td>
					<span class="fs-lms-status <?php echo esc_attr( $statusClass ); ?>">
						<?php echo esc_html( $statusLabel ); ?>
					</span>
				</td>

				<td>
					<?php if ( $joinUrl ) : ?>
						<button type="button"
							class="button-link fs-lms-copy-join fs-lms-join-code"
							data-url="<?php echo esc_attr( $joinUrl ); ?>"
							title="<?php esc_attr_e( 'Нажмите, чтобы скопировать ссылку', 'fs-lms' ); ?>">
							<?php echo esc_html( $joinDisplay ); ?>
						</button>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-date">
					<?php echo esc_html( substr( $app->createdAt, 0, 10 ) ); ?>
				</td>

                <td class="column-actions">
                    <div class="row-actions visible">

						<?php if ( $app->status === ApplicationStatus::Trash ) : ?>

                            <span class="restore">
				<a href="#"
                   class="fs-lms-btn-restore"
                   data-id="<?php echo esc_attr( (string) $app->id ); ?>">
					<?php esc_html_e( 'Восстановить', 'fs-lms' ); ?>
				</a>
			</span>

                            |

                            <span class="delete">
				<a href="#"
                   class="fs-lms-btn-delete"
                   data-id="<?php echo esc_attr( (string) $app->id ); ?>">
					<?php esc_html_e( 'Удалить навсегда', 'fs-lms' ); ?>
				</a>
			</span>

						<?php else : ?>

							<?php if ( $canEnroll ) : ?>
                                <span class="enroll">
					<a href="#"
					   class="js-enrollment-application"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>"
					   data-status="<?php echo esc_attr( $app->status->value ); ?>">
						<?php esc_html_e( 'Зачислить', 'fs-lms' ); ?>
					</a>
				</span>
                                |
							<?php endif; ?>

                            <span class="edit">
				<?php if ( $app->status === ApplicationStatus::PendingParent ) : ?>
					<a href="#"
					   class="js-edit-application"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>"
					   data-last-name="<?php echo esc_attr( $studentLastName ); ?>"
					   data-first-name="<?php echo esc_attr( $studentFirstName ); ?>"
					   data-middle-name="<?php echo esc_attr( $studentMiddleName ); ?>"
					   data-birth-date="<?php echo esc_attr( $studentBirthDate ); ?>"
					   data-email="<?php echo esc_attr( $studentEmail ); ?>"
					   data-phone="<?php echo esc_attr( $studentPhone ); ?>"
					   data-school="<?php echo esc_attr( $studentSchool ); ?>"
					   data-grade="<?php echo esc_attr( $studentGrade ); ?>">
						<?php esc_html_e( 'Изменить', 'fs-lms' ); ?>
					</a>
				<?php elseif ( $app->status === ApplicationStatus::ReadyForReview ) : ?>
					<a href="#"
					   class="js-review-application"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>"
					   data-s-last-name="<?php echo esc_attr( $studentLastName ); ?>"
					   data-s-first-name="<?php echo esc_attr( $studentFirstName ); ?>"
					   data-s-middle-name="<?php echo esc_attr( $studentMiddleName ); ?>"
					   data-s-birth-date="<?php echo esc_attr( $studentBirthDate ); ?>"
					   data-s-doc-type="<?php echo esc_attr( $studentDocType ); ?>"
					   data-s-doc-number="<?php echo esc_attr( $studentDocNumber ); ?>"
					   data-s-inn="<?php echo esc_attr( $studentInn ); ?>"
					   data-p-last-name="<?php echo esc_attr( $parentLastName ); ?>"
					   data-p-first-name="<?php echo esc_attr( $parentFirstName ); ?>"
					   data-p-middle-name="<?php echo esc_attr( $parentMiddleName ); ?>"
					   data-p-birth-date="<?php echo esc_attr( $parentBirthDate ); ?>"
					   data-p-relation-type="<?php echo esc_attr( $parentRelationType ); ?>"
					   data-p-email="<?php echo esc_attr( $parentEmail ); ?>"
					   data-p-phone="<?php echo esc_attr( $parentPhone ); ?>"
					   data-p-doc-type="<?php echo esc_attr( $parentDocType ); ?>"
					   data-p-doc-number="<?php echo esc_attr( $parentDocNumber ); ?>"
					   data-p-doc-issued-by="<?php echo esc_attr( $parentDocIssuedBy ); ?>"
					   data-p-doc-issued-date="<?php echo esc_attr( $parentDocIssuedDate ); ?>"
					   data-p-inn="<?php echo esc_attr( $parentInn ); ?>"
					   data-p-address="<?php echo esc_attr( $parentAddress ); ?>">
						<?php esc_html_e( 'Изменить', 'fs-lms' ); ?>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( $detailUrl ); ?>">
						<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
					</a>
				<?php endif; ?>
			</span>

							<?php if ( $canTrash ) : ?>
                                |
                                <span class="trash">
					<a href="#"
                       class="fs-lms-btn-trash"
                       data-id="<?php echo esc_attr( (string) $app->id ); ?>">
						<?php esc_html_e( 'В корзину', 'fs-lms' ); ?>
					</a>
				</span>
							<?php endif; ?>

						<?php endif; ?>

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

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/application-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/application-review-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/application-enrollment-modal.php'; ?>
