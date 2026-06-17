<?php
/**
 * Бейдж «используется в N» в колонке списка банка контента.
 *
 * @var int $count Число потребителей (0 = orphan).
 */

declare( strict_types=1 );

if ( $count > 0 ) :
	?>
	<strong><?php echo esc_html( (string) $count ); ?></strong>
	<?php
else :
	echo '&mdash;';
endif;
