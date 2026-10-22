<?php
/**
 * Чип файла-материала задания в плеере.
 *
 * Свой origin — скачивание (`download`); чужая ссылка — вкладка: атрибут `download` на чужом origin
 * браузер игнорирует, и клик уводил бы со страницы.
 *
 * @var array{name: string, url: string, download?: bool} $attach_file
 */

use Inc\Enums\Ui\Icon;

defined( 'ABSPATH' ) || exit;

$attach_download = ! isset( $attach_file['download'] ) || $attach_file['download'];
?>
<a class="attach" href="<?php echo esc_url( (string) $attach_file['url'] ); ?>"
	<?php if ( $attach_download ) : ?>
		download="<?php echo esc_attr( (string) $attach_file['name'] ); ?>"
	<?php else : ?>
		target="_blank" rel="noopener"
	<?php endif; ?>
	>
	<span class="ai"><?php echo Icon::File->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<span class="at"><b><?php echo esc_html( (string) $attach_file['name'] ); ?></b></span>
	<span class="adl"><?php echo Icon::Download->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
</a>
