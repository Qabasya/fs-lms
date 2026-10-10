#!/usr/bin/env node
/**
 * Нагрузочный стенд сеанса (этап 13.1.4–13.1.5): N гостей стартуют одновременно, автосохраняют ответы в штатном темпе станции,
 * затем одновременно сдают работу. Запросы идут по HTTP к `admin-ajax.php` (PHP из Apache контейнера, а не WP-CLI).
 *
 * Запуск: node tests/stand/exam-session-load.mjs [--n=50] [--minutes=10] [--interval=3000] [--assessment=ID] [--base=http://localhost:8080]
 * Нужны: контейнеры `wp_app` и `wp_db` (docker), `/tmp/wp-cli.phar` в `wp_app`. Только dev. После прогона — `wp fs-lms exam stand-clean`.
 * Проверка: ни один отправленный ответ не потерян (последний отправленный текст по каждому заданию лежит в базе),
 * попыток ровно N, 95-й перцентиль времени ответа сервера на старт, сохранение и сдачу. Код выхода 1 при расхождении.
 */
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const arg = (name, fallback) => {
	const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
	return hit ? hit.slice(name.length + 3) : fallback;
};
const N = Number(arg('n', 50));
const MINUTES = Number(arg('minutes', 10));
const INTERVAL = Number(arg('interval', 3000));
const ASSESSMENT = arg('assessment', '19560');
const BASE = arg('base', 'http://localhost:8080');
const AJAX = `${BASE}/wp-admin/admin-ajax.php`;
const WPCLI = 'php /tmp/wp-cli.phar --allow-root --path=/var/www/html';

const sh = (cmd) => execSync(cmd, { encoding: 'utf8', maxBuffer: 1 << 26 });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const p95 = (xs) => (xs.length ? [...xs].sort((a, b) => a - b)[Math.min(xs.length - 1, Math.ceil(xs.length * 0.95) - 1)] : 0);
const db = (sql) => sh(`docker exec wp_db mariadb -u root -proot wordpress -N -e ${JSON.stringify(sql)}`).trim();

console.log(`Сеанс на ${N} гостей, ${MINUTES} мин, автосохранение раз в ${INTERVAL} мс на участника.`);
sh('docker cp tests/stand/exam-session-seed.php wp_app:/tmp/exam-session-seed.php');
const seeded = JSON.parse(sh(`docker exec wp_app sh -c "${WPCLI} eval-file /tmp/exam-session-seed.php ${N} ${ASSESSMENT} 2>/dev/null | tail -1"`));
const users = seeded.participants;

const post = async (user, params) => {
	const t0 = performance.now();
	const res = await fetch(AJAX, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: `fs_exam_guest=${user.cookie}` },
		body: new URLSearchParams(params),
	});
	const text = await res.text();
	let json = null;
	try { json = JSON.parse(text); } catch { /* не JSON — 500/белая страница */ }
	return { ms: performance.now() - t0, status: res.status, ok: res.status === 200 && json?.success === true, json };
};

// Нонсы страницы станции (одинаковые для всех гостей: пользователь не вошёл).
const page = await (await fetch(`${seeded.permalink}?exam_reg=${users[0].registration_id}`, { headers: { Cookie: `fs_exam_guest=${users[0].cookie}` } })).text();
const nonce = (key) => page.match(new RegExp(`"${key}":"([a-f0-9]+)"`))?.[1];
const startNonce = nonce('startAttempt');
const submitNonce = nonce('submitAttempt');
if (!startNonce || !submitNonce) { console.error('Нонсы станции не найдены на странице'); process.exit(1); }

const stats = { start: [], save: [], submit: [] };
const failures = [];
const record = (kind, r, who) => { stats[kind].push(r.ms); if (!r.ok) { failures.push(`${kind} ${who}: HTTP ${r.status} ${JSON.stringify(r.json)?.slice(0, 160)}`); } };

// 1. Одновременный старт.
const started = await Promise.all(users.map(async (u) => {
	const r = await post(u, { action: 'start_attempt', security: startNonce, assessment_id: ASSESSMENT, exam_registration_id: u.registration_id });
	record('start', r, u.participation_id);
	u.attemptId = r.json?.data?.attempt_id;
	return r;
}));
console.log(`Старт: ${started.filter((r) => r.ok).length}/${N} успешно, p95 ${p95(stats.start).toFixed(0)} мс`);

// 2. Автосохранение в штатном темпе: каждый участник — своим циклом, со сдвигом, чтобы запросы не шли залпом.
const sent = new Map(); // `${attempt}:${task}` → последний принятый текст
const deadline = Date.now() + MINUTES * 60_000;
await Promise.all(users.map(async (u, index) => {
	await sleep((index * INTERVAL) / N);
	let seq = 0;
	while (Date.now() < deadline && u.attemptId) {
		const task = seeded.task_ids[seq % seeded.task_ids.length];
		const text = `ответ ${u.participation_id}-${++seq}`;
		const r = await post(u, { action: 'save_attempt_answer', security: startNonce, attempt_id: u.attemptId, task_id: task, answer_text: text });
		record('save', r, u.participation_id);
		if (r.ok) { sent.set(`${u.attemptId}:${task}`, text); }
		await sleep(INTERVAL);
	}
}));
console.log(`Сохранений: ${stats.save.length}, успешно ${stats.save.length - failures.filter((f) => f.startsWith('save')).length}, p95 ${p95(stats.save).toFixed(0)} мс`);

// 3. Одновременная сдача.
await Promise.all(users.map(async (u) => record('submit', await post(u, { action: 'submit_attempt', security: submitNonce, attempt_id: u.attemptId }), u.participation_id)));
console.log(`Сдача: p95 ${p95(stats.submit).toFixed(0)} мс`);

// 4. Сверка с базой.
const attempts = Number(db(`SELECT COUNT(*) FROM wp_fs_lms_assessment_attempts a INNER JOIN wp_fs_lms_exam_participations p ON p.id = a.exam_participation_id WHERE p.event_id = ${seeded.event_id}`));
const submitted = Number(db(`SELECT COUNT(*) FROM wp_fs_lms_assessment_attempts a INNER JOIN wp_fs_lms_exam_participations p ON p.id = a.exam_participation_id WHERE p.event_id = ${seeded.event_id} AND a.status <> 'in_progress'`));
const rows = db(`SELECT ans.attempt_id, ans.task_id, ans.answer_text FROM wp_fs_lms_assessment_answers ans INNER JOIN wp_fs_lms_assessment_attempts a ON a.id = ans.attempt_id INNER JOIN wp_fs_lms_exam_participations p ON p.id = a.exam_participation_id WHERE p.event_id = ${seeded.event_id}`);
const stored = new Map(rows.split('\n').filter(Boolean).map((l) => { const [a, t, ...rest] = l.split('\t'); return [`${a}:${t}`, rest.join('\t')]; }));
let lost = 0;
for (const [key, text] of sent) { if (stored.get(key) !== text) { lost++; } }
const outbox = Number(db(`SELECT COUNT(*) FROM wp_fs_lms_exam_events_outbox o INNER JOIN wp_fs_lms_exam_participations p ON o.aggregate_type = 'participation' AND o.aggregate_id = p.id WHERE p.event_id = ${seeded.event_id} AND o.type = 'attempt_started'`));
const dupAttempts = Number(db(`SELECT COUNT(*) FROM ( SELECT exam_participation_id FROM wp_fs_lms_assessment_attempts WHERE exam_participation_id IN ( SELECT id FROM wp_fs_lms_exam_participations WHERE event_id = ${seeded.event_id} ) GROUP BY exam_participation_id HAVING COUNT(*) > 1 ) d`));

const summary = {
	participants: N, minutes: MINUTES, interval_ms: INTERVAL,
	attempts, submitted, duplicate_attempts: dupAttempts, outbox_attempt_started: outbox,
	saves_sent: stats.save.length, answers_expected: sent.size, answers_lost: lost,
	http_failures: failures.length,
	p95_ms: { start: Math.round(p95(stats.start)), save: Math.round(p95(stats.save)), submit: Math.round(p95(stats.submit)) },
};
console.log(JSON.stringify(summary, null, 2));
if (failures.length) { console.log(failures.slice(0, 10).join('\n')); }

const ok = attempts === N && submitted === N && dupAttempts === 0 && lost === 0 && failures.length === 0 && outbox === N;
console.log(ok ? 'ИТОГ: все проверки сошлись' : 'ИТОГ: есть расхождения');
sh(`docker exec wp_app sh -c "${WPCLI} fs-lms exam stand-clean 2>/dev/null | tail -1"`);
process.exit(ok ? 0 : 1);
