<?php
/**
 * Шаблон страницы подачи заявки на зачисление (/lms/apply)
 *
 * @package FS LMS
 *
 * @var \Inc\DTO\Subject\SubjectDTO[] $subjects Активные предметы для селекта «Направление».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<main class="fs-lms-apply-page">
    <div class="fs-apply-card">
        <h2 class="fs-apply-card__title"><?php esc_html_e( 'Подать заявку на обучение', 'fs-lms' ); ?></h2>

        <?php require __DIR__ . '/apply-fields.php'; ?>
    </div>
</main>
