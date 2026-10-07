<?php

declare( strict_types=1 );

namespace Unit\Managers\Subject;

use Inc\DTO\Task\TaskTypeBoilerplateDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Subject\TaskManager;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Task\TaskNumberService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Контент типового условия — JSON, и в репозитории он лежит без WP-слэшей.
 * Лишний `wp_unslash()` срезал экранирование самого JSON: переносы превращались
 * в `rn`, а кавычки атрибутов (таблица, картинка) рвали разбор — и вся сырая
 * строка уезжала в «Условие задания» вместо «Общего условия».
 */
class TaskManagerBoilerplateTest extends TestCase {

	private const COMMON = "<p>Алфавит A = (a<sub>0</sub>, a<sub>1</sub>).</p>\r\n\r\n"
		. '<table class="fs-table" style="width: 100%"><tr><td>q<sub>0</sub></td><td>команда</td></tr></table>'
		. "\r\n<p>\$a \\cdot b\$ и «L», «R»</p>";

	/** @var array<string, mixed> */
	private array $inserted = array();

	/** @var array<string, mixed> */
	private array $meta = array();

	public function test_json_boilerplate_fills_its_own_fields_intact(): void {
		$content = (string) wp_json_encode(
			array( 'common_condition' => self::COMMON, 'task_condition' => '' ),
			JSON_UNESCAPED_UNICODE
		);

		$this->manager( $content )->createNewTask( 'inf', 7, 'Машина Тьюринга', 'bp_1' );

		self::assertSame(
			array( 'common_condition' => self::COMMON, 'task_condition' => '', 'task_answer' => '' ),
			$this->meta[ PostMetaName::Meta->value ]
		);
		// Ядро снимет слэши при вставке — в базе должен оказаться исходный текст.
		self::assertSame( self::COMMON . "\n\n", wp_unslash( $this->inserted['post_content'] ) );
	}

	public function test_legacy_plain_boilerplate_goes_to_task_condition(): void {
		$legacy = '<p>Условие c "кавычками" и $\frac{1}{2}$</p>';

		$this->manager( $legacy )->createNewTask( 'inf', 7, 'Старое условие', 'default' );

		self::assertSame(
			array( 'task_condition' => $legacy, 'task_answer' => '' ),
			$this->meta[ PostMetaName::Meta->value ]
		);
		self::assertSame( $legacy, wp_unslash( $this->inserted['post_content'] ) );
	}

	private function manager( string $content ): TaskManager {
		$term       = new \WP_Term( 7, '12', 'inf_task_number' );
		$term->slug = 'inf_12';

		$terms = $this->createStub( TermManager::class );
		$terms->method( 'get' )->willReturn( $term );

		$posts = $this->createStub( PostManager::class );
		$posts->method( 'insert' )->willReturnCallback(
			function ( array $data ): int {
				$this->inserted = $data;

				return 501;
			}
		);
		$posts->method( 'updateMeta' )->willReturnCallback(
			function ( int $post_id, string $key, mixed $value ): void {
				$this->meta[ $key ] = $value;
			}
		);

		$boilerplates = $this->createStub( BoilerplateRepository::class );
		$boilerplates->method( 'findBoilerplate' )->willReturn(
			new TaskTypeBoilerplateDTO( 'bp_1', 'inf', 'inf_12', 'МТ', $content )
		);

		$numbers = $this->createStub( TaskNumberService::class );
		$numbers->method( 'build' )->willReturn( '12000' );

		return new TaskManager(
			$posts,
			$terms,
			$this->createStub( MetaBoxRepository::class ),
			$boilerplates,
			$numbers,
			$this->createStub( TemplateResolver::class ),
			new TemplateRegistry(),
		);
	}
}
