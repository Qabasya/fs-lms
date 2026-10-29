<?php
/**
 * Карточка-форма создания и правки мастер-класса (скрыта атрибутом `hidden`, без модалки: модульный JS не тянет
 * `modal-base.js` ядра). Поведение — `assets/admin.js`.
 *
 * @var list<array{id: int, name: string}> $teachers  Преподаватели
 * @var array<string, string>              $subjects  Предметы с банком уроков: ключ → название
 * @var array<string, mixed>               $defaults  Окно и размер пачки по умолчанию
 * @var int                                $chunk_max Предел аккаунтов в одном запросе
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="fs-card fs-card--flat" id="fs-masterclass-form-card" hidden>
	<div class="fs-card__header">
		<h2 class="fs-card__title" id="fs-masterclass-form-title">Новый мастер-класс</h2>
	</div>
	<div class="fs-card__body">
		<form id="fs-masterclass-form" class="fs-config-form" autocomplete="off">
			<input type="hidden" name="id" value="">

			<div class="fs-field">
				<label for="fs-masterclass-school" class="fs-field__label">Школа</label>
				<div class="fs-field__control">
					<input type="text" id="fs-masterclass-school" name="school_name" class="regular-text" maxlength="200" required>
				</div>
			</div>

			<div class="fs-field">
				<label for="fs-masterclass-subject" class="fs-field__label">Предмет</label>
				<div class="fs-field__control">
					<select id="fs-masterclass-subject" name="subject_key" required>
						<option value="">Выберите предмет</option>
						<?php foreach ( $subjects as $key => $name ) : ?>
							<option value="<?php echo esc_attr( (string) $key ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<p class="fs-field__desc" id="fs-masterclass-subject-note" hidden>Предмет созданного мастер-класса не меняется.</p>
			</div>

			<div class="fs-field">
				<label for="fs-masterclass-lesson" class="fs-field__label">Урок</label>
				<div class="fs-field__control">
					<select id="fs-masterclass-lesson" name="lesson_id" required disabled>
						<option value="">Сначала выберите предмет</option>
					</select>
				</div>
				<p class="fs-field__desc">Подходят только опубликованные уроки, которые проверяются автоматически; остальные недоступны для выбора.</p>
			</div>

			<div class="fs-field">
				<label for="fs-masterclass-teacher" class="fs-field__label">Преподаватель</label>
				<div class="fs-field__control">
					<select id="fs-masterclass-teacher" name="teacher_user_id" required>
						<option value="">Выберите преподавателя</option>
						<?php foreach ( $teachers as $teacher ) : ?>
							<option value="<?php echo esc_attr( (string) $teacher['id'] ); ?>"><?php echo esc_html( $teacher['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="fs-field">
				<label for="fs-masterclass-date" class="fs-field__label">Дата</label>
				<div class="fs-field__control">
					<input type="date" id="fs-masterclass-date" name="date" required>
				</div>
			</div>

			<div class="fs-field">
				<label for="fs-masterclass-start" class="fs-field__label">Начало и конец (местное время)</label>
				<div class="fs-field__control">
					<input type="time" id="fs-masterclass-start" name="start_time" class="small-text" value="<?php echo esc_attr( (string) $defaults['start_time'] ); ?>" required>
					—
					<input type="time" id="fs-masterclass-end" name="end_time" class="small-text" value="<?php echo esc_attr( (string) $defaults['end_time'] ); ?>" required>
				</div>
			</div>

			<div class="fs-field" id="fs-masterclass-count-field">
				<label for="fs-masterclass-count" class="fs-field__label">Сколько аккаунтов создать</label>
				<div class="fs-field__control">
					<input type="number" id="fs-masterclass-count" name="count" class="small-text" min="0" max="500" value="<?php echo esc_attr( (string) $defaults['batch_size'] ); ?>">
				</div>
				<p class="fs-field__desc">Создаются порциями по <?php echo esc_html( (string) $chunk_max ); ?>; позже можно докинуть ещё.</p>
			</div>

			<p>
				<button type="submit" class="button button-primary" id="fs-masterclass-save">Сохранить</button>
				<button type="button" class="button" id="fs-masterclass-cancel">Отмена</button>
			</p>
		</form>
	</div>
</div>
