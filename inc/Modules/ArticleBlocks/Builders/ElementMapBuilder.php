<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Builders;

use Inc\Modules\ArticleBlocks\Enums\ArticleBlock;
use Inc\Modules\ArticleBlocks\Services\ArticleBlockRenderer;
use Inc\Modules\ArticleBlocks\Services\ImageSizeOptions;

/**
 * Class ElementMapBuilder
 *
 * Описания элементов WPBakery для блоков статьи (массивы для `vc_map()`).
 *
 * Значения по умолчанию (`std`) совпадают с умолчаниями {@see ArticleBlockRenderer}: WPBakery не
 * пишет в шорткод атрибут, равный пустому значению, и рендер обязан понять его так же.
 *
 * @package Inc\Modules\ArticleBlocks\Builders
 */
final readonly class ElementMapBuilder {

	/** Категория элементов в панели «Добавить элемент». */
	public const CATEGORY = 'Статьи FS LMS';

	/** Тип поля модуля: многострочный текст, всегда в base64 (см. BlockValueCodec). */
	public const ENCODED_PARAM = 'fs_lms_encoded_text';

	public function __construct(
		private ImageSizeOptions $sizes,
	) {}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function elements(): array {
		return array_map( fn( ArticleBlock $block ): array => $this->element( $block ), ArticleBlock::cases() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function element( ArticleBlock $block ): array {
		return array(
			'name'                    => $block->label(),
			'base'                    => $block->value,
			'category'                => self::CATEGORY,
			'description'             => $block->description(),
			'icon'                    => $block->icon(),
			'show_settings_on_create' => true,
			'params'                  => match ( $block ) {
				ArticleBlock::Code    => $this->codeParams(),
				ArticleBlock::Table   => $this->tableParams(),
				ArticleBlock::Image   => $this->imageParams(),
				ArticleBlock::Heading => $this->headingParams(),
			},
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function codeParams(): array {
		return array(
			array(
				'type'        => self::ENCODED_PARAM,
				'heading'     => 'Код',
				'param_name'  => 'code',
				'fs_mode'     => 'code',
				'description' => 'Вставьте код как есть — экранировать символы не нужно. Tab добавляет отступ в 4 пробела.',
			),
			array(
				'type'        => 'dropdown',
				'heading'     => 'Язык',
				'param_name'  => 'lang',
				'value'       => array_combine( ArticleBlockRenderer::LANGUAGES, ArticleBlockRenderer::LANGUAGES ),
				'std'         => ArticleBlockRenderer::LANGUAGES[0],
				'admin_label' => true,
				'description' => 'Подпись на плашке. Подсветка синтаксиса — для Python.',
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function tableParams(): array {
		return array(
			array(
				'type'        => self::ENCODED_PARAM,
				'heading'     => 'Данные',
				'param_name'  => 'rows',
				'fs_mode'     => 'table',
				'description' => 'Скопируйте диапазон ячеек из LibreOffice Calc или Excel и вставьте сюда. Вручную — строки через «|» или Tab. `x` в ячейке — моноширинный текст.',
			),
			array(
				'type'       => 'checkbox',
				'heading'    => 'Строка заголовка',
				'param_name' => 'no_header',
				'value'      => array( 'Без строки заголовка (по умолчанию первая строка — заголовок)' => 'yes' ),
			),
			array(
				'type'        => 'textfield',
				'heading'     => 'Подпись',
				'param_name'  => 'caption',
				'admin_label' => true,
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function imageParams(): array {
		return array(
			array(
				'type'       => 'attach_image',
				'heading'    => 'Изображение',
				'param_name' => 'image',
			),
			array(
				'type'        => 'dropdown',
				'heading'     => 'Размер',
				'param_name'  => 'size',
				'value'       => $this->sizes->options(),
				'std'         => ImageSizeOptions::DEFAULT,
				'description' => 'Шире текста статьи картинка не станет.',
			),
			array(
				'type'        => 'textfield',
				'heading'     => 'Ширина, px',
				'param_name'  => 'width',
				'description' => 'Необязательно. Перекрывает «Размер»; высота подстраивается пропорционально.',
			),
			array(
				'type'        => 'textfield',
				'heading'     => 'Подпись',
				'param_name'  => 'caption',
				'admin_label' => true,
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function headingParams(): array {
		return array(
			array(
				'type'        => 'textfield',
				'heading'     => 'Текст',
				'param_name'  => 'text',
				'admin_label' => true,
			),
			array(
				'type'       => 'dropdown',
				'heading'    => 'Уровень',
				'param_name' => 'level',
				'value'      => array(
					'H2 — раздел'    => 'h2',
					'H3 — подраздел' => 'h3',
				),
				'std'        => 'h2',
			),
		);
	}
}
