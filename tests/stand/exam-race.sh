#!/bin/sh
# Стенд параллельных запросов экзаменов на настоящей MariaDB (этап 3.5, сценарии room-race — 4.4, start-vs-missed — 6.3).
#
# Гонку доказывает только база с несколькими соединениями: юнит-тесты на моках и FakeWpdb этого не проверяют.
# Параллельные процессы `wp fs-lms exam stand-*` запускаются внутри контейнера WordPress через `xargs -P`.
#
# Запуск:   tests/stand/exam-race.sh [--scenario=<имя|all>] [--runs=<N>] [--seats=<n>] [--participants=<m>]
# Сценарии: last-seat, multi-session, same-participant, two-sessions, guest-vs-student, expired-hold, room-race, start-vs-missed
# Код выхода: 0 — все проверки сошлись; 1 — есть расхождение (печатается FAIL с числами).
#
# Окружение (значения по умолчанию подходят dev-стенду из CLAUDE.md):
#   EXAM_STAND_CONTAINER  контейнер WordPress (wp_app)
#   EXAM_STAND_DB         контейнер базы (wp_db), нужен сценарию room-race
#   EXAM_STAND_WPCLI      команда WP-CLI внутри контейнера
#   EXAM_STAND_PARALLEL   число одновременных процессов (50)
#   EXAM_STAND_ASSESSMENT вариант экзамена для room-race (ID опубликованной работы, по умолчанию 723)
#   EXAM_STAND_SUBJECT    предмет этого варианта (inf_ege)
#   EXAM_STAND_ROOM       ID кабинета с вместимостью для room-race (первый из `wp_fs_lms_rooms`)
#   EXAM_STAND_LEAD       start-vs-missed: за сколько секунд до границы запускать процессы; не задан — сценарий измеряет сам
#                         (время загрузки WordPress у такого же числа параллельных процессов, умноженное на два, плюс запас)
set -eu

CONTAINER="${EXAM_STAND_CONTAINER:-wp_app}"
DB_CONTAINER="${EXAM_STAND_DB:-wp_db}"
WPCLI="${EXAM_STAND_WPCLI:-php /tmp/wp-cli.phar --allow-root --path=/var/www/html}"
PARALLEL="${EXAM_STAND_PARALLEL:-50}"
ASSESSMENT="${EXAM_STAND_ASSESSMENT:-723}"
SUBJECT="${EXAM_STAND_SUBJECT:-inf_ege}"

SCENARIO="all"
RUNS=1
SEATS=20
PARTICIPANTS=100
for arg in "$@"; do
	case "$arg" in
		--scenario=*) SCENARIO="${arg#--scenario=}" ;;
		--runs=*) RUNS="${arg#--runs=}" ;;
		--seats=*) SEATS="${arg#--seats=}" ;;
		--participants=*) PARTICIPANTS="${arg#--participants=}" ;;
		*) echo "Неизвестный параметр: $arg" >&2; exit 2 ;;
	esac
done

FAILED=0

# Одна команда WP-CLI (последовательно). Сообщения об ошибках PHP не мешают разбору ответа.
exam() {
	docker exec "$CONTAINER" sh -c "$WPCLI fs-lms exam $* 2>/dev/null"
}

# Параллельный запуск: каждая строка stdin — аргументы `fs-lms exam …`; печатает ответы процессов по одному на строку.
par() {
	docker exec -i "$CONTAINER" xargs -P "$PARALLEL" -I{} sh -c "$WPCLI fs-lms exam {} 2>/dev/null"
}

# Параллельный запуск с заданным числом процессов (по умолчанию в par — PARALLEL).
par_n() {
	docker exec -i "$CONTAINER" xargs -P "$1" -I{} sh -c "$WPCLI fs-lms exam {} 2>/dev/null" || true
}

# То же, но с сообщениями об ошибках (stderr) и без обрыва сценария: нужно, чтобы увидеть, что именно сказал упавший процесс.
par_n_verbose() {
	docker exec -i "$CONTAINER" xargs -P "$1" -I{} sh -c "$WPCLI fs-lms exam {} 2>&1" || true
}

# Значение поля из строки stand-report («capacity=20 occupied=20 …»).
field() {
	echo "$1" | tr ' ' '\n' | grep "^$2=" | cut -d= -f2
}

# Сколько раз слово встретилось в ответах (точное совпадение строки).
count() {
	echo "$1" | grep -c "^$2\$" || true
}

# Значение строки «ключ=значение» из вывода stand-seed.
seed_value() {
	echo "$1" | grep "^$2=" | cut -d= -f2
}

check() { # check <сценарий> <условие 0/1> <описание>
	if [ "$2" = "1" ]; then
		echo "  OK   $1: $3"
	else
		echo "  FAIL $1: $3"
		FAILED=1
	fi
}

has_errors() {
	if echo "$1" | grep -q "^error:"; then echo 1; else echo 0; fi
}

participant_range() { # participants=<first>-<last> → "first last"
	echo "$1" | tr '-' ' '
}

scenario_last_seat() {
	echo "== last-seat: $SEATS мест, $PARTICIPANTS участников"
	out=$(exam "stand-seed --seats=$SEATS --participants=$PARTICIPANTS --sessions=1")
	session=$(seed_value "$out" sessions)
	set -- $(participant_range "$(seed_value "$out" participants)")
	first=$1; last=$2

	answers=$(seq "$first" "$last" | sed "s/.*/stand-register --session=$session --participant=&/" | par)
	report=$(exam "stand-report --session=$session")
	confirmed=$(count "$answers" confirmed)
	full=$(count "$answers" full)

	echo "  ответы: confirmed=$confirmed full=$full; $report"
	check last-seat "$([ "$confirmed" -eq "$SEATS" ] && echo 1 || echo 0)" "ровно $SEATS подтверждённых"
	check last-seat "$([ "$full" -eq $((PARTICIPANTS - SEATS)) ] && echo 1 || echo 0)" "остальные получили «мест нет»"
	check last-seat "$([ "$(field "$report" occupied)" -eq "$SEATS" ] && [ "$(field "$report" active)" -eq "$SEATS" ] && echo 1 || echo 0)" "occupied_count = числу действующих записей = $SEATS"
	check last-seat "$([ "$(field "$report" doubles)" -eq 0 ] && echo 1 || echo 0)" "двойных записей нет"
	check last-seat "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

scenario_multi_session() {
	echo "== multi-session: сеансы на 5, 10, 15 и 20 мест, 100 участников"
	out=$(exam "stand-seed --seats=5,10,15,20 --participants=100")
	sessions=$(seed_value "$out" sessions)
	set -- $(participant_range "$(seed_value "$out" participants)")
	first=$1; last=$2

	# Сеанс выбирается по номеру участника: детерминированно, но с перемешиванием по четырём сеансам.
	answers=$(seq "$first" "$last" | awk -v list="$sessions" -v first="$first" 'BEGIN { n = split(list, s, ",") } { printf "stand-register --session=%s --participant=%d\n", s[(($1 * 7 + int($1 / 3)) % n) + 1], $1 }' | par)

	ok=1
	i=0
	for s in $(echo "$sessions" | tr ',' ' '); do
		report=$(exam "stand-report --session=$s")
		echo "  сеанс $s: $report"
		if [ "$(field "$report" occupied)" -gt "$(field "$report" capacity)" ] || [ "$(field "$report" occupied)" -ne "$(field "$report" active)" ]; then ok=0; fi
		i=$((i + 1))
	done
	check multi-session "$ok" "в каждом сеансе занято не больше вместимости и равно числу записей"
	check multi-session "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

scenario_same_participant() {
	echo "== same-participant: один участник, 10 запросов (5 с одним ключом, 5 с разными)"
	out=$(exam "stand-seed --seats=5 --participants=1 --sessions=1")
	session=$(seed_value "$out" sessions)
	participant=$(seed_value "$out" participants | cut -d- -f1)

	answers=$( (
		for n in 1 2 3 4 5; do echo "stand-register --session=$session --participant=$participant --key=same"; done
		for n in 1 2 3 4 5; do echo "stand-register --session=$session --participant=$participant --key=other$n"; done
	) | par)
	report=$(exam "stand-report --session=$session")

	echo "  ответы: confirmed=$(count "$answers" confirmed) conflict=$(count "$answers" conflict); $report"
	check same-participant "$([ "$(field "$report" active)" -eq 1 ] && [ "$(field "$report" occupied)" -eq 1 ] && echo 1 || echo 0)" "одна действующая запись и одно занятое место"
	check same-participant "$([ "$(field "$report" doubles)" -eq 0 ] && echo 1 || echo 0)" "двойных записей нет"
	check same-participant "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

scenario_two_sessions() {
	echo "== two-sessions: один участник одновременно в два сеанса одного проведения"
	out=$(exam "stand-seed --seats=5 --participants=1 --sessions=2")
	sessions=$(seed_value "$out" sessions)
	first_session=$(echo "$sessions" | cut -d, -f1)
	second_session=$(echo "$sessions" | cut -d, -f2)
	participant=$(seed_value "$out" participants | cut -d- -f1)

	answers=$( (
		for n in 1 2 3 4 5; do
			echo "stand-register --session=$first_session --participant=$participant --key=a$n"
			echo "stand-register --session=$second_session --participant=$participant --key=b$n"
		done
	) | par)
	r1=$(exam "stand-report --session=$first_session")
	r2=$(exam "stand-report --session=$second_session")

	active=$(( $(field "$r1" active) + $(field "$r2" active) ))
	occupied=$(( $(field "$r1" occupied) + $(field "$r2" occupied) ))
	echo "  ответы: confirmed=$(count "$answers" confirmed) conflict=$(count "$answers" conflict); действующих записей=$active занято мест=$occupied"
	check two-sessions "$([ "$active" -eq 1 ] && [ "$occupied" -eq 1 ] && echo 1 || echo 0)" "одна действующая запись и одно занятое место на оба сеанса"
	check two-sessions "$([ "$(field "$r1" doubles)" -eq 0 ] && echo 1 || echo 0)" "двойных записей нет"
	check two-sessions "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

scenario_guest_vs_student() {
	echo "== guest-vs-student: 1 место, ученик и гость одновременно"
	out=$(exam "stand-seed --seats=1 --participants=1 --sessions=1")
	session=$(seed_value "$out" sessions)
	participant=$(seed_value "$out" participants | cut -d- -f1)

	answers=$( (
		echo "stand-register --session=$session --participant=$participant"
		echo "stand-hold --session=$session --n=1"
	) | par)
	report=$(exam "stand-report --session=$session")

	winners=$(( $(count "$answers" confirmed) + $(count "$answers" held) ))
	echo "  ответы: $(echo "$answers" | tr '\n' ' '); $report"
	check guest-vs-student "$([ "$(field "$report" occupied)" -eq 1 ] && echo 1 || echo 0)" "занято ровно одно место"
	check guest-vs-student "$([ "$(( $(field "$report" active) + $(field "$report" holds) ))" -eq 1 ] && echo 1 || echo 0)" "запись и бронь вместе занимают одно место"
	check guest-vs-student "$([ "$(count "$answers" confirmed)" -le 1 ] && [ "$(count "$answers" held)" -le 1 ] && [ "$winners" -eq 1 ] && echo 1 || echo 0)" "один победитель"
	check guest-vs-student "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

scenario_expired_hold() {
	echo "== expired-hold: просроченная бронь и два параллельных тика"
	out=$(exam "stand-seed --seats=5 --participants=0 --sessions=1")
	session=$(seed_value "$out" sessions)
	exam "stand-hold --session=$session --n=1 --expired" >/dev/null
	before=$(exam "stand-report --session=$session")

	( echo "tick --name=hold-release"; echo "tick --name=hold-release" ) | par >/dev/null
	after=$(exam "stand-report --session=$session")

	echo "  до: $before"
	echo "  после: $after"
	check expired-hold "$([ "$(field "$before" occupied)" -eq 1 ] && [ "$(field "$before" holds)" -eq 1 ] && echo 1 || echo 0)" "до тика бронь держит одно место"
	check expired-hold "$([ "$(field "$after" occupied)" -eq 0 ] && [ "$(field "$after" holds)" -eq 0 ] && echo 1 || echo 0)" "место освобождено ровно один раз (occupied_count = 0, не отрицательно)"
}

scenario_room_race() {
	echo "== room-race: $PARALLEL процессов назначают сеанс в один кабинет на одно время"
	room="${EXAM_STAND_ROOM:-$(docker exec "$DB_CONTAINER" mariadb -u root -proot wordpress -N -e 'SELECT id FROM wp_fs_lms_rooms WHERE seats > 0 AND deleted_at IS NULL ORDER BY id LIMIT 1')}"
	if [ -z "$room" ]; then
		echo "  нет кабинета с вместимостью (этап 0.9): room-race пропущен"
		FAILED=1
		return
	fi
	day=$(date -d '+5 days' +%Y-%m-%d)

	# Каждый процесс создаёт своё проведение: так сеансы разных проведений борются за один кабинет, и только блокировка кабинета
	# не даёт назначить его дважды (в одном проведении их бы сериализовала блокировка самого проведения).
	answers=$(seq 1 "$PARALLEL" | sed "s/.*/stand-session --subject=$SUBJECT --room=$room --date=$day --time=10:00 --assessment=$ASSESSMENT/" | par)
	created=$(count "$answers" created)
	conflicts=$(count "$answers" conflict)
	rows=$(docker exec "$DB_CONTAINER" mariadb -u root -proot wordpress -N -e "SELECT COUNT(*) FROM wp_fs_lms_exam_sessions s INNER JOIN wp_fs_lms_exam_events e ON e.id = s.event_id WHERE e.title LIKE 'STAND%' AND s.room_id = $room")

	echo "  ответы: created=$created conflict=$conflicts; сеансов в базе=$rows"
	check room-race "$([ "$created" -eq 1 ] && [ "$rows" -eq 1 ] && echo 1 || echo 0)" "создан ровно один сеанс"
	check room-race "$([ "$conflicts" -eq $((PARALLEL - 1)) ] && echo 1 || echo 0)" "остальные получили «кабинет занят»"
	check room-race "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

scenario_start_vs_missed() {
	n="${EXAM_STAND_START_N:-50}"
	ticks="${EXAM_STAND_TICKS:-2}"
	echo "== start-vs-missed: $n участников, плановый конец сеанса наступает в момент запуска; параллельно старты и $ticks тика auto-expire"
	out=$(exam "stand-seed --seats=$n --participants=$n --sessions=1 --subject=$SUBJECT")
	event=$(seed_value "$out" event)
	session=$(seed_value "$out" sessions)
	set -- $(participant_range "$(seed_value "$out" participants)")
	first=$1; last=$2

	seq "$first" "$last" | sed "s/.*/stand-register --session=$session --participant=&/" | par >/dev/null

	# Все процессы должны успеть загрузить WordPress до границы, иначе проверяется не гонка, а опоздание: время загрузки измеряем на таком же числе параллельных процессов.
	lead="${EXAM_STAND_LEAD:-}"
	if [ -z "$lead" ]; then
		t0=$(docker exec "$CONTAINER" date +%s)
		seq 1 $((n + ticks)) | sed "s/.*/stand-report --session=$session/" | par_n $((n + ticks)) >/dev/null
		boot=$(( $(docker exec "$CONTAINER" date +%s) - t0 ))
		lead=$(( boot * 2 + 45 ))
		echo "  загрузка $((n + ticks)) процессов заняла $boot с; запас до границы $lead с"
	fi

	# Час контейнера, а не хоста: часы Docker могут расходиться с часами машины.
	end=$(( $(docker exec "$CONTAINER" date +%s) + lead ))
	exam "stand-window --session=$session --end-at=$end --assessment=$ASSESSMENT" >/dev/null

	answers=$({ seq "$first" "$last" | sed "s/.*/stand-start --event=$event --participant=& --at=$end --jitter=400/"; seq 1 "$ticks" | sed "s/.*/tick --name=auto-expire --at=$end/"; } | par_n_verbose $((n + ticks)))
	exam "tick --name=auto-expire" >/dev/null
	echo "  тики и сообщения об ошибках: $(echo "$answers" | grep -iE '^(Error|Warning|Success|PHP|Fatal)' | cut -c1-120 | sort | uniq -c | tr '
' ';')"

	started=$(count "$answers" started)
	missed=$(count "$answers" missed)
	row=$(docker exec "$DB_CONTAINER" mariadb -u root -proot wordpress -N -e "SELECT COUNT(*), COALESCE(SUM(pt.current_attempt_id IS NOT NULL), 0), COALESCE(SUM(r.status = 'missed'), 0), COALESCE(SUM(pt.current_attempt_id IS NOT NULL AND r.status = 'missed'), 0), COALESCE(SUM(pt.current_attempt_id IS NULL AND r.status <> 'missed'), 0) FROM wp_fs_lms_exam_participations pt INNER JOIN wp_fs_lms_exam_registrations r ON r.participation_id = pt.id WHERE pt.event_id = $event")
	set -- $row
	total=$1; with_attempt=$2; with_missed=$3; both=$4; neither=$5
	attempts=$(docker exec "$DB_CONTAINER" mariadb -u root -proot wordpress -N -e "SELECT COUNT(*) FROM wp_fs_lms_assessment_attempts a INNER JOIN wp_fs_lms_exam_participations pt ON pt.id = a.exam_participation_id WHERE pt.event_id = $event")
	report=$(exam "stand-report --session=$session")

	echo "  все ответы процессов: $(echo "$answers" | cut -c1-40 | sort | uniq -c | tr '
' ';')"
	echo "  ответы стартов: started=$started missed=$missed; в базе: участий=$total с попыткой=$with_attempt неявка=$with_missed обе=$both ни одной=$neither; попыток=$attempts; $report"
	check start-vs-missed "$([ "$total" -eq "$n" ] && echo 1 || echo 0)" "у каждого из $n участников одна запись"
	check start-vs-missed "$([ "$both" -eq 0 ] && echo 1 || echo 0)" "нет участий с попыткой и пропущенной записью одновременно"
	check start-vs-missed "$([ "$neither" -eq 0 ] && [ $((with_attempt + with_missed)) -eq "$n" ] && echo 1 || echo 0)" "у каждого участника ровно один исход: попытка ($with_attempt) или неявка ($with_missed)"
	check start-vs-missed "$([ "$attempts" -eq "$with_attempt" ] && echo 1 || echo 0)" "попыток в базе ровно по одной на участие ($attempts)"
	check start-vs-missed "$([ "$started" -eq "$with_attempt" ] && echo 1 || echo 0)" "ответов «started» столько же, сколько попыток"
	check start-vs-missed "$([ "$(field "$report" occupied)" -eq "$with_attempt" ] && [ "$(field "$report" active)" -eq "$with_attempt" ] && echo 1 || echo 0)" "места: заняты только у начавших (occupied = active = $with_attempt)"
	check start-vs-missed "$([ "$(has_errors "$answers")" -eq 0 ] && echo 1 || echo 0)" "ответов error: нет"
}

run() {
	name="$1"
	r=1
	while [ "$r" -le "$RUNS" ]; do
		echo "-- прогон $r из $RUNS"
		case "$name" in
			last-seat) scenario_last_seat ;;
			multi-session) scenario_multi_session ;;
			same-participant) scenario_same_participant ;;
			two-sessions) scenario_two_sessions ;;
			guest-vs-student) scenario_guest_vs_student ;;
			expired-hold) scenario_expired_hold ;;
			room-race) scenario_room_race ;;
			start-vs-missed) scenario_start_vs_missed ;;
			*) echo "Неизвестный сценарий: $name" >&2; exit 2 ;;
		esac
		exam "stand-clean" >/dev/null
		r=$((r + 1))
	done
}

if [ "$SCENARIO" = "all" ]; then
	for s in last-seat multi-session same-participant two-sessions guest-vs-student expired-hold room-race start-vs-missed; do
		run "$s"
	done
else
	run "$SCENARIO"
fi

left=$(docker exec "$DB_CONTAINER" mariadb -u root -proot wordpress -N -e "SELECT COUNT(*) FROM wp_fs_lms_exam_events WHERE title LIKE 'STAND%'")
check cleanup "$([ "$left" -eq 0 ] && echo 1 || echo 0)" "после stand-clean проведений стенда в базе: $left"

if [ "$FAILED" -ne 0 ]; then
	echo "ИТОГ: есть расхождения"
	exit 1
fi
echo "ИТОГ: все проверки сошлись"
