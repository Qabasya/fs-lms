<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Монтажная точка JS-конструктора (шаги урока / состав работы / состав контрольной).
 *
 * Разметку строит JS; сервер отдаёт только корневой div с data-атрибутами и
 * начальные данные JSON-скриптом (уже экранированы JSON_HEX_*).
 *
 * @var string      $root_class CSS-класс корневого div
 * @var array       $data       data-атрибуты: имя без префикса `data-` => значение
 * @var string      $json       Начальные данные конструктора
 * @var bool        $outer_wrap Обернуть ли в `.fs-sb-wrap`
 */

$outer_wrap = $outer_wrap ?? true;

if ( $outer_wrap ) :
	?><div class="fs-sb-wrap"><?php
endif;
?>
	<div class="<?php echo esc_attr( $root_class ); ?>"
		<?php foreach ( $data as $attr => $value ) : ?>
			data-<?php echo esc_attr( $attr ); ?>="<?php echo esc_attr( (string) $value ); ?>"
		<?php endforeach; ?>>
		<script type="application/json" class="fs-sb-data"><?php echo $json ?: '[]'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON уже экранирован JSON_HEX_* ?></script>
	</div>
<?php
if ( $outer_wrap ) :
	?></div><?php
endif;
