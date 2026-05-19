<?php
/**
 *  Временная страница ЛК, потом поправить согласно дизайну!
 *
 * @var \WP_User $user
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="fs-lms-profile-container">
	<div class="fs-lms-profile-header">
		<div class="fs-lms-profile-avatar">
			<?php echo get_avatar( $user->ID, 96 ); ?>
		</div>
		<div class="fs-lms-profile-welcome">
			<h2>Привет, <?php echo esc_html( $user->display_name ); ?>!</h2>
			<p class="fs-lms-profile-role">
				Статус: <span><?php echo esc_html( in_array( 'administrator', $user->roles ) ? 'Преподаватель' : 'Ученик' ); ?></span>
			</p>
		</div>
	</div>

	<div class="fs-lms-profile-content">
		<div class="fs-lms-info-card">
			<h3>Личные данные</h3>
			<div class="fs-lms-info-row">
				<span class="label">Имя пользователя:</span>
				<span class="value"><?php echo esc_html( $user->user_login ); ?></span>
			</div>
			<div class="fs-lms-info-row">
				<span class="label">Email:</span>
				<span class="value"><?php echo esc_html( $user->user_email ); ?></span>
			</div>
		</div>
	</div>

	<div class="fs-lms-profile-footer">
		<a href="<?php echo wp_logout_url( home_url() ); ?>" class="button button-secondary">
			<span class="dashicons dashicons-signout"></span> Выйти из аккаунта
		</a>
	</div>
</div>