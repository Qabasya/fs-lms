<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Profile;

use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Services\Profile\Learner\LearnerRankingSection;
use Inc\Services\Profile\Learner\RankingPointsPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LearnerRankingSectionTest extends TestCase {

	private function points(array $overrides = []): RankingPointsPolicy {
		$config = $this->createStub(\Inc\Services\Shared\PluginConfig::class);
		$config->method('rankingSettings')->willReturn(array_merge(['practice'=>0.0,'independent'=>1.0,'homework'=>1.0,'survey'=>1.0,'assessment'=>1.0,'attendance_bonus'=>0.0,'attendance_streak_length'=>3], $overrides));
		return new RankingPointsPolicy($config);
	}

	public static function periods(): array {
		return [
			['2026-09-01', '2026-09-01 00:00:00', '2026-12-01 00:00:00'],
			['2026-11-30', '2026-09-01 00:00:00', '2026-12-01 00:00:00'],
			['2026-12-01', '2026-12-01 00:00:00', '2027-03-01 00:00:00'],
			['2027-01-01', '2026-12-01 00:00:00', '2027-03-01 00:00:00'],
			['2028-02-29', '2027-12-01 00:00:00', '2028-03-01 00:00:00'],
			['2027-03-01', '2027-03-01 00:00:00', '2027-06-01 00:00:00'],
			['2027-05-31', '2027-03-01 00:00:00', '2027-06-01 00:00:00'],
		];
	}

	#[DataProvider('periods')]
	public function test_period_boundaries(string $now, string $from, string $until): void {
		$section = new LearnerRankingSection($this->createStub(LearnerRankingRepository::class), $this->points());
		self::assertSame($from, $section->period($now)['from']);
		self::assertSame($until, $section->period($now)['until']);
	}

	public function test_summer_does_not_read_or_show_ranking(): void {
		$repo = $this->createMock(LearnerRankingRepository::class);
		$repo->expects(self::never())->method('studentIds');
		$section = new LearnerRankingSection($repo, $this->points());
		foreach (['2027-06-01', '2027-07-20', '2027-08-31'] as $day) {
			self::assertSame([], $section->forGroups(1, [['subject_key' => 'python']], $day));
		}
	}

	public function test_dense_rank_counts_all_four_sources_and_successful_repeats(): void {
		$repo = $this->createMock(LearnerRankingRepository::class);
		$repo->expects(self::once())->method('studentIds')->with('python')->willReturn([1, 2, 3, 4]);
		$work = [];
		foreach ([1, 2, 3] as $student) {
			foreach (['independent', 'homework', 'survey'] as $source) {
				$work[] = ['student_person_id' => $student, 'source' => $source];
			}
		}
		// Two separate correct submissions of the same task are both credited.
		$work[] = ['student_person_id' => 1, 'source' => 'homework'];
		$work[] = ['student_person_id' => 2, 'source' => 'homework'];
		$work[] = ['student_person_id' => 3, 'source' => 'practice'];
		$work[] = ['student_person_id' => 99, 'source' => 'homework'];
		$repo->method('workEvents')->willReturn($work);
		$repo->method('assessmentEvents')->willReturn(array_map(static fn(int $id): array => ['student_person_id' => $id, 'source' => 'assessment'], [1,2,3]));
		$section = new LearnerRankingSection($repo, $this->points());
		$groups = [['subject_key' => 'python', 'subject' => 'Python'], ['subject_key' => 'python']];
		$result = $section->forGroups(3, $groups, '2026-10-31');
		self::assertCount(1, $result);
		self::assertSame(4.0, $result[0]['score']);
		self::assertSame(2, $result[0]['rank']); // 5, 5, 4 => 1, 1, 2.
	}

	public function test_subject_rosters_are_independent_and_zero_points_have_a_place(): void {
		$repo = $this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1, 2]);
		$repo->method('workEvents')->willReturnCallback(static fn(string $subject): array => 'robo' === $subject ? [['student_person_id' => 2, 'source' => 'survey']] : []);
		$section = new LearnerRankingSection($repo, $this->points());
		$result = $section->forGroups(1, [['subject_key' => 'python'], ['subject_key' => 'robo']], '2026-10-31');
		self::assertSame([1, 2], array_column($result, 'rank'));
		self::assertSame([0.0, 0.0], array_column($result, 'score'));
	}
	public function test_configurable_weights_and_decimal_ties(): void {
		$repo = $this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1, 2]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>1,'source'=>'practice'],
			['student_person_id'=>1,'source'=>'homework'],
			['student_person_id'=>2,'source'=>'survey'],
		]);
		$section = new LearnerRankingSection($repo, $this->points(['practice'=>0.1,'homework'=>0.2,'survey'=>0.3]));
		foreach ([1,2] as $student) {
			$result = $section->forGroups($student, [['subject_key'=>'python']], '2026-10-31');
			self::assertSame(0.3, $result[0]['score']);
			self::assertSame(1, $result[0]['rank']);
		}
	}

	public function test_attendance_bonus_nonoverlapping_series_absence_and_duplicates(): void {
		$repo = $this->createMock(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1]);
		$events = [];
		// 4 present, absent, 6 present: floor(4/3)+floor(6/3)=3 bonuses.
		foreach ([1,1,1,1,0,1,1,1,1,1,1] as $index=>$present) {
			$event = ['student_person_id'=>1,'group_lesson_id'=>$index+1,'present'=>$present];
			$events[] = $event;
			$events[] = $event; // Duplicate enrollment rows never count as another lesson.
		}
		$repo->expects(self::once())->method('attendanceEvents')->with('python','2026-09-01 00:00:00','2026-12-01 00:00:00','2026-10-31')->willReturn($events);
		$section = new LearnerRankingSection($repo, $this->points(['attendance_bonus'=>0.25]));
		$result = $section->forGroups(1, [['subject_key'=>'python']], '2026-10-31');
		self::assertSame(0.75,$result[0]['score']);
	}

	public function test_disabled_attendance_bonus_does_not_query_attendance(): void {
		$repo=$this->createMock(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1]);
		$repo->expects(self::never())->method('attendanceEvents');
		$section=new LearnerRankingSection($repo,$this->points());
		self::assertSame(0.0,$section->forGroups(1,[['subject_key'=>'python']],'2026-10-31')[0]['score']);
	}

	public function test_work_ranking_uses_historical_task_price_times_type_coefficient(): void {
		$repo=$this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1,2]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>1,'source'=>'homework','task_points'=>2.0],
			['student_person_id'=>1,'source'=>'homework','task_points'=>3.0],
			['student_person_id'=>1,'source'=>'homework','task_points'=>3.0],
			['student_person_id'=>2,'source'=>'homework','task_points'=>3.0],
		]);
		// Exam event stays one solved task even if its formal primary score was larger.
		$repo->method('assessmentEvents')->willReturn([['student_person_id'=>1,'source'=>'assessment']]);
		$section=new LearnerRankingSection($repo,$this->points(['homework'=>0.5,'assessment'=>1.0]));
		$result=$section->forGroups(1,[['subject_key'=>'python']],'2026-10-31');
		self::assertSame(5.0,$result[0]['score']);
		self::assertSame(1,$result[0]['rank']);
	}

}
