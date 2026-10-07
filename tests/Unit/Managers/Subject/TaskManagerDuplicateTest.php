<?php

declare( strict_types=1 );

namespace Unit\Managers\Subject;

use Inc\Enums\Subject\TaskTemplate;
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
 * «Дублировать задание»: копия получает следующий свободный номер серии, те же
 * поля и термы, но без эталонного ответа — и только мету шаблона и полей.
 */
class TaskManagerDuplicateTest extends TestCase {

	private const CONDITION = '<p>Найдите $a \cdot b$ при "a" = 2</p>';

	/** @var array<string, mixed> */
	private array $inserted = array();

	/** @var array<string, mixed> */
	private array $meta = array();

	/** @var array<string, int[]> */
	private array $terms = array();

	public function test_copy_gets_next_number_same_fields_and_no_answer(): void {
		$GLOBALS['_fs_test_user_id'] = 9;

		$id = $this->manager(
			TaskTemplate::Standard->value,
			array(
				'common_condition' => '<p>Общее</p>',
				'task_condition'   => self::CONDITION,
				'task_answer'      => '42',
				'task_text'        => '<p>Решение</p>',
				'task_hint'        => 'Подсказка',
			)
		)->duplicate( 100 );

		self::assertSame( 501, $id );
		self::assertSame( '№ 3002. Авторские задания', wp_unslash( $this->inserted['post_title'] ) );
		self::assertSame( '3002', $this->inserted['post_name'] );
		self::assertSame( 'inf_tasks', $this->inserted['post_type'] );
		self::assertSame( 'draft', $this->inserted['post_status'] );
		self::assertSame( 9, $this->inserted['post_author'] );
		self::assertSame( self::CONDITION, wp_unslash( $this->inserted['post_content'] ) );

		self::assertSame(
			array(
				'common_condition' => '<p>Общее</p>',
				'task_condition'   => self::CONDITION,
				'task_answer'      => '',
				'task_text'        => '<p>Решение</p>',
				'task_hint'        => 'Подсказка',
			),
			$this->meta[ PostMetaName::Meta->value ]
		);
		self::assertSame( TaskTemplate::Standard->value, $this->meta[ PostMetaName::TemplateType->value ] );
		// Служебная мета исходника (дети связки и т.п.) в копию не едет.
		self::assertSame( array( PostMetaName::TemplateType->value, PostMetaName::Meta->value ), array_keys( $this->meta ) );

		self::assertSame( array( 'inf_task_number' => array( 7 ), 'inf_author' => array( 21, 22 ) ), $this->terms );
	}

	public function test_triple_loses_all_three_answers(): void {
		$this->manager(
			TaskTemplate::Triple->value,
			array(
				'task_19_condition' => 'c19',
				'task_19_answer'    => '1',
				'task_20_condition' => 'c20',
				'task_20_answer'    => '2',
				'task_21_condition' => 'c21',
				'task_21_answer'    => '3',
				'task_code'         => 'print()',
			)
		)->duplicate( 100 );

		self::assertSame(
			array(
				'task_19_condition' => 'c19',
				'task_19_answer'    => '',
				'task_20_condition' => 'c20',
				'task_20_answer'    => '',
				'task_21_condition' => 'c21',
				'task_21_answer'    => '',
				'task_code'         => 'print()',
			),
			$this->meta[ PostMetaName::Meta->value ]
		);
	}

	public function test_choice_keeps_options_but_drops_correct_marks(): void {
		$this->manager(
			TaskTemplate::Choice->value,
			array(
				'task_condition' => 'Выберите',
				'task_options'   => array(
					'multiple' => false,
					'options'  => array(
						array( 'id' => '1', 'text' => 'Да', 'correct' => true ),
						array( 'id' => '2', 'text' => 'Нет', 'correct' => false ),
					),
				),
			)
		)->duplicate( 100 );

		self::assertSame(
			array(
				array( 'id' => '1', 'text' => 'Да', 'correct' => false ),
				array( 'id' => '2', 'text' => 'Нет', 'correct' => false ),
			),
			$this->meta[ PostMetaName::Meta->value ]['task_options']['options']
		);
	}

	public function test_task_without_number_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'не выбран номер задания' );

		$this->manager( TaskTemplate::Standard->value, array(), withNumber: false )->duplicate( 100 );
	}

	public function test_bundle_child_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'часть связки' );

		$this->manager( TaskTemplate::Standard->value, array(), bundleParentId: 55 )->duplicate( 100 );
	}

	/**
	 * @param array<string, mixed> $sourceMeta Мета исходного задания
	 */
	private function manager( string $templateId, array $sourceMeta, bool $withNumber = true, int $bundleParentId = 0 ): TaskManager {
		$source = new \WP_Post(
			array(
				'ID'           => 100,
				'post_type'    => 'inf_tasks',
				'post_title'   => '№ 3001. Авторские задания',
				'post_name'    => '3001',
				'post_content' => self::CONDITION,
			)
		);

		$number       = new \WP_Term( 7, '3', 'inf_task_number' );
		$number->slug = 'inf_3';

		$sourceTerms = array(
			'inf_task_number' => $withNumber ? array( $number ) : array(),
			'inf_author'      => array( new \WP_Term( 21, 'А', 'inf_author' ), new \WP_Term( 22, 'Б', 'inf_author' ) ),
			'inf_year'        => array(),
		);

		$terms = $this->createStub( TermManager::class );
		$terms->method( 'taxonomiesOf' )->willReturn( array_keys( $sourceTerms ) );
		$terms->method( 'getPostTerms' )->willReturnCallback(
			static fn( int $post_id, string $taxonomy ): array => $sourceTerms[ $taxonomy ] ?? array()
		);
		$terms->method( 'setPostTerms' )->willReturnCallback(
			function ( int $post_id, array $ids, string $taxonomy ): void {
				$this->terms[ $taxonomy ] = $ids;
			}
		);

		$posts = $this->createStub( PostManager::class );
		$posts->method( 'get' )->willReturn( $source );
		$posts->method( 'taskMeta' )->willReturn( $sourceMeta );
		$posts->method( 'getMeta' )->willReturnCallback(
			static fn( int $post_id, string $key ): mixed => match ( $key ) {
				PostMetaName::TemplateType->value       => $templateId,
				PostMetaName::TaskBundleParentId->value => $bundleParentId ?: '',
				default                                 => '',
			}
		);
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

		$numbers = $this->createStub( TaskNumberService::class );
		$numbers->method( 'build' )->willReturnCallback(
			static fn( string $post_type, int $task_number ): string => $task_number . '002'
		);

		$resolver = $this->createStub( TemplateResolver::class );
		$resolver->method( 'resolveId' )->willReturn( $templateId );

		return new TaskManager(
			$posts,
			$terms,
			$this->createStub( MetaBoxRepository::class ),
			$this->createStub( BoilerplateRepository::class ),
			$numbers,
			$resolver,
			new TemplateRegistry(),
		);
	}
}
