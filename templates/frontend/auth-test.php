<?php

/**
 * @var array<array{url: string, id: string, label: string}> $providers
 * @var WP_User|null $current_user
 * @var string $logout_url
 */
?>
<div class="lms-auth-test-wrapper" style="padding: 20px; border: 1px solid #ccc;">
	<h3>Тест авторизации</h3>

	<?php foreach ( $providers as $provider ) : ?>
		<p>
			<a href="<?php echo esc_url( $provider['url'] ); ?>"
			   class="button auth-btn-<?php echo esc_attr( $provider['id'] ); ?>"
			   style="display:inline-block; padding:10px 20px; background:#0073aa; color:#fff; text-decoration:none; border-radius:4px; margin-bottom:5px;">
				Войти через <?php echo esc_html( $provider['label'] ); ?>
			</a>
		</p>
	<?php endforeach; ?>

	<?php if ( $current_user ) : ?>
		<hr>
		<p style="color: green;">
			Вы сейчас авторизованы как: <strong><?php echo esc_html( $current_user->display_name ); ?></strong>
		</p>
		<p><a href="<?php echo esc_url( $logout_url ); ?>">Выйти</a></p>
	<?php endif; ?>
</div>