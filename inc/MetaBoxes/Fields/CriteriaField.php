<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class CriteriaField
 *
 * Критерии оценивания развёрнутого ответа (Эпик 13, D17): разложение СЫРОГО
 * балла задачи на слагаемые в духе официальных критериев ЕГЭ/ОГЭ
 * («К1: 0–2, К2: 0–1»). Балл задачи = сумма баллов по критериям.
 * НИКАКИХ весов/процентов/перевода в отметку (D4 в силе).
 *
 * Хранит: { criteria: [ {label, max_points} ] }
 * POST-структура: fs_lms_meta[task_criteria][criteria][N][label|max_points]
 *
 * @package Inc\MetaBoxes\Fields
 */
class CriteriaField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data     = is_array( $value ) ? $value : array();
		$criteria = is_array( $data['criteria'] ?? null ) ? $data['criteria'] : array();

		$base = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-criteria-field" data-field="task-criteria">
			<label class="fs-lms-label">
				<?php echo esc_html( $label ); ?>
				<?php echo self::tooltip( 'Опционально. Балл задачи = сумма баллов по критериям (сырые баллы, без весов). Пусто — обычное одно поле балла при проверке.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</label>

			<ul class="fs-task-criteria__list">
				<?php foreach ( $criteria as $i => $criterion ) : ?>
					<li class="fs-task-criteria__item" data-index="<?php echo (int) $i; ?>">
						<?php $this->renderCriterionRow( $base, $i, is_array( $criterion ) ? $criterion : array() ); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<button type="button" class="button js-criteria-add">+ Добавить критерий</button>

			<script type="text/template" class="js-criteria-tpl">
				<?php $this->renderCriterionRow( $base, '__IDX__', array() ); ?>
			</script>
		</div>
		<?php
	}

	private function renderCriterionRow( string $base, int|string $i, array $criterion ): void {
		$label     = esc_attr( (string) ( $criterion['label'] ?? '' ) );
		$maxPoints = (float) ( $criterion['max_points'] ?? 1 );
		?>
		<div class="fs-task-criteria__row">
			<div class="fs-form-group">
				<input type="text"
					name="<?php echo $base; ?>[criteria][<?php echo $i; ?>][label]"
					value="<?php echo $label; ?>"
					class="regular-text js-criterion-label"
					placeholder="Например: К1 — обоснованно получен верный ответ">
			</div>
			<input type="number"
				name="<?php echo $base; ?>[criteria][<?php echo $i; ?>][max_points]"
				value="<?php echo esc_attr( (string) $maxPoints ); ?>"
				class="small-text js-criterion-points"
				min="0.5" step="0.5"
				title="Максимум баллов по критерию">
			<button type="button" class="button-link js-criteria-remove">✕</button>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'criteria' => array() );
		}
		$raw      = is_array( $value['criteria'] ?? null ) ? $value['criteria'] : array();
		$criteria = array();

		foreach ( $raw as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				continue;
			}
			$label = $this->sanitizeTextValue( $criterion['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$maxPoints  = (float) ( $criterion['max_points'] ?? 1 );
			$criteria[] = array(
				'label'      => $label,
				'max_points' => $maxPoints > 0 ? round( $maxPoints, 2 ) : 1.0,
			);
		}

		return array( 'criteria' => array_values( $criteria ) );
	}

	public function editorType(): string {
		return 'criteria';
	}
}
