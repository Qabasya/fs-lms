<?php
/** Настройки экзаменов для гостей в отдельной вкладке. */
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;
$exam      = $config['exams'] ?? array();
$exam_prod = $exam_products ?? array();
$exam_woo  = (bool) ( $exam_woo_active ?? false );
$exam_list = $exam_checklist ?? array();
$exam_num  = static function ( string $name, string $label, int $value, int $min, int $max, string $desc ): void {
	?>
	<div class="fs-field">
		<label for="fs-config-<?php echo esc_attr( $name ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
		<div class="fs-field__control">
			<input type="number" id="fs-config-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" data-exam-field class="small-text"
				min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
		</div>
		<?php if ( '' !== $desc ) : ?><p class="fs-field__desc"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
	</div>
	<?php
};
$exam_text = static function ( string $name, string $label, string $value, string $desc ): void {
	?>
	<div class="fs-field">
		<label for="fs-config-<?php echo esc_attr( $name ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
		<div class="fs-field__control">
			<input type="text" id="fs-config-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" data-exam-field class="regular-text" value="<?php echo esc_attr( $value ); ?>" />
		</div>
		<?php if ( '' !== $desc ) : ?><p class="fs-field__desc"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
	</div>
	<?php
};
?>
<div id="tab-exams" class="tab-pane active">
<div class="fs-lms-config">
	<form id="fs-config-form" class="fs-config-form">
		<!-- ======== Экзамены для гостей ======== -->
		<div class="fs-card fs-card--flat" id="fs-config-exams">
			<div class="fs-card__header">
				<h2 class="fs-card__title">Экзамены для гостей</h2>
			</div>
			<div class="fs-card__body">
				<?php if ( ! $exam_woo ) : ?>
					<p class="fs-card__desc">WooCommerce не активен: гостевая оплата недоступна.</p>
				<?php else : ?>
					<?php foreach ( array( 9 => 'Товар экзамена для 9 класса (ОГЭ)', 11 => 'Товар экзамена для 11 класса (ЕГЭ)' ) as $grade => $label ) : ?>
						<div class="fs-field">
							<label for="fs-config-exam-product-<?php echo esc_attr( (string) $grade ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
							<div class="fs-field__control">
								<select id="fs-config-exam-product-<?php echo esc_attr( (string) $grade ); ?>" name="exam_product_<?php echo esc_attr( (string) $grade ); ?>" data-exam-field>
									<option value="0">— не выбран —</option>
									<?php foreach ( $exam_prod as $product ) : ?>
										<option value="<?php echo esc_attr( (string) $product['id'] ); ?>" <?php selected( (int) ( $exam[ 'product_' . $grade ] ?? 0 ), (int) $product['id'] ); ?>>
											<?php echo esc_html( sprintf( '%s · %s ₽ · %s', $product['name'], $product['price'], $product['purchasable'] ? 'доступен' : 'недоступен к покупке' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					<?php endforeach; ?>
					<p class="fs-field__desc">Один и тот же товар можно выбрать для обоих классов. Цена берётся из магазина.</p>
				<?php endif; ?>

				<?php
				$exam_num( 'exam_hold_minutes', 'Срок временной брони, мин', (int) ( $exam['hold_minutes'] ?? 20 ), 5, 120, 'От 5 до 120 минут. Место удерживается за гостем, пока он оплачивает.' );
				$exam_num( 'exam_ip_active_holds', 'Активных броней с одного IP', (int) ( $exam['ip_active_holds'] ?? 40 ), 1, 10000, 'За общим адресом школы бывает до 20 человек — запас вдвое.' );
				$exam_num( 'exam_ip_hourly', 'Броней в час с одного IP', (int) ( $exam['ip_hourly'] ?? 60 ), 1, 10000, '' );
				$exam_num( 'exam_source_active_holds', 'Активных броней на ссылку школы', (int) ( $exam['source_active_holds'] ?? 60 ), 1, 10000, '' );
				$exam_num( 'exam_guest_retention_days', 'Хранение данных гостей, дней', (int) ( $exam['guest_retention_days'] ?? 365 ), 1, 3650, 'Считается от завершения проведения.' );
				$exam_num( 'exam_unpaid_retention_days', 'Хранение неоплаченных заявок, дней', (int) ( $exam['unpaid_retention_days'] ?? 30 ), 1, 3650, '' );
				$contacts = $exam['contacts'] ?? array();
				$exam_text( 'center_phone', 'Телефон центра', (string) ( $contacts['phone'] ?? '' ), 'Запасное значение: тема подставляет свои контакты через фильтр.' );
				$exam_text( 'center_email', 'Почта центра', (string) ( $contacts['email'] ?? '' ), '' );
				$exam_text( 'center_hours', 'Часы работы', (string) ( $contacts['hours'] ?? '' ), '' );
				$exam_text( 'center_address', 'Адрес', (string) ( $contacts['address'] ?? '' ), 'Например: Калининград, ул. Черняховского, д. 6, каб. 316 — номер кабинета в публичный адрес не попадает.' );
				?>

				<div class="fs-field">
					<span class="fs-field__label">Готовность к запуску</span>
					<ul class="fs-config-checklist">
						<?php foreach ( $exam_list as $item ) : ?>
							<li class="fs-config-checklist__item <?php echo $item['ok'] ? 'is-ok' : 'is-fail'; ?>">
								<?php echo $item['ok'] ? '✔' : '✖'; ?>
								<?php echo esc_html( $item['label'] ); ?>
								<?php if ( ! $item['ok'] ) : ?>
									<span class="fs-field__desc"> — <?php echo esc_html( $item['hint'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="fs-field__desc">Пока есть непройденный пункт, проведение с включённой «Записью гостей» опубликовать нельзя.</p>
				</div>
			</div>
			<div class="fs-card__footer">
				<button type="submit" class="button button-primary js-config-save">Сохранить</button>
				<span class="fs-config-status js-config-status"></span>
			</div>
		</div>

	</form>
</div>
</div>
