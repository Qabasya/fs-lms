<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\TaskNumberService;
use PHPUnit\Framework\TestCase;

/**
 * Номер задания в банке: `{номер задания}{счётчик из трёх цифр}`. Выдаётся
 * наименьший свободный, при публикации проверяется принадлежность к номеру
 * задания и занятость.
 */
class TaskNumberServiceTest extends TestCase {

	private PostManager       $posts;
	private TaskNumberService $numbers;

	protected function setUp(): void {
		parent::setUp();

		$this->posts   = $this->createMock( PostManager::class );
		$this->numbers = new TaskNumberService( $this->posts );
	}

	public function test_first_task_of_number_gets_zero_counter(): void {
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array() );

		self::assertSame( '5000', $this->numbers->build( 'inf_tasks', 5 ) );
	}

	public function test_next_task_continues_series(): void {
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( '5000', '5001', '5002' ) );

		self::assertSame( '5003', $this->numbers->build( 'inf_tasks', 5 ) );
	}

	public function test_freed_number_is_reused(): void {
		// 5001 удалили или отправили в корзину — следующее задание занимает его,
		// а не уходит в конец серии.
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( '5000', '5002' ) );

		self::assertSame( '5001', $this->numbers->build( 'inf_tasks', 5 ) );
	}

	public function test_other_task_numbers_sharing_prefix_are_ignored(): void {
		// LIKE '5%' цепляет и серию №50 — её номера счётчик №5 не занимают.
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( '5000', '50000', '50001' ) );

		self::assertSame( '5001', $this->numbers->build( 'inf_tasks', 5 ) );
	}

	public function test_build_excludes_given_post(): void {
		$this->posts
			->expects( self::once() )
			->method( 'findSlugsByPrefix' )
			->with( 'inf_tasks', '5', 42 )
			->willReturn( array() );

		self::assertSame( '5000', $this->numbers->build( 'inf_tasks', 5, 42 ) );
	}

	public function test_free_valid_number_passes(): void {
		$this->posts->method( 'findBySlug' )->willReturn( null );

		self::assertNull( $this->numbers->publishError( 'inf_tasks', 42, '5003', 5 ) );
	}

	public function test_taken_number_is_reported_with_free_one(): void {
		$other = new \WP_Post( array( 'ID' => 7, 'post_title' => '№ 5002. Демоверсия' ) );

		$this->posts->method( 'findBySlug' )->with( 'inf_tasks', '5002', 42 )->willReturn( $other );
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( '5000', '5001', '5002' ) );

		$error = $this->numbers->publishError( 'inf_tasks', 42, '5002', 5 );

		self::assertStringContainsString( 'уже занят заданием «№ 5002. Демоверсия» (ID 7)', (string) $error );
		self::assertStringContainsString( 'Свободный номер — 5003', (string) $error );
	}

	public function test_number_of_other_task_is_rejected(): void {
		// Номер задания сменили с 5 на 6, а номер остался от пятого.
		$this->posts->expects( self::never() )->method( 'findBySlug' );
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array( '6000' ) );

		$error = $this->numbers->publishError( 'inf_tasks', 42, '5003', 6 );

		self::assertStringContainsString( 'Номер «5003» не подходит к заданию №6', (string) $error );
		self::assertStringContainsString( 'Свободный номер — 6001', (string) $error );
	}

	public function test_longer_series_of_neighbour_number_is_rejected(): void {
		$this->posts->method( 'findSlugsByPrefix' )->willReturn( array() );

		// 50015 — это №50, а не 15-й номер №5 (счётчик с ведущим нулём не пишется).
		self::assertNotNull( $this->numbers->publishError( 'inf_tasks', 42, '50015', 5 ) );
		self::assertNotNull( $this->numbers->publishError( 'inf_tasks', 42, 'nomer-5', 5 ) );
	}

	public function test_counter_beyond_three_digits_belongs_to_number(): void {
		$this->posts->method( 'findBySlug' )->willReturn( null );

		self::assertNull( $this->numbers->publishError( 'inf_tasks', 42, '51000', 5 ) );
	}
}
