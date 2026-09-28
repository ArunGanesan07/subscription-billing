<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchant Dashboard · Usage Billing</title>
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .num { font-variant-numeric: tabular-nums; }
        .skeleton { background: linear-gradient(90deg,#eef2f7 25%,#e2e8f0 37%,#eef2f7 63%); background-size: 400% 100%; animation: sk 1.4s ease infinite; }
        @keyframes sk { 0% { background-position: 100% 50% } 100% { background-position: 0 50% } }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

<header class="bg-slate-800 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-4 flex flex-wrap items-center gap-4 justify-between">
        <div>
            <h1 class="text-xl font-semibold">Merchant Dashboard <span class="text-slate-400">—</span> <span id="merchant-name">…</span></h1>
            <p class="text-xs text-slate-400 mt-0.5">As of <span id="as-of">—</span> · generated <span id="generated-at">—</span> · refreshes every 60s</p>
        </div>
        <form id="connect" class="flex flex-wrap items-center gap-2 text-sm">
            <label class="sr-only" for="merchant-id">Merchant ID</label>
            <input id="merchant-id" type="number" min="1" placeholder="Merchant ID" class="w-28 rounded-md bg-slate-700 border border-slate-600 px-2 py-1.5 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-400">
            <label class="sr-only" for="api-key">API key</label>
            <input id="api-key" type="password" placeholder="API key (mk_…)" class="w-64 rounded-md bg-slate-700 border border-slate-600 px-2 py-1.5 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-400">
            <button class="rounded-md bg-sky-500 hover:bg-sky-400 px-3 py-1.5 font-medium">Connect</button>
            <button type="button" id="refresh" class="rounded-md border border-slate-500 hover:bg-slate-700 px-3 py-1.5">Refresh</button>
        </form>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">
    <div id="error" class="hidden rounded-lg border border-red-300 bg-red-50 text-red-800 px-4 py-3 text-sm"></div>

    {{-- Stat cards --}}
    <section class="grid gap-4 md:grid-cols-3">
        <div class="rounded-lg bg-white shadow-sm border-l-4 border-blue-600 p-5">
            <p class="text-sm text-slate-500">Current Cycle Usage</p>
            <p class="mt-2 text-2xl font-semibold num"><span id="cycle-usage">—</span> <span class="text-slate-400 text-lg">/ <span id="cycle-allowance">—</span> units</span></p>
            <div class="mt-3 h-2 rounded bg-slate-100 overflow-hidden"><div id="cycle-bar" class="h-2 bg-blue-600 transition-all" style="width:0%"></div></div>
            <p class="mt-2 text-xs text-slate-500"><span id="cycle-subs">—</span> active subscriptions</p>
        </div>
        <div class="rounded-lg bg-white shadow-sm border-l-4 border-amber-500 p-5">
            <p class="text-sm text-slate-500">Projected Overage Revenue</p>
            <p id="overage" class="mt-2 text-2xl font-semibold num">—</p>
            <p class="mt-2 text-xs text-slate-500" title="" id="overage-method">Current cycle, extrapolated from usage to date</p>
        </div>
        <div class="rounded-lg bg-white shadow-sm border-l-4 border-green-700 p-5">
            <p class="text-sm text-slate-500">Active Plan</p>
            <p id="top-plan" class="mt-2 text-2xl font-semibold">—</p>
            <p id="plan-mix" class="mt-2 text-xs text-slate-500">—</p>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-3">
        {{-- Top customers --}}
        <div class="lg:col-span-2 rounded-lg bg-white shadow-sm p-5">
            <h2 class="font-semibold">Top 5 Customers by Usage <span class="text-slate-400 font-normal text-sm">(this month)</span></h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-slate-500 border-b">
                        <th class="py-2 font-medium">Customer</th>
                        <th class="py-2 font-medium text-right">Usage</th>
                        <th class="py-2 font-medium pl-6 w-2/5">% of Allowance</th>
                    </tr></thead>
                    <tbody id="top-customers"></tbody>
                </table>
            </div>
        </div>

        {{-- Churn risk --}}
        <div class="rounded-lg border border-red-300 bg-red-50 p-5">
            <h2 class="font-semibold text-red-800">⚠ Churn Risk <span class="font-normal text-sm">(usage ↓ &gt;50% MoM)</span></h2>
            <p id="churn-window" class="text-xs text-red-700/80 mt-1"></p>
            <ul id="churn" class="mt-3 space-y-2 text-sm text-red-900"></ul>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-3">
        {{-- Trend --}}
        <div class="lg:col-span-2 rounded-lg bg-white shadow-sm p-5">
            <h2 class="font-semibold">Daily Usage Trend <span class="text-slate-400 font-normal text-sm">(last 30 days)</span></h2>
            <div class="mt-3 h-64"><canvas id="trend"></canvas></div>
        </div>

        {{-- System status --}}
        <div class="rounded-lg border border-sky-300 bg-sky-50 p-5 text-sm text-sky-900">
            <h2 class="font-semibold">System status <span class="font-normal text-xs">(informational)</span></h2>
            <dl id="system" class="mt-3 space-y-2"></dl>
        </div>
    </section>

    {{-- Invoices --}}
    <section class="rounded-lg bg-white shadow-sm p-5">
        <div class="flex items-baseline justify-between">
            <h2 class="font-semibold">Recent Invoices</h2>
            <p class="text-xs text-slate-500">Click an invoice to see its prorated lines</p>
        </div>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-slate-500 border-b">
                    <th class="py-2 font-medium">Invoice</th>
                    <th class="py-2 font-medium">Customer</th>
                    <th class="py-2 font-medium">Period</th>
                    <th class="py-2 font-medium text-right">Total</th>
                </tr></thead>
                <tbody id="invoices"></tbody>
            </table>
        </div>
    </section>
</main>

{{-- Invoice detail --}}
<dialog id="invoice-dialog" class="rounded-lg shadow-xl p-0 w-full max-w-3xl backdrop:bg-slate-900/50">
    <div class="p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 id="inv-title" class="text-lg font-semibold"></h3>
                <p id="inv-sub" class="text-sm text-slate-500"></p>
            </div>
            <button onclick="document.getElementById('invoice-dialog').close()" class="text-slate-400 hover:text-slate-700 text-2xl leading-none" aria-label="Close">×</button>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-slate-500 border-b">
                    <th class="py-2 font-medium">Line</th>
                    <th class="py-2 font-medium">Period</th>
                    <th class="py-2 font-medium text-right">Qty</th>
                    <th class="py-2 font-medium text-right">Amount</th>
                </tr></thead>
                <tbody id="inv-lines"></tbody>
                <tfoot><tr class="border-t font-semibold"><td class="py-2" colspan="3">Total</td><td id="inv-total" class="py-2 text-right num"></td></tr></tfoot>
            </table>
        </div>
    </div>
</dialog>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const fmt = new Intl.NumberFormat('en-IN');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let chart, timer;

    // Credentials: URL fragment (never sent to the server) → localStorage.
    const hash = new URLSearchParams(location.hash.slice(1));
    const store = {
        get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch {} },
    };
    $('merchant-id').value = hash.get('merchant') || store.get('merchant') || '';
    $('api-key').value = hash.get('key') || store.get('key') || '';
    if (hash.has('key')) history.replaceState(null, '', location.pathname);

    async function api(path) {
        const res = await fetch('/api/v1' + path, {
            headers: { 'Authorization': 'Bearer ' + $('api-key').value.trim(), 'Accept': 'application/json' },
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(`${res.status}: ${body.message || res.statusText}`);
        return body;
    }

    async function load() {
        const merchantId = $('merchant-id').value.trim();
        if (!merchantId || !$('api-key').value.trim()) return showError('Enter a merchant ID and API key (printed by `php artisan db:seed`).');
        store.set('merchant', merchantId); store.set('key', $('api-key').value.trim());

        try {
            const [{ data }, invoices] = await Promise.all([
                api(`/merchants/${merchantId}/dashboard`),
                api('/invoices'),
            ]);
            $('error').classList.add('hidden');
            render(data);
            renderInvoices(invoices.data.slice(0, 10));
        } catch (e) {
            showError('Could not load dashboard. ' + e.message);
        }
    }

    function showError(msg) { $('error').textContent = msg; $('error').classList.remove('hidden'); }

    function render(d) {
        $('merchant-name').textContent = d.merchant.name;
        $('as-of').textContent = d.as_of;
        $('generated-at').textContent = new Date(d.generated_at).toLocaleTimeString();

        const c = d.current_cycle;
        $('cycle-usage').textContent = fmt.format(c.usage);
        $('cycle-allowance').textContent = fmt.format(c.allowance);
        $('cycle-bar').style.width = Math.min(100, c.allowance ? c.usage / c.allowance * 100 : 0) + '%';
        $('cycle-subs').textContent = c.active_subscriptions;

        $('overage').textContent = d.projected_overage_revenue.formatted;
        $('overage-method').title = d.projected_overage_revenue.method;

        const plans = d.active_plans;
        $('top-plan').textContent = plans.length ? `${plans[0].name} — ${plans[0].billing_interval}` : '—';
        $('plan-mix').textContent = plans.map((p) => `${p.name}: ${p.subscribers}`).join(' · ') || 'No active subscriptions';

        $('top-customers').innerHTML = d.top_customers.map((t) => {
            const pct = t.percent_of_allowance;
            const bar = pct === null ? '' : `<div class="flex items-center gap-2">
                <div class="flex-1 h-2 rounded bg-slate-100 overflow-hidden"><div class="h-2 ${pct > 100 ? 'bg-amber-500' : 'bg-blue-600'}" style="width:${Math.min(100, pct)}%"></div></div>
                <span class="num w-14 text-right ${pct > 100 ? 'text-amber-700 font-medium' : ''}">${pct}%</span></div>`;
            return `<tr class="border-b last:border-0"><td class="py-2">${esc(t.name)}</td><td class="py-2 text-right num">${fmt.format(t.usage)}</td><td class="py-2 pl-6">${bar}</td></tr>`;
        }).join('') || '<tr><td colspan="3" class="py-4 text-slate-400">No usage this month yet.</td></tr>';

        const w = d.churn_risk.window;
        $('churn-window').textContent = `${w.current.start} → ${w.current.end} vs ${w.previous.start} → ${w.previous.end}`;
        $('churn').innerHTML = d.churn_risk.customers.map((r) =>
            `<li class="flex justify-between gap-2"><span>• ${esc(r.name)}</span><span class="num font-medium">${r.drop_percent}% drop</span></li>
             <li class="text-xs text-red-700/80 -mt-1 pl-3 num">${fmt.format(r.previous_usage)} → ${fmt.format(r.current_usage)} units</li>`
        ).join('') || '<li class="text-red-700/80">No customers at risk. 🎉</li>';

        renderTrend(d.daily_trend);

        const s = d.system;
        const last = s.last_aggregation_run;
        const rows = [
            ['Plan pricing cache', `${s.cache_store}, TTL ${s.plan_cache_ttl_seconds / 60}m + invalidate on write`],
            ['Aggregation job', `queued (${s.queue_connection}), chunked ${fmt.format(s.aggregation_chunk_size)} customers/job`],
            ['Last aggregation', last ? `${new Date(last.finished_at).toLocaleString()} (${last.name})` : 'never'],
            ['Last usage recorded', s.last_usage_recorded_at ? new Date(s.last_usage_recorded_at).toLocaleString() : 'none yet'],
            ['Usage endpoint', `rate-limited ${s.usage_rate_limit_per_minute} req/min per API key`],
            ['Failed jobs', s.failed_jobs],
        ];
        $('system').innerHTML = rows.map(([k, v]) => `<div><dt class="text-xs text-sky-700">${k}</dt><dd class="${k === 'Failed jobs' && v > 0 ? 'text-red-700 font-semibold' : ''}">${esc(v)}</dd></div>`).join('');
    }

    function renderTrend(points) {
        const labels = points.map((p) => p.date.slice(5));
        const values = points.map((p) => p.units);
        if (chart) { chart.data.labels = labels; chart.data.datasets[0].data = values; chart.update(); return; }
        chart = new Chart($('trend'), {
            type: 'line',
            data: { labels, datasets: [{ label: 'Units / day', data: values, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.08)', fill: true, tension: .3, pointRadius: 2 }] },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => fmt.format(c.parsed.y) + ' units' } } },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => fmt.format(v) } }, x: { ticks: { maxTicksLimit: 10 } } },
            },
        });
    }

    function renderInvoices(list) {
        $('invoices').innerHTML = list.map((i) =>
            `<tr class="border-b last:border-0 hover:bg-slate-50 cursor-pointer" data-id="${i.id}">
                <td class="py-2 font-medium text-sky-700">${esc(i.number)}</td>
                <td class="py-2">${esc(i.customer_name)}</td>
                <td class="py-2 num">${i.period.start} → ${i.period.end}</td>
                <td class="py-2 text-right num">${esc(i.total.formatted)}</td>
            </tr>`
        ).join('') || '<tr><td colspan="4" class="py-4 text-slate-400">No invoices yet. Cycles are invoiced once settled.</td></tr>';
    }

    $('invoices').addEventListener('click', async (e) => {
        const row = e.target.closest('tr[data-id]');
        if (!row) return;
        try {
            const { data: inv } = await api('/invoices/' + row.dataset.id);
            $('inv-title').textContent = `${inv.number} · ${inv.customer_name}`;
            $('inv-sub').textContent = `Billing period ${inv.period.start} → ${inv.period.end} · ${inv.status}`;
            $('inv-lines').innerHTML = inv.lines.map((l) =>
                `<tr class="border-b last:border-0 align-top">
                    <td class="py-2">${esc(l.description)}${l.type === 'base' ? `<div class="text-xs text-slate-500 num">usage ${fmt.format(l.meta.usage)} · allowance ${fmt.format(l.meta.allowance)}</div>` : ''}</td>
                    <td class="py-2 num whitespace-nowrap">${l.period.start} → ${l.period.end}</td>
                    <td class="py-2 text-right num">${fmt.format(l.quantity)}${l.type === 'base' ? ' days' : ''}</td>
                    <td class="py-2 text-right num">${esc(l.amount.formatted)}</td>
                </tr>`).join('');
            $('inv-total').textContent = inv.total.formatted;
            $('invoice-dialog').showModal();
        } catch (err) { showError(err.message); }
    });

    $('connect').addEventListener('submit', (e) => { e.preventDefault(); load(); });
    $('refresh').addEventListener('click', load);
    load();
    timer = setInterval(load, 60000);
})();
</script>
</body>
</html>
