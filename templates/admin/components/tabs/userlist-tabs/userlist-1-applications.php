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
	<ul class="subsubsub" style="margin: 12px 0;">
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

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th> <?php esc_html_e( 'ФИО ученика', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'ФИО родителя', 'fs-lms' ); ?></th>
				<th ><?php esc_html_e( 'Статус', 'fs-lms' ); ?></th>
				<th ><?php esc_html_e( 'JOIN-ссылка', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'Создана', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'Действия', 'fs-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $apps ) ) : ?>
			<tr>
				<td colspan="6" style="text-align:center;padding:24px;color:#6b7280">
					<?php esc_html_e( 'Заявок нет.', 'fs-lms' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $apps as $app ) :
				// Расшифровка данных ученика
				$studentName = '—';
				if ( ! empty( $app->studentDataEnc ) ) {
					try {
						$sd          = json_decode( $crypto->decrypt( $app->studentDataEnc ), true );
						$studentName = $sd['full_name'] ?? '—';
					} catch ( \Throwable $e ) {
						$studentName = '<em>Ошибка расшифровки</em>';
					}
				}

				// Расшифровка данных родителя (если заполнена)
				$parentName = '—';
				if ( ! empty( $app->parentDataEnc ) ) {
					try {
						$pd         = json_decode( $crypto->decrypt( $app->parentDataEnc ), true );
						$parentName = $pd['parent_full_name'] ?? $pd['full_name'] ?? '—';
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
				$canEnroll = $app->status === ApplicationStatus::ReadyForReview;
				$canTrash  = $app->status->isTrashable();
			?>
			<tr data-app-id="<?php echo esc_attr( (string) $app->id ); ?>">

				<td><?php echo esc_html( $studentName ); ?></td>

				<td><?php echo esc_html( $parentName ); ?></td>

				<td>
					<span class="fs-lms-status <?php echo esc_attr( $statusClass ); ?>">
						<?php echo esc_html( $statusLabel ); ?>
					</span>
				</td>

				<td>
					<?php if ( $joinUrl ) : ?>
						<button type="button"
							class="button-link fs-lms-copy-join"
							data-url="<?php echo esc_attr( $joinUrl ); ?>"
							title="<?php esc_attr_e( 'Нажмите, чтобы скопировать ссылку', 'fs-lms' ); ?>"
							style="font-family:monospace;font-size:11px;color:#0369a1;text-decoration:underline;cursor:pointer">
							<?php echo esc_html( $joinDisplay ); ?>
						</button>
					<?php else : ?>
						<span style="color:#9ca3af">—</span>
					<?php endif; ?>
				</td>

				<td style="white-space:nowrap;font-size:12px;color:#6b7280">
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
					<a href="<?php echo esc_url( $detailUrl ); ?>">
						<?php esc_html_e( 'Зачислить', 'fs-lms' ); ?>
					</a>
				</span>
                                |
							<?php endif; ?>

                            <span class="edit">
				<a href="<?php echo esc_url( $detailUrl ); ?>">
					<?php esc_html_e( 'Изменить', 'fs-lms' ); ?>
				</a>
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
		<div class="tablenav bottom" style="margin-top:8px">
			<div class="tablenav-pages">
				<?php for ( $p = 1; $p <= $pages; $p++ ) :
					$url = add_query_arg( array( 'paged' => $p ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>"
						class="button button-small <?php echo $p === $page ? 'button-primary' : ''; ?>"
						style="margin-right:2px">
						<?php echo esc_html( (string) $p ); ?>
					</a>
				<?php endfor; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
