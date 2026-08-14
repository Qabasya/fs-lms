<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Config;

/**
 * Class KegeSlidesConfig
 *
 * Слайды инструкции станции КЕГЭ (тренажёр компьютерного ЕГЭ) — карусель
 * скриншотов настоящей станции: слайд это картинка плюс alt и её собственные
 * размеры. Чтобы добавить/убрать слайд — правится только этот массив и каталог
 * с картинками; шаблон и JS о содержимом ничего не знают (контент отделён от
 * рендера).
 *
 * URL собирается здесь, а не в шаблоне: шаблон не должен знать, где лежат
 * ассеты модуля.
 *
 * Картинки лежат В МОДУЛЕ, а не в корневом `assets/`: корневой каталог целиком
 * в `.gitignore` (это build-выход gulp), и рукописные ассеты оттуда не уехали
 * бы в репозиторий. `inc/Modules/*\/assets/` — штатное место для таких файлов.
 *
 * @package Inc\Modules\EgeComputer\Config
 */
class KegeSlidesConfig {

	/** Каталог картинок инструкции относительно корня плагина. */
	private const IMAGE_DIR = 'inc/Modules/EgeComputer/assets/images/';

	/**
	 * Слайды инструкции по порядку показа.
	 *
	 * @return array<int, array{image: string, alt: string, width: int, height: int}>
	 */
	public static function slides(): array {
		$slides = [
			[ 'instruction-1.webp', 'Инструкция к заданиям КИМ: состав и время выполнения работы', 1541, 1073 ],
			[ 'instruction-2.webp', 'Инструкция к заданиям КИМ: обозначения и соглашения', 1542, 1073 ],
			[ 'instruction-3.webp', 'Ввод номера бланка регистрации', 1541, 1073 ],
			[ 'instruction-4.webp', 'Регистрация участника и номер КИМ', 1541, 1074 ],
			[ 'instruction-5.webp', 'Активация экзамена по коду организатора', 1541, 1075 ],
			[ 'instruction-6.webp', 'Экран экзамена: навигация по заданиям', 1542, 1073 ],
			[ 'instruction-7.webp', 'Ввод и сохранение ответов', 1538, 1074 ],
			[ 'instruction-8.webp', 'Страница завершения экзамена и контрольная сумма', 1529, 1064 ],
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
	 * Скан бланка регистрации для экрана ввода номера — подсказка, где именно
	 * на бланке напечатан нужный номер.
	 *
	 * @return array{image: string, alt: string, width: int, height: int}
	 */
	public static function blankHint(): array {
		return [
			'image'  => self::imageUrl( 'blank-number.webp' ),
			'alt'    => 'Бланк регистрации: номер напечатан под штрихкодом слева',
			'width'  => 786,
			'height' => 500,
		];
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
