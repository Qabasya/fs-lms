<?php

declare( strict_types=1 );

/**
 * «Шапка» банка над нативной таблицей (курсы/уроки/работы/задания/статьи):
 * описание-абзац + таб-бар предметов (при 2+ предметах).
 *
 * НБ-1: описание и табы лежат в ОДНОМ `.notice`, чтобы штатный JS WP перенёс их
 * под заголовок целиком (табы не «прыгают» отдельно). Выводится через хук
 * admin_notices в LearningMenuController::renderBankChrome().
 *
 * @var string                                                     $text  Описание банка.
 * @var array<int, array{name: string, url: string, active: bool}> $tabs  Вкладки-предметы (может быть пустым).
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice fs-lms-learning-notice">
	<p class="fs-lms-bank-intro"><?php echo esc_html( $text ); ?></p>
	<?php if ( ! empty( $tabs ) ) : ?>
	<h2 class="nav-tab-wrapper fs-lms-subject-tabs">
		<?php foreach ( $tabs as $tab ) : ?>
			<a
				class="nav-tab<?php echo $tab['active'] ? ' nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( $tab['url'] ); ?>"
			><?php echo esc_html( $tab['name'] ); ?></a>
		<?php endforeach; ?>
	</h2>
	<?php endif; ?>
</div>
