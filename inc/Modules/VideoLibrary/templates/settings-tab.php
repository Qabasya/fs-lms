<?php
/** Вкладка «S3 хранилище» в настройках; данные секции отдаёт контроллер модуля. */
defined( 'ABSPATH' ) || exit;
?>
<div class="fs-lms-config">
	<?php do_action( 'fs_lms_s3_settings', $subjects ?? array() ); ?>
</div>
