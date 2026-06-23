<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class OrderItemsField
 *
 * Поле элементов сортировки для задания «Сортировка».
 * Автор вводит элементы в правильном порядке — на фронте они перемешиваются.
 *
 * Хранит: { items: ['элемент1', 'элемент2', ...] }
 * POST-структура: fs_lms_meta[task_order_items][items][]
 *
 * @package Inc\MetaBoxes\Fields
 */
class OrderItemsField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data  = is_array( $value ) ? $value : array();
		$items = is_array( $data['items'] ?? null ) ? $data['items'] : array();
		$base  = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-order-field" data-field="task-order">
			<label class="fs-lms-label"><?php echo esc_html( $label ); ?></label>
			<p class="description">Введите элементы в правильном порядке. На фронте они будут перемешаны.</p>

			<ul class="fs-task-order__list">
				<?php foreach ( $items as $item ) : ?>
					<li class="fs-task-order__item">
						<?php $this->renderItemRow( $base, (string) $item ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<button type="button" class="button js-order-add">+ Добавить элемент</button>

			<script type="text/template" class="js-order-tpl">
				<?php $this->renderItemRow( $base, '' ); ?>
			</script>
		</div>
		<?php
	}

	private function renderItemRow( string $base, string $value ): void {
		?>
		<div class="fs-task-order__row">
			<span class="fs-task-order__handle dashicons dashicons-menu"></span>
			<input type="text"
				name="<?php echo $base; ?>[items][]"
				value="<?php echo esc_attr( $value ); ?>"
				class="regular-text"
				placeholder="Элемент">
			<button type="button" class="button-link js-order-remove">✕</button>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'items' => array() );
		}

		$raw   = is_array( $value['items'] ?? null ) ? $value['items'] : array();
		$items = array();

		foreach ( $raw as $item ) {
			$text = $this->sanitizeTextValue( (string) $item );
			if ( '' !== $text ) {
				$items[] = $text;
			}
		}

		return array( 'items' => array_values( $items ) );
	}

	public function editorType(): string {
		return 'order_items';
	}
}
