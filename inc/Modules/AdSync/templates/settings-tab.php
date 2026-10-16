<?php
/** Вкладка «AdSync» в настройках; данные секции отдаёт контроллер модуля. */
defined( 'ABSPATH' ) || exit;
?>
<div class="fs-lms-config">
	<?php do_action( 'fs_lms_adsync_settings', $subjects ?? array() ); ?>
</div>
