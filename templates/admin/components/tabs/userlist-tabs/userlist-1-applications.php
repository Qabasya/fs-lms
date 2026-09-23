<?php

declare( strict_types=1 );
/**
 * Таб "Заявки" — таблица заявок на зачисление.
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Log\LogNameResolver;
use Inc\Services\Security\PiiCryptoService;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$repo   = new ApplicationRepository();
$crypto = new PiiCryptoService();

$statusFilter = sanitize_key( $_GET['status'] ?? '' );
$filters      = $statusFilter ? array( 'status' => $statusFilter ) : array();

// Без пагинации: таблица растёт вниз, заявки не теряются на дальних страницах.
$apps = $repo->list( $filters );

// Данные ученика и родителя расшифровываются один раз: по ним и сортировка, и строки.
$decrypt = static function ( ?string $enc ) use ( $crypto ): ?array {
	if ( empty( $enc ) ) {
		return null;
	}
	try {
		$data = json_decode( $crypto->decrypt( $enc ), true );
	} catch ( \Throwable ) {
		return null;
	}

	return is_array( $data ) ? $data : null;
};

$decoded = array();
foreach ( $apps as $app ) {
	$decoded[ $app->id ] = array(
		'student' => $decrypt( $app->studentDataEnc ),
		'parent'  => $decrypt( $app->parentDataEnc ),
	);
}

// Сортировка — в PHP: ФИО зашифрованы, SQL их не упорядочит.
$sortColumns = array( 'student', 'parent', 'status', 'term', 'created' );
$orderby     = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
$orderby     = in_array( $orderby, $sortColumns, true ) ? $orderby : 'created';
$order       = 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ?? '' ) ) ) ? 'asc' : 'desc';

$statusRank = array_flip( array_map( static fn( $s ) => $s->value, ApplicationStatus::cases() ) );
$personName = static fn( ?array $d ): string => mb_strtolower( trim( (string) ( $d['full_name'] ?? '' ) ) );
$sortKey    = static function ( $app ) use ( $orderby, $decoded, $statusRank, $personName ): string {
	return match ( $orderby ) {
		'student' => $personName( $decoded[ $app->id ]['student'] ),
		'parent'  => $personName( $decoded[ $app->id ]['parent'] ),
		'status'  => sprintf( '%02d', $statusRank[ $app->status->value ] ?? 99 ),
		// «Срок» — время жизни JOIN-ссылки; ссылка действует только у заявок, ждущих родителя
		'term'    => ApplicationStatus::PendingParent === $app->status ? (string) $app->joinCodeExpiresAt : '',
		default   => $app->createdAt,
	};
};

usort( $apps, static function ( $a, $b ) use ( $sortKey, $order ): int {
	$ka = $sortKey( $a );
	$kb = $sortKey( $b );

	// Пустое значение (нет родителя, нет срока) — всегда в конце, в любом направлении.
	if ( ( '' === $ka ) !== ( '' === $kb ) ) {
		return '' === $ka ? 1 : -1;
	}

	$cmp = 'asc' === $order ? strcmp( $ka, $kb ) : strcmp( $kb, $ka );

	return 0 !== $cmp ? $cmp : $b->id <=> $a->id;
} );

$sortUrl = add_query_arg(
	array_filter( array( 'page' => 'fs_lms_userlist', 'tab' => 'tab-1', 'status' => $statusFilter ) ),
	admin_url( 'admin.php' )
);

$trashNonce = wp_create_nonce( Nonce::TrashApplication->value );

// Направление заявки хранится ключом предмета; в таблице нужно название.
// Карта читается один раз на всю страницу — не по запросу на строку.
$subjectNames = array();
foreach ( ( new SubjectRepository() )->readAll() as $subject ) {
	$subjectNames[ $subject->key ] = $subject->name;
}

$statusLabels = array_combine(
	array_map( fn( $s ) => $s->value, ApplicationStatus::cases() ),
	array_map( fn( $s ) => $s->label(), ApplicationStatus::cases() )
);

// Временный доступ до зачисления: запись ученика заявки в группе — одним запросом на страницу.
$trialRecords = ( new StudentRecordRepository() )->findTrialByStudents(
	array_map( static fn( $a ) => (int) $a->studentPersonId, $apps )
);
$groupsRepo   = new GroupsRepository();
$trialGroups  = array();
foreach ( $trialRecords as $trialRecord ) {
	$trialGroups[ $trialRecord->groupId ] ??= (string) ( $groupsRepo->findById( $trialRecord->groupId )->name ?? '' );
}

/**
 * Остаток срока в коротком виде («2 д 5 ч», «3 ч 10 мин»); null — срок уже вышел.
 *
 * @param string|null $expiresAtUtc Срок 'Y-m-d H:i:s' в UTC
 */
$timeLeft = static function ( ?string $expiresAtUtc ): ?string {
	$left = strtotime( $expiresAtUtc . ' UTC' ) - time();
	if ( $left <= 0 ) {
		return null;
	}

	$days  = intdiv( $left, DAY_IN_SECONDS );
	$hours = intdiv( $left % DAY_IN_SECONDS, HOUR_IN_SECONDS );
	if ( $days > 0 ) {
		return $hours > 0 ? "{$days} д {$hours} ч" : "{$days} д";
	}

	$minutes = max( 1, intdiv( $left % HOUR_IN_SECONDS, MINUTE_IN_SECONDS ) );

	return $hours > 0 ? "{$hours} ч {$minutes} мин" : "{$minutes} мин";
};

/**
 * Цвет остатка срока: красный — меньше $dangerBelow (или срок вышел), жёлтый — меньше $warnBelow.
 * Ссылка: 8 ч / 1 д. Заявка: 1 д / 4 д — жёлтым уже при «3 д …» в колонке.
 *
 * @param string|null $expiresAtUtc Срок 'Y-m-d H:i:s' в UTC
 * @param int         $dangerBelow  Порог красного, секунды
 * @param int         $warnBelow    Порог жёлтого, секунды
 */
$termTone = static function ( ?string $expiresAtUtc, int $dangerBelow, int $warnBelow ): string {
	$left = strtotime( $expiresAtUtc . ' UTC' ) - time();

	return match ( true ) {
		$left < $dangerBelow => 'fs-text-danger',
		$left < $warnBelow   => 'fs-text-warning',
		default              => '',
	};
};

?>

<div class="fs-lms-applications">

	<!-- Фильтры по статусу -->
	<ul class="subsubsub">
		<li>
			<a href="?page=fs_lms_userlist&tab=tab-1"
				class="<?php echo ! $statusFilter ? 'current' : ''; ?>">
				Все <span class="count">(<?php echo esc_html( (string) $repo->count( array() ) ); ?>)</span>
			</a> |
		</li>
		<?php foreach ( ApplicationStatus::cases() as $s ) :
			$cnt = $repo->count( array( 'status' => $s->value ) );
			if ( 0 === $cnt ) { continue; }
			?>
			<li>
				<a href="?page=fs_lms_userlist&tab=tab-1&status=<?php echo esc_attr( $s->value ); ?>"
					class="<?php echo $statusFilter === $s->value ? 'current' : ''; ?>">
					<?php echo esc_html( $s->label() ); ?>
					<span class="count">(<?php echo esc_html( (string) $cnt ); ?>)</span>
				</a>
				<?php if ( $s !== ApplicationStatus::Trash ) { echo ' | '; } ?>
			</li>
		<?php endforeach; ?>
	</ul>

    <table class="wp-list-table widefat fixed striped fs-table fs-table--applications">

        <thead>
        <tr>
            <?php // Ширины столбцов — утилитами tw-* (common/_widths.scss); у «Действий» — остаток. ?>
            <th class="column-title column-primary">
                <?php echo LogNameResolver::sortableHeader( 'ФИО ученика', 'student', $orderby, $order, $sortUrl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </th>

            <th class="column-title">
                <?php echo LogNameResolver::sortableHeader( 'ФИО родителя', 'parent', $orderby, $order, $sortUrl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </th>

            <th class="column-title tw-10">
                <?php esc_html_e( 'Направление', 'fs-lms' ); ?>
            </th>

            <th class="column-title tw-10">
                <?php echo LogNameResolver::sortableHeader( 'Статус', 'status', $orderby, $order, $sortUrl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </th>

            <th class="column-title tw-10">
                <?php esc_html_e( 'JOIN-ссылка', 'fs-lms' ); ?>
            </th>

            <th class="column-title tw-10">
                <?php echo LogNameResolver::sortableHeader( 'Срок', 'term', $orderby, $order, $sortUrl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </th>

            <th class="column-title tw-7">
                <?php echo LogNameResolver::sortableHeader( 'Создана', 'created', $orderby, $order, $sortUrl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </th>

            <th class="column-title tw-15">
                <?php esc_html_e( 'Действия', 'fs-lms' ); ?>
            </th>
        </tr>
        </thead>

        <tbody id="the-list">
        <?php if ( empty( $apps ) ) : ?>
            <tr>
                <td colspan="8">
                    <div class="notice notice-info inline fs-table__no-items">
                        <p><?php esc_html_e( 'Заявок пока нет.', 'fs-lms' ); ?></p>
                    </div>
                </td>
            </tr>

        <?php else : ?>
			<?php foreach ( $apps as $app ) :
				// Расшифровка данных ученика
				$studentName       = '';
				$studentLastName   = '';
				$studentFirstName  = '';
				$studentMiddleName = '';
				$studentEmail      = '';
				$studentPhone      = '';
				$studentSchool     = '';
				$studentGrade      = '';
				$studentBirthDate  = '';

				if ( ! empty( $app->studentDataEnc ) ) {
					try {
						$sd          = $decoded[ $app->id ]['student'] ?? throw new \RuntimeException( 'decrypt' );
						$studentName = $sd['full_name'] ?? '—';

						$sParts            = explode( ' ', $sd['full_name'] ?? '', 3 );
						$studentLastName   = $sd['last_name']   ?? $sParts[0] ?? '';
						$studentFirstName  = $sd['first_name']  ?? $sParts[1] ?? '';
						$studentMiddleName = $sd['middle_name'] ?? $sParts[2] ?? '';
						$studentEmail      = $sd['email']      ?? '';
						$studentPhone      = $sd['phone']      ?? '';
						$studentSchool     = $sd['school']     ?? '';
						$studentGrade      = (string) ( $sd['grade'] ?? '' );
						$studentBirthDate  = $sd['birth_date'] ?? '';
						$studentDocType    = $sd['doc_type']   ?? '';
						$studentDocNumber  = $sd['doc_number'] ?? '';
						$studentInn        = $sd['inn']        ?? '';
					} catch ( \Throwable $e ) {
						$studentName = '<em>Ошибка расшифровки</em>';
					}
				}

				// Расшифровка данных родителя (если заполнена)
				$parentName        = '—';
				$parentLastName    = '';
				$parentFirstName   = '';
				$parentMiddleName  = '';
				$parentBirthDate   = '';
				$parentRelationType  = '';
				$parentDocType     = '';
				$parentDocNumber   = '';
				$parentDocIssuedBy = '';
				$parentDocIssuedDate = '';
				$parentInn         = '';
				$parentAddress     = '';
				$parentPhone       = '';
				$parentEmail       = '';

				if ( ! empty( $app->parentDataEnc ) ) {
					try {
						$pd         = $decoded[ $app->id ]['parent'] ?? throw new \RuntimeException( 'decrypt' );
						$parentName = $pd['full_name'] ?? '—';

						$pParts             = explode( ' ', $pd['full_name'] ?? '', 3 );
						$parentLastName     = $pd['last_name']   ?? $pParts[0] ?? '';
						$parentFirstName    = $pd['first_name']  ?? $pParts[1] ?? '';
						$parentMiddleName   = $pd['middle_name'] ?? $pParts[2] ?? '';
						$parentBirthDate    = $pd['birth_date']      ?? '';
						$parentDocType      = $pd['doc_type']        ?? '';
						$parentDocNumber    = $pd['doc_number']      ?? '';
						$parentDocIssuedBy  = $pd['doc_issued_by']   ?? '';
						$parentDocIssuedDate = $pd['doc_issued_date'] ?? '';
						$parentInn          = $pd['inn']             ?? '';
						$parentAddress      = $pd['address']         ?? '';
						$parentPhone        = $pd['phone']           ?? '';
						$parentEmail        = $pd['email']           ?? '';
					} catch ( \Throwable $e ) {
						$parentName = '<em>Ошибка расшифровки</em>';
					}
				}

				// Расшифровка JOIN-кода
				$joinCode = null;
				if ( ! empty( $app->joinCodeEnc ) ) {
					try {
						$joinCode = $crypto->decrypt( $app->joinCodeEnc );
					} catch ( \Throwable $e ) {
						$joinCode = null;
					}
				}
				$joinUrl     = $joinCode ? home_url( '/lms/join/' . $joinCode ) : null;
				$joinDisplay = $joinCode ?? '—';

				$statusVal   = $app->status->value;
				$statusLabel = $statusLabels[ $statusVal ] ?? $statusVal;
				$statusClass = 'fs-lms-status--' . str_replace( '_', '-', $statusVal );

				$canEnroll = in_array( $app->status, [ ApplicationStatus::ReadyForReview, ApplicationStatus::Enrolling ], true );
				$canTrash  = $app->status->isTrashable();

				$isPending = ApplicationStatus::PendingParent === $app->status;
				$canTrial  = $isPending || ApplicationStatus::ReadyForReview === $app->status;
				$trial     = null !== $app->studentPersonId ? ( $trialRecords[ $app->studentPersonId ] ?? null ) : null;
			?>
			<tr data-app-id="<?php echo esc_attr( (string) $app->id ); ?>">

				   <td class="column-title">

                        <?php echo esc_html( $studentName ); ?>

                </td>

				<td class="column-title">

                    <?php echo esc_html( $parentName ); ?></td>

				<td class="column-title">
					<?php if ( null !== $app->subjectKey && isset( $subjectNames[ $app->subjectKey ] ) ) : ?>
						<?php echo esc_html( $subjectNames[ $app->subjectKey ] ); ?>
					<?php elseif ( null !== $app->subjectKey && '' !== $app->subjectKey ) : ?>
						<?php // Предмет удалён или переименован — ключ всё равно информативнее прочерка. ?>
						<?php echo esc_html( $app->subjectKey ); ?>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td>
					<span class="fs-lms-status <?php echo esc_attr( $statusClass ); ?>">
						<?php echo esc_html( $statusLabel ); ?>
					</span>
					<?php if ( null !== $trial ) : ?>
						<span class="fs-lms-status fs-lms-status--trial">
							<?php esc_html_e( 'Временный доступ', 'fs-lms' ); ?>
						</span>
						<span class="fs-lms-term fs-code-sm fs-text-muted"><?php echo esc_html( $trialGroups[ $trial->groupId ] ?? '' ); ?></span>
					<?php endif; ?>
				</td>

				<td>
					<?php // Ссылка нужна, только пока заявка ждёт родителя: после анкеты она не действует. ?>
					<?php if ( $joinUrl && $isPending ) : ?>
						<button type="button"
							class="button-link fs-lms-copy-join fs-lms-join-code"
							data-url="<?php echo esc_attr( $joinUrl ); ?>"
							title="<?php esc_attr_e( 'Нажмите, чтобы скопировать ссылку', 'fs-lms' ); ?>">
							<?php echo esc_html( $joinDisplay ); ?>
						</button>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
					<?php if ( $app->status === ApplicationStatus::PendingParent ) : ?>
						<br>
						<?php if ( $app->parentPersonId !== null ) : ?>
							<button type="button"
								class="button-link js-select-existing-parent fs-btn fs-btn--link-sm"
								data-application-id="<?php echo esc_attr( (string) $app->id ); ?>">
								<?php esc_html_e( '✎ Сменить родителя', 'fs-lms' ); ?>
							</button>
							<button type="button"
								class="button-link js-remove-parent-assignment fs-btn fs-btn--link-sm fs-text-danger"
								data-application-id="<?php echo esc_attr( (string) $app->id ); ?>">
								<?php esc_html_e( '✕ Снять назначение', 'fs-lms' ); ?>
							</button>
						<?php else : ?>
							<button type="button"
								class="button-link js-select-existing-parent fs-btn fs-btn--link-sm"
								data-application-id="<?php echo esc_attr( (string) $app->id ); ?>">
								<?php esc_html_e( '+ Назначить родителя', 'fs-lms' ); ?>
							</button>
						<?php endif; ?>
					<?php endif; ?>
				</td>

				<td class="column-term">
					<?php if ( $isPending ) :
						$linkLeft = $timeLeft( $app->joinCodeExpiresAt );
						$appLeft  = $timeLeft( $app->expiresAt );
						?>
						<span class="fs-lms-term <?php echo esc_attr( $termTone( $app->joinCodeExpiresAt, HOUR_IN_SECONDS * 8, DAY_IN_SECONDS ) ); ?>">
							<?php
							echo esc_html( null !== $linkLeft
								? sprintf( 'Ссылка: %s', $linkLeft )
								: 'Ссылка истекла — скопируйте заново' );
							?>
						</span>
						<span class="fs-lms-term <?php echo esc_attr( $termTone( $app->expiresAt, DAY_IN_SECONDS, DAY_IN_SECONDS * 4 ) ); ?>">
							<?php echo esc_html( sprintf( 'Заявка: %s', $appLeft ?? 'истекает' ) ); ?>
						</span>
					<?php elseif ( null !== $trial ) : ?>
						<span class="fs-lms-term fs-text-muted"><?php esc_html_e( 'Доступ до зачисления', 'fs-lms' ); ?></span>
					<?php else : ?>
						<span class="fs-table__empty-value">—</span>
					<?php endif; ?>
				</td>

				<td class="column-date">
					<?php echo esc_html( substr( $app->createdAt, 0, 10 ) ); ?>
				</td>

                <td class="column-actions">
                    <div class="row-actions visible">

						<?php if ( $app->status === ApplicationStatus::Trash ) : ?>

							<span class="view">
								<a href="#"
								   class="js-view-application"
								   data-s-last-name="<?php echo esc_attr( $studentLastName ); ?>"
								   data-s-first-name="<?php echo esc_attr( $studentFirstName ); ?>"
								   data-s-middle-name="<?php echo esc_attr( $studentMiddleName ); ?>"
								   data-s-birth-date="<?php echo esc_attr( $studentBirthDate ); ?>"
								   data-s-email="<?php echo esc_attr( $studentEmail ); ?>"
								   data-s-phone="<?php echo esc_attr( $studentPhone ); ?>"
								   data-s-school="<?php echo esc_attr( $studentSchool ); ?>"
								   data-s-grade="<?php echo esc_attr( $studentGrade ); ?>"
								   data-s-doc-type="<?php echo esc_attr( $studentDocType ); ?>"
								   data-s-doc-number="<?php echo esc_attr( $studentDocNumber ); ?>"
								   data-s-inn="<?php echo esc_attr( $studentInn ); ?>"
								   data-p-last-name="<?php echo esc_attr( $parentLastName ); ?>"
								   data-p-first-name="<?php echo esc_attr( $parentFirstName ); ?>"
								   data-p-middle-name="<?php echo esc_attr( $parentMiddleName ); ?>"
								   data-p-birth-date="<?php echo esc_attr( $parentBirthDate ); ?>"
								   data-p-email="<?php echo esc_attr( $parentEmail ); ?>"
								   data-p-phone="<?php echo esc_attr( $parentPhone ); ?>"
								   data-p-doc-type="<?php echo esc_attr( $parentDocType ); ?>"
								   data-p-doc-number="<?php echo esc_attr( $parentDocNumber ); ?>"
								   data-p-doc-issued-by="<?php echo esc_attr( $parentDocIssuedBy ); ?>"
								   data-p-doc-issued-date="<?php echo esc_attr( $parentDocIssuedDate ); ?>"
								   data-p-inn="<?php echo esc_attr( $parentInn ); ?>"
								   data-p-address="<?php echo esc_attr( $parentAddress ); ?>">
									<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
								</a>
							</span>

							|

                            <span class="restore">
								<a href="#"
								   class="fs-btn fs-btn--secondary"
								   data-id="<?php echo esc_attr( (string) $app->id ); ?>">
									<?php esc_html_e( 'Восстановить', 'fs-lms' ); ?>
								</a>
							</span>

                            |

                            <span class="delete">
								<a href="#"
								   class="fs-btn fs-btn--danger"
								   data-id="<?php echo esc_attr( (string) $app->id ); ?>">
									<?php esc_html_e( 'Удалить навсегда', 'fs-lms' ); ?>
								</a>
							</span>

						<?php else : ?>

							<?php if ( $canEnroll ) : ?>
                                <span class="enroll">
					<a href="#"
					   class="js-enrollment-application"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>"
					   data-status="<?php echo esc_attr( $app->status->value ); ?>">
						<?php esc_html_e( 'Зачислить', 'fs-lms' ); ?>
					</a>
				</span>
                                |
							<?php endif; ?>

                            <span class="edit">
				<?php if ( $app->status === ApplicationStatus::PendingParent ) : ?>
					<a href="#"
					   class="js-edit-application"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>"
					   data-trial-active="<?php echo null !== $trial ? '1' : ''; ?>"
					   data-trial-subject="<?php echo esc_attr( (string) $app->subjectKey ); ?>"
					   data-trial-student="<?php echo esc_attr( wp_strip_all_tags( $studentName ) ); ?>"
					   data-last-name="<?php echo esc_attr( $studentLastName ); ?>"
					   data-first-name="<?php echo esc_attr( $studentFirstName ); ?>"
					   data-middle-name="<?php echo esc_attr( $studentMiddleName ); ?>"
					   data-birth-date="<?php echo esc_attr( $studentBirthDate ); ?>"
					   data-email="<?php echo esc_attr( $studentEmail ); ?>"
					   data-phone="<?php echo esc_attr( $studentPhone ); ?>"
					   data-school="<?php echo esc_attr( $studentSchool ); ?>"
					   data-grade="<?php echo esc_attr( $studentGrade ); ?>"
					   data-login="<?php echo esc_attr( $sd['username'] ?? '' ); ?>"
					   data-password="<?php echo esc_attr( $sd['login_password'] ?? '' ); ?>">
						<?php esc_html_e( 'Изменить', 'fs-lms' ); ?>
					</a>
				<?php elseif ( $app->status === ApplicationStatus::ReadyForReview ) : ?>
					<a href="#"
					   class="js-review-application"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>"
					   data-trial-active="<?php echo null !== $trial ? '1' : ''; ?>"
					   data-trial-subject="<?php echo esc_attr( (string) $app->subjectKey ); ?>"
					   data-trial-student="<?php echo esc_attr( wp_strip_all_tags( $studentName ) ); ?>"
					   data-s-last-name="<?php echo esc_attr( $studentLastName ); ?>"
					   data-s-first-name="<?php echo esc_attr( $studentFirstName ); ?>"
					   data-s-middle-name="<?php echo esc_attr( $studentMiddleName ); ?>"
					   data-s-birth-date="<?php echo esc_attr( $studentBirthDate ); ?>"
					   data-s-doc-type="<?php echo esc_attr( $studentDocType ); ?>"
					   data-s-doc-number="<?php echo esc_attr( $studentDocNumber ); ?>"
					   data-s-inn="<?php echo esc_attr( $studentInn ); ?>"
					   data-p-last-name="<?php echo esc_attr( $parentLastName ); ?>"
					   data-p-first-name="<?php echo esc_attr( $parentFirstName ); ?>"
					   data-p-middle-name="<?php echo esc_attr( $parentMiddleName ); ?>"
					   data-p-birth-date="<?php echo esc_attr( $parentBirthDate ); ?>"
					   data-p-email="<?php echo esc_attr( $parentEmail ); ?>"
					   data-p-phone="<?php echo esc_attr( $parentPhone ); ?>"
					   data-p-doc-type="<?php echo esc_attr( $parentDocType ); ?>"
					   data-p-doc-number="<?php echo esc_attr( $parentDocNumber ); ?>"
					   data-p-doc-issued-by="<?php echo esc_attr( $parentDocIssuedBy ); ?>"
					   data-p-doc-issued-date="<?php echo esc_attr( $parentDocIssuedDate ); ?>"
					   data-p-inn="<?php echo esc_attr( $parentInn ); ?>"
					   data-p-address="<?php echo esc_attr( $parentAddress ); ?>">
						<?php esc_html_e( 'Изменить', 'fs-lms' ); ?>
					</a>
				<?php else : ?>
					<a href="#"
					   class="js-view-application"
					   data-s-last-name="<?php echo esc_attr( $studentLastName ); ?>"
					   data-s-first-name="<?php echo esc_attr( $studentFirstName ); ?>"
					   data-s-middle-name="<?php echo esc_attr( $studentMiddleName ); ?>"
					   data-s-birth-date="<?php echo esc_attr( $studentBirthDate ); ?>"
					   data-s-email="<?php echo esc_attr( $studentEmail ); ?>"
					   data-s-phone="<?php echo esc_attr( $studentPhone ); ?>"
					   data-s-school="<?php echo esc_attr( $studentSchool ); ?>"
					   data-s-grade="<?php echo esc_attr( $studentGrade ); ?>"
					   data-s-doc-type="<?php echo esc_attr( $studentDocType ); ?>"
					   data-s-doc-number="<?php echo esc_attr( $studentDocNumber ); ?>"
					   data-s-inn="<?php echo esc_attr( $studentInn ); ?>"
					   data-p-last-name="<?php echo esc_attr( $parentLastName ); ?>"
					   data-p-first-name="<?php echo esc_attr( $parentFirstName ); ?>"
					   data-p-middle-name="<?php echo esc_attr( $parentMiddleName ); ?>"
					   data-p-birth-date="<?php echo esc_attr( $parentBirthDate ); ?>"
					   data-p-email="<?php echo esc_attr( $parentEmail ); ?>"
					   data-p-phone="<?php echo esc_attr( $parentPhone ); ?>"
					   data-p-doc-type="<?php echo esc_attr( $parentDocType ); ?>"
					   data-p-doc-number="<?php echo esc_attr( $parentDocNumber ); ?>"
					   data-p-doc-issued-by="<?php echo esc_attr( $parentDocIssuedBy ); ?>"
					   data-p-doc-issued-date="<?php echo esc_attr( $parentDocIssuedDate ); ?>"
					   data-p-inn="<?php echo esc_attr( $parentInn ); ?>"
					   data-p-address="<?php echo esc_attr( $parentAddress ); ?>">
						<?php esc_html_e( 'Просмотреть', 'fs-lms' ); ?>
					</a>
				<?php endif; ?>
			</span>

							<?php // Выдача — кнопкой в модалке «Изменить»; здесь только снятие уже выданного. ?>
							<?php if ( $canTrial && null !== $trial ) : ?>
                                |
                                <span class="trial">
					<a href="#"
					   class="js-revoke-trial fs-text-danger"
					   data-id="<?php echo esc_attr( (string) $app->id ); ?>">
						<?php esc_html_e( 'Снять доступ', 'fs-lms' ); ?>
					</a>
				</span>
							<?php endif; ?>

							<?php if ( $canTrash ) : ?>
                                |
                                <span class="trash">
					<a href="#"
                       class="fs-btn fs-btn--ghost"
                       data-id="<?php echo esc_attr( (string) $app->id ); ?>">
						<?php esc_html_e( 'В корзину', 'fs-lms' ); ?>
					</a>
				</span>
							<?php endif; ?>

						<?php endif; ?>

                    </div>
                </td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

</div>

<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/applications/application-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/applications/application-review-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/applications/application-enrollment-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/applications/application-view-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/applications/trial-access-modal.php'; ?>
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/enrollment/select-parent-modal.php'; ?>
