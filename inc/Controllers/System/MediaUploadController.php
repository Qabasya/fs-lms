<?php

declare( strict_types=1 );

namespace Inc\Controllers\System;

use Inc\Contracts\ServiceInterface;
use Inc\Managers\Wp\MediaManager;

/**
 * Class MediaUploadController
 *
 * Контроллер проверки типов загружаемых файлов.
 *
 * Регистрирует постоянный фильтр 'wp_check_filetype_and_ext' — действует на все
 * пути загрузки (медиатека в админке, async-upload, AJAX плагина). Логика — в
 * MediaManager::allowTxtDetectedAsCsv(): .txt с дробями через запятую («1,5»,
 * данные ЕГЭ, задание 27) finfo принимает за text/csv, и ядро отказывает в
 * загрузке из-за расхождения с заявленным text/plain.
 *
 * @package Inc\Controllers
 */
class MediaUploadController implements ServiceInterface {

	public function __construct(
		private readonly MediaManager $media,
	) {
	}

	public function register(): void {
		add_filter( 'wp_check_filetype_and_ext', array( $this->media, 'allowTxtDetectedAsCsv' ), 10, 5 );
	}
}
