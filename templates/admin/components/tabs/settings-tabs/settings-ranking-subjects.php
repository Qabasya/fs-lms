<?php
/** Subject-specific ranking settings. */
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;
?>
<form id="fs-ranking-subjects-form" class="fs-card fs-card--flat">
	<div class="fs-card__header"><h2 class="fs-card__title">Рейтинг</h2></div>
	<div class="fs-card__body">
		<p class="fs-card__desc">Выберите предметы, в которых ученикам доступен рейтинг. Сотрудники видят рейтинг согласно своим правам.</p>
		<?php if ( ! $ranking_view['cards'] ) : ?><p>Предметов пока нет.</p><?php endif; ?>
		<?php foreach ( $ranking_view['cards'] as $card ) : ?>
			<?php $subject = $card['subject']; $policy = $card['policy']; $prefix = 'fs-ranking-' . $subject->key; ?>
			<section class="fs-card fs-card--flat" data-ranking-card="<?php echo esc_attr( $subject->key ); ?>">
				<div class="fs-card__header">
					<h3 class="fs-card__title"><?php echo esc_html( $subject->name . ( $subject->archived ? ' (архив)' : '' ) ); ?></h3>
					<?php render_fs_toggle( 'subject_' . $subject->key, (bool) $policy['enabled'], array( 'id' => $prefix . '-enabled' ) ); ?>
					<label for="<?php echo esc_attr( $prefix . '-enabled' ); ?>">Отображать рейтинг</label>
				</div>
				<fieldset id="<?php echo esc_attr( $prefix . '-fields' ); ?>" class="fs-card__body fs-ranking-fields" data-ranking-fields <?php disabled( ! $policy['enabled'] ); ?> <?php echo $policy['enabled'] ? '' : 'hidden'; ?>>
					<?php foreach ( $ranking_fields as $key => $label ) : ?>
						<?php $is_streak = 'attendance_streak_length' === $key; $value = $policy[ $key ]; ?>
						<div class="fs-field"><label class="fs-field__label" for="<?php echo esc_attr( $prefix . '-' . $key ); ?>"><?php echo esc_html( $label ); ?></label>
							<input type="number" class="small-text" id="<?php echo esc_attr( $prefix . '-' . $key ); ?>" name="<?php echo esc_attr( $key ); ?>" data-ranking-field data-original="<?php echo esc_attr( (string) $value ); ?>" required min="<?php echo $is_streak ? '2' : '0'; ?>" max="<?php echo $is_streak ? '100' : '1000'; ?>" step="<?php echo (float) $value !== floor( (float) $value ) ? 'any' : '1'; ?>" value="<?php echo esc_attr( (string) $value ); ?>">
						</div>
					<?php endforeach; ?>

				</fieldset>
			</section>
		<?php endforeach; ?>
		<?php include __DIR__ . '/settings-ranking-exclusions.php'; ?>
	</div>
	<div class="fs-card__footer"><button type="submit" class="button button-primary">Сохранить</button><span class="fs-config-status js-ranking-status" role="status" aria-live="polite"></span></div>
</form>
