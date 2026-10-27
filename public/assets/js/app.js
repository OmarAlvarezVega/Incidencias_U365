// UNIVERS365 Incidencias: comportamiento común. Sin JS en línea (CSP script-src 'self').

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export async function api(url, { method = 'GET', body = null } = {}) {
    const headers = { 'Accept': 'application/json', 'X-Requested-With': 'fetch' };
    let payload = body;
    if (method !== 'GET') {
        headers['X-CSRF-Token'] = csrf;
        if (body && !(body instanceof FormData)) {
            payload = new URLSearchParams(body);
        }
    }
    const res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    if (res.status === 401) {
        window.location.href = '/login';
        throw new Error('Sesión caducada');
    }
    if (!res.ok) {
        throw new Error(data?.message ?? 'Error ' + res.status);
    }
    return data;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ---------- Menú lateral en móvil ----------
document.querySelector('[data-toggle-sidebar]')?.addEventListener('click', () => {
    document.getElementById('sidebar')?.classList.toggle('open');
});
document.addEventListener('click', (e) => {
    const sb = document.getElementById('sidebar');
    if (sb?.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('[data-toggle-sidebar]')) {
        sb.classList.remove('open');
    }
});

// ---------- Confirmaciones (data-confirm="texto") ----------
const confirmModalEl = document.getElementById('confirmModal');
let pendingConfirm = null;
document.addEventListener('submit', (e) => {
    const form = e.target;
    const text = form.dataset.confirm ?? e.submitter?.dataset.confirm;
    if (!text || form.dataset.confirmed === '1' || !confirmModalEl || !window.bootstrap) {
        if (form.dataset.loading !== undefined) showLoading();
        return;
    }
    e.preventDefault();
    pendingConfirm = { form, submitter: e.submitter };
    confirmModalEl.querySelector('[data-confirm-text]').textContent = text;
    window.bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
});
confirmModalEl?.querySelector('[data-confirm-ok]')?.addEventListener('click', () => {
    if (!pendingConfirm) return;
    const { form, submitter } = pendingConfirm;
    pendingConfirm = null;
    form.dataset.confirmed = '1';
    window.bootstrap.Modal.getOrCreateInstance(confirmModalEl).hide();
    if (submitter?.name) {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
        form.appendChild(h);
    }
    form.submit();
});

function showLoading() { document.querySelector(".loading-overlay")?.classList.add('show'); }

// Evita envíos dobles
document.addEventListener('submit', (e) => {
    if (e.defaultPrevented) return;
    const btn = e.submitter;
    if (btn && btn.form === e.target && !btn.hasAttribute('data-no-disable')) {
        setTimeout(() => { btn.disabled = true; }, 0);
        setTimeout(() => { btn.disabled = false; }, 8000);
    }
});

// ---------- Selects que navegan (data-nav-select) y autoenvío (data-autosubmit) ----------
document.querySelectorAll('[data-autosubmit]').forEach(el => {
    el.addEventListener('change', () => el.form?.requestSubmit());
});
document.querySelectorAll('[data-nav-select]').forEach(el => {
    el.addEventListener('change', () => {
        // Solo rutas internas
        if (el.value.startsWith('/') && !el.value.startsWith('//')) window.location.assign(el.value);
    });
});

// ---------- Búsqueda global ----------
const gs = document.querySelector('[data-global-search]');
if (gs) {
    const input = gs.querySelector('input');
    const box = gs.querySelector('.search-results');
    let timer = null; let seq = 0;
    const render = (data) => {
        const groups = [
            ['tickets', 'Incidencias', r => `<span class="ticket-number">${escapeHtml(r.ticket_number)}</span> ${escapeHtml(r.subject)}`],
            ['clients', 'Clientes', r => escapeHtml(r.name)],
            ['contacts', 'Contactos', r => `${escapeHtml(r.name)} <span class="text-muted small">${escapeHtml(r.client ?? '')}</span>`],
            ['messages', 'Mensajes', r => `<span class="ticket-number">${escapeHtml(r.ticket_number)}</span> ${escapeHtml(r.snippet)}`],
        ];
        let html = '';
        for (const [key, title, fmt] of groups) {
            const rows = data[key] ?? [];
            if (!rows.length) continue;
            html += `<div class="group">${title}</div>`;
            html += rows.map(r => `<a href="${escapeHtml(r.url)}">${fmt(r)}</a>`).join('');
        }
        box.innerHTML = html || '<div class="p-3 text-muted small">Sin resultados</div>';
        box.innerHTML += `<a class="border-top small text-primary" href="/buscar?q=${encodeURIComponent(input.value)}">Ver todos los resultados</a>`;
        box.classList.add('show');
    };
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { box.classList.remove('show'); return; }
        timer = setTimeout(async () => {
            const my = ++seq;
            try {
                const data = await api('/buscar?q=' + encodeURIComponent(q) + '&rapida=1');
                if (my === seq) render(data);
            } catch { /* silencioso */ }
        }, 250);
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { window.location.href = '/buscar?q=' + encodeURIComponent(input.value.trim()); }
        if (e.key === 'Escape') { box.classList.remove('show'); input.blur(); }
    });
    document.addEventListener('click', (e) => { if (!gs.contains(e.target)) box.classList.remove('show'); });
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
            e.preventDefault(); input.focus();
        }
    });
}

// ---------- Notificaciones ----------
const notifToggle = document.querySelector('[data-notifications-toggle]');
if (notifToggle) {
    const list = document.querySelector('[data-notifications-list]');
    const badge = document.querySelector('[data-notifications-count]');
    const load = async () => {
        try {
            const data = await api('/notificaciones/recientes');
            badge.textContent = data.unread;
            badge.classList.toggle('d-none', !data.unread);
            if (!data.items.length) { list.innerHTML = '<div class="p-3 text-muted">No tienes notificaciones.</div>'; return; }
            list.innerHTML = data.items.map(n => `
                <a class="d-block px-3 py-2 border-bottom text-reset text-decoration-none ${n.read ? '' : 'bg-light'}" href="/notificaciones/${n.id}/abrir">
                    <div class="fw-semibold">${escapeHtml(n.title)}</div>
                    <div class="text-muted">${escapeHtml(n.body ?? '')}</div>
                    <div class="text-muted" style="font-size:.72rem">${escapeHtml(n.when)}</div>
                </a>`).join('') + '<form method="post" action="/notificaciones/leer-todas" class="p-2 text-center"><input type="hidden" name="_token" value="' + escapeHtml(csrf) + '"><button class="btn btn-link btn-sm">Marcar todas como leídas</button></form>';
        } catch { list.innerHTML = '<div class="p-3 text-danger">No se pudieron cargar.</div>'; }
    };
    notifToggle.addEventListener('show.bs.dropdown', load);
    setInterval(async () => {
        if (document.hidden) return;
        try {
            const data = await api('/notificaciones/recientes?solo_contador=1');
            badge.textContent = data.unread;
            badge.classList.toggle('d-none', !data.unread);
        } catch { /* silencioso */ }
    }, 60000);
}

// ---------- Temporizador activo ----------
const timerPill = document.querySelector('[data-active-timer]');
let timerStart = null;
function pad(n) { return String(n).padStart(2, '0'); }
function tick() {
    if (!timerStart) return;
    const s = Math.max(0, Math.floor((Date.now() - timerStart) / 1000));
    const txt = `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`;
    timerPill.querySelector('[data-timer-clock]').textContent = txt;
    document.querySelectorAll('[data-timer-local]').forEach(el => { el.textContent = txt; });
}
async function loadTimer() {
    if (!timerPill) return;
    try {
        const data = await api('/temporizador');
        if (data.running) {
            timerStart = Date.now() - data.elapsed_seconds * 1000;
            const link = timerPill.querySelector('[data-timer-link]');
            link.textContent = data.ticket_number;
            link.href = '/incidencias/' + data.ticket_id;
            timerPill.classList.remove('d-none');
            tick();
        } else {
            timerStart = null;
            timerPill.classList.add('d-none');
        }
    } catch { /* silencioso */ }
}
if (timerPill) { loadTimer(); setInterval(tick, 1000); }

// ---------- Selector de cliente → contactos (formularios de incidencia) ----------
document.querySelectorAll('[data-client-select]').forEach(sel => {
    const target = document.querySelector(sel.dataset.clientSelect);
    if (!target) return;
    const filterInput = document.querySelector('[data-client-filter]');
    const fillContacts = async () => {
        const id = sel.value;
        const keep = target.dataset.selected ?? target.value;
        target.innerHTML = '<option value="">Sin contacto</option>';
        if (!id) return;
        try {
            const rows = await api('/api/clientes/' + encodeURIComponent(id) + '/contactos');
            for (const c of rows) {
                const o = document.createElement('option');
                o.value = c.id; o.textContent = c.name + (c.email ? ' · ' + c.email : '');
                if (String(c.id) === String(keep)) o.selected = true;
                target.appendChild(o);
            }
        } catch { /* silencioso */ }
    };
    sel.addEventListener('change', () => { target.dataset.selected = ''; fillContacts(); });
    if (sel.value) fillContacts();
    if (filterInput) {
        let t = null;
        filterInput.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(async () => {
                const q = filterInput.value.trim();
                if (q.length < 2) return;
                try {
                    const rows = await api('/api/clientes?q=' + encodeURIComponent(q));
                    const current = sel.value;
                    sel.innerHTML = '<option value="">Selecciona un cliente…</option>';
                    for (const c of rows) {
                        const o = document.createElement('option');
                        o.value = c.id; o.textContent = c.name + (c.tax_id ? ' · ' + c.tax_id : '');
                        if (String(c.id) === current) o.selected = true;
                        sel.appendChild(o);
                    }
                    if (rows.length === 1) { sel.value = rows[0].id; sel.dispatchEvent(new Event('change')); }
                } catch { /* silencioso */ }
            }, 300);
        });
    }
});

// ---------- Botones de temporizador en la ficha ----------
document.querySelectorAll('form[data-timer-form]').forEach(f => {
    f.addEventListener('submit', () => setTimeout(loadTimer, 800));
});

// ---------- Selección múltiple en listados ----------
document.querySelectorAll('[data-check-all]').forEach(master => {
    master.addEventListener('change', () => {
        document.querySelectorAll(master.dataset.checkAll).forEach(cb => { cb.checked = master.checked; });
        document.dispatchEvent(new Event('selection-changed'));
    });
});
const bulkBar = document.querySelector('[data-bulk-bar]');
if (bulkBar) {
    const update = () => {
        const n = document.querySelectorAll('[data-row-check]:checked').length;
        bulkBar.classList.toggle('d-none', n === 0);
        bulkBar.querySelector('[data-bulk-count]').textContent = n;
    };
    document.addEventListener('change', (e) => { if (e.target.matches('[data-row-check]')) update(); });
    document.addEventListener('selection-changed', update);
}

// ---------- Copiar al portapapeles ----------
// data-copy="#selector" copia el valor de un campo; data-copy-text="texto" copia ese texto
document.querySelectorAll('[data-copy], [data-copy-text]').forEach(btn => {
    btn.addEventListener('click', async () => {
        const el = btn.dataset.copy ? document.querySelector(btn.dataset.copy) : null;
        const text = btn.dataset.copyText ?? (el ? (el.value ?? el.textContent) : null);
        if (text === null) return;
        const original = btn.textContent;
        try {
            await navigator.clipboard.writeText(text);
            btn.textContent = 'Copiado';
            setTimeout(() => { btn.textContent = original; }, 1500);
        } catch { el?.select?.(); }
    });
});

// ---------- Mostrar/ocultar por valor de un select (data-show-when="#sel=valor") ----------
document.querySelectorAll('[data-show-when]').forEach(el => {
    const [selector, values] = el.dataset.showWhen.split('=');
    const src = document.querySelector(selector);
    if (!src) return;
    const allowed = values.split(',');
    const apply = () => { el.classList.toggle('d-none', !allowed.includes(src.value)); };
    src.addEventListener('change', apply);
    apply();
});

// ---------- Gráficos del panel ----------
const charts = document.querySelectorAll('canvas[data-chart]');
if (charts.length && window.Chart) {
    const palette = ['#1f6feb', '#20c997', '#fd7e14', '#6f42c1', '#dc3545', '#0dcaf0', '#ffc107', '#6c757d', '#198754', '#d63384'];
    const url = document.querySelector('[data-dashboard-source]')?.dataset.dashboardSource;
    if (url) {
        api(url).then(data => {
            charts.forEach(cv => {
                const def = data.charts?.[cv.dataset.chart];
                if (!def) return;
                const datasets = def.datasets.map((d, i) => ({
                    borderWidth: def.type === 'line' ? 2 : 0,
                    tension: .3,
                    backgroundColor: def.type === 'doughnut' ? (d.colors ?? palette) : (d.color ?? palette[i % palette.length]),
                    borderColor: d.color ?? palette[i % palette.length],
                    ...d,
                }));
                new window.Chart(cv, {
                    type: def.type,
                    data: { labels: def.labels, datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: def.type === 'doughnut' || datasets.length > 1, position: 'bottom' } },
                        scales: def.type === 'doughnut' ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    },
                });
            });
        }).catch(() => {
            charts.forEach(cv => { cv.parentElement.innerHTML = '<div class="text-muted small p-3">No se pudieron cargar los datos.</div>'; });
        });
    }
}
