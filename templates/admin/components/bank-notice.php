<?php

declare( strict_types=1 );

/**
 * Описание-абзац над таблицей банка (курсы/уроки/работы/задания/статьи).
 *
 * Выводится через хук admin_notices в LearningMenuController. Текст приходит
 * параметром (см. LearningMenuController::bankDescription()).
 *
 * @var string $text
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice fs-lms-learning-notice">
	<p class="fs-lms-bank-intro"><?php echo esc_html( $text ); ?></p>
</div>
