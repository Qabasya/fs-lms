<?php
/**
 * Шаблон переключателя
 * @var array $template_data Приходит из функции render_fs_toggle
 */
$name        = $template_data['name'] ?? '';
$value       = $template_data['value'] ?? false;
$args        = $template_data['args'] ?? [];
$is_readonly = ! empty( $args['readonly'] );
$id          = $args['id'] ?? uniqid( 'toggle_' );
$class       = $args['class'] ?? '';

$wrapper_class = 'fs-toggle';
if ( $is_readonly ) $wrapper_class .= ' is-readonly';
if ( $class )       $wrapper_class .= ' ' . $class;
?>

<div class="<?php echo esc_attr( $wrapper_class ); ?>">
    <input
            type="checkbox"
            name="<?php echo esc_attr( $name ); ?>"
            id="<?php echo esc_attr( $id ); ?>"
            <?php checked( $value, true ); ?>
            <?php disabled( $is_readonly, true ); ?>
    >
    <label class="fs-toggle-switch" for="<?php echo esc_attr( $id ); ?>">
        <?php _e( 'Toggle', 'fs-lms' ); ?>
    </label>
</div>