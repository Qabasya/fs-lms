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

	case Clock = 'clock';

	/** «Завершить работу/контрольную» (флажок). */
	case Flag = 'flag';

	/* Видео-плеер (step-video). */
	case Play          = 'play';
	case Pause         = 'pause';
	case SeekBack10    = 'seek_back_10';
	case SeekForward10 = 'seek_forward_10';
	case Fullscreen    = 'fullscreen';
	case Volume        = 'volume';
	case VolumeOff     = 'volume_off';
	case PictureInPicture = 'picture_in_picture';

	/** Файл-вложение (конспект). */
	case File = 'file';

	case Download = 'download';

	/** Лупа поиска (тулбар страницы «Все задания»). */
	case Search = 'search';

	/** Крестик очистки (напр. поле поиска); зеркало icoCross из common/icons.js. */
	case Cross = 'cross';

	/** Стрелка-вправо в кнопке действия (напр. «Смотреть решение»). */
	case ArrowRight = 'arrow_right';

	/** Стрелки прокрутки списка (напр. навигатор заданий станции КЕГЭ). */
	case ArrowUp   = 'arrow_up';
	case ArrowDown = 'arrow_down';

	/* Действия в шапке плеера: связаться и выйти. */
	case Phone   = 'phone';
	case Message = 'message';
	case Logout  = 'logout';

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
			self::Clock           => 14,
			self::Flag            => 14,
			self::Play,
			self::Pause,
			self::Volume,
			self::VolumeOff,
			self::PictureInPicture,
			self::Fullscreen      => 16,
			self::SeekBack10,
			self::SeekForward10,
			self::File,
			self::Download        => 17,
			self::Cross           => 12,
			self::Search,
			self::ArrowRight      => 16,
			self::ArrowUp,
			self::ArrowDown       => 14,
			self::Phone,
			self::Message,
			self::Logout          => 16,
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
			self::Clock           => '<circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.2l2.8 1.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
			self::Flag            => '<path d="M5 17V3.5M5 4h9.5l-2 3 2 3H5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Volume => '<path d="M3 8h3l4-3v10l-4-3H3zM13 7a4 4 0 0 1 0 6M15 4a8 8 0 0 1 0 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			self::VolumeOff => '<path d="M3 8h3l4-3v10l-4-3H3zM13 7l5 6M18 7l-5 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			self::PictureInPicture => '<rect x="2" y="4" width="16" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><rect x="10" y="10" width="6" height="4" rx="1" fill="currentColor"/>',
			self::Play            => '<path d="M7 4.8v10.4L15.5 10 7 4.8z" fill="currentColor"/>',
			self::Pause           => '<rect x="5.5" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/><rect x="11.3" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/>',
			self::SeekBack10      => '<path d="M10 3a7 7 0 1 1-6.4 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M3 3v4.5h4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><text x="7" y="14.5" fill="currentColor" font-size="6.5" font-weight="700">10</text>',
			self::SeekForward10   => '<path d="M10 3a7 7 0 1 0 6.4 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M17 3v4.5h-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><text x="6.5" y="14.5" fill="currentColor" font-size="6.5" font-weight="700">10</text>',
			self::Fullscreen      => '<path d="M3 8V3h5M12 3h5v5M17 12v5h-5M8 17H3v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			self::File            => '<path d="M5 2.5h6.2L15.5 6.8V17.5H5V2.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M11 3v4.3h4.3" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
			self::Download        => '<path d="M10 3v9m0 0 3.5-3.5M10 12 6.5 8.5M4 16h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			self::Search          => '<circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6"/><path d="m13.5 13.5 3.2 3.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
			self::Cross           => '<path d="M5 5l10 10M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
			self::ArrowRight      => '<path d="M4 10h11m0 0-4-4m4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>',
			self::ArrowUp         => '<path d="M10 16V4M4.5 9.5 10 4l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			self::ArrowDown       => '<path d="M10 4v12M4.5 10.5 10 16l5.5-5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			// Три глифа нарисованы на сетке 24 (Feather) и приведены к общей сетке 20 масштабом группы.
			self::Phone           => '<g transform="scale(.8333)" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></g>',
			self::Message         => '<g transform="scale(.8333)" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></g>',
			self::Logout          => '<g transform="scale(.8333)" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></g>',
		};
	}
}
