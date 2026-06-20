<?php

declare( strict_types=1 );
/**
 * Таб "Преподаватели" — таблица преподавателей с их предметами и группами.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$groupRepo   = new GroupsRepository();
$subjectRepo = new SubjectRepository();

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$teacherUsers = get_users( array(
	'role'    => UserRole::FSTeacher->value,
	'number'  => $perPage,
	'offset'  => ( $page - 1 ) * $perPage,
	'orderby' => 'display_name',
	'order'   => 'ASC',
) );
$total = (int) ( count_users()['avail_roles'][ UserRole::FSTeacher->value ] ?? 0 );
$pages = $total > 0 ? (int) ceil( $total / $perPage ) : 1;

// Все группы один раз — группируем по teacher_id
$allGroups       = $groupRepo->findAll();
$groupsByTeacher = array();
foreach ( $allGroups as $group ) {
	$tid = (int) ( $group->teacher_id ?? 0 );
	if ( $tid > 0 ) {
		$groupsByTeacher[ $tid ][] = $group;
	}
}

// Все предметы один раз
$allSubjects = array();
foreach ( $subjectRepo->readAll() as $dto ) {
	$allSubjects[ $dto->key ] = $dto->name;
}

?>

<div class="fs-lms-teachers">

	<div class="tablenav top fs-students-bulk-bar"></div>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

		<thead>
		<tr>
			<th class="column-title column-primary">
				<?php esc_html_e( 'ФИО', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Предметы', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Группы', 'fs-lms' ); ?>
			</th>
			<th class="column-title">
				<?php esc_html_e( 'Действия', 'fs-lms' ); ?>
			</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $teacherUsers ) ) : ?>
			<tr>
				<td colspan="4">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Преподавателей пока нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $teacherUsers as $user ) :
				$groups = $groupsByTeacher[ $user->ID ] ?? array();

				// Группируем группы по предмету, сохраняя порядок первого появления
				$subjectGroups = array();
				foreach ( $groups as $group ) {
					$key = $group->subject_key ?? '';
					if ( $key && ! isset( $subjectGroups[ $key ] ) ) {
						$subjectGroups[ $key ] = array(
							'name'   => $allSubjects[ $key ] ?? $key,
							'groups' => array(),
						);
					}
					if ( $key ) {
						$subjectGroups[ $key ]['groups'][] = $group->name ?? '';
					}
				}

				// Формируем HTML для ячеек (предметы через <br><br>, группы аналогично)
				$subjectParts = array();
				$groupParts   = array();
				foreach ( $subjectGroups as $data ) {
					$subjectParts[] = esc_html( $data['name'] );
					$groupParts[]   = implode( '<br>', array_map( 'esc_html', $data['groups'] ) );
				}
				$subjectHtml = implode( '<br><br>', $subjectParts );
				$groupHtml   = implode( '<br><br>', $groupParts );

				// Структурированные данные для модалки
				$subjectsGroupsData = array_values( array_map(
					static fn( array $d ) => array(
						'subject_name' => $d['name'],
						'groups'       => $d['groups'],
					),
					$subjectGroups
				) );

				$teacherData = array(
					'full_name'      => $user->display_name,
					'email'          => $user->user_email,
					'subjects_groups' => $subjectsGroupsData,
				);
			?>
			<tr data-teacher="<?php echo esc_attr( (string) wp_json_encode( $teacherData ) ); ?>">

				<td class="column-title">
					<?php echo esc_html( $user->display_name ); ?>
				</td>

				<td>
					<?php if ( ! empty( $subjectGroups ) ) : ?>
						<?php echo wp_kses( $subjectHtml, array( 'br' => array() ) ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td>
					<?php if ( ! empty( $subjectGroups ) ) : ?>
						<?php echo wp_kses( $groupHtml, array( 'br' => array() ) ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
						<span class="view">
							<a href="#" class="js-view-teacher">
								<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
							</a>
						</span>
					</div>
				</td>

			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php for ( $p = 1; $p <= $pages; $p++ ) :
					$url = add_query_arg( array( 'paged' => $p ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>"
						class="button button-small <?php echo $p === $page ? 'button-primary' : ''; ?>">
						<?php echo esc_html( (string) $p ); ?>
					</a>
				<?php endfor; ?>
			</div>
		</div>
	<?php endif; ?>

</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/teacher-view-modal.php'; ?>
