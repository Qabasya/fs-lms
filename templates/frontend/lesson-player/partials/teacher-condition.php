<?php
/**
 * Условие задачи только для чтения — режим преподавателя в плеере (без полей ответа).
 *
 * Показывает ровно то, что ученик читает перед вводом: условие (у «Три в одном» и
 * «Двух условий» — по частям), текст с пропусками (пропуск — прочерк), файлы.
 * Виджета ответа, чипа и баллов здесь нет по построению.
 *
 * @var array  $cond_task  Бандл задачи: template, condition_html, widget_data, files.
 * @var string $cond_class Класс обёртки условия (`q wpc` в карточке работы, `fs-task-condition wpc` в шаге).
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

$cond_template = (string) ( $cond_task['template'] ?? '' );
$cond_html     = $cond_task['condition_html'] ?? '';
$cond_segments = (array) ( $cond_task['widget_data']['segments'] ?? array() );
$cond_widget   = (array) ( $cond_task['widget_data'] ?? array() );
$cond_options  = array_map( static fn( $o ): string => (string) ( $o['text'] ?? '' ), (array) ( $cond_widget['options'] ?? array() ) );
$cond_lefts    = array_map( static fn( $o ): string => (string) ( $o['text'] ?? '' ), (array) ( $cond_widget['lefts'] ?? array() ) );
$cond_rights   = array_map( 'strval', (array) ( $cond_widget['rights'] ?? array() ) );
$cond_items    = array_map( static fn( $o ): string => (string) ( $o['text'] ?? '' ), (array) ( $cond_widget['items'] ?? array() ) );
?>
<?php if ( is_array( $cond_html ) ) : ?>
	<?php foreach ( $cond_html as $cond_key => $cond_part ) : ?>
		<?php if ( '' === (string) $cond_part ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<div class="fs-task-subpart">
			<h3 class="fs-task-subpart__label">
				<?php echo esc_html( 'triple_task' === $cond_template ? __( 'Задание №', 'fs-lms' ) . $cond_key : sprintf( /* translators: %s: номер условия */ __( 'Условие %s', 'fs-lms' ), $cond_key ) ); ?>
			</h3>
			<div class="fs-task-subpart__body wpc"><?php echo \Inc\Shared\SafeHtml::post( (string) $cond_part ); ?></div>
		</div>
	<?php endforeach; ?>
<?php elseif ( 'fill_task' === $cond_template && array() !== $cond_segments ) : ?>
	<?php // Текст задачи с пропусками: у ученика в них поля ввода, у преподавателя — прочерк. ?>
	<div class="<?php echo esc_attr( $cond_class ); ?>">
		<?php foreach ( $cond_segments as $cond_segment ) : ?>
			<?php echo 'gap' === ( $cond_segment['type'] ?? '' ) ? '<span class="fs-fill-blank">______</span>' : esc_html( (string) ( $cond_segment['content'] ?? '' ) ); ?>
		<?php endforeach; ?>
	</div>
<?php elseif ( is_string( $cond_html ) && '' !== $cond_html ) : ?>
	<div class="<?php echo esc_attr( $cond_class ); ?>"><?php echo \Inc\Shared\SafeHtml::post( $cond_html ); ?></div>
<?php endif; ?>

<?php // Варианты ответа: у ученика их рисует виджет, у преподавателя — списком без выбора. ?>
<?php if ( 'choice' === ( $cond_widget['type'] ?? '' ) && array() !== $cond_options ) : ?>
	<div class="fs-task-variants">
		<span class="fs-task-variants__label"><?php echo esc_html( ! empty( $cond_widget['multiple'] ) ? __( 'Варианты ответа (несколько верных)', 'fs-lms' ) : __( 'Варианты ответа', 'fs-lms' ) ); ?></span>
		<ul class="fs-task-variants__list">
			<?php foreach ( $cond_options as $cond_option ) : ?>
				<li><?php echo esc_html( $cond_option ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php elseif ( 'matching' === ( $cond_widget['type'] ?? '' ) && array() !== $cond_lefts ) : ?>
	<div class="fs-task-variants fs-task-variants--cols">
		<div>
			<span class="fs-task-variants__label"><?php esc_html_e( 'Элементы', 'fs-lms' ); ?></span>
			<ol class="fs-task-variants__list">
				<?php foreach ( $cond_lefts as $cond_left ) : ?>
					<li><?php echo esc_html( $cond_left ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<div>
			<span class="fs-task-variants__label"><?php esc_html_e( 'Соответствия', 'fs-lms' ); ?></span>
			<ul class="fs-task-variants__list">
				<?php foreach ( $cond_rights as $cond_right ) : ?>
					<li><?php echo esc_html( $cond_right ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
<?php elseif ( 'ordering' === ( $cond_widget['type'] ?? '' ) && array() !== $cond_items ) : ?>
	<div class="fs-task-variants">
		<span class="fs-task-variants__label"><?php esc_html_e( 'Элементы для сортировки', 'fs-lms' ); ?></span>
		<ul class="fs-task-variants__list">
			<?php foreach ( $cond_items as $cond_item ) : ?>
				<li><?php echo esc_html( $cond_item ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php foreach ( (array) ( $cond_task['files'] ?? array() ) as $cond_file ) : ?>
	<a class="attach" href="<?php echo esc_url( (string) $cond_file['url'] ); ?>" download>
		<span class="ai"><?php echo Icon::File->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="at"><b><?php echo esc_html( (string) $cond_file['name'] ); ?></b></span>
		<span class="adl"><?php echo Icon::Download->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	</a>
<?php endforeach; ?>
