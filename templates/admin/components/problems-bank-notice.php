<?php

declare( strict_types=1 );

/**
 * Описание над таблицей «Банк задач» (экран edit.php?post_type=fs_lms_problems).
 *
 * Выводится через хук admin_notices в ProblemsController. Текст правится здесь.
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice fs-lms-learning-notice">
	<p class="fs-lms-bank-intro">
		<?php echo esc_html__( 'Глобальный набор приватных задач. Задачи не привязаны к предмету и не отображаются на сайте — их можно переиспользовать в уроках и работах любого предмета.', 'fs-lms' ); ?>
	</p>
</div>
