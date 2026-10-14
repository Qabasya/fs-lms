<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Config;

/**
 * Class OgeSlidesConfig
 *
 * Слайды инструкции станции ОГЭ — карусель `kege/entry.php`, тот же экран, что у {@see KegeSlidesConfig}:
 * приветственные экраны из `.docs/exam-screens/oge` (обновлены 2026-10-14), шесть картинок. Контент отделён от
 * рендера: `entry.php` просто перебирает массив.
 *
 * Бланк регистрации и код активации — ОБЩИЕ с ЕГЭ (решено с пользователем
 * 2026-08-18): `blankHint()` здесь не заводится, `EgeComputerModule` для обоих
 * kind резолвит {@see KegeSlidesConfig::blankHint()}.
 *
 * @package Inc\Modules\EgeComputer\Config
 */
class OgeSlidesConfig {

	/** Каталог картинок инструкции относительно корня плагина — общий с КЕГЭ. */
	private const IMAGE_DIR = 'inc/Modules/EgeComputer/assets/images/';

	/**
	 * Слайды инструкции по порядку показа (тот же контракт полей, что у {@see KegeSlidesConfig::slides()}).
	 *
	 * @return array<int, array{image: string, alt: string, width: int, height: int}>
	 */
	public static function slides(): array {
		$slides = [
			[ 'welcome-oge-1.webp', 'Инструкция к заданиям КИМ: состав и время выполнения работы', 1920, 1081 ],
			[ 'welcome-oge-2.webp', 'Инструкция к заданиям КИМ: кнопка «i» и переход между заданиями', 1920, 1081 ],
			[ 'welcome-oge-3.webp', 'Прохождение экзамена: информация об экзамене, меню заданий, текст задания, файлы и ответ', 1920, 1081 ],
			[ 'welcome-oge-4.webp', 'Боковое меню заданий: количество ответов, активное задание, задания с ответом и без', 1920, 1081 ],
			[ 'welcome-oge-5.webp', 'Информация об экзамене и элементы управления: таймер, номера КИМ и бланка, досрочное завершение', 1920, 1081 ],
			[ 'welcome-oge-6.webp', 'Область ввода краткого ответа: пустое и сохранённое состояние', 1920, 1081 ],
		];

		return array_map(
			static fn( array $slide ): array => [
				'image'  => self::imageUrl( $slide[0] ),
				'alt'    => $slide[1],
				'width'  => $slide[2],
				'height' => $slide[3],
			],
			$slides
		);
	}

	/**
	 * Публичный адрес картинки станции.
	 *
	 * @param string $file Имя файла в каталоге картинок
	 */
	private static function imageUrl( string $file ): string {
		return plugins_url( self::IMAGE_DIR . $file, FS_LMS_PATH . 'fs-lms.php' );
	}
}
