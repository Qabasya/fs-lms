<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;

if ( ! current_user_can( Capability::ManageLmsRoles->value ) ) {
	return;
}

$assignable = array(
	UserRole::FSOffice->value    => UserRole::FSOffice->label(),
	UserRole::FSMethodist->value => UserRole::FSMethodist->label(),
	UserRole::FSMarket->value    => UserRole::FSMarket->label(),
	UserRole::FSTeacher->value   => UserRole::FSTeacher->label(),
);

$users = get_users( array(
	'role__in' => array_merge( array( 'administrator' ), array_keys( $assignable ) ),
	'orderby'  => 'display_name',
	'order'    => 'ASC',
	'number'   => 200,
) );

$nonce       = Nonce::SaveRoles->create();
$ajax_action = AjaxHook::SaveUserRoles->jsAction();
?>

<div id="tab-roles" class="tab-pane active">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Роли персонала</h1>
		</div>
	</div>

	<?php settings_errors(); ?>

	<div class="fs-lms-roles-tab" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-action="<?php echo esc_attr( $ajax_action ); ?>">

		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
				<tr>
					<th class="column-title tw-20">Пользователь</th>
					<?php foreach ( $assignable as $slug => $label ) : ?>
						<th class="column-title column-primary"><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody id="the-list">
				<?php foreach ( $users as $user ) :
					$is_admin   = in_array( 'administrator', (array) $user->roles, true );
					$user_roles = (array) $user->roles;
				?>
				<tr data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>">
					<td class="column-title">
						<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
						<span class="description"><?php echo esc_html( $user->user_email ); ?></span>
					</td>
					<?php foreach ( $assignable as $slug => $label ) :
						// T12.1: администратор — суперсет ролей, все чекбоксы отмечены визуально
						// (в БД роли не пишем; изменения строки заблокированы на бэкенде).
						$checked = $is_admin || in_array( $slug, $user_roles, true );
					?>
					<td>
						<label>
							<input
								type="checkbox"
								class="fs-role-checkbox"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $checked ); ?>
								<?php disabled( $is_admin ); ?>
							>
						</label>
					</td>
					<?php endforeach; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	</div>

</div>
