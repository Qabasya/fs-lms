<?php
/**
 * @var array $template_data Приходит из функции render_fs_badge
 */
$text  = $template_data['text'] ?? '';
$color = $template_data['color'] ?? 'gray';
$class = $template_data['class'] ?? '';
?>

<span class="fs-badge is-<?php echo esc_attr($color); ?> <?php echo esc_attr($class); ?>">
    <?php echo esc_html($text); ?>
</span>