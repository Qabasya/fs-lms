<?php
/**
 * Страница записи гостя на экзамен по ссылке школы (этап 11a.3).
 *
 * Выводится шорткодом `[fs_lms_exam_signup]` внутри страницы темы (как форма заявки), поэтому шапку и подвал даёт сама тема.
 * Разметка карточки и полей — существующая (`fs-join-card`, `fs-field-control`). **Капчи на форме нет** (решение 26): защита —
 * honeypot, токен времени и лимиты на сервере. Цена приходит с сервера из WooCommerce, школа/класс/преподаватель — текст с замком, не поля.
 *
 * @package FS LMS
 *
 * @var string                       $state     form | full | closed | unavailable
 * @var array{school: string, grade: int, teacher: string}   $source
 * @var array{title: string}         $event
 * @var array<string, string>        $contacts  phone, email, hours, city, street
 * @var list<array<string, mixed>>   $sessions  Карточки сеансов
 * @var string                       $direction Направление («ЕГЭ» / «ОГЭ»)
 * @var array{html: string, amount: string}|null $price Цена из магазина
 * @var string                       $address   Адрес без номера кабинета
 * @var int                          $hold_minutes
 * @var int                          $retention_days
 * @var list<array{key: string, title: string, url: string, required: bool}> $consents
 * @var string                       $honeypot  Имя honeypot-поля
 * @var string                       $form_token Подписанная метка времени
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$contact_phone = (string) ( $contacts['phone'] ?? '' );
?>

<main class="fs-lms-join-page fs-exam-signup" id="fs-exam-signup" data-state="<?php echo esc_attr( $state ); ?>">
	<div class="fs-join-card">
		<h2 class="fs-join-card__title"><?php esc_html_e( 'Запись на экзамен', 'fs-lms' ); ?></h2>
		<p class="fs-join-card__subtitle"><?php echo esc_html( (string) ( $event['title'] ?? '' ) ); ?></p>

		<?php if ( 'form' !== $state ) : ?>
			<?php
			$messages = array(
				'full'        => __( 'Свободных мест нет.', 'fs-lms' ),
				'closed'      => __( 'Запись закрыта.', 'fs-lms' ),
				'unavailable' => __( 'Запись временно недоступна.', 'fs-lms' ),
			);
			?>
			<p class="fs-exam-signup__state" role="status">
				<?php echo esc_html( $messages[ $state ] ?? $messages['closed'] ); ?>
				<?php if ( '' !== $contact_phone ) : ?>
					<?php echo esc_html( sprintf( 'Обратитесь к сотруднику: %s.', $contact_phone ) ); ?>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<form id="fs-exam-signup-form" name="fs_exam_signup_form" method="post" novalidate autocomplete="on">

				<!-- Организатор приглашения: текст с замком, не поля ввода и не скрытые поля -->
				<fieldset class="fs-join-card__section">
					<legend class="fs-join-card__section-title"><?php esc_html_e( 'Организатор приглашения', 'fs-lms' ); ?></legend>
					<p class="fs-join-card__locked-notice fs-exam-signup__organizer">
						<span class="dashicons dashicons-lock" aria-hidden="true"></span>
						<span>
							<?php echo esc_html( sprintf( '%s, %d класс', (string) $source['school'], (int) $source['grade'] ) ); ?><br>
							<?php echo esc_html( (string) $source['teacher'] ); ?>
						</span>
					</p>
				</fieldset>

				<!-- Данные участника -->
				<fieldset class="fs-join-card__section">
					<legend class="fs-join-card__section-title"><?php esc_html_e( 'Данные участника', 'fs-lms' ); ?></legend>
					<p class="fs-join-card__subtitle fs-exam-signup__hint"><?php esc_html_e( 'ФИО того, кто будет сдавать экзамен', 'fs-lms' ); ?></p>

					<?php
					$fields = array(
						array( 'last_name', 'Фамилия', 'family-name', true, 'dashicons-admin-users', 'text' ),
						array( 'first_name', 'Имя', 'given-name', true, 'dashicons-admin-users', 'text' ),
						array( 'middle_name', 'Отчество (если есть)', 'additional-name', false, 'dashicons-admin-users', 'text' ),
						array( 'phone', 'Телефон', 'tel', true, 'dashicons-phone', 'tel' ),
					);
					foreach ( $fields as list( $name, $label, $autocomplete, $required, $icon, $type ) ) :
						?>
						<div class="fs-join-card__field-group fs-form-group" data-field="<?php echo esc_attr( $name ); ?>">
							<label for="fs_exam_<?php echo esc_attr( $name ); ?>">
								<?php echo esc_html( $label ); ?><?php if ( $required ) : ?> <span aria-hidden="true">*</span><?php endif; ?>
							</label>
							<div class="fs-field-control">
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
								<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" id="fs_exam_<?php echo esc_attr( $name ); ?>"
									autocomplete="<?php echo esc_attr( $autocomplete ); ?>" <?php echo 'phone' === $name ? 'inputmode="tel" placeholder="+7 (___) ___-__-__"' : 'maxlength="100"'; ?>
									<?php echo $required ? 'required aria-required="true"' : ''; ?>>
							</div>
							<p class="fs-join-card__error" data-error="<?php echo esc_attr( $name ); ?>" role="alert" hidden></p>
						</div>
					<?php endforeach; ?>

					<!-- Мессенджер: простой текстовый ввод любых символов, без кнопки и без проверки формата -->
					<div class="fs-join-card__field-group fs-form-group" data-field="messenger">
						<label for="fs_exam_messenger"><?php esc_html_e( 'Связь через мессенджер', 'fs-lms' ); ?></label>
						<div class="fs-field-control">
							<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
							<input type="text" name="messenger" id="fs_exam_messenger" maxlength="100" autocomplete="off">
						</div>
						<p class="fs-join-card__error" data-error="messenger" role="alert" hidden></p>
					</div>
				</fieldset>

				<!-- Дата и время: карусель сеансов; сеанс автоматически не выбирается -->
				<fieldset class="fs-join-card__section" data-field="session_id">
					<legend class="fs-join-card__section-title"><?php esc_html_e( 'Дата и время', 'fs-lms' ); ?></legend>
					<div class="fs-exam-carousel" id="fs-exam-carousel">
						<button type="button" class="fs-exam-carousel__arrow fs-exam-carousel__arrow--prev" aria-label="<?php esc_attr_e( 'Назад', 'fs-lms' ); ?>" disabled>
							<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
						</button>
						<div class="fs-exam-carousel__track" role="radiogroup" aria-label="<?php esc_attr_e( 'Сеансы', 'fs-lms' ); ?>">
							<?php foreach ( $sessions as $session ) : ?>
								<button type="button" class="fs-exam-slot<?php echo $session['selectable'] ? '' : ' is-full'; ?>" role="radio" aria-checked="false"
									data-session="<?php echo esc_attr( (string) $session['id'] ); ?>"
									data-date="<?php echo esc_attr( (string) $session['date'] ); ?>"
									data-weekday="<?php echo esc_attr( (string) $session['weekday'] ); ?>"
									data-time="<?php echo esc_attr( (string) $session['time_start'] ); ?>"
									data-room="<?php echo esc_attr( (string) $session['room'] ); ?>"
									<?php echo $session['selectable'] ? '' : 'disabled aria-disabled="true"'; ?>>
									<span class="fs-exam-slot__date"><?php echo esc_html( wp_date( 'j F', (int) strtotime( (string) $session['date'] ) ) ); ?></span>
									<span class="fs-exam-slot__weekday"><?php echo esc_html( (string) $session['weekday'] ); ?></span>
									<span class="fs-exam-slot__time"><?php echo esc_html( (string) $session['time_start'] ); ?></span>
									<span class="fs-exam-slot__room"><?php echo esc_html( $session['selectable'] ? sprintf( 'каб. %s', $session['room'] ) : __( 'мест нет', 'fs-lms' ) ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>
						<button type="button" class="fs-exam-carousel__arrow fs-exam-carousel__arrow--next" aria-label="<?php esc_attr_e( 'Вперёд', 'fs-lms' ); ?>">
							<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						</button>
					</div>
					<input type="hidden" name="session_id" id="fs_exam_session_id" value="">
					<p class="fs-join-card__error" data-error="session_id" role="alert" hidden></p>
				</fieldset>

				<!-- Согласия: обработка ПД обязательна; передача результата преподавателю и маркетинг — не отмечены по умолчанию -->
				<fieldset class="fs-join-card__section fs-join-card__section--consents">
					<legend class="screen-reader-text"><?php esc_html_e( 'Согласия', 'fs-lms' ); ?></legend>
					<?php foreach ( $consents as $consent ) : ?>
						<div class="fs-join-card__consent" data-field="consent_<?php echo esc_attr( $consent['key'] ); ?>">
							<label>
								<input type="checkbox" name="consents[]" value="<?php echo esc_attr( $consent['key'] ); ?>" <?php echo $consent['required'] ? 'required' : ''; ?>>
								<span>
									<?php echo esc_html( $consent['title'] ); ?><?php if ( $consent['required'] ) : ?> <span aria-hidden="true">*</span><?php endif; ?>
									<?php if ( '' !== $consent['url'] ) : ?>
										<a href="<?php echo esc_url( $consent['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Прочитать', 'fs-lms' ); ?></a>
									<?php endif; ?>
								</span>
							</label>
							<p class="fs-join-card__error" data-error="consent_<?php echo esc_attr( $consent['key'] ); ?>" role="alert" hidden></p>
						</div>
					<?php endforeach; ?>
					<p class="fs-exam-signup__login">
						<?php esc_html_e( 'Уже учитесь у нас?', 'fs-lms' ); ?>
						<a href="<?php echo esc_url( add_query_arg( 'redirect_to', rawurlencode( \Inc\Enums\Wp\PageRoutes::UserProfile->screenUrl( 'learner-exams' ) ), \Inc\Enums\Wp\PageRoutes::SignIn->url() ) ); ?>"><?php esc_html_e( 'Войти', 'fs-lms' ); ?></a>
					</p>
				</fieldset>

				<!-- Резюме перед оплатой: обновляется при вводе и выборе сеанса -->
				<section class="fs-exam-summary" id="fs-exam-summary" aria-live="polite"
					data-direction="<?php echo esc_attr( $direction ); ?>"
					data-address="<?php echo esc_attr( $address ); ?>"
					data-price="<?php echo esc_attr( (string) ( $price['amount'] ?? '' ) ); ?>">
					<h3 class="fs-exam-summary__title"><?php esc_html_e( 'Ваша запись', 'fs-lms' ); ?></h3>
					<dl class="fs-exam-summary__list">
						<dt><?php esc_html_e( 'Участник', 'fs-lms' ); ?></dt><dd data-summary="name">—</dd>
						<dt><?php esc_html_e( 'Экзамен', 'fs-lms' ); ?></dt><dd data-summary="direction"><?php echo esc_html( $direction ); ?></dd>
						<dt><?php esc_html_e( 'Дата', 'fs-lms' ); ?></dt><dd data-summary="date">—</dd>
						<dt><?php esc_html_e( 'Время', 'fs-lms' ); ?></dt><dd data-summary="time">—</dd>
						<dt><?php esc_html_e( 'Адрес', 'fs-lms' ); ?></dt><dd data-summary="address"><?php echo esc_html( $address ); ?></dd>
						<dt><?php esc_html_e( 'Стоимость', 'fs-lms' ); ?></dt><dd data-summary="price"><?php echo wp_kses_post( (string) ( $price['html'] ?? '' ) ); ?></dd>
					</dl>
					<p class="fs-exam-summary__note">
						<?php echo esc_html( sprintf( 'Место удерживается %d мин. после перехода к оплате. Данные хранятся %d дн. после экзамена.', $hold_minutes, $retention_days ) ); ?>
					</p>
				</section>

				<!-- Honeypot и метка времени (капчи нет) -->
				<div class="fs-exam-signup__hp" aria-hidden="true">
					<label><?php esc_html_e( 'Не заполняйте это поле', 'fs-lms' ); ?>
						<input type="text" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off">
					</label>
				</div>
				<input type="hidden" name="form_token" value="<?php echo esc_attr( $form_token ); ?>">

				<p class="fs-join-card__error" id="fs-exam-form-error" role="alert" hidden></p>
				<button type="submit" id="fs-exam-submit" class="fs-join-card__submit"><?php esc_html_e( 'Перейти к оплате', 'fs-lms' ); ?></button>
				<p class="fs-exam-signup__status" id="fs-exam-status" role="status" hidden></p>
			</form>
		<?php endif; ?>
	</div>
</main>
