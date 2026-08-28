<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Inc\Enums\Wp\PostMetaName;

/**
 * Метабокс «Предмет и номер задания» для банковской задачи (fs_lms_problems).
 * Необязательная пометка автора — используется для поиска/фильтра в конструкторе
 * контрольной (ЕГЭ/ОГЭ показывает только задачи с подходящим номером позиции).
 * Номер выбирается из тех же термов таксономии `{subject}_task_number`, что и у
 * предметных заданий — совпадает с требованием экзамена, а не вводится вручную.
 *
 * @var \Inc\DTO\Subject\SubjectDTO[] $subjects
 * @var string                        $subject
 * @var string                        $number
 * @var array<string, string[]>       $numbersBySubject subjectKey => номера
 */
?>
<p>
	<label for="fs_lms_bank_task_subject"><?php esc_html_e( 'Предмет', 'fs-lms' ); ?></label><br />
	<select
		name="<?php echo esc_attr( PostMetaName::BankTaskSubject->value ); ?>"
		id="fs_lms_bank_task_subject"
		class="widefat"
	>
		<option value=""><?php esc_html_e( '— не указан —', 'fs-lms' ); ?></option>
		<?php foreach ( $subjects as $s ) : ?>
			<option value="<?php echo esc_attr( $s->key ); ?>" <?php selected( $subject, $s->key ); ?>>
				<?php echo esc_html( $s->name ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</p>
<p class="fs-bank-number-row" <?php echo '' === $subject ? 'hidden' : ''; ?>>
	<label for="fs_lms_bank_task_number"><?php esc_html_e( 'Номер задания', 'fs-lms' ); ?></label><br />
	<select
		name="<?php echo esc_attr( PostMetaName::BankTaskNumber->value ); ?>"
		id="fs_lms_bank_task_number"
		class="widefat"
		data-numbers="<?php echo esc_attr( (string) wp_json_encode( $numbersBySubject ) ); ?>"
	>
		<option value=""><?php esc_html_e( '— не выбран —', 'fs-lms' ); ?></option>
		<?php foreach ( ( $numbersBySubject[ $subject ] ?? array() ) as $n ) : ?>
			<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $number, $n ); ?>>
				<?php echo esc_html( $n ); ?>
			</option>
		<?php endforeach; ?>
	</select>
</p>
<div class="fs-bank-boilerplate-row" <?php echo ( '' === $subject || '' === $number ) ? 'hidden' : ''; ?>>
	<p>
		<label for="fs_lms_bank_task_boilerplate"><?php esc_html_e( 'Типовое условие', 'fs-lms' ); ?></label><br />
		<select id="fs_lms_bank_task_boilerplate" class="widefat" disabled>
			<option value=""><?php esc_html_e( '— не выбрано —', 'fs-lms' ); ?></option>
		</select>
		<span class="description"><?php esc_html_e( 'Подставляет текст условия в поля ниже — можно выбрать другое или поправить вручную.', 'fs-lms' ); ?></span>
	</p>
</div>
