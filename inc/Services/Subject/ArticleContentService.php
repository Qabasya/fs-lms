<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\DTO\Article\ArticleContentDTO;
use Inc\DTO\Article\ArticleTaskCardDTO;
use Inc\DTO\Article\HeadingDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\TaskMetaService;

/**
 * Class ArticleContentService
 *
 * Пост-обработка контента статьи: редактор WordPress отдаёт голые теги, а
 * странице нужны якоря заголовков, классы врезок и карточка задания.
 *
 * Разбор идёт по DOM, а не регулярками: контент пишет человек, и любая
 * регулярка ломается на первом же вложенном теге. Автор ничего специального
 * не размечает — единственное соглашение в том, что абзац, целиком состоящий
 * из ссылки на задание, превращается в карточку этого задания.
 *
 * @package Inc\Services\Subject
 */
readonly class ArticleContentService {

	/** Язык листинга: поля выбора языка у статьи нет, все листинги — Python. */
	private const CODE_LANG = 'Python';

	/** Сколько слов условия показывать в свёрнутой карточке задания. */
	private const PEEK_WORDS = 28;

	/**
	 * Абзац-пункт перечисления: «а)», «б.», «1)», «2.» в начале строки.
	 *
	 * Авторы набирают такие перечисления обычными абзацами, а не списком, и на
	 * общем ритме статьи пункты расползаются — им нужен свой, узкий отступ.
	 */
	private const ITEM_MARKER = '/^\s*(?:[а-яёa-z]|\d{1,2})\s*[).]\s+\S/iu';

	/** Класс-хук подсветки кода: разметку редактора собирает components/code-block.js. */
	private const CODE_HOOK_CLASS = 'js-code';

	/**
	 * @param PostManager     $post_manager      Менеджер записей WordPress.
	 * @param TaskMetaService $task_meta_service Мета-данные задания для карточки.
	 */
	public function __construct(
		private PostManager $post_manager,
		private TaskMetaService $task_meta_service,
	) {}

	/**
	 * Готовит контент статьи к выводу и собирает оглавление.
	 *
	 * @param string $raw_content Сырой post_content статьи.
	 *
	 * @return ArticleContentDTO
	 */
	public function build( string $raw_content ): ArticleContentDTO {
		$html = $this->post_manager->renderContent( $raw_content );

		if ( '' === trim( $html ) ) {
			return new ArticleContentDTO();
		}

		$dom = $this->loadDom( $html );

		if ( ! $dom ) {
			return new ArticleContentDTO( html: $html );
		}

		$root     = $dom->documentElement;
		$headings = $this->markHeadings( $dom );

		$this->markLead( $root );
		$this->markBlocks( $dom );

		$cards = $this->replaceTaskLinks( $dom );

		return new ArticleContentDTO(
			html:     $this->renderCards( $this->serialize( $root ), $cards ),
			headings: $headings,
		);
	}

	/**
	 * Загружает HTML в DOM-документ.
	 *
	 * Обёртка-контейнер обязательна: без неё libxml достраивает html/body и
	 * сериализация возвращает лишние теги. Кодировку задаём XML-прологом —
	 * иначе кириллица приезжает в CP1252.
	 *
	 * @param string $html HTML контента.
	 *
	 * @return \DOMDocument|null Null — libxml не смог разобрать разметку.
	 */
	private function loadDom( string $html ): ?\DOMDocument {
		$dom = new \DOMDocument( '1.0', 'UTF-8' );

		$previous = libxml_use_internal_errors( true );

		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"?><div class="fs-article-prose">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return ( $loaded && $dom->documentElement ) ? $dom : null;
	}

	/**
	 * Собирает HTML внутренностей контейнера обратно в строку.
	 *
	 * @param \DOMElement $root Контейнер контента.
	 *
	 * @return string
	 */
	private function serialize( \DOMElement $root ): string {
		$html = '';

		foreach ( $root->childNodes as $node ) {
			$html .= $root->ownerDocument->saveHTML( $node );
		}

		return $html;
	}

	/**
	 * Проставляет заголовкам якоря и возвращает оглавление.
	 *
	 * Уже заданный автором id уважаем: на него могут вести внешние ссылки.
	 *
	 * @param \DOMDocument $dom Документ контента.
	 *
	 * @return HeadingDTO[]
	 */
	private function markHeadings( \DOMDocument $dom ): array {
		$headings = array();
		$used     = array();
		$index    = 0;

		foreach ( $this->query( $dom, '//h2|//h3' ) as $node ) {
			++$index;

			$text = trim( (string) $node->textContent );
			$id   = $node->getAttribute( 'id' );

			if ( '' === $id ) {
				$id = urldecode( sanitize_title( $text ) );
				$id = '' !== $id ? $id : 'razdel-' . $index;

				// Одинаковые заголовки в одной статье — обычное дело («Шаг 1»
				// в двух разделах): без суффикса якорь вёл бы на первый из них.
				if ( isset( $used[ $id ] ) ) {
					$id = $id . '-' . ++$used[ $id ];
				} else {
					$used[ $id ] = 1;
				}

				$node->setAttribute( 'id', $id );
			}

			if ( '' === $text ) {
				continue;
			}

			$headings[] = new HeadingDTO(
				id:    $id,
				text:  $text,
				level: 'h3' === $node->nodeName ? 3 : 2,
			);
		}

		return $headings;
	}

	/**
	 * Помечает первый абзац статьи лид-абзацем (крупный вводный текст макета).
	 *
	 * @param \DOMElement $root Контейнер контента.
	 *
	 * @return void
	 */
	private function markLead( \DOMElement $root ): void {
		foreach ( $root->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			if ( 'p' === $node->nodeName && '' !== trim( (string) $node->textContent ) ) {
				$this->addClass( $node, 'fs-article-lead' );
			}

			// Лид — именно первый блок статьи: если текст начинается с врезки
			// или иллюстрации, выделять нечего.
			return;
		}
	}

	/**
	 * Навешивает классы на блоки, которые редактор отдаёт голыми тегами.
	 *
	 * @param \DOMDocument $dom Документ контента.
	 *
	 * @return void
	 */
	private function markBlocks( \DOMDocument $dom ): void {
		foreach ( $this->query( $dom, '//blockquote' ) as $node ) {
			$this->addClass( $node, 'fs-article-note' );
		}

		foreach ( $this->query( $dom, '//figure' ) as $node ) {
			$this->addClass( $node, 'fs-article-figure' );
		}

		foreach ( $this->query( $dom, '//table' ) as $node ) {
			$this->addClass( $node, 'fs-article-table' );
		}

		// Инлайновый код — только тот, что вне <pre>: листинг оформляет редактор кода.
		foreach ( $this->query( $dom, '//code[not(ancestor::pre)]' ) as $node ) {
			$this->addClass( $node, 'fs-article-mono' );
		}

		foreach ( $this->query( $dom, '//p' ) as $node ) {
			if ( preg_match( self::ITEM_MARKER, (string) $node->textContent ) ) {
				$this->addClass( $node, 'fs-article-item' );
			}
		}

		$this->markCodeBlocks( $dom );
	}

	/**
	 * Готовит листинги к сборке редактора кода на клиенте.
	 *
	 * Разметку с подсветкой и кнопкой копирования строит `code-block.js` по
	 * классу-хуку — та же связка, что на странице задания.
	 *
	 * @param \DOMDocument $dom Документ контента.
	 *
	 * @return void
	 */
	private function markCodeBlocks( \DOMDocument $dom ): void {
		foreach ( $this->query( $dom, '//pre' ) as $pre ) {
			$code = null;

			foreach ( $pre->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'code' === $child->nodeName ) {
					$code = $child;
					break;
				}
			}

			// <pre> без <code> внутри: заворачиваем сами, иначе хук не на что вешать.
			if ( ! $code ) {
				$code = $pre->ownerDocument->createElement( 'code' );

				while ( $pre->firstChild ) {
					$code->appendChild( $pre->firstChild );
				}

				$pre->appendChild( $code );
			}

			$this->addClass( $code, self::CODE_HOOK_CLASS );

			if ( '' === $code->getAttribute( 'data-lang' ) ) {
				$code->setAttribute( 'data-lang', self::CODE_LANG );
			}
		}
	}

	/**
	 * Заменяет абзацы-ссылки на задания плейсхолдерами карточек.
	 *
	 * Плейсхолдер, а не готовая разметка: вставлять HTML в DOM пришлось бы
	 * вторым документом с импортом узлов, а подстановка по маркеру после
	 * сериализации короче и не зависит от того, как libxml разберёт карточку.
	 *
	 * @param \DOMDocument $dom Документ контента.
	 *
	 * @return array<int, ArticleTaskCardDTO> Карточки, ключ — номер плейсхолдера.
	 */
	private function replaceTaskLinks( \DOMDocument $dom ): array {
		$cards = array();

		foreach ( $this->query( $dom, '//p[count(*)=1 and count(a)=1]' ) as $paragraph ) {
			$link = $paragraph->getElementsByTagName( 'a' )->item( 0 );

			if ( ! $link instanceof \DOMElement ) {
				continue;
			}

			// Ссылка обязана быть содержимым абзаца целиком: строка «подробнее в
			// <a>задании</a>» — это текст статьи, а не заявка на карточку.
			if ( trim( (string) $paragraph->textContent ) !== trim( (string) $link->textContent ) ) {
				continue;
			}

			$card = $this->buildCard( $link->getAttribute( 'href' ) );

			// Ссылка ведёт не на задание — карточки не будет, но это всё равно
			// строка-ссылка, а не абзац текста: широкий отступ ей не нужен.
			if ( ! $card ) {
				$this->addClass( $paragraph, 'fs-article-linkline' );
				continue;
			}

			$index           = count( $cards );
			$cards[ $index ] = $card;

			$placeholder = $dom->createElement( 'div' );
			$placeholder->setAttribute( 'data-fs-task-card', (string) $index );

			$paragraph->parentNode?->replaceChild( $placeholder, $paragraph );
		}

		return $cards;
	}

	/**
	 * Собирает карточку задания по ссылке из текста статьи.
	 *
	 * @param string $url Ссылка из абзаца.
	 *
	 * @return ArticleTaskCardDTO|null Null — ссылка ведёт не на задание.
	 */
	private function buildCard( string $url ): ?ArticleTaskCardDTO {
		$post_id = $this->post_manager->idFromUrl( $url );

		if ( 0 === $post_id ) {
			return null;
		}

		$post = $this->post_manager->get( $post_id );

		if ( ! $post || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return null;
		}

		$meta      = $this->post_manager->getMeta( $post_id, PostMetaName::Meta->value );
		$condition = $this->task_meta_service->getCombinedCondition( is_array( $meta ) ? $meta : array() );

		return new ArticleTaskCardDTO(
			id:        $post_id,
			title:     get_the_title( $post_id ),
			url:       (string) get_permalink( $post_id ),
			peek:      wp_trim_words( wp_strip_all_tags( $condition ), self::PEEK_WORDS, '…' ),
			condition: $condition,
		);
	}

	/**
	 * Подставляет разметку карточек на места плейсхолдеров.
	 *
	 * @param string                          $html  Сериализованный контент.
	 * @param array<int, ArticleTaskCardDTO> $cards Карточки по номеру плейсхолдера.
	 *
	 * @return string
	 */
	private function renderCards( string $html, array $cards ): string {
		foreach ( $cards as $index => $card ) {
			$html = str_replace(
				'<div data-fs-task-card="' . $index . '"></div>',
				$this->renderCard( $card ),
				$html
			);
		}

		return $html;
	}

	/**
	 * Рендерит партиал карточки задания.
	 *
	 * @param ArticleTaskCardDTO $task_card Данные карточки.
	 *
	 * @return string
	 */
	private function renderCard( ArticleTaskCardDTO $task_card ): string {
		$file = FS_LMS_PATH . 'templates/frontend/partials/article-task-card.php';

		if ( ! file_exists( $file ) ) {
			return '';
		}

		ob_start();
		require $file;

		return (string) ob_get_clean();
	}

	/**
	 * Выполняет XPath-запрос и возвращает узлы отдельным массивом.
	 *
	 * Массив, а не DOMNodeList: список живой, и замена узла прямо во время
	 * обхода сбивает итерацию.
	 *
	 * @param \DOMDocument $dom        Документ контента.
	 * @param string       $expression XPath-выражение.
	 *
	 * @return \DOMElement[]
	 */
	private function query( \DOMDocument $dom, string $expression ): array {
		$nodes = array();
		$found = ( new \DOMXPath( $dom ) )->query( $expression );

		if ( ! $found ) {
			return $nodes;
		}

		foreach ( $found as $node ) {
			if ( $node instanceof \DOMElement ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Добавляет класс, сохраняя выставленные автором.
	 *
	 * @param \DOMElement $node  Узел.
	 * @param string      $class Класс.
	 *
	 * @return void
	 */
	private function addClass( \DOMElement $node, string $class ): void {
		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) ) ?: array();
		$classes = array_filter( $classes );

		if ( in_array( $class, $classes, true ) ) {
			return;
		}

		$classes[] = $class;

		$node->setAttribute( 'class', implode( ' ', $classes ) );
	}
}