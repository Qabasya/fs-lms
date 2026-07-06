<?php

declare( strict_types=1 );

namespace Inc\Enums\Ui;

/**
 * Единственный источник SVG-иконок для PHP-шаблонов (templates/).
 *
 * Правила:
 * - НЕ писать инлайновые `<svg>` в шаблонах — только `Icon::X->svg( $size )`
 *   (echo с phpcs:ignore EscapeOutput: разметка доверенная, генерируется здесь).
 * - Размер один (иконки квадратные); 0/пусто — размер по умолчанию кейса.
 * - Цвет — currentColor (наследуется от родителя), как и в JS-зеркале.
 * - JS-зеркало — `src/js/common/icons.js`; общие глифы (check/chevron/lock/…)
 *   должны визуально совпадать с ним.
 *
 * @package Inc\Enums
 */
enum Icon: string {

	/** Логотип-«шапочка» в сайдбаре кабинета (белая, двухцветная). */
	case BrandMark = 'brand_mark';

	/** Свернуть сайдбар (панель со стрелкой влево). */
	case SidebarCollapse = 'sidebar_collapse';

	/** Развернуть сайдбар (панель со стрелкой вправо). */
	case SidebarExpand = 'sidebar_expand';

	case Bell  = 'bell';
	case Home  = 'home';
	case Check = 'check';

	/** «Вернуться» из плеера (изогнутая стрелка назад). */
	case Back = 'back';

	case ChevronLeft  = 'chevron_left';
	case ChevronRight = 'chevron_right';
	case ChevronDown  = 'chevron_down';
	case Lock         = 'lock';

	/** Закрепить панель (рейка плеера). */
	case Pin = 'pin';

	case Clock = 'clock';

	/** «Завершить работу/контрольную» (флажок). */
	case Flag = 'flag';

	/* Видео-плеер (step-video). */
	case Play          = 'play';
	case Pause         = 'pause';
	case SeekBack10    = 'seek_back_10';
	case SeekForward10 = 'seek_forward_10';
	case Fullscreen    = 'fullscreen';

	/** Файл-вложение (конспект). */
	case File = 'file';

	case Download = 'download';

	/**
	 * Готовая SVG-разметка иконки.
	 *
	 * @param int $size Сторона в px; 0 — размер по умолчанию кейса.
	 */
	public function svg( int $size = 0 ): string {
		$s = $size > 0 ? $size : $this->defaultSize();

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="%2$s" fill="none" aria-hidden="true">%3$s</svg>',
			$s,
			$this->viewBox(),
			$this->body()
		);
	}

	private function defaultSize(): int {
		return match ( $this ) {
			self::BrandMark       => 18,
			self::SidebarCollapse => 17,
			self::SidebarExpand   => 18,
			self::Bell            => 20,
			self::Home            => 20,
			self::Check           => 16,
			self::Back            => 16,
			self::ChevronLeft,
			self::ChevronRight    => 15,
			self::ChevronDown     => 13,
			self::Lock            => 13,
			self::Pin             => 15,
			self::Clock           => 14,
			self::Flag            => 14,
			self::Play,
			self::Pause,
			self::Fullscreen      => 16,
			self::SeekBack10,
			self::SeekForward10,
			self::File,
			self::Download        => 17,
		};
	}

	private function viewBox(): string {
		return '0 0 20 20';
	}

	private function body(): string {
		return match ( $this ) {
			self::BrandMark       => '<path d="M3 5.5 10 2l7 3.5L10 9 3 5.5z" fill="#fff"/><path d="M6 8v3.5c0 1.2 1.8 2.2 4 2.2s4-1 4-2.2V8" stroke="#fff" stroke-width="1.4" fill="none"/>',
			self::SidebarCollapse => '<rect x="2.5" y="3.5" width="15" height="13" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M7.5 3.5v13" stroke="currentColor" stroke-width="1.5"/><path d="M13.8 8 11.8 10l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			self::SidebarExpand   => '<rect x="2.5" y="3.5" width="15" height="13" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M7.5 3.5v13" stroke="currentColor" stroke-width="1.5"/><path d="M11.6 8l2 2-2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Bell            => '<path d="M10 3a4 4 0 0 0-4 4c0 4-1.5 5-1.5 5h11S14 11 14 7a4 4 0 0 0-4-4zM8.5 15a1.5 1.5 0 0 0 3 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Home            => '<path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Check           => '<path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Back            => '<path d="M15 5v4a4 4 0 0 1-4 4H5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 9.5 4.3 13 8 16.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
			self::ChevronLeft     => '<path d="M12 4.5 6.5 10l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			self::ChevronRight    => '<path d="M8 4.5 13.5 10 8 15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			self::ChevronDown     => '<path d="M4.5 8 10 13.5 15.5 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Lock            => '<rect x="4.5" y="8.5" width="11" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 8.5V6.5a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5"/>',
			self::Pin             => '<path d="M8 3h4l.6 5.2 2.4 2.3v1.5H5v-1.5l2.4-2.3L8 3zM10 12v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Clock           => '<circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.2l2.8 1.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
			self::Flag            => '<path d="M5 17V3.5M5 4h9.5l-2 3 2 3H5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Play            => '<path d="M7 4.8v10.4L15.5 10 7 4.8z" fill="currentColor"/>',
			self::Pause           => '<rect x="5.5" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/><rect x="11.3" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/>',
			self::SeekBack10      => '<path d="M10 3a7 7 0 1 1-6.4 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M3 3v4.5h4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><text x="7" y="14.5" fill="currentColor" font-size="6.5" font-weight="700">10</text>',
			self::SeekForward10   => '<path d="M10 3a7 7 0 1 0 6.4 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M17 3v4.5h-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><text x="6.5" y="14.5" fill="currentColor" font-size="6.5" font-weight="700">10</text>',
			self::Fullscreen      => '<path d="M3 8V3h5M12 3h5v5M17 12v5h-5M8 17H3v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			self::File            => '<path d="M5 2.5h6.2L15.5 6.8V17.5H5V2.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M11 3v4.3h4.3" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
			self::Download        => '<path d="M10 3v9m0 0 3.5-3.5M10 12 6.5 8.5M4 16h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
		};
	}
}
