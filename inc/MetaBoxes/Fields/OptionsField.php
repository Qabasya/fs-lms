<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class OptionsField
 *
 * Поле вариантов ответа для задания типа «Выбор».
 *
 * Хранит: { multiple: bool, options: [ {id, text, correct} ] }
 * POST-структура: fs_lms_meta[task_options][multiple], fs_lms_meta[task_options][options][N][text|correct]
 *
 * @package Inc\MetaBoxes\Fields
 */
class OptionsField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data     = is_array( $value ) ? $value : array();
		$multiple = (bool) ( $data['multiple'] ?? false );
		$options  = is_array( $data['options'] ?? null ) ? $data['options'] : array();

		$base = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-options-field" data-field="task-options">
			<label class="fs-lms-label"><?php echo esc_html( $label ); ?></label>

			<div class="fs-task-options__mode">
				<label>
					<input type="hidden"
						name="<?php echo $base; ?>[multiple]"
						value="0">
					<input type="checkbox"
						name="<?php echo $base; ?>[multiple]"
						value="1"
						class="js-options-multiple"
						<?php checked( $multiple ); ?>>
					Несколько правильных ответов (checkbox)
				</label>
			</div>

			<ul class="fs-task-options__list">
				<?php foreach ( $options as $i => $opt ) : ?>
					<li class="fs-task-options__item" data-index="<?php echo (int) $i; ?>">
						<?php $this->renderOptionRow( $base, $i, $opt, $multiple ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<button type="button" class="button js-options-add">+ Добавить вариант</button>

			<script type="text/template" class="js-options-tpl">
				<?php $this->renderOptionRow( $base, '__IDX__', array(), $multiple ); ?>
			</script>
		</div>
		<?php
	}

	private function renderOptionRow( string $base, int|string $i, array $opt, bool $multiple ): void {
		$text    = esc_attr( (string) ( $opt['text'] ?? '' ) );
		$correct = (bool) ( $opt['correct'] ?? false );
		?>
		<div class="fs-task-options__row">
			<input type="hidden"
				name="<?php echo $base; ?>[options][<?php echo $i; ?>][correct]"
				value="0">
			<input type="<?php echo $multiple ? 'checkbox' : 'radio'; ?>"
				name="<?php echo $base; ?>[options][<?php echo $i; ?>][correct]"
				value="1"
				class="js-option-correct"
				<?php checked( $correct ); ?>>
			<div class="fs-form-group">
				<input type="text"
					name="<?php echo $base; ?>[options][<?php echo $i; ?>][text]"
					value="<?php echo $text; ?>"
					class="regular-text js-option-text"
					placeholder="Текст варианта">
			</div>
			<button type="button" class="button-link js-options-remove">✕</button>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'multiple' => false, 'options' => array() );
		}

		$multiple = ! empty( $value['multiple'] );
		$raw      = is_array( $value['options'] ?? null ) ? $value['options'] : array();
		$options  = array();

		foreach ( $raw as $i => $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$text = $this->sanitizeTextValue( $opt['text'] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			$options[] = array(
				'id'      => (string) ( $i + 1 ),
				'text'    => $text,
				'correct' => ! empty( $opt['correct'] ),
			);
		}

		return array(
			'multiple' => $multiple,
			'options'  => array_values( $options ),
		);
	}

	public function editorType(): string {
		return 'options';
	}
}
