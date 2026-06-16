<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class ArticleRefField
 *
 * Опциональный select статьи предмета как источника теории.
 * Переопределяет inline-теорию из post_content ссылкой на {key}_articles.
 *
 * @package Inc\MetaBoxes\Fields
 */
class ArticleRefField extends BaseField {

	/** @var array<int, string> post_id => title */
	private array $options = array();

	/**
	 * @param array<int, string> $options
	 */
	public function setOptions( array $options ): void {
		$this->options = $options;
	}

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$current = (int) $value;
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					class="fs-lms-article-ref-select">
				<option value="0"><?php esc_html_e( '— Inline-теория из текста урока —', 'fs-lms' ); ?></option>
				<?php foreach ( $this->options as $post_id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $post_id ); ?>"
						<?php selected( $current, $post_id ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php esc_html_e( 'Если выбрана статья — она используется как теория вместо текста урока.', 'fs-lms' ); ?>
			</p>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return (int) $value;
	}
}
