<?php
/**
 * Admin-notice: удаление заблокировано, контент используется.
 *
 * @var int $count Число потребителей.
 */

declare( strict_types=1 );
?>
<div class="notice notice-error">
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d — число потребителей */
				__( 'Нельзя удалить: контент используется в %d месте(ах). Используйте «В архив».', 'fs-lms' ),
				$count
			)
		);
		?>
	</p>
</div>
