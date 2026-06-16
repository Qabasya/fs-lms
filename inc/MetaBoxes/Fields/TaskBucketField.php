<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Enums\PostMetaName;

/**
 * Class TaskBucketField
 *
 * Поле бакета урока: инструкция + упорядоченный список ссылок на задания.
 * Задания хранятся как task_id-ссылки, не копии.
 *
 * @package Inc\MetaBoxes\Fields
 */
class TaskBucketField extends BaseField {

	/**
	 * @param \WP_Post $post
	 * @param string   $id    Идентификатор бакета: practice | independent | homework
	 * @param string   $label Название секции
	 * @param mixed    $value ['content' => string, 'task_ids' => int[]]
	 */
	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$value    = is_array( $value ) ? $value : array();
		$content  = $value['content'] ?? '';
		$task_ids = isset( $value['task_ids'] ) && is_array( $value['task_ids'] )
			? array_filter( array_map( 'intval', $value['task_ids'] ) )
			: array();

		$subject_key = \Inc\Services\PostTypeResolver::subjectFromLessonPostType( $post->post_type );
		$meta_base   = PostMetaName::Meta->value . '[' . esc_attr( $id ) . ']';
		?>
		<div class="fs-lms-bucket-field"
			data-subject="<?php echo esc_attr( $subject_key ); ?>"
			data-bucket="<?php echo esc_attr( $id ); ?>">

			<h4 class="fs-lms-bucket-label"><?php echo esc_html( $label ); ?></h4>

			<div class="fs-lms-field-group">
				<label class="fs-lms-label"><?php esc_html_e( 'Инструкция / комментарий', 'fs-lms' ); ?></label>
				<textarea name="<?php echo esc_attr( $meta_base . '[content]' ); ?>"
						class="large-text fs-lms-bucket-content"
						rows="2"><?php echo esc_textarea( $content ); ?></textarea>
			</div>

			<div class="fs-lms-bucket-chips" data-bucket-chips="<?php echo esc_attr( $id ); ?>">
				<?php foreach ( $task_ids as $task_id ) : ?>
					<?php $post_obj = get_post( $task_id ); ?>
					<?php if ( $post_obj ) : ?>
						<div class="fs-lms-task-chip" data-task-id="<?php echo esc_attr( (string) $task_id ); ?>">
							<span class="fs-lms-chip-title"><?php echo esc_html( $post_obj->post_title ); ?></span>
							<button type="button" class="fs-lms-chip-remove" aria-label="<?php esc_attr_e( 'Удалить', 'fs-lms' ); ?>">×</button>
							<input type="hidden"
								name="<?php echo esc_attr( $meta_base . '[task_ids][]' ); ?>"
								value="<?php echo esc_attr( (string) $task_id ); ?>">
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<div class="fs-lms-bucket-search-wrap">
				<input type="text"
					class="fs-lms-task-search-input"
					placeholder="<?php esc_attr_e( 'Найти задание по заголовку...', 'fs-lms' ); ?>">
				<div class="fs-lms-search-dropdown" style="display:none;"></div>
			</div>

			<div class="fs-lms-bucket-actions">
				<button type="button" class="button fs-lms-create-task-in-bucket">
					<?php esc_html_e( '+ Создать новое задание', 'fs-lms' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * @param mixed $value ['content' => string, 'task_ids' => string[]]
	 * @return array{content: string, task_ids: int[]}
	 */
	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'content' => '', 'task_ids' => array() );
		}

		return array(
			'content'  => $this->sanitizeEditorContent( $value['content'] ?? '' ),
			'task_ids' => array_values(
				array_filter(
					array_map( 'intval', (array) ( $value['task_ids'] ?? array() ) )
				)
			),
		);
	}
}
