<?php
/**
 * Карточка person в административной панели.
 * Рендерится из PiiCallbacks::renderPersonDetailPage().
 *
 * Доступные переменные:
 *   $personId  int                  ID записи person
 *   $person    PersonDTO            Строка таблицы persons
 *   $decrypted PersonDecryptedDTO|null  Расшифрованные поля (если есть Capability::ViewPII)
 *
 * @package FS LMS
 */

use Inc\Enums\Capability;
use Inc\Enums\PiiField;
use Inc\Enums\UserRole;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\ArchiveRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Services\Person\PiiMaskingService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

$wpUser    = $person->wpUserId ? get_userdata( $person->wpUserId ) : null;
$userRoles = $wpUser ? (array) $wpUser->roles : array();
$isStudent = in_array( UserRole::FSStudent->value, $userRoles, true );
$isParent  = in_array( UserRole::FSParent->value, $userRoles, true );

$displayName = $wpUser ? $wpUser->display_name : ( $person->fullName ?: "Person #{$personId}" );

$archiveRepo = new ArchiveRepository();
$enrollRepo  = new EnrollmentRepository();
$groupRepo   = new GroupsRepository();
$masking     = new PiiMaskingService();

$maskedPhone   = $decrypted ? $masking->mask( $decrypted->phone,   PiiField::Phone )   : '—';
$maskedPass    = $decrypted ? $masking->mask( $decrypted->pass,    PiiField::Pass )    : '—';
$maskedInn     = $decrypted ? $masking->mask( $decrypted->inn,     PiiField::Inn )     : '—';
$maskedAddress = $decrypted ? $masking->mask( $decrypted->address, PiiField::Address ) : '—';
$fullName      = $decrypted?->fullName ?: $person->fullName ?: '—';

$archiveActive  = null;
$representatives = array();
$dependents      = array();

if ( $isStudent ) {
	$archiveActive = $archiveRepo->findActiveByStudent( $personId );
	if ( $archiveActive !== null ) {
		$representatives[] = $archiveActive;
	}
	$enrollments = $enrollRepo->findByStudent( $personId );
} elseif ( $isParent ) {
	$dependents  = $archiveRepo->findActiveByParent( $personId );
	$enrollments = array();
	foreach ( $dependents as $dep ) {
		foreach ( $enrollRepo->findActiveByStudent( $dep->studentPersonId ) as $enr ) {
			$enrollments[] = $enr;
		}
	}
} else {
	$enrollments = array();
}

$getPersonName = function ( int $pid ): string {
	$p = ( new \Inc\Repositories\WPDBRepositories\PersonRepository() )->find( $pid );
	if ( $p ) {
		if ( $p->wpUserId ) {
			$u = get_userdata( $p->wpUserId );
			if ( $u ) return $u->display_name;
		}
		if ( $p->fullName !== '' ) return $p->fullName;
	}
	return "Person #{$pid}";
};

$activeTab = sanitize_key( $_GET['person_tab'] ?? 'data' );
$tabs = $isStudent
	? array( 'data' => 'Данные', 'representatives' => 'Представители', 'enrollments' => 'Зачисления' )
	: array( 'data' => 'Данные', 'dependents'      => 'Подопечные',    'enrollments' => 'Зачисления' );
?>

<div class="wrap fs-person-detail">

	<h1 class="wp-heading-inline">
		<?php echo esc_html( $displayName ); ?>
		<?php if ( $isStudent ) : ?>
			<span class="fs-lms-status fs-lms-status--enrolling"><?php esc_html_e( 'Ученик', 'fs-lms' ); ?></span>
		<?php elseif ( $isParent ) : ?>
			<span class="fs-lms-status fs-lms-status--ready-for-review"><?php esc_html_e( 'Родитель', 'fs-lms' ); ?></span>
		<?php endif; ?>
	</h1>

	<div class="fs-person-detail__actions">
		<?php if ( current_user_can( Capability::ManagePersons->value ) ) : ?>
			<button type="button" class="button js-open-edit-person">
				<?php esc_html_e( 'Редактировать', 'fs-lms' ); ?>
			</button>
		<?php endif; ?>
		<?php if ( current_user_can( Capability::ExportPII->value ) ) : ?>
			<button type="button" class="button js-export-pii" data-person-id="<?php echo esc_attr( (string) $personId ); ?>">
				<?php esc_html_e( 'Экспорт ПД', 'fs-lms' ); ?>
			</button>
		<?php endif; ?>
		<?php if ( current_user_can( Capability::ManagePersons->value ) && ! $person->deletedAt ) : ?>
			<button type="button" class="button button-link-delete js-delete-pii" data-person-id="<?php echo esc_attr( (string) $personId ); ?>">
				<?php esc_html_e( 'Удалить ПД', 'fs-lms' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<h2 class="nav-tab-wrapper" style="margin-top:16px">
		<?php foreach ( $tabs as $tabId => $tabTitle ) : ?>
			<a href="#"
			   class="nav-tab <?php echo $activeTab === $tabId ? 'nav-tab-active' : ''; ?>"
			   data-tab="fs-person-tab-<?php echo esc_attr( $tabId ); ?>">
				<?php echo esc_html( $tabTitle ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<!-- ДАННЫЕ -->
	<div id="fs-person-tab-data" class="fs-tab-panel tab-content"
		<?php echo $activeTab !== 'data' ? 'style="display:none"' : ''; ?>>

		<?php if ( null === $decrypted ) : ?>
			<p><?php esc_html_e( 'Недостаточно прав для просмотра персональных данных.', 'fs-lms' ); ?></p>
		<?php else : ?>
			<table class="form-table">
				<tbody>

					<tr>
						<th><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></th>
						<td><?php echo esc_html( $fullName ); ?></td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Email', 'fs-lms' ); ?></th>
						<td><?php echo esc_html( $wpUser?->user_email ?? '—' ); ?></td>
					</tr>

					<?php
					$piiField = function ( string $field, string $maskedValue ) use ( $personId ) : void {
						?>
						<div class="fs-pii-field">
							<span class="fs-pii-field__masked"><?php echo esc_html( $maskedValue ); ?></span>
							<span class="fs-pii-field__revealed" hidden></span>
							<button type="button"
								class="button-link js-reveal-pii"
								data-field="<?php echo esc_attr( $field ); ?>"
								data-person-id="<?php echo esc_attr( (string) $personId ); ?>">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Показать', 'fs-lms' ); ?>
							</button>
						</div>
						<?php
					};
					?>

					<tr>
						<th><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></th>
						<td><?php $piiField( 'phone', $maskedPhone ); ?></td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Документ (паспорт/св. о рожд.)', 'fs-lms' ); ?></th>
						<td><?php $piiField( 'pass', $maskedPass ); ?></td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></th>
						<td><?php $piiField( 'inn', $maskedInn ); ?></td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Адрес регистрации', 'fs-lms' ); ?></th>
						<td><?php $piiField( 'address', $maskedAddress ); ?></td>
					</tr>

					<?php if ( $person->deletedAt ) : ?>
						<tr>
							<th><?php esc_html_e( 'Статус', 'fs-lms' ); ?></th>
							<td>
								<span class="fs-lms-status fs-lms-status--trash">
									<?php echo esc_html( sprintf( __( 'Удаление запрошено %s', 'fs-lms' ), substr( $person->deletedAt, 0, 10 ) ) ); ?>
								</span>
							</td>
						</tr>
					<?php endif; ?>

				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- ПРЕДСТАВИТЕЛИ (ученик) -->
	<?php if ( $isStudent ) : ?>
	<div id="fs-person-tab-representatives" class="fs-tab-panel tab-content"
		<?php echo $activeTab !== 'representatives' ? 'style="display:none"' : ''; ?>>

		<div class="tablenav top">
			<button type="button" class="button js-open-add-representative">
				<?php esc_html_e( '+ Добавить представителя', 'fs-lms' ); ?>
			</button>
		</div>

		<?php if ( empty( $representatives ) ) : ?>
			<p><?php esc_html_e( 'Представители не привязаны.', 'fs-lms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Зачислен', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Действия', 'fs-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $representatives as $arc ) : ?>
						<tr>
							<td><?php echo esc_html( $getPersonName( $arc->parentPersonId ) ); ?></td>
							<td><?php echo esc_html( substr( $arc->enrolledAt, 0, 10 ) ); ?></td>
							<td>
								<a href="#"
								   class="js-open-replace-representative"
								   data-archive-id="<?php echo esc_attr( (string) $arc->id ); ?>">
									<?php esc_html_e( 'Заменить', 'fs-lms' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ПОДОПЕЧНЫЕ (родитель) -->
	<?php if ( $isParent ) : ?>
	<div id="fs-person-tab-dependents" class="fs-tab-panel tab-content"
		<?php echo $activeTab !== 'dependents' ? 'style="display:none"' : ''; ?>>

		<?php if ( empty( $dependents ) ) : ?>
			<p><?php esc_html_e( 'Подопечных нет.', 'fs-lms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ФИО ученика', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Зачислен', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Карточка', 'fs-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $dependents as $arc ) :
						$studentDetailUrl = admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $arc->studentPersonId );
					?>
						<tr>
							<td><?php echo esc_html( $getPersonName( $arc->studentPersonId ) ); ?></td>
							<td><?php echo esc_html( substr( $arc->enrolledAt, 0, 10 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $studentDetailUrl ); ?>">
									<?php esc_html_e( 'Открыть', 'fs-lms' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ЗАЧИСЛЕНИЯ -->
	<div id="fs-person-tab-enrollments" class="fs-tab-panel tab-content"
		<?php echo $activeTab !== 'enrollments' ? 'style="display:none"' : ''; ?>>

		<?php if ( empty( $enrollments ) ) : ?>
			<p><?php esc_html_e( 'Зачислений нет.', 'fs-lms' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped fs-table">
				<thead>
					<tr>
						<?php if ( $isParent ) : ?>
							<th><?php esc_html_e( 'Ученик', 'fs-lms' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Группа', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Статус', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Дата зачисления', 'fs-lms' ); ?></th>
						<th><?php esc_html_e( 'Дата завершения', 'fs-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $enrollments as $enr ) :
						$group      = $enr->groupId ? $groupRepo->findById( $enr->groupId ) : null;
						$groupTitle = $group ? $group->group_name : '—';
					?>
						<tr>
							<?php if ( $isParent ) : ?>
								<td><?php echo esc_html( $getPersonName( $enr->studentPersonId ) ); ?></td>
							<?php endif; ?>
							<td><?php echo esc_html( $groupTitle ); ?></td>
							<td>
								<span class="fs-lms-status fs-lms-status--<?php echo esc_attr( str_replace( '_', '-', $enr->status->value ) ); ?>">
									<?php echo esc_html( $enr->status->label() ); ?>
								</span>
							</td>
							<td><?php echo esc_html( substr( $enr->enrolledAt, 0, 10 ) ); ?></td>
							<td><?php echo $enr->terminatedAt ? esc_html( substr( $enr->terminatedAt, 0, 10 ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

</div><!-- /.wrap.fs-person-detail -->

<!-- МОДАЛКИ -->

<!-- Редактировать данные -->
<div id="fs-edit-person-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Редактировать данные', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>
		<div class="fs-lms-modal-body">
			<form id="fs-edit-person-modal-form">
				<input type="hidden" name="person_id" value="<?php echo esc_attr( (string) $personId ); ?>">
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
					<input type="email" name="email" class="regular-text" value="<?php echo esc_attr( $wpUser?->user_email ?? '' ); ?>">
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
					<input type="tel" name="phone" class="regular-text">
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Адрес', 'fs-lms' ); ?></label>
					<input type="text" name="address" class="regular-text">
				</div>
				<p class="description"><?php esc_html_e( 'Заполните только изменяемые поля. Пустые поля будут проигнорированы.', 'fs-lms' ); ?></p>
			</form>
		</div>
		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel"><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
			<button type="submit" class="button button-primary" form="fs-edit-person-modal-form"><?php esc_html_e( 'Сохранить', 'fs-lms' ); ?></button>
		</div>
	</div>
</div>

<!-- Добавить представителя (только для ученика) -->
<?php if ( $isStudent ) : ?>
<div id="fs-add-representative-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Добавить представителя', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>
		<div class="fs-lms-modal-body">
			<form id="fs-add-representative-form">
				<input type="hidden" name="student_person_id" value="<?php echo esc_attr( (string) $personId ); ?>">
				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?> <span class="required">*</span></label>
						<input type="text" name="full_name" class="regular-text" required>
					</div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Номер документа', 'fs-lms' ); ?> <span class="required">*</span></label>
						<input type="text" name="doc_number" class="regular-text" required>
					</div>
					<div class="fs-form-group">
						<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
						<input type="text" name="inn" class="regular-text">
					</div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
						<input type="email" name="email" class="regular-text">
					</div>
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
						<input type="tel" name="phone" class="regular-text">
					</div>
				</div>
				<div class="fs-form-group">
					<label><?php esc_html_e( 'Адрес', 'fs-lms' ); ?></label>
					<input type="text" name="address" class="regular-text" style="width:100%">
				</div>
			</form>
		</div>
		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel"><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
			<button type="submit" class="button button-primary" form="fs-add-representative-form"><?php esc_html_e( 'Добавить', 'fs-lms' ); ?></button>
		</div>
	</div>
</div>

<!-- Заменить представителя -->
<div id="fs-replace-representative-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Заменить представителя', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close">&times;</button>
		</div>
		<div class="fs-lms-modal-body">
			<form id="fs-replace-representative-form">
				<input type="hidden" name="archive_id" value="">
				<p class="description" style="margin-bottom:12px">
					<?php esc_html_e( 'Заполните данные нового представителя.', 'fs-lms' ); ?>
				</p>
				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'ФИО', 'fs-lms' ); ?> <span class="required">*</span></label>
						<input type="text" name="full_name" class="regular-text" required>
					</div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Номер документа', 'fs-lms' ); ?> <span class="required">*</span></label>
						<input type="text" name="doc_number" class="regular-text" required>
					</div>
					<div class="fs-form-group">
						<label><?php esc_html_e( 'ИНН', 'fs-lms' ); ?></label>
						<input type="text" name="inn" class="regular-text">
					</div>
				</div>
				<div class="fs-form-row">
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Email', 'fs-lms' ); ?></label>
						<input type="email" name="email" class="regular-text">
					</div>
					<div class="fs-form-group">
						<label><?php esc_html_e( 'Телефон', 'fs-lms' ); ?></label>
						<input type="tel" name="phone" class="regular-text">
					</div>
				</div>
			</form>
		</div>
		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel"><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
			<button type="submit" class="button button-primary" form="fs-replace-representative-form"><?php esc_html_e( 'Заменить', 'fs-lms' ); ?></button>
		</div>
	</div>
</div>
<?php endif; ?>
