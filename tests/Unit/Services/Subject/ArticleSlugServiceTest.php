<?php

declare( strict_types=1 );

namespace Unit\Services\Subject;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\ArticleSlugService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Слаг статьи: `article-task-{номер задания}-{номер в серии}`, а без задания —
 * `article-{номер}`. Уникальность целиком на сервисе: ядро WP слаг, выставленный
 * на фильтре `wp_insert_post_data`, уже не проверяет.
 */
class ArticleSlugServiceTest extends TestCase {

	private PostManager        $posts;
	private ArticleSlugService $slugs;

	protected function setUp(): void {
		parent::setUp();

		$this->posts = $this->createMock( PostManager::class );
		$this->slugs = new ArticleSlugService( $this->posts );
	}

	public function test_first_article_of_task_gets_ordinal_one(): void {
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array() );

		self::assertSame( 'article-task-1-1', $this->slugs->build( 'math_articles', 0, 1 ) );
	}

	public function test_next_article_continues_series(): void {
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( 'article-task-1-1' ) );

		self::assertSame( 'article-task-1-2', $this->slugs->build( 'math_articles', 0, 1 ) );
	}

	public function test_gaps_are_not_reused(): void {
		// Вторую статью серии удалили: следующая обязана взять -4, а не занять
		// освободившееся -2, иначе старая ссылка привела бы на другой материал.
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( 'article-task-1-1', 'article-task-1-3' ) );

		self::assertSame( 'article-task-1-4', $this->slugs->build( 'math_articles', 0, 1 ) );
	}

	public function test_article_excludes_itself_from_taken_slugs(): void {
		// Иначе черновик со слагом article-task-1-2 видел бы сам себя и уползал
		// на -3, -4, … при каждом сохранении.
		$this->posts
			->expects( self::once() )
			->method( 'findSlugsByPrefix' )
			->with( 'math_articles', 'article-task-1-', 42 )
			->willReturn( array() );

		self::assertSame( 'article-task-1-1', $this->slugs->build( 'math_articles', 42, 1 ) );
	}

	public function test_article_without_task_gets_plain_series(): void {
		// Префикс article- по LIKE цепляет и article-task-*, но порядковым
		// номером такой слаг не считается.
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( 'article-task-5-2', 'article-1' ) );

		self::assertSame( 'article-2', $this->slugs->build( 'math_articles', 0, null ) );
	}

	public function test_occupied_slug_is_skipped(): void {
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( 'article-task-1-1', 'article-task-1-02' ) );

		// max+1 даёт -2, но такой слаг уже занят нестандартной записью.
		self::assertSame( 'article-task-1-2', $this->slugs->build( 'math_articles', 0, 1 ) );
	}

	public function test_compose_builds_slug_from_ordinal(): void {
		self::assertSame( 'article-task-7-3', $this->slugs->compose( 7, 3 ) );
		self::assertSame( 'article-2', $this->slugs->compose( null, 2 ) );
		self::assertSame( 'article-1', $this->slugs->compose( null, 0 ) );
	}

	/**
	 * @param mixed    $value    Значение tax_input.
	 * @param int|null $expected Ожидаемый номер задания.
	 */
	#[DataProvider( 'taxInputProvider' )]
	public function test_resolves_task_number_from_tax_input( mixed $value, ?int $expected ): void {
		self::assertSame( $expected, $this->slugs->resolveTaskNumber( $value, 'math' ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: int|null}>
	 */
	public static function taxInputProvider(): array {
		return array(
			'слаг терма из метабокса'      => array( array( 'math_5' ), 5 ),
			'пустая заглушка перед слагом' => array( array( '', 'math_5' ), 5 ),
			'только заглушка'              => array( array( '' ), null ),
			'имя терма из быстрой правки'  => array( '5', 5 ),
			'строка с несколькими именами' => array( '5, 7', 5 ),
			'чужая таксономия'             => array( array( 'math_section_a' ), null ),
			'значения нет'                 => array( null, null ),
			'ноль номером не считается'    => array( array( 'math_0' ), null ),
		);
	}

	public function test_subject_key_with_underscore_is_parsed(): void {
		self::assertSame( 12, $this->slugs->resolveTaskNumber( array( 'inf_ege_12' ), 'inf_ege' ) );
	}
}