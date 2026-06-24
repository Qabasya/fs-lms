<?php
/**
 * Admin-notice: удаление заблокировано, контент используется.
 *
 * @var string                                    $title     Название удаляемого элемента.
 * @var array<int, array{id:int,title:string,type:string}> $consumers Список потребителей.
 */

declare( strict_types=1 );
?>
<div class="notice notice-error">
	<p>
		<strong><?php echo esc_html( sprintf( __( 'Невозможно удалить «%s»', 'fs-lms' ), $title ) ); ?>:</strong>
		<?php if ( ! empty( $consumers ) ) : ?>
			<?php esc_html_e( 'используется в следующих объектах. Удаление заблокировано — используйте «В архив».', 'fs-lms' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'контент задействован и не может быть удалён. Используйте «В архив».', 'fs-lms' ); ?>
		<?php endif; ?>
	</p>
	<?php if ( ! empty( $consumers ) ) : ?>
		<ul>
			<?php foreach ( $consumers as $consumer ) : ?>
				<?php $link = get_edit_post_link( $consumer['id'] ); ?>
				<li>
					<?php if ( $link ) : ?>
						<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $consumer['title'] ?: __( '(без названия)', 'fs-lms' ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $consumer['title'] ?: __( '(без названия)', 'fs-lms' ) ); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
