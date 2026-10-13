<?php
/**
 * Кнопка «Нашли ошибку?» — открывает модалку из partials/report-error-modal.php
 * (components/report-error.js).
 *
 * @var int $report_task_id ID задания, к которому относится сообщение.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<button type="button" class="fs-report-btn js-report-open" data-task-id="<?php echo esc_attr( (string) ( $report_task_id ?? 0 ) ); ?>">Нашли ошибку?</button>
