<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class RefSelectField
 *
 * Базовое поле «упорядоченный список ссылок на посты» (чипы + поиск + drag-drop).
 * Хранит массив ID (ссылки, не копии) в fs_lms_meta[<id>][].
 *
 * @package Inc\MetaBoxes\Fields
 */
abstract class RefSelectField extends BaseField {

	/**
	 * Тип ссылки для JS-селектора: item | work | lesson.
	 */
	abstract protected function refType(): string;

	/**
	 * Извлекает ключ предмета из post type поста-контейнера.
	 */
	abstract protected function subjectFromPostType( string $post_type ): string;

	/**
	 * Подпись основной кнопки «Создать» (пустая строка = кнопка не рендерится).
	 */
	protected function createLabel(): string {
		return '';
	}

	/**
	 * Подпись второй кнопки «Создать задачу» (только для item-полей; пустая = не рендерится).
	 */
	protected function createProblemLabel(): string {
		return '';
	}

	/**
	 * @param \WP_Post $post  Пост-контейнер.
	 * @param string   $id    Ключ поля в meta (item_ids|work_ids|lesson_ids).
	 * @param string   $label Подпись секции.
	 * @param mixed    $value int[] — ссылки на посты.
	 */
	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$ids = is_array( $value )
			? array_values( array_filter( array_map( 'intval', $value ) ) )
			: array();

		$subject_key = $this->subjectFromPostType( $post->post_type );
		$name        = PostMetaName::Meta->value . '[' . esc_attr( $id ) . '][]';
		?>
		<div class="fs-lms-ref-field"
			data-subject="<?php echo esc_attr( $subject_key ); ?>"
			data-ref-type="<?php echo esc_attr( $this->refType() ); ?>"
			data-field="<?php echo esc_attr( $id ); ?>">

			<label class="fs-lms-label"><?php echo esc_html( $label ); ?></label>

			<div class="fs-lms-ref-chips">
				<?php foreach ( $ids as $ref_id ) : ?>
					<?php $ref_post = get_post( $ref_id ); ?>
					<?php if ( $ref_post instanceof \WP_Post ) : ?>
						<?php $item_type = PostTypeResolver::isProblemPostType( $ref_post->post_type ) ? 'problem' : 'task'; ?>
						<div class="fs-lms-ref-chip" draggable="true"
							data-ref-id="<?php echo esc_attr( (string) $ref_id ); ?>"
							data-item-type="<?php echo esc_attr( $item_type ); ?>">
							<span class="fs-lms-ref-handle" aria-hidden="true">⋮⋮</span>
							<?php if ( 'problem' === $item_type ) : ?>
								<span class="fs-lms-item-type-badge"><?php esc_html_e( 'Задача', 'fs-lms' ); ?></span>
							<?php endif; ?>
							<span class="fs-lms-ref-title"><?php echo esc_html( $ref_post->post_title ); ?></span>
							<button type="button" class="fs-lms-ref-remove" aria-label="<?php esc_attr_e( 'Удалить', 'fs-lms' ); ?>">×</button>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $ref_id ); ?>">
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<div class="fs-lms-ref-search-wrap">
				<input type="text"
					class="fs-lms-ref-search regular-text"
					placeholder="<?php esc_attr_e( 'Найти по названию…', 'fs-lms' ); ?>"
					autocomplete="off">
				<div class="fs-lms-ref-dropdown" hidden></div>
			</div>

			<?php if ( $create_label = $this->createLabel() ) : ?>
				<button type="button" class="button fs-lms-ref-create">
					+ <?php echo esc_html( $create_label ); ?>
				</button>
			<?php endif; ?>

			<?php if ( $prob_label = $this->createProblemLabel() ) : ?>
				<button type="button" class="button fs-lms-ref-create-problem">
					+ <?php echo esc_html( $prob_label ); ?>
				</button>
			<?php endif; ?>

			<template class="fs-lms-ref-chip-template">
				<div class="fs-lms-ref-chip" draggable="true" data-ref-id="" data-item-type="">
					<span class="fs-lms-ref-handle" aria-hidden="true">⋮⋮</span>
					<span class="fs-lms-item-type-badge" hidden></span>
					<span class="fs-lms-ref-title"></span>
					<button type="button" class="fs-lms-ref-remove" aria-label="<?php esc_attr_e( 'Удалить', 'fs-lms' ); ?>">×</button>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="">
				</div>
			</template>
		</div>
		<?php
	}

	/**
	 * @param mixed $value string[]|int[]
	 * @return int[]
	 */
	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $value ) ) );
	}
}
