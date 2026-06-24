<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class PairsField
 *
 * Поле пар для задания «Сопоставление».
 *
 * Хранит: { pairs: [ {left, right} ] }
 * POST-структура: fs_lms_meta[task_pairs][pairs][N][left|right]
 *
 * @package Inc\MetaBoxes\Fields
 */
class PairsField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data  = is_array( $value ) ? $value : array();
		$pairs = is_array( $data['pairs'] ?? null ) ? $data['pairs'] : array();
		$base  = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-pairs-field" data-field="task-pairs">
			<label class="fs-lms-label"><?php echo esc_html( $label ); ?></label>
			<p class="description">Левая колонка — вопрос/понятие, правая — соответствующий ответ.</p>

			<div class="fs-task-pairs__header">
				<span>Левая плашка</span>
				<span>Правая плашка</span>
			</div>

			<ul class="fs-task-pairs__list">
				<?php foreach ( $pairs as $i => $pair ) : ?>
					<li class="fs-task-pairs__item">
						<?php $this->renderPairRow( $base, $i, $pair ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<button type="button" class="button js-pairs-add">+ Добавить пару</button>

			<script type="text/template" class="js-pairs-tpl">
				<?php $this->renderPairRow( $base, '__IDX__', array() ); ?>
			</script>
		</div>
		<?php
	}

	private function renderPairRow( string $base, int|string $i, array $pair ): void {
		$left  = esc_attr( (string) ( $pair['left'] ?? '' ) );
		$right = esc_attr( (string) ( $pair['right'] ?? '' ) );
		?>
		<div class="fs-task-pairs__row">
			<div class="fs-form-group">
				<input type="text"
					name="<?php echo $base; ?>[pairs][<?php echo $i; ?>][left]"
					value="<?php echo $left; ?>"
					class="regular-text js-pair-left"
					placeholder="Левая плашка">
			</div>
			<span class="fs-task-pairs__sep">↔</span>
			<div class="fs-form-group">
				<input type="text"
					name="<?php echo $base; ?>[pairs][<?php echo $i; ?>][right]"
					value="<?php echo $right; ?>"
					class="regular-text js-pair-right"
					placeholder="Правая плашка">
			</div>
			<button type="button" class="button-link js-pairs-remove">✕</button>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'pairs' => array() );
		}

		$raw   = is_array( $value['pairs'] ?? null ) ? $value['pairs'] : array();
		$pairs = array();

		foreach ( $raw as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$left  = $this->sanitizeTextValue( $pair['left'] ?? '' );
			$right = $this->sanitizeTextValue( $pair['right'] ?? '' );
			if ( '' === $left && '' === $right ) {
				continue;
			}
			$pairs[] = array(
				'left'  => $left,
				'right' => $right,
			);
		}

		return array( 'pairs' => array_values( $pairs ) );
	}

	public function editorType(): string {
		return 'pairs';
	}
}
