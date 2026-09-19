<?php

declare( strict_types=1 );

namespace Inc\Modules\PublicExams\Fields;

use Inc\MetaBoxes\Fields\BaseField;
use Inc\Modules\PublicExams\Services\PublicExamCatalog;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ExamYearField
 *
 * Год публичного экзамена: поле из четырёх цифр с подсказками из уже введённых
 * годов предмета (`<datalist>`) — по нему экзамены группируются на странице раздела.
 *
 * @package Inc\Modules\PublicExams\Fields
 */
class ExamYearField extends BaseField {

	public function __construct(
		private readonly PublicExamCatalog $catalog,
	) {}

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$years = $this->catalog->years( PostTypeResolver::subjectFromAssessmentPostType( $post->post_type ) );
		$list  = $id . '_list';
		?>
		<div class="fs-field">
			<label class="fs-field__label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div class="fs-field__control">
				<input type="text"
					   id="<?php echo esc_attr( $id ); ?>"
					   name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					   value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>"
					   list="<?php echo esc_attr( $list ); ?>"
					   inputmode="numeric"
					   maxlength="4"
					   pattern="[0-9]{4}"
					   placeholder="2026"
					   autocomplete="off">
				<datalist id="<?php echo esc_attr( $list ); ?>">
					<?php foreach ( $years as $year ) : ?>
						<option value="<?php echo esc_attr( $year ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			</div>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return substr( (string) preg_replace( '/\D/', '', is_scalar( $value ) ? (string) $value : '' ), 0, 4 );
	}
}
