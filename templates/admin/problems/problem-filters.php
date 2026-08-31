<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Фильтры над нативной таблицей банка задач (хук restrict_manage_posts).
 *
 * @var array{name: string, options: array, selected: string, all_label: string}|null $subject
 * @var array{name: string, options: array, selected: string, all_label: string, numbersBySubject: array<string,string[]>}|null $number
 * @var array{name: string, options: array, selected: string, all_label: string}|null $tag
 * @var array{selected: string, courses: array<int,string>, works: array<int,string>} $usage
 * @var array{name: string, options: array, selected: string, all_label: string}|null $author
 */

require_once __DIR__ . '/../components/UI/ui_renderers.php';

if ( null !== $subject ) {
	render_fs_select( $subject );
}

if ( null !== $number ) : ?>
	<select name="<?php echo esc_attr( $number['name'] ); ?>" id="fs_problem_number" data-numbers="<?php echo esc_attr( (string) wp_json_encode( $number['numbersBySubject'] ) ); ?>">
		<option value=""><?php echo esc_html( $number['all_label'] ); ?></option>
		<?php foreach ( $number['options'] as $value => $label ) : ?>
			<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $number['selected'], (string) $value ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
<?php endif;

if ( null !== $tag ) {
	render_fs_select( $tag );
}
?>

<select name="fs_problem_usage">
	<option value=""><?php esc_html_e( 'Все задачи', 'fs-lms' ); ?></option>
	<option value="orphan" <?php selected( $usage['selected'], 'orphan' ); ?>>
		<?php esc_html_e( 'Не используется', 'fs-lms' ); ?>
	</option>

	<?php if ( ! empty( $usage['courses'] ) ) : ?>
		<optgroup label="<?php esc_attr_e( 'По курсу', 'fs-lms' ); ?>">
			<?php foreach ( $usage['courses'] as $course_id => $title ) : ?>
				<option value="<?php echo esc_attr( 'c' . $course_id ); ?>" <?php selected( $usage['selected'], 'c' . $course_id ); ?>>
					<?php echo esc_html( $title ); ?>
				</option>
			<?php endforeach; ?>
		</optgroup>
	<?php endif; ?>

	<?php if ( ! empty( $usage['works'] ) ) : ?>
		<optgroup label="<?php esc_attr_e( 'По работе', 'fs-lms' ); ?>">
			<?php foreach ( $usage['works'] as $work_id => $title ) : ?>
				<option value="<?php echo esc_attr( (string) $work_id ); ?>" <?php selected( $usage['selected'], (string) $work_id ); ?>>
					<?php echo esc_html( $title ); ?>
				</option>
			<?php endforeach; ?>
		</optgroup>
	<?php endif; ?>
</select>

<?php
if ( null !== $author ) {
	render_fs_select( $author );
}
