<?php
/**
 * Секция настроек модуля DaData в табе «Конфигурация».
 * Рендерится через generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает).
 *
 * @var \Inc\Modules\DaData\Config\DaDataConfig $config
 *
 * @package Inc\Modules\DaData
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$dadata_token       = $config->token();
$dadata_from_config = $config->tokenFromConstant();
?>

<form id="fs-dadata-form" class="fs-config-form">
	<div class="fs-card fs-card--flat">

		<div class="fs-card__header">
			<h2 class="fs-card__title">Автодополнение с помощью DaData</h2>
			<div class="fs-card__actions">
				<a href="#" class="fs-config-help-link js-open-help-modal" data-provider="dadata">
					Как подключить? <span class="dashicons dashicons-external"></span>
				</a>
			</div>
		</div>

		<div class="fs-card__body">
			<p class="fs-card__desc">
				Подсказки ФИО и адреса на форме завершения регистрации (<code>/lms/join</code>) через API DaData.
				Токен можно задать здесь или константой <code>DADATA_API_TOKEN</code> в <code>wp-config.php</code> (тогда поле только для чтения).
			</p>
			<div class="fs-field">
				<label for="fs-dadata-token" class="fs-field__label">
					DaData API Token
					<?php if ( $dadata_from_config ) : ?>
						<?php render_fs_badge( 'wp-config', 'blue' ); ?>
					<?php endif; ?>
				</label>
				<div class="fs-field__control">
					<input
						type="text"
						id="fs-dadata-token"
						name="dadata_token"
						class="regular-text"
						value="<?php echo esc_attr( $dadata_token ); ?>"
						<?php echo $dadata_from_config ? 'disabled readonly' : ''; ?>
					/>
				</div>
				<p class="fs-field__desc">Токен для API DaData (подсказки адресов и ФИО на форме записи).</p>
			</div>
		</div>

		<?php if ( ! $dadata_from_config ) : ?>
			<div class="fs-card__footer">
				<button type="submit" id="fs-dadata-save" class="button button-primary">Сохранить</button>
				<span class="fs-config-status" id="fs-dadata-status"></span>
			</div>
		<?php endif; ?>

	</div>
</form>
