<?php
/**
 * Вкладка «Мастер-классы» в настройках плагина. Показывается только при включённом модуле;
 * тумблер модуля живёт на Dashboard.
 *
 * @var \Inc\Modules\Masterclass\Config\MasterclassConfig $masterclass_config Передаётся контроллером через `data` вкладки (имя не `$config`: страница настроек уже держит под ним массив настроек плагина, а `extract()` с `EXTR_SKIP` его не перезапишет).
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Inc\Modules\Masterclass\Config\MasterclassConfig;

$mk_number = static function ( string $name, string $label, int $value, string $desc ): void {
	[ , $min, $max ] = MasterclassConfig::INT_BOUNDS[ $name ];
	?>
	<div class="fs-field">
		<label for="fs-masterclass-<?php echo esc_attr( $name ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
		<div class="fs-field__control">
			<input type="number" id="fs-masterclass-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" class="small-text"
				min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
		</div>
		<p class="fs-field__desc"><?php echo esc_html( $desc ); ?> От <?php echo esc_html( (string) $min ); ?> до <?php echo esc_html( (string) $max ); ?>.</p>
	</div>
	<?php
};

$mk_input = static function ( string $type, string $name, string $label, string $value, string $desc, string $class = 'regular-text', int $maxlength = 0 ): void {
	?>
	<div class="fs-field">
		<label for="fs-masterclass-<?php echo esc_attr( $name ); ?>" class="fs-field__label"><?php echo esc_html( $label ); ?></label>
		<div class="fs-field__control">
			<input type="<?php echo esc_attr( $type ); ?>" id="fs-masterclass-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" class="<?php echo esc_attr( $class ); ?>"
				value="<?php echo esc_attr( $value ); ?>"<?php echo $maxlength > 0 ? ' maxlength="' . esc_attr( (string) $maxlength ) . '"' : ''; ?> />
		</div>
		<?php if ( '' !== $desc ) : ?>
			<p class="fs-field__desc"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div id="tab-masterclasses" class="tab-pane active">
	<div class="fs-lms-config">
		<form id="fs-masterclass-form" class="fs-config-form">

			<div class="fs-card fs-card--flat">
				<div class="fs-card__header">
					<h2 class="fs-card__title">Окно мастер-класса по умолчанию</h2>
				</div>
				<div class="fs-card__body">
					<?php
					$mk_input( 'time', 'start_time', 'Начало', $masterclass_config->startTime(), 'Подставляется в форму нового мастер-класса.', 'small-text' );
					$mk_input( 'time', 'end_time', 'Конец', $masterclass_config->endTime(), 'Должен быть позже начала.', 'small-text' );
					?>
				</div>
			</div>

			<div class="fs-card fs-card--flat">
				<div class="fs-card__header">
					<h2 class="fs-card__title">Аккаунты и сроки</h2>
				</div>
				<div class="fs-card__body">
					<?php
					$mk_number( 'batch_size', 'Аккаунтов в одной пачке', $masterclass_config->batchSize(), 'Столько временных аккаунтов предлагает форма по умолчанию.' );
					$mk_number( 'home_timeout_minutes', 'Повторный вход «дома» через, мин', $masterclass_config->homeTimeoutMinutes(), 'Вход позже этого срока после первого считается домашним.' );
					$mk_number( 'freeze_days', 'Заморозка доступа через, дней', $masterclass_config->freezeDays(), 'Считается от конца мастер-класса.' );
					$mk_number( 'unused_purge_days', 'Удаление неиспользованных аккаунтов через, дней', $masterclass_config->unusedPurgeDays(), 'Считается от конца мастер-класса.' );
					$mk_number( 'frozen_purge_days', 'Полная очистка после заморозки через, дней', $masterclass_config->frozenPurgeDays(), 'Аккаунты с поданной заявкой не удаляются, а отвязываются.' );
					$mk_number( 'log_retention_days', 'Хранение журнала, дней', $masterclass_config->logRetentionDays(), 'Старые записи журнала мастер-классов удаляются.' );
					?>
				</div>
			</div>

			<div class="fs-card fs-card--flat">
				<div class="fs-card__header">
					<h2 class="fs-card__title">Связь с ребёнком</h2>
				</div>
				<div class="fs-card__body">
					<?php
					$mk_input( 'url', 'messenger_url', 'Ссылка на мессенджер', $masterclass_config->messengerUrl(), 'Кнопка в окне «Связаться». Пусто — кнопки нет. Телефон берётся из «Настройки → Экзамены → Телефон центра».' );
					$mk_input( 'text', 'banner_text', 'Текст баннера в плеере', $masterclass_config->bannerText(), 'До ' . MasterclassConfig::BANNER_MAX . ' символов. Пусто — текст по умолчанию.', 'large-text', MasterclassConfig::BANNER_MAX );
					?>
				</div>
				<div class="fs-card__footer">
					<button type="submit" id="fs-masterclass-save" class="button button-primary">Сохранить</button>
					<span class="fs-config-status" id="fs-masterclass-status"></span>
				</div>
			</div>

		</form>
	</div>
</div>
