<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\Capability;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Profile\RankingAccessService;
use Inc\Services\Profile\SubjectRankingService;
use Inc\Services\Profile\RankingCalculationService;
use Inc\Services\Profile\Learner\RankingPointsPolicy;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class SubjectRankingServiceTest extends TestCase {

	public function test_group_places_are_hidden_only_when_that_group_contains_incomplete_score(): void {
		$GLOBALS['_test_user_can'][7] = [ Capability::ManageLmsTeaching->value => true ];
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'studentIds' )->willReturn( [ 1, 2 ] );
		$repo->method( 'roster' )->willReturn( [
			[ 'student_person_id' => 1, 'snapshot_last_name' => 'А', 'snapshot_first_name' => 'А', 'snapshot_middle_name' => '', 'group_id' => 1, 'group_name' => 'Группа 1' ],
			[ 'student_person_id' => 2, 'snapshot_last_name' => 'Б', 'snapshot_first_name' => 'Б', 'snapshot_middle_name' => '', 'group_id' => 2, 'group_name' => 'Группа 2' ],
		] );
		$assignments = $this->createStub( \Inc\Services\Profile\RankingAssignmentsService::class );
		$assignments->method( 'tasks' )->willReturn( [ [ 'student_person_id' => 1, 'group_lesson_id' => 2, 'work_id' => 3, 'task_id' => 0, 'source' => 'homework', 'task_points' => 0, 'credited' => false, 'max_unknown' => true, 'score_unknown' => true ] ] );
		$config = $this->createStub( PluginConfig::class );
		$config->method( 'rankingForSubject' )->willReturn( [ 'homework' => 1.0, 'attendance_bonus' => 0.0, 'attendance_streak_length' => 3, 'excluded_students' => [] ] );
		$ranking = new RankingCalculationService( $repo, new RankingPointsPolicy( $config ), $assignments, true );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-01 12:00:00' );
		$service = new SubjectRankingService( $this->access(), $repo, $ranking, $clock, $this->createStub( \Inc\Services\Profile\RankingSnapshotService::class ) );
		$all = $service->build( 7, 'inf_ege' );
		self::assertSame( [ null, null ], array_column( $all['rows'], 'rank' ) );
		self::assertTrue( $all['rows'][0]['score_unknown'] );
		self::assertNull( $service->build( 7, 'inf_ege', 1 )['rows'][0]['rank'] );
		self::assertSame( 1, $service->build( 7, 'inf_ege', 2 )['rows'][0]['rank'] );
	}

	public function test_practice_breakdown_groups_events_by_points_without_changing_score(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'studentIds' )->willReturn( [1, 2] );
		$events = array();
		foreach ( [3 => 1, 1 => 100, 2 => 10] as $points => $count ) {
			for ( $i = 0; $i < $count; ++$i ) {
				$events[] = ['student_person_id' => 1, 'source' => 'practice', 'task_points' => $points];
			}
		}
		$events[] = ['student_person_id' => 2, 'source' => 'practice', 'task_points' => 2];
		$events[] = ['student_person_id' => 1, 'source' => 'homework', 'task_points' => 3];
		$repo->method( 'workEvents' )->willReturn( $events );
		$config = $this->createStub( PluginConfig::class );
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method( 'rankingSettings' )->willReturn( ['practice' => 0.5, 'homework' => 1, 'attendance_bonus' => 0] );
		$ranking = new RankingCalculationService( $repo, new RankingPointsPolicy( $config ) );
		$plain = $ranking->scoresForSubject( 'inf_ege', '2026-11-01 12:00:00' );
		$detailed = $ranking->scoresForSubject( 'inf_ege', '2026-11-01 12:00:00', null, true );
		$parts = array_column( $detailed[1]['breakdown'], null, 'source' );
		self::assertSame( 64.5, $detailed[1]['score'] );
		self::assertSame( $plain[1]['score'], $detailed[1]['score'] );
		self::assertSame( 123.0, $parts['practice']['amount'] );
		self::assertSame( [
			['points' => 1.0, 'count' => 100],
			['points' => 2.0, 'count' => 10],
			['points' => 3.0, 'count' => 1],
		], $parts['practice']['task_groups'] );
		self::assertArrayNotHasKey( 'task_groups', $parts['homework'] );
		self::assertArrayNotHasKey( 'breakdown', $plain[1] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_user_can'][7] );
		parent::tearDown();
	}

	private function access(): RankingAccessService {
		$groups=$this->createStub(GroupsRepository::class);
		$groups->method('findByTeacherId')->willReturn([(object)['subject_key'=>'inf_ege']]);
		$groups->method('findByIds')->willReturn([11 => (object)['subject_key'=>'inf_ege']]);
		$subjects=$this->createStub(SubjectRepository::class);
		$subjects->method('readActive')->willReturn([(object)['key'=>'inf_ege','name'=>'Информатика'],(object)['key'=>'python','name'=>'Python']]);
		$records=$this->createStub(StudentRecordRepository::class);
		// Ученик personId=5 состоит в одной активной группе — её предмет и есть его единственное направление.
		$records->method('findActiveByStudent')->willReturn([(object)['groupId'=>11]]);
		$config=$this->createStub(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('isLearnerRankingVisible')->willReturn(true);
		return new RankingAccessService($groups,$subjects,$records,$config);
	}

	private function service(string $now='2026-10-31 12:00:00'): SubjectRankingService {
		$repo=$this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1,2,3,4]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>1,'source'=>'homework','task_points'=>90.0],
			['student_person_id'=>2,'source'=>'homework','task_points'=>90.0],
			['student_person_id'=>3,'source'=>'homework','task_points'=>89.0],
		]);
		$roster=[];
		foreach ([[2,'Белов',1,'Группа1'],[1,'Андреев',1,'Группа1'],[1,'Андреев',2,'Группа2'],[3,'Волков',2,'Группа2'],[4,'Громов',2,'Группа2']]as[$id,$name,$gid,$group]) {
			$roster[]=['student_person_id'=>$id,'snapshot_last_name'=>$name,'snapshot_first_name'=>'Иван','snapshot_middle_name'=>'Иванович','group_id'=>$gid,'group_name'=>$group];
		}
		$repo->method('roster')->willReturn($roster);
		$config=$this->createStub(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('rankingSettings')->willReturn(['homework'=>1.0,'attendance_bonus'=>0.0]);
		$ranking=new RankingCalculationService($repo,new RankingPointsPolicy($config));
		$clock=$this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn($now);
		return new SubjectRankingService($this->access(),$repo,$ranking,$clock,$this->createStub(\Inc\Services\Profile\RankingSnapshotService::class));
	}

	public function test_teacher_sees_only_directions_with_own_groups(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		self::assertSame(['inf_ege'],array_column($this->access()->subjectsFor(7),'key'));
		$this->expectException(\DomainException::class);
		$this->service()->build(7,'python');
	}

	public function test_office_and_admin_can_select_all_active_directions(): void {
		foreach ([Capability::ManageLmsPlatform,Capability::Admin]as$capability) {
			$GLOBALS['_test_user_can'][7]=[$capability->value=>true];
			self::assertSame(['inf_ege','python'],array_column($this->access()->subjectsFor(7),'key'));
			self::assertCount(4,$this->service()->build(7,'python')['rows']);
		}
	}

	public function test_table_uses_subject_dense_ranks_and_stable_names_with_multiple_groups(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service()->build(7,'inf_ege');
		self::assertSame([1,2,3,4],array_column($data['rows'],'person_id'));
		self::assertSame([1,1,2,3],array_column($data['rows'],'rank'));
		self::assertSame([90.0,90.0,89.0,0.0],array_column($data['rows'],'score'));
		self::assertSame('Андреев Иван',$data['rows'][0]['name'],'без отчества — как в журнале');
		self::assertCount(2,$data['rows'][0]['groups']);
		self::assertCount(2,$data['groups']);
		self::assertSame('Информатика',$data['groups'][0]['subject'],'у групп есть имя предмета — кнопка выбора как в «Сводке»');
	}

	public function test_group_filter_recalculates_dense_places_within_the_group(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		// Вне группы 1 есть ученик с большим баллом (Громов, 95, группа 2): по направлению
		// места группы 1 — 2 и 3, а внутри своей группы рейтинг пересчитывается: 1 и 2.
		$repo=$this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1,2,4]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>4,'source'=>'homework','task_points'=>95.0],
			['student_person_id'=>2,'source'=>'homework','task_points'=>90.0],
			['student_person_id'=>1,'source'=>'homework','task_points'=>89.0],
		]);
		$roster=[];
		foreach ([[2,'Белов',1],[1,'Андреев',1],[4,'Громов',2]] as [$id,$name,$gid]) {
			$roster[]=['student_person_id'=>$id,'snapshot_last_name'=>$name,'snapshot_first_name'=>'Иван','snapshot_middle_name'=>'','group_id'=>$gid,'group_name'=>'Г'.$gid];
		}
		$repo->method('roster')->willReturn($roster);
		$config=$this->createStub(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('rankingSettings')->willReturn(['homework'=>1.0,'attendance_bonus'=>0.0]);
		$clock=$this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn('2026-10-31 12:00:00');
		$service=new SubjectRankingService($this->access(),$repo,new RankingCalculationService($repo,new RankingPointsPolicy($config)),$clock,$this->createStub(\Inc\Services\Profile\RankingSnapshotService::class));

		$all=$service->build(7,'inf_ege');
		self::assertSame([1,2,3],array_column($all['rows'],'rank'),'все группы — общие места направления (эталон)');

		$group=$service->build(7,'inf_ege',1);
		self::assertSame([2,1],array_column($group['rows'],'person_id'));
		self::assertSame([1,2],array_column($group['rows'],'rank'),'внутри группы места пересчитаны');
	}

	public function test_foreign_group_is_rejected(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',999);
	}

	public function test_summer_computes_ranking_with_summer_period(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service('2026-07-20')->build(7,'inf_ege');
		self::assertSame('Июнь — август 2026',$data['period']);
		self::assertSame('2026-06-01 00:00:00',$data['period_from']);
		self::assertCount(4,$data['rows'],'летом рейтинг считается');
		self::assertTrue($data['periods'][0]['current']);
		self::assertArrayNotHasKey('summer',$data);
	}

	public function test_explicit_period_selects_its_bounds_and_label(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service('2027-02-15 12:00:00')->build(7,'inf_ege',0,'2026-09-01 00:00:00');
		self::assertSame('Сентябрь — ноябрь 2026',$data['period']);
		self::assertSame('2026-09-01 00:00:00',$data['period_from']);
	}

	public function test_unknown_period_is_rejected(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',0,'2020-01-01 00:00:00');
	}

	public function test_previous_academic_year_period_is_not_offered(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		// Март — май 2026 относится к прошлому учебному году для «сейчас» = октябрь 2026.
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',0,'2026-03-01 00:00:00');
	}

	public function test_student_without_staff_permission_cannot_request_any_direction(): void {
		self::assertSame([],$this->access()->subjectsFor(7));
		$this->expectException(\DomainException::class);
		$this->service()->build(7,'inf_ege');
	}

	public function test_learner_directions_are_subjects_of_own_active_groups(): void {
		self::assertSame(['inf_ege'],array_column($this->access()->learnerSubjectsFor(5),'key'));
	}

	public function test_learner_receives_only_own_score_breakdown(): void {
		$data = $this->service()->buildForLearner( 2, 'inf_ege' );
		foreach ( $data['rows'] as $row ) {
			if ( 2 === $row['person_id'] ) {
				self::assertArrayHasKey( 'breakdown', $row );
			} else {
				self::assertArrayNotHasKey( 'breakdown', $row );
			}
		}
	}

	public function test_learner_gets_same_table_but_only_for_own_direction(): void {
		$data=$this->service()->buildForLearner(5,'inf_ege');
		self::assertSame(5, $data['viewer_person_id']);
		self::assertSame(0, $data['viewer_group_id']);
		self::assertSame(1, $this->service()->buildForLearner(2, 'inf_ege', 2)['viewer_group_id']);
		self::assertSame([1,2,3,4],array_column($data['rows'],'person_id'));
		self::assertSame([1,1,2,3],array_column($data['rows'],'rank'));
		self::assertCount(2,$data['groups']);
		$this->expectException(\DomainException::class);
		$this->service()->buildForLearner(5,'python');
	}

	public function test_learner_group_filter_and_period_work_like_for_staff(): void {
		$data=$this->service('2027-02-15 12:00:00')->buildForLearner(5,'inf_ege',2,'2026-09-01 00:00:00');
		self::assertSame([1,3,4],array_column($data['rows'],'person_id'));
		self::assertSame('Сентябрь — ноябрь 2026',$data['period']);
	}

	public function test_shared_calculation_matches_tiles_and_group_ranks_with_decimal_ties(): void {
		$GLOBALS['_test_user_can'][7] = [Capability::ManageLmsTeaching->value => true];
		$repo = $this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1, 2, 3]);
		$repo->method('workEvents')->willReturn([
			['student_person_id' => 1, 'source' => 'practice', 'task_points' => 1],
			['student_person_id' => 1, 'source' => 'homework', 'task_points' => 1],
			['student_person_id' => 2, 'source' => 'survey', 'task_points' => 1],
			['student_person_id' => 3, 'source' => 'practice', 'task_points' => 1],
		]);
		$repo->method('roster')->willReturn(array_map(static fn(int $id): array => [
			'student_person_id' => $id, 'snapshot_last_name' => 'Student ' . $id, 'snapshot_first_name' => '',
			'group_id' => 1 === $id ? 1 : 2, 'group_name' => 'Group',
		], [1, 2, 3]));
		$config = $this->createMock(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('isLearnerRankingVisible')->willReturn(true);
		$config->expects(self::exactly(3))->method('rankingSettings')->willReturn([
			'practice' => 0.1, 'homework' => 0.2, 'survey' => 0.3, 'attendance_bonus' => 0.0,
		]);
		$ranking = new RankingCalculationService($repo, new RankingPointsPolicy($config));
		$clock = $this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn('2026-11-01 12:00:00');
		$service = new SubjectRankingService($this->access(), $repo, $ranking, $clock, $this->createStub(\Inc\Services\Profile\RankingSnapshotService::class));
		$tile = (new \Inc\Services\Profile\Learner\LearnerRankingSection($ranking))->forGroups(2, [['subject_key' => 'inf_ege']], '2026-11-01 12:00:00')[0];
		$all = $service->build(7, 'inf_ege');
		$student = array_column($all['rows'], null, 'person_id')[2];
		self::assertSame($tile['score'], $student['score']);
		self::assertSame($tile['rank'], $student['rank']);
		self::assertSame([1, 1, 2], array_column($all['rows'], 'rank'));
		$group = $service->build(7, 'inf_ege', 2);
		self::assertSame([2, 3], array_column($group['rows'], 'person_id'));
		self::assertSame([1, 2], array_column($group['rows'], 'rank'));
	}
	public function test_individual_group_name_is_hidden_but_student_remains_ranked(): void {
		$GLOBALS['_test_user_can'][7] = [Capability::ManageLmsPlatform->value => true];
		$repo = $this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1, 2]);
		$repo->method('roster')->willReturn([
			['student_person_id'=>1,'snapshot_last_name'=>'Один','snapshot_first_name'=>'','group_id'=>10,'group_name'=>'Личная','access_mode'=>'individual'],
			['student_person_id'=>2,'snapshot_last_name'=>'Два','snapshot_first_name'=>'','group_id'=>11,'group_name'=>'Обычная','access_mode'=>'scheduled'],
		]);
		$config = $this->createStub(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('rankingSettings')->willReturn(['attendance_bonus'=>0]);
		$clock = $this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn('2026-11-01 12:00:00');
		$service = new SubjectRankingService($this->access(), $repo, new RankingCalculationService($repo, new RankingPointsPolicy($config)), $clock, $this->createStub(\Inc\Services\Profile\RankingSnapshotService::class));
		$data = $service->build(7, 'inf_ege');
		self::assertSame([], array_column($data['rows'], null, 'person_id')[1]['groups']);
		self::assertSame([11], array_column($data['groups'], 'id'));
	}

	public function test_excluded_student_does_not_take_a_place(): void {
		$repo = $this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1, 2]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>1, 'source'=>'homework', 'task_points'=>10],
			['student_person_id'=>2, 'source'=>'homework', 'task_points'=>1],
		]);
		$config = $this->createStub(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('rankingSettings')->willReturn(['homework'=>1, 'attendance_bonus'=>0]);
		$config->method('rankingExcludedStudents')->willReturn([1]);
		$scores = (new RankingCalculationService($repo, new RankingPointsPolicy($config)))->scoresForSubject('inf_ege', '2026-11-01 12:00:00');
		self::assertSame([2], array_keys($scores));
		self::assertSame(1, $scores[2]['rank']);
	}

	public function test_hidden_subject_cannot_be_opened_by_learner_but_staff_access_is_unchanged(): void {
		$groups = $this->createStub(GroupsRepository::class);
		$groups->method('findByIds')->willReturn([(object)['subject_key'=>'inf_ege']]);
		$subjects = $this->createStub(SubjectRepository::class);
		$subjects->method('readActive')->willReturn([(object)['key'=>'inf_ege','name'=>'Информатика']]);
		$records = $this->createStub(StudentRecordRepository::class);
		$records->method('findActiveByStudent')->willReturn([(object)['groupId'=>11]]);
		$config = $this->createStub(PluginConfig::class);
		$config->method('rankingForSubject')->willReturnCallback(static fn() => array_merge($config->rankingSettings(), ['excluded_students' => $config->rankingExcludedStudents()]));
		$config->method('isLearnerRankingVisible')->willReturn(false);
		$access = new RankingAccessService($groups, $subjects, $records, $config);
		$GLOBALS['_test_user_can'][7] = [Capability::ManageLmsPlatform->value=>true];
		self::assertSame([], $access->learnerSubjectsFor(5));
		self::assertSame(['inf_ege'], array_column($access->subjectsFor(7), 'key'));
		$repo = $this->createStub(LearnerRankingRepository::class);
		$service = new SubjectRankingService($access, $repo, new RankingCalculationService($repo, new RankingPointsPolicy($config)), $this->createStub(ClockInterface::class), $this->createStub(\Inc\Services\Profile\RankingSnapshotService::class));
		$this->expectException(\DomainException::class);
		$service->buildForLearner(5, 'inf_ege');
	}

}
