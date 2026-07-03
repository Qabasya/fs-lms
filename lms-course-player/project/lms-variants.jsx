// lms-variants.jsx — LMS Assignment Page design variants

const LMS_F = {
  serif: "'IBM Plex Serif', Georgia, serif",
  sans:  "'IBM Plex Sans', system-ui, sans-serif",
  mono:  "'IBM Plex Mono', 'Courier New', monospace",
};

const LMS_TASK = {
  course:      "Математический анализ",
  semester:    "2 курс · 3 семестр",
  module:      "Модуль 3",
  moduleTitle: "Производные и дифференциалы",
  taskNum:     "Задание 3.2",
  taskTitle:   "Нахождение производной сложной функции",
  condition:   "Дана функция f(x) = arctg(√(x² + 1)). Найдите производную f′(x), упростите результат и исследуйте функцию на монотонность на множестве вещественных чисел.",
  details:     "При решении используйте правило дифференцирования сложной функции. Ответ оформите в развёрнутой форме с пошаговыми выкладками. Для проверки вычислите f′(0) и f′(1) и сопоставьте результат с графиком функции.",
  hint:        "Напоминание: производная arctg(u) равна u′ / (1 + u²).",
  solution:    "f′(x)  =  [arctg(√(x² + 1))]′\n       =  1/(1 + (x² + 1)) · (√(x² + 1))′\n       =  1/(x² + 2) · x / √(x² + 1)\n       =  x / ((x² + 2) · √(x² + 1))",
  conclusion:  "Знак f′(x) совпадает со знаком x: функция убывает при x < 0 и возрастает при x > 0. Критическая точка x = 0, значение f(0) = arctg(1) = π/4.",
  files: [
    { name: "Методические указания.pdf",   size: "1.2 МБ",  type: "pdf" },
    { name: "Таблица производных.pdf",     size: "340 КБ",  type: "pdf" },
    { name: "Условие задания 3.2.docx",    size: "48 КБ",   type: "doc" },
  ],
  related: [
    { title: "Лекция 7: Правила дифференцирования",              type: "Лекция"     },
    { title: "Практика 3.1: Производные элементарных функций",   type: "Практика"   },
    { title: "Справочник по формулам дифференцирования",          type: "Справочник" },
  ],
};

// ─── Icons ───────────────────────────────────────────────────────────────────

const IcoDoc = ({ type = "pdf" }) => {
  const color = type === "doc" ? "#3b82f6" : "#ef4444";
  const label = type === "doc" ? "DOC" : "PDF";
  return (
    <svg width="18" height="22" viewBox="0 0 18 22" fill="none">
      <path d="M2 1h9l5 5v15H2V1z" fill="#f9fafb" stroke="#e5e7eb" strokeWidth="1"/>
      <path d="M11 1v5h5" fill="none" stroke="#e5e7eb" strokeWidth="1"/>
      <rect x="0" y="9" width="12" height="6" rx="1.5" fill={color}/>
      <text x="6" y="14.2" textAnchor="middle" fill="white" fontSize="4" fontFamily="system-ui" fontWeight="700">{label}</text>
    </svg>
  );
};

const IcoDl = ({ color = "currentColor" }) => (
  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
    <line x1="6.5" y1="1" x2="6.5" y2="9" stroke={color} strokeWidth="1.5" strokeLinecap="round"/>
    <polyline points="3,6.5 6.5,10 10,6.5" fill="none" stroke={color} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
    <line x1="1.5" y1="12" x2="11.5" y2="12" stroke={color} strokeWidth="1.5" strokeLinecap="round"/>
  </svg>
);

const IcoLink = () => (
  <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
    <path d="M4.5 7.5L7.5 4.5" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round"/>
    <path d="M5 3.5H3A2.5 2.5 0 003 8.5h2" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round"/>
    <path d="M7 8.5h2A2.5 2.5 0 009 3.5H7" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round"/>
  </svg>
);

const IcoCheck = ({ c = "#22c55e" }) => (
  <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
    <polyline points="1,5 3.5,8 9,2" stroke={c} strokeWidth="1.6" fill="none" strokeLinecap="round" strokeLinejoin="round"/>
  </svg>
);

// Density multiplier
const sp = (n, density) => {
  const m = density === "compact" ? 0.72 : density === "spacious" ? 1.3 : 1;
  return n * m;
};

// ─────────────────────────────────────────────────────────────────────────────
// VARIANT A  —  Classic two-column (Coursera-style)
// ─────────────────────────────────────────────────────────────────────────────

const VariantA = ({ accent = "#1a56a0", showSolution = true, density = "normal" }) => {
  const s = (n) => sp(n, density);

  const mods = [
    { id: 1, lbl: "Модуль 1", title: "Пределы последовательностей", done: true,  open: false },
    { id: 2, lbl: "Модуль 2", title: "Непрерывность функций",        done: true,  open: false },
    { id: 3, lbl: "Модуль 3", title: "Производные и дифференциалы",  done: false, open: true,
      tasks: [
        { n: "3.1", t: "Производные элем. функций",    done: true  },
        { n: "3.2", t: "Производная сложной функции",  done: false, active: true },
        { n: "3.3", t: "Дифференциал функции",         done: false },
      ]},
    { id: 4, lbl: "Модуль 4", title: "Интегральное исчисление",       done: false, open: false },
  ];

  const SectionHead = ({ children }) => (
    <h2 style={{ fontSize: 10, fontWeight: 700, letterSpacing: ".1em", textTransform: "uppercase",
      color: "#6b7280", margin: `0 0 ${s(12)}px`, paddingBottom: 8, borderBottom: "1px solid #e5e7eb" }}>
      {children}
    </h2>
  );

  return (
    <div style={{ display: "flex", minHeight: 1380, fontFamily: LMS_F.sans, fontSize: 14, color: "#1a1a1a", background: "#fff" }}>

      {/* ── Sidebar ── */}
      <div style={{ width: 256, flexShrink: 0, background: "#f7f8fa", borderRight: "1px solid #e5e7eb", display: "flex", flexDirection: "column" }}>

        <div style={{ padding: `${s(18)}px ${s(20)}px`, borderBottom: "1px solid #e5e7eb", background: "#fff" }}>
          <div style={{ fontSize: 10, letterSpacing: ".1em", textTransform: "uppercase", color: accent, fontWeight: 700, marginBottom: 5 }}>Курс</div>
          <div style={{ fontFamily: LMS_F.serif, fontWeight: 600, fontSize: 15, color: "#0d1b2a", lineHeight: 1.3 }}>{LMS_TASK.course}</div>
          <div style={{ fontSize: 11, color: "#6b7280", marginTop: 4 }}>{LMS_TASK.semester}</div>
        </div>

        <div style={{ padding: `${s(14)}px ${s(20)}px`, borderBottom: "1px solid #e5e7eb", background: "#fff" }}>
          <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 7 }}>
            <span style={{ fontSize: 10, color: "#9ca3af", fontWeight: 600, letterSpacing: ".06em", textTransform: "uppercase" }}>Прогресс</span>
            <span style={{ fontSize: 11, fontWeight: 700, color: accent }}>33%</span>
          </div>
          <div style={{ height: 3, background: "#e5e7eb", borderRadius: 2 }}>
            <div style={{ width: "33%", height: "100%", background: accent, borderRadius: 2 }} />
          </div>
          <div style={{ fontSize: 11, color: "#9ca3af", marginTop: 5 }}>4 из 12 заданий</div>
        </div>

        <div style={{ flex: 1, paddingTop: s(8) }}>
          {mods.map(m => (
            <div key={m.id}>
              <div style={{ display: "flex", alignItems: "flex-start", gap: 9, padding: `${s(9)}px ${s(18)}px`, cursor: "pointer", background: m.open ? `${accent}0d` : "transparent" }}>
                <svg width="8" height="8" viewBox="0 0 8 8" style={{ marginTop: 3, flexShrink: 0 }}>
                  {m.open
                    ? <polygon points="0,1 8,1 4,7" fill={accent} />
                    : <polygon points="1,0 7,4 1,8" fill="#c4ccd8" />}
                </svg>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 10, color: m.open ? accent : "#9ca3af", fontWeight: 700, letterSpacing: ".06em", textTransform: "uppercase" }}>{m.lbl}</div>
                  <div style={{ fontSize: 12, color: m.open ? "#0d1b2a" : "#6b7280", fontWeight: m.open ? 500 : 400, lineHeight: 1.35, marginTop: 1 }}>{m.title}</div>
                </div>
                {m.done && <IcoCheck c="#22c55e" />}
              </div>
              {m.open && m.tasks && m.tasks.map(t => (
                <div key={t.n} style={{ display: "flex", alignItems: "center", gap: 9, padding: `${s(7)}px ${s(18)}px ${s(7)}px 40px`, borderLeft: t.active ? `3px solid ${accent}` : "3px solid transparent", background: t.active ? `${accent}0e` : "transparent", cursor: "pointer" }}>
                  <div style={{ width: 15, height: 15, borderRadius: "50%", border: `2px solid ${t.done ? "#22c55e" : t.active ? accent : "#d1d5db"}`, background: t.done ? "#22c55e" : "transparent", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
                    {t.done && <IcoCheck c="#fff" />}
                    {t.active && !t.done && <div style={{ width: 5, height: 5, borderRadius: "50%", background: accent }} />}
                  </div>
                  <span style={{ fontSize: 12, color: t.active ? "#0d1b2a" : "#6b7280", fontWeight: t.active ? 600 : 400, lineHeight: 1.3 }}>{t.n}. {t.t}</span>
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>

      {/* ── Main ── */}
      <div style={{ flex: 1, display: "flex", flexDirection: "column", minWidth: 0 }}>
        <div style={{ padding: `${s(11)}px ${s(36)}px`, borderBottom: "1px solid #e5e7eb", display: "flex", alignItems: "center", gap: 5, fontSize: 12, color: "#6b7280" }}>
          <span style={{ color: accent, cursor: "pointer" }}>{LMS_TASK.course}</span>
          <span>›</span>
          <span style={{ color: accent, cursor: "pointer" }}>{LMS_TASK.module}: {LMS_TASK.moduleTitle}</span>
          <span>›</span>
          <span style={{ color: "#1a1a1a", fontWeight: 500 }}>{LMS_TASK.taskNum}</span>
          <span style={{ marginLeft: "auto", padding: "3px 10px", borderRadius: 12, background: "#dcfce7", color: "#166534", fontSize: 10, fontWeight: 700, letterSpacing: ".04em" }}>ОТКРЫТО</span>
        </div>

        <div style={{ padding: `${s(36)}px`, maxWidth: 860 }}>
          <div style={{ marginBottom: s(28) }}>
            <div style={{ fontSize: 12, color: "#6b7280", marginBottom: 8 }}>{LMS_TASK.module}: {LMS_TASK.moduleTitle}</div>
            <h1 style={{ fontFamily: LMS_F.serif, fontSize: 25, fontWeight: 600, color: "#0d1b2a", lineHeight: 1.25, margin: 0 }}>{LMS_TASK.taskNum}: {LMS_TASK.taskTitle}</h1>
          </div>

          <section style={{ marginBottom: s(26) }}>
            <SectionHead>Условие задания</SectionHead>
            <div style={{ background: "#f8f9fb", borderLeft: `4px solid ${accent}`, padding: `${s(18)}px ${s(22)}px`, borderRadius: "0 6px 6px 0" }}>
              <p style={{ fontFamily: LMS_F.serif, fontSize: 15, lineHeight: 1.7, color: "#111827", margin: `0 0 ${s(10)}px` }}>{LMS_TASK.condition}</p>
              <p style={{ fontSize: 13, lineHeight: 1.65, color: "#374151", margin: 0 }}>{LMS_TASK.details}</p>
            </div>
            <div style={{ marginTop: s(12), padding: `${s(11)}px ${s(16)}px`, background: `${accent}09`, border: `1px solid ${accent}22`, borderRadius: 6, fontSize: 13, color: "#374151" }}>
              <strong style={{ color: accent }}>Подсказка: </strong>{LMS_TASK.hint}
            </div>
          </section>

          {showSolution && (
            <section style={{ marginBottom: s(26) }}>
              <SectionHead>Решение</SectionHead>
              <div style={{ border: "1px solid #e5e7eb", borderRadius: 8, overflow: "hidden" }}>
                <div style={{ padding: `${s(16)}px ${s(22)}px`, background: "#f8f9fb", borderBottom: "1px solid #e5e7eb" }}>
                  <pre style={{ fontFamily: LMS_F.mono, fontSize: 13, lineHeight: 1.9, color: "#1a1a1a", whiteSpace: "pre-wrap", margin: 0 }}>{LMS_TASK.solution}</pre>
                </div>
                <div style={{ padding: `${s(14)}px ${s(22)}px` }}>
                  <p style={{ fontSize: 13, lineHeight: 1.6, color: "#374151", margin: 0 }}><strong>Вывод:</strong> {LMS_TASK.conclusion}</p>
                </div>
              </div>
            </section>
          )}

          <section style={{ marginBottom: s(26) }}>
            <SectionHead>Файлы</SectionHead>
            <div style={{ display: "flex", flexDirection: "column", gap: s(8) }}>
              {LMS_TASK.files.map(f => (
                <div key={f.name} style={{ display: "flex", alignItems: "center", gap: 13, padding: `${s(11)}px ${s(15)}px`, border: "1px solid #e5e7eb", borderRadius: 8 }}>
                  <IcoDoc type={f.type} />
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 13, fontWeight: 500, color: "#111827" }}>{f.name}</div>
                    <div style={{ fontSize: 11, color: "#9ca3af", marginTop: 2 }}>{f.size}</div>
                  </div>
                  <button style={{ display: "flex", alignItems: "center", gap: 5, padding: "5px 13px", border: `1px solid ${accent}`, borderRadius: 5, background: "#fff", color: accent, fontSize: 12, fontWeight: 600, cursor: "pointer", fontFamily: LMS_F.sans }}>
                    <IcoDl color={accent} />&nbsp;Скачать
                  </button>
                </div>
              ))}
            </div>
          </section>

          <section>
            <SectionHead>Связанные материалы</SectionHead>
            <div style={{ display: "flex", flexDirection: "column", gap: s(7) }}>
              {LMS_TASK.related.map(r => (
                <div key={r.title} style={{ display: "flex", alignItems: "center", gap: 11, padding: `${s(11)}px ${s(15)}px`, border: "1px solid #e5e7eb", borderRadius: 8, cursor: "pointer" }}>
                  <span style={{ padding: "2px 7px", background: `${accent}14`, color: accent, fontSize: 10, fontWeight: 700, borderRadius: 4, letterSpacing: ".04em", flexShrink: 0 }}>{r.type.toUpperCase()}</span>
                  <span style={{ fontSize: 13, fontWeight: 500, color: "#111827", flex: 1 }}>{r.title}</span>
                  <span style={{ color: accent }}><IcoLink /></span>
                </div>
              ))}
            </div>
          </section>
        </div>
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────────────────────
// VARIANT B  —  Document-style (centered, banner header)
// ─────────────────────────────────────────────────────────────────────────────

const VariantB = ({ accent = "#1a56a0", showSolution = true, density = "normal" }) => {
  const s = (n) => sp(n, density);

  return (
    <div style={{ fontFamily: LMS_F.sans, fontSize: 14, color: "#1a1a1a", background: "#fafaf8", minHeight: 1300 }}>

      {/* Top nav */}
      <div style={{ background: "#fff", borderBottom: "1px solid #e5e7eb", padding: `${s(12)}px ${s(32)}px`, display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <div style={{ width: 28, height: 28, borderRadius: 6, background: accent, display: "flex", alignItems: "center", justifyContent: "center" }}>
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
              <rect x="1" y="1" width="5" height="5" rx="1" fill="white" opacity=".9"/>
              <rect x="8" y="1" width="5" height="5" rx="1" fill="white" opacity=".6"/>
              <rect x="1" y="8" width="5" height="5" rx="1" fill="white" opacity=".6"/>
              <rect x="8" y="8" width="5" height="5" rx="1" fill="white" opacity=".9"/>
            </svg>
          </div>
          <span style={{ fontFamily: LMS_F.serif, fontWeight: 600, fontSize: 14, color: "#0d1b2a" }}>{LMS_TASK.course}</span>
          <span style={{ color: "#d1d5db", fontSize: 16 }}>·</span>
          <span style={{ fontSize: 12, color: "#6b7280" }}>{LMS_TASK.semester}</span>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 20 }}>
          {["Материалы", "Обсуждение", "Оценки"].map(lnk => (
            <span key={lnk} style={{ fontSize: 12, color: "#6b7280", cursor: "pointer" }}>{lnk}</span>
          ))}
          <div style={{ width: 30, height: 30, borderRadius: "50%", background: accent, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 11, fontWeight: 700, color: "#fff", cursor: "pointer" }}>АС</div>
        </div>
      </div>

      {/* Banner */}
      <div style={{ background: accent, padding: `${s(40)}px ${s(32)}px ${s(44)}px` }}>
        <div style={{ maxWidth: 780, margin: "0 auto" }}>
          <div style={{ display: "flex", alignItems: "center", gap: 5, fontSize: 12, color: "rgba(255,255,255,.65)", marginBottom: s(14) }}>
            <span style={{ cursor: "pointer" }}>{LMS_TASK.course}</span>
            <span>›</span>
            <span style={{ cursor: "pointer" }}>{LMS_TASK.module}: {LMS_TASK.moduleTitle}</span>
            <span>›</span>
            <span style={{ color: "rgba(255,255,255,.9)" }}>{LMS_TASK.taskNum}</span>
          </div>
          <h1 style={{ fontFamily: LMS_F.serif, fontSize: 28, fontWeight: 600, color: "#fff", lineHeight: 1.22, margin: `0 0 ${s(16)}px` }}>
            {LMS_TASK.taskNum}: {LMS_TASK.taskTitle}
          </h1>
          <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
            <span style={{ fontSize: 13, color: "rgba(255,255,255,.7)" }}>{LMS_TASK.module} · {LMS_TASK.moduleTitle}</span>
            <span style={{ padding: "3px 11px", background: "rgba(255,255,255,.18)", color: "#fff", borderRadius: 12, fontSize: 11, fontWeight: 700, border: "1px solid rgba(255,255,255,.3)" }}>Открыто</span>
          </div>
        </div>
      </div>

      {/* Content */}
      <div style={{ maxWidth: 780, margin: "0 auto", padding: `${s(36)}px ${s(16)}px ${s(56)}px` }}>

        {/* Condition */}
        <div style={{ background: "#fff", border: "1px solid #e5e7eb", borderRadius: 10, overflow: "hidden", marginBottom: s(20) }}>
          <div style={{ padding: `${s(15)}px ${s(24)}px`, borderBottom: "1px solid #e5e7eb", display: "flex", alignItems: "center", gap: 10 }}>
            <div style={{ width: 3, height: 18, background: accent, borderRadius: 2 }} />
            <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: ".08em", textTransform: "uppercase", color: "#374151" }}>Условие задания</span>
          </div>
          <div style={{ padding: `${s(22)}px ${s(24)}px` }}>
            <p style={{ fontFamily: LMS_F.serif, fontSize: 16, lineHeight: 1.72, color: "#111827", margin: `0 0 ${s(14)}px` }}>{LMS_TASK.condition}</p>
            <p style={{ fontSize: 13.5, lineHeight: 1.65, color: "#374151", margin: `0 0 ${s(16)}px` }}>{LMS_TASK.details}</p>
            <div style={{ padding: `${s(12)}px ${s(16)}px`, background: `${accent}09`, border: `1px solid ${accent}22`, borderRadius: 7, fontSize: 13, color: "#374151" }}>
              <strong style={{ color: accent }}>Подсказка: </strong>{LMS_TASK.hint}
            </div>
          </div>
        </div>

        {/* Solution */}
        {showSolution && (
          <div style={{ background: "#fff", border: "1px solid #e5e7eb", borderRadius: 10, overflow: "hidden", marginBottom: s(20) }}>
            <div style={{ padding: `${s(15)}px ${s(24)}px`, borderBottom: "1px solid #e5e7eb", display: "flex", alignItems: "center", gap: 10 }}>
              <div style={{ width: 3, height: 18, background: "#22c55e", borderRadius: 2 }} />
              <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: ".08em", textTransform: "uppercase", color: "#374151" }}>Решение</span>
            </div>
            <div style={{ padding: `${s(22)}px ${s(24)}px` }}>
              <pre style={{ fontFamily: LMS_F.mono, fontSize: 13, lineHeight: 1.9, color: "#1a1a1a", whiteSpace: "pre-wrap", margin: `0 0 ${s(16)}px`, background: "#f8f9fb", padding: `${s(16)}px`, borderRadius: 7 }}>{LMS_TASK.solution}</pre>
              <p style={{ fontSize: 13.5, lineHeight: 1.6, color: "#374151", margin: 0 }}><strong>Вывод:</strong> {LMS_TASK.conclusion}</p>
            </div>
          </div>
        )}

        {/* Files + Related in 2-col */}
        <div style={{ display: "flex", gap: s(16), alignItems: "flex-start" }}>
          <div style={{ flex: 1, background: "#fff", border: "1px solid #e5e7eb", borderRadius: 10, overflow: "hidden" }}>
            <div style={{ padding: `${s(14)}px ${s(20)}px`, borderBottom: "1px solid #e5e7eb" }}>
              <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: ".08em", textTransform: "uppercase", color: "#374151" }}>Файлы</span>
            </div>
            <div style={{ padding: `${s(12)}px ${s(16)}px`, display: "flex", flexDirection: "column", gap: s(8) }}>
              {LMS_TASK.files.map(f => (
                <div key={f.name} style={{ display: "flex", alignItems: "center", gap: 10, padding: `${s(9)}px`, borderRadius: 7, border: "1px solid #f0f0f0" }}>
                  <IcoDoc type={f.type} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 12, fontWeight: 500, color: "#111827", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{f.name}</div>
                    <div style={{ fontSize: 11, color: "#9ca3af" }}>{f.size}</div>
                  </div>
                  <span style={{ color: accent, cursor: "pointer", flexShrink: 0 }}><IcoDl color={accent} /></span>
                </div>
              ))}
            </div>
          </div>

          <div style={{ flex: 1, background: "#fff", border: "1px solid #e5e7eb", borderRadius: 10, overflow: "hidden" }}>
            <div style={{ padding: `${s(14)}px ${s(20)}px`, borderBottom: "1px solid #e5e7eb" }}>
              <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: ".08em", textTransform: "uppercase", color: "#374151" }}>Связанные материалы</span>
            </div>
            <div style={{ padding: `${s(12)}px ${s(16)}px`, display: "flex", flexDirection: "column", gap: s(7) }}>
              {LMS_TASK.related.map(r => (
                <div key={r.title} style={{ display: "flex", alignItems: "flex-start", gap: 8, padding: `${s(9)}px`, borderRadius: 7, border: "1px solid #f0f0f0", cursor: "pointer" }}>
                  <span style={{ padding: "2px 6px", background: `${accent}12`, color: accent, fontSize: 9, fontWeight: 700, borderRadius: 3, flexShrink: 0, marginTop: 1, letterSpacing: ".04em" }}>{r.type.slice(0, 3).toUpperCase()}</span>
                  <span style={{ fontSize: 12, color: "#111827", lineHeight: 1.4 }}>{r.title}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ─────────────────────────────────────────────────────────────────────────────
// VARIANT C  —  Right sidebar (metadata + files + related on the side)
// ─────────────────────────────────────────────────────────────────────────────

const VariantC = ({ accent = "#1a56a0", showSolution = true, density = "normal" }) => {
  const s = (n) => sp(n, density);

  return (
    <div style={{ fontFamily: LMS_F.sans, fontSize: 14, color: "#1a1a1a", background: "#fff", minHeight: 1220 }}>

      {/* Breadcrumb bar */}
      <div style={{ borderBottom: "1px solid #e5e7eb", padding: `${s(10)}px ${s(36)}px`, display: "flex", alignItems: "center", gap: 5, fontSize: 12, color: "#6b7280" }}>
        <span style={{ color: accent, cursor: "pointer" }}>{LMS_TASK.course}</span>
        <span>›</span>
        <span style={{ color: accent, cursor: "pointer" }}>{LMS_TASK.module}</span>
        <span>›</span>
        <span style={{ color: accent, cursor: "pointer" }}>{LMS_TASK.moduleTitle}</span>
        <span>›</span>
        <span style={{ color: "#111827", fontWeight: 500 }}>{LMS_TASK.taskNum}</span>
        <div style={{ marginLeft: "auto", display: "flex", gap: 10 }}>
          <button style={{ padding: "5px 14px", border: "1px solid #e5e7eb", borderRadius: 5, background: "#fff", fontSize: 12, color: "#374151", cursor: "pointer", fontFamily: LMS_F.sans }}>← Назад</button>
          <button style={{ padding: "5px 14px", border: `1px solid ${accent}`, borderRadius: 5, background: accent, fontSize: 12, color: "#fff", cursor: "pointer", fontFamily: LMS_F.sans }}>Вперёд →</button>
        </div>
      </div>

      <div style={{ display: "flex" }}>
        {/* ── Content ── */}
        <div style={{ flex: 1, padding: `${s(40)}px ${s(40)}px`, minWidth: 0 }}>

          <div style={{ marginBottom: s(28) }}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 10 }}>
              <span style={{ padding: "2px 9px", background: `${accent}12`, color: accent, borderRadius: 4, fontSize: 10, fontWeight: 700, letterSpacing: ".07em" }}>ЗАДАНИЕ</span>
              <span style={{ fontSize: 12, color: "#6b7280" }}>{LMS_TASK.module} · {LMS_TASK.moduleTitle}</span>
            </div>
            <h1 style={{ fontFamily: LMS_F.serif, fontSize: 26, fontWeight: 600, color: "#0d1b2a", lineHeight: 1.25, margin: `0 0 ${s(10)}px` }}>
              {LMS_TASK.taskNum}: {LMS_TASK.taskTitle}
            </h1>
            <div style={{ width: 44, height: 2, background: accent, borderRadius: 1 }} />
          </div>

          <div style={{ marginBottom: s(28) }}>
            <h2 style={{ fontSize: 13, fontWeight: 700, color: "#374151", margin: `0 0 ${s(14)}px` }}>Условие задания</h2>
            <p style={{ fontFamily: LMS_F.serif, fontSize: 15.5, lineHeight: 1.75, color: "#111827", margin: `0 0 ${s(12)}px` }}>{LMS_TASK.condition}</p>
            <p style={{ fontSize: 13.5, lineHeight: 1.65, color: "#374151", margin: `0 0 ${s(14)}px` }}>{LMS_TASK.details}</p>
            <div style={{ padding: `${s(12)}px ${s(16)}px`, background: `${accent}09`, borderLeft: `3px solid ${accent}`, fontSize: 13, color: "#374151" }}>
              <strong style={{ color: accent }}>Подсказка: </strong>{LMS_TASK.hint}
            </div>
          </div>

          {showSolution && (
            <div>
              <h2 style={{ fontSize: 13, fontWeight: 700, color: "#374151", margin: `0 0 ${s(14)}px` }}>Решение</h2>
              <div style={{ background: "#f8f9fb", border: "1px solid #e5e7eb", borderRadius: 8, padding: `${s(20)}px`, marginBottom: s(14) }}>
                <pre style={{ fontFamily: LMS_F.mono, fontSize: 13, lineHeight: 1.9, color: "#1a1a1a", whiteSpace: "pre-wrap", margin: 0 }}>{LMS_TASK.solution}</pre>
              </div>
              <p style={{ fontSize: 13.5, lineHeight: 1.6, color: "#374151", margin: 0 }}><strong>Вывод:</strong> {LMS_TASK.conclusion}</p>
            </div>
          )}
        </div>

        {/* ── Sidebar ── */}
        <div style={{ width: 284, flexShrink: 0, borderLeft: "1px solid #e5e7eb", padding: `${s(32)}px ${s(22)}px`, display: "flex", flexDirection: "column", gap: s(22) }}>

          <div>
            <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: ".1em", textTransform: "uppercase", color: "#9ca3af", marginBottom: s(12) }}>Информация</div>
            <div style={{ display: "flex", flexDirection: "column", gap: s(9) }}>
              {[
                { lbl: "Курс",    val: LMS_TASK.course },
                { lbl: "Модуль",  val: `${LMS_TASK.module}: ${LMS_TASK.moduleTitle}` },
                { lbl: "Статус",  val: <span style={{ color: "#166534", fontWeight: 600, background: "#dcfce7", padding: "2px 8px", borderRadius: 10, fontSize: 11 }}>Открыто</span> },
              ].map(item => (
                <div key={item.lbl} style={{ display: "flex", gap: 8, fontSize: 12 }}>
                  <span style={{ color: "#9ca3af", flexShrink: 0, minWidth: 52 }}>{item.lbl}:</span>
                  <span style={{ color: "#374151", fontWeight: 500, lineHeight: 1.4 }}>{item.val}</span>
                </div>
              ))}
            </div>
          </div>

          <div style={{ height: 1, background: "#e5e7eb" }} />

          <div>
            <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: ".1em", textTransform: "uppercase", color: "#9ca3af", marginBottom: s(10) }}>Файлы</div>
            <div style={{ display: "flex", flexDirection: "column", gap: s(8) }}>
              {LMS_TASK.files.map(f => (
                <div key={f.name} style={{ display: "flex", alignItems: "center", gap: 9, padding: `${s(9)}px ${s(11)}px`, border: "1px solid #e5e7eb", borderRadius: 7, cursor: "pointer" }}>
                  <IcoDoc type={f.type} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 12, fontWeight: 500, color: "#111827", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{f.name}</div>
                    <div style={{ fontSize: 10, color: "#9ca3af" }}>{f.size}</div>
                  </div>
                  <span style={{ color: accent, flexShrink: 0 }}><IcoDl color={accent} /></span>
                </div>
              ))}
            </div>
          </div>

          <div style={{ height: 1, background: "#e5e7eb" }} />

          <div>
            <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: ".1em", textTransform: "uppercase", color: "#9ca3af", marginBottom: s(10) }}>Связанные материалы</div>
            <div style={{ display: "flex", flexDirection: "column", gap: s(7) }}>
              {LMS_TASK.related.map(r => (
                <div key={r.title} style={{ padding: `${s(9)}px ${s(11)}px`, border: "1px solid #e5e7eb", borderRadius: 7, cursor: "pointer" }}>
                  <div style={{ fontSize: 10, color: accent, fontWeight: 700, marginBottom: 3, letterSpacing: ".04em" }}>{r.type.toUpperCase()}</div>
                  <div style={{ fontSize: 12, color: "#111827", lineHeight: 1.4 }}>{r.title}</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// ─── Export to window ─────────────────────────────────────────────────────────
Object.assign(window, { VariantA, VariantB, VariantC });
