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

<div class="fs-config-section fs-config-section--dadata">
	<h2 class="fs-config-section__title">Автодополнение с помощью DaData</h2>
	<p class="description">
		Подсказки ФИО и адреса на форме завершения регистрации (<code>/lms/join</code>) через API DaData.
		Токен можно задать здесь или константой <code>DADATA_API_TOKEN</code> в <code>wp-config.php</code> (тогда поле только для чтения).
	</p>

	<form id="fs-dadata-form" class="fs-config-form">
		<div class="fs-config-field">
			<label for="fs-dadata-token" class="fs-config-field__label">
				DaData API Token
				<?php if ( $dadata_from_config ) : ?>
					<?php render_fs_badge( 'wp-config', 'blue' ); ?>
				<?php endif; ?>
				<a href="#" class="fs-config-help-link js-open-help-modal" data-provider="dadata">
					Как подключить? <span class="dashicons dashicons-external"></span>
				</a>
			</label>
			<input
				type="text"
				id="fs-dadata-token"
				name="dadata_token"
				class="regular-text"
				value="<?php echo esc_attr( $dadata_token ); ?>"
				<?php echo $dadata_from_config ? 'disabled readonly' : ''; ?>
			/>
			<p class="description">Токен для API DaData (подсказки адресов и ФИО на форме записи).</p>
		</div>

		<?php if ( ! $dadata_from_config ) : ?>
			<div class="fs-config-actions">
				<button type="submit" id="fs-dadata-save" class="button button-primary">Сохранить</button>
				<span class="fs-config-status" id="fs-dadata-status"></span>
			</div>
		<?php endif; ?>
	</form>
</div>
