<?php

declare( strict_types=1 );
/**
 * Таб "Ученики" — таблица зачисленных учеников.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Access\Capability;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Course\WeekDay;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Log\LogNameResolver;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$recordRepo  = new StudentRecordRepository();
$personRepo  = new PersonRepository();
$groupRepo   = new GroupsRepository();
$subjectRepo = new SubjectRepository();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$subjectFilter = sanitize_key( wp_unslash( $_GET['subject_key'] ?? '' ) );
$groupFilter   = (int) ( $_GET['group_id'] ?? 0 );
$orderby       = 'student_name' === sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) ) ? 'student_name' : 'enrolled_at';
$order         = 'asc' === sanitize_key( wp_unslash( $_GET['order'] ?? '' ) ) ? 'ASC' : 'DESC';

$filters = array( 'status' => EnrollmentStatus::Active->value );
if ( '' !== $subjectFilter ) {
	$filters['subject_key'] = $subjectFilter;
}
if ( $groupFilter > 0 ) {
	$filters['group_id'] = $groupFilter;
}

$studentIds = $recordRepo->listDistinctStudentIds( $filters, $page, $perPage, $orderby, $order );
$total      = $recordRepo->countDistinctStudents( $filters );
$pages      = (int) ceil( $total / $perPage );

$allSubjects = array();
foreach ( $subjectRepo->readAll() as $dto ) {
	$allSubjects[ $dto->key ] = $dto->name;
}

$groupOptions = array();
foreach ( $groupRepo->findAll() as $g ) {
	$groupOptions[ $g->id ] = $g->name . ' (' . ( $allSubjects[ $g->subject_key ] ?? $g->subject_key ) . ')';
}

$pageSlug      = sanitize_key( $_GET['page'] ?? '' );
$baseUrl       = add_query_arg( array( 'page' => $pageSlug, 'tab' => 'tab-2' ), admin_url( 'admin.php' ) );
$activeFilters = array_filter( array(
	'subject_key' => $subjectFilter,
	'group_id'    => $groupFilter ?: '',
) );
$sortUrl    = add_query_arg( $activeFilters, $baseUrl );
$sortParams = 'student_name' === $orderby
	? array( 'orderby' => 'student_name', 'order' => strtolower( $order ) )
	: array();
$filterUrl  = add_query_arg( array_merge( $activeFilters, $sortParams ), $baseUrl );

?>

<div class="fs-lms-students fs-logs-tab">

	<div class="tablenav top fs-students-bulk-bar">
		<div class="alignleft actions bulkactions">
			<label for="js-bulk-action" class="screen-reader-text">Выберите действие</label>
			<select id="js-bulk-action">
				<option value="">— Массовые действия —</option>
				<option value="expel">Отчислить</option>
				<option value="export">Экспортировать</option>
			</select>
			<button type="button" id="js-bulk-apply" class="button action">Применить</button>
		</div>
	</div>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $pageSlug ); ?>">
		<input type="hidden" name="tab"  value="tab-2">

		<?php render_fs_select( array(
			'name'      => 'subject_key',
			'options'   => $allSubjects,
			'selected'  => $subjectFilter,
			'all_label' => 'Все предметы',
		) ); ?>

		<?php render_fs_select( array(
			'name'      => 'group_id',
			'options'   => $groupOptions,
			'selected'  => $groupFilter ?: '',
			'all_label' => 'Все группы',
		) ); ?>

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $activeFilters ) ) : ?>
			<a href="<?php echo esc_url( $baseUrl ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>

	<p class="fs-logs-summary">
		Найдено учеников: <strong><?php echo number_format_i18n( $total ); ?></strong>
		<?php if ( ! empty( $activeFilters ) ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="column-cb check-column"><input type="checkbox" id="js-select-all-students"></th>
			<th class="column-title column-primary">
				<?php echo LogNameResolver::sortableHeader( 'ФИО ученика', 'student_name', $orderby, strtolower( $order ), $sortUrl ); // phpcs:ignore ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Предмет', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Группа', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Расписание', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Номер договора', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Действия', 'fs-lms' ); ?>
			</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $studentIds ) ) : ?>
			<tr>
				<td colspan="7">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Зачисленных учеников пока нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>

		<?php else : ?>
			<?php foreach ( $studentIds as $studentPersonId ) :
				$studentRecords = $recordRepo->findActiveByStudent( $studentPersonId );
				if ( empty( $studentRecords ) ) { continue; }

				$firstRecord = $studentRecords[0];
				$person      = $personRepo->find( $studentPersonId );

				// Имя ученика
				$studentName = '—';
				$wpUser      = null;
				if ( $person !== null ) {
					$studentName = $person->fullName() ?: '—';
					if ( $person->wpUserId ) {
						$wpUser = get_userdata( $person->wpUserId );
						if ( $wpUser ) {
							$studentName = $wpUser->display_name ?: $studentName;
						}
					}
				}

				// Имя родителя из первой записи
				$parentName = '';
				if ( $firstRecord->parentPersonId ) {
					$parentPerson = $personRepo->find( $firstRecord->parentPersonId );
					if ( $parentPerson !== null ) {
						$parentWpUser = $parentPerson->wpUserId ? get_userdata( $parentPerson->wpUserId ) : null;
						$parentName   = $parentWpUser ? $parentWpUser->display_name : $parentPerson->fullName();
					}
				}

				// Данные по каждой записи (один проход — без дублирования findById)
				$subjectParts    = array();
				$groupParts      = array();
				$scheduleParts   = array();
				$contractParts   = array();
				$enrollmentsList = array();

				$firstSubjectName = '—';
				$firstGroupTitle  = '—';
				$firstScheduleStr = '';

				foreach ( $studentRecords as $idx => $record ) {
					$groupId = (int) ( $record->groupId ?? 0 );
					$group   = $groupId ? $groupRepo->findById( $groupId ) : null;

					$subjectName   = $group !== null
						? ( $allSubjects[ $group->subject_key ] ?? $group->subject_key )
						: '—';
					$groupTitle    = $group?->name ?? '—';
					$scheduleArray = $group !== null && is_string( $group->meetings )
						? ( json_decode( $group->meetings, true ) ?? array() )
						: array();
					$scheduleStr   = WeekDay::formatSchedule( $scheduleArray );

					$subjectParts[]  = $subjectName;
					$groupParts[]    = $groupTitle;
					$scheduleParts[] = $scheduleStr !== '' ? $scheduleStr : '—';
					$contractParts[] = $record->contractNo ?? '—';

					$enrollmentsList[] = array(
						'record_id'    => $record->id,
						'subject_name' => $subjectName,
						'group_title'  => $groupTitle,
					);

					if ( 0 === $idx ) {
						$firstSubjectName = $subjectName;
						$firstGroupTitle  = $groupTitle;
						$firstScheduleStr = $scheduleStr;
					}
				}

				// HTML-строки для ячеек
				$subjectHtml  = implode( '<br>', array_map( 'esc_html', $subjectParts ) );
				$groupHtml    = implode( '<br>', array_map( 'esc_html', $groupParts ) );
				$scheduleHtml = implode( '<br>', array_map( 'esc_html', $scheduleParts ) );
				$contractHtml = implode( '<br>', array_map( 'esc_html', $contractParts ) );

				// data-enrollment: данные первой записи для мгновенного предзаполнения модалки
				$enrollmentData = array(
					'subject'                  => $firstSubjectName,
					'group'                    => $firstGroupTitle,
					'schedule'                 => $firstScheduleStr,
					'contract_no'              => $firstRecord->contractNo   ?? '',
					'contract_date'            => $firstRecord->contractDate ?? '',
					'order_no'                 => $firstRecord->orderNo      ?? '',
					'order_date'               => $firstRecord->orderDate    ?? '',
					'enrolled_at'              => substr( $firstRecord->enrolledAt, 0, 10 ),
					'student_last_name'        => $person?->lastName   ?? '',
					'student_first_name'       => $person?->firstName  ?? '',
					'student_middle_name'      => $person?->middleName ?? '',
					'student_full_name'        => $studentName,
					'student_birth_date'       => $person?->birthDate  ?? '',
					'student_email'            => '',
					'student_phone'            => '',
					'student_school'           => $person?->school ?? '',
					'student_grade'            => $person?->grade  ?? '',
					'student_doc_type'         => '',
					'student_doc_number'       => '',
					'student_inn'              => '',
					'guardian_full_name'       => $parentName,
					'guardian_birth_date'      => '',
					'guardian_email'           => '',
					'guardian_phone'           => '',
					'guardian_doc_type'        => '',
					'guardian_doc_number'      => '',
					'guardian_doc_issued_by'   => '',
					'guardian_doc_issued_date' => '',
					'guardian_inn'             => '',
					'guardian_address'         => '',
				);

				$wpUserId             = $person?->wpUserId ?? 0;
				$enrollmentsJson      = (string) wp_json_encode( $enrollmentsList );
			?>
			<tr data-enrollment="<?php echo esc_attr( (string) wp_json_encode( $enrollmentData ) ); ?>" data-wp-user-id="<?php echo esc_attr( (string) $wpUserId ); ?>">

				<td class="check-column"><input type="checkbox" class="js-student-cb" value="<?php echo esc_attr( (string) $wpUserId ); ?>" data-student-name="<?php echo esc_attr( $studentName ); ?>"></td>

				<td class="column-title">
					<?php echo esc_html( $studentName ); ?>
				</td>

				<td class="column-title">
					<?php echo wp_kses( $subjectHtml, array( 'br' => array() ) ); ?>
				</td>

				<td class="column-title">
					<?php echo wp_kses( $groupHtml, array( 'br' => array() ) ); ?>
				</td>

				<td>
					<?php echo wp_kses( $scheduleHtml, array( 'br' => array() ) ); ?>
				</td>

				<td>
					<?php echo wp_kses( $contractHtml, array( 'br' => array() ) ); ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#"
							   class="js-view-person"
							   data-person-id="<?php echo esc_attr( (string) $studentPersonId ); ?>"
							   data-wp-user-id="<?php echo esc_attr( (string) $wpUserId ); ?>"
							   data-person-type="student"
							   data-display-name="<?php echo esc_attr( $studentName ); ?>"
							   data-email="<?php echo esc_attr( $wpUser?->user_email ?? '' ); ?>"
							   data-user-login="<?php echo esc_attr( $wpUser ? $wpUser->user_login : '' ); ?>">
								<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
							</a>
						</span>
						<span class="export">
							<a href="#"
							   class="js-export-person"
							   data-person-id="<?php echo esc_attr( (string) $studentPersonId ); ?>"
							   data-person-type="student">
								<?php esc_html_e( ' | Экспорт', 'fs-lms' ); ?>
							</a>
						</span>
						<span class="expel">
							<a href="#"
							   class="js-expel-student"
							   data-expel-student-id="<?php echo esc_attr( (string) $wpUserId ); ?>"
							   data-expel-student-name="<?php echo esc_attr( $studentName ); ?>"
							   data-expel-enrollments="<?php echo esc_attr( $enrollmentsJson ); ?>">
								<?php esc_html_e( ' | Отчислить', 'fs-lms' ); ?>
							</a>
						</span>
					</div>
				</td>

			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php render_fs_pagination( $page, $pages, add_query_arg( 'paged', '%#%', $filterUrl ) ); ?>

</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/person/student-person-modal.php'; ?>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/pii-export-modal.php'; ?>
