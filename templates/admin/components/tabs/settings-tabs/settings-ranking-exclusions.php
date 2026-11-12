<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;
?>
				<div class="fs-field fs-ranking-exclusions">
					<label class="fs-field__label" for="fs-ranking-student-search">Не отображать в рейтинге</label>
					<div class="fs-field__control fs-print-center__search">
						<input type="text" id="fs-ranking-student-search" placeholder="Начните вводить ФИО" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="fs-ranking-student-list" aria-autocomplete="list">
						<ul id="fs-ranking-student-list" class="fs-print-center__suggest" role="listbox" hidden></ul>
					</div>
					<select id="fs-ranking-excluded" multiple hidden tabindex="-1" aria-hidden="true">
						<?php foreach ( $ranking_view['students'] as $student ) : ?>
							<option value="<?php echo esc_attr( (string) $student['id'] ); ?>" <?php selected( in_array( (int) $student['id'], $ranking_view['excluded_students'], true ) ); ?>><?php echo esc_html( $student['name'] . ' (ID ' . $student['id'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<div class="fs-ranking-selected" aria-label="Исключённые ученики" aria-live="polite">
						<?php foreach ( $ranking_view['students'] as $student ) : ?>
							<?php if ( in_array( (int) $student['id'], $ranking_view['excluded_students'], true ) ) : ?>
								<span class="fs-ranking-selected__item" data-ranking-selected="<?php echo esc_attr( (string) $student['id'] ); ?>"><?php echo esc_html( $student['name'] . ' (ID ' . $student['id'] . ')' ); ?> <button type="button" class="fs-ranking-selected__remove" aria-label="<?php echo esc_attr( 'Удалить ' . $student['name'] ); ?>">×</button></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<p class="fs-field__desc">Выбранные ученики исключаются из всех рейтингов.</p>
				</div>
