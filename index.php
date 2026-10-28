<?php
/*
 * AED → BDT Exchange Compare
 * Single-file PHP application. Save as index.php.
 *
 * Requirements: PHP 7.4+ with cURL enabled (recommended).
 * Default reference-rate API: https://open.er-api.com (daily indicative rates).
 * Historical chart: Frankfurter API (when AED/BDT history is available).
 *
 * IMPORTANT:
 * - Reference rates are not guaranteed remittance/payout rates.
 * - Provider-specific rates below are only shown when you configure a compatible
 *   JSON API. This file does NOT scrape exchange websites or invent their quotes.
 * - User-supplied API endpoints are fetched in the browser to avoid server-side
 *   request forgery (SSRF). The API must allow browser CORS requests.
 */

declare(strict_types=1);

function send_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function http_json(string $url, int $timeout = 10): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled on this host.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'AED-BDT-Exchange-Compare/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Could not reach the rate service. Please try again.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Rate service returned HTTP ' . $status . '. Please try again later.');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('The rate service returned invalid JSON.');
    }
    return $data;
}

if (isset($_GET['api'])) {
    try {
        $action = (string) $_GET['api'];

        if ($action === 'latest') {
            $data = http_json('https://open.er-api.com/v6/latest/AED');
            if (($data['result'] ?? '') !== 'success' || !isset($data['rates']['BDT'])) {
                throw new RuntimeException('AED/BDT reference rate is not available right now.');
            }
            send_json([
                'ok' => true,
                'base' => 'AED',
                'quote' => 'BDT',
                'rate' => (float) $data['rates']['BDT'],
                'source' => 'ExchangeRate-API Open Access',
                'sourceUrl' => 'https://www.exchangerate-api.com/',
                'updatedAt' => $data['time_last_update_utc'] ?? null,
                'nextUpdateAt' => $data['time_next_update_utc'] ?? null,
                'notice' => 'Indicative reference rate; not a guaranteed remittance quote.',
            ]);
        }

        if ($action === 'history') {
            // Frankfurter daily history. It may not support this exact pair at all times.
            $to = new DateTimeImmutable('today', new DateTimeZone('UTC'));
            $from = $to->modify('-29 days');
            $url = 'https://api.frankfurter.dev/v1/' .
                $from->format('Y-m-d') . '..' . $to->format('Y-m-d') .
                '?base=AED&symbols=BDT';
            $data = http_json($url);
            $points = [];
            foreach (($data['rates'] ?? []) as $date => $values) {
                if (isset($values['BDT']) && is_numeric($values['BDT'])) {
                    $points[] = ['date' => $date, 'rate' => (float) $values['BDT']];
                }
            }
            usort($points, fn($a, $b) => strcmp($a['date'], $b['date']));
            if (!$points) {
                throw new RuntimeException('Historical data for AED/BDT is not available from the selected source.');
            }
            send_json(['ok' => true, 'points' => $points, 'source' => 'Frankfurter', 'notice' => 'Daily reference data; weekends and holidays may be absent.']);
        }

        send_json(['ok' => false, 'error' => 'Unknown API action.'], 400);
    } catch (Throwable $e) {
        send_json(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b1220">
  <meta name="description" content="Compare AED to BDT reference exchange rates and calculate estimated conversions.">
  <title>AED → BDT Exchange Compare</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    :root { color-scheme: dark; }
    body { background:#0b1220; color:#e7edf7; }
    body.light { background:#f4f7fb; color:#172033; }
    .panel { background:rgba(18,29,48,.88); border:1px solid rgba(148,163,184,.16); }
    body.light .panel { background:#fff; border-color:#dce4ef; box-shadow:0 8px 28px rgba(20,35,60,.045); }
    body.light .muted { color:#64748b; }
    body.light .field { background:#f8fafc; border-color:#cbd5e1; color:#172033; }
    body.light .provider-row { border-color:#e2e8f0; }
    .muted { color:#9aa9bf; }
    .field { background:#0b1424; border:1px solid #334155; color:#e7edf7; }
    .btn { transition:transform .15s ease,opacity .15s ease,background .15s ease; }
    .btn:active { transform:scale(.98); }
    .provider-row { border-bottom:1px solid rgba(148,163,184,.13); }
    .provider-row:last-child { border-bottom:0; }
    .spinner { width:16px;height:16px;border:2px solid #64748b;border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:inline-block; }
    @keyframes spin { to { transform:rotate(360deg) } }
    .rise { animation:rise .35s ease both; }
    @keyframes rise { from {opacity:0;transform:translateY(6px)} to {opacity:1;transform:translateY(0)} }
    * { scrollbar-width:thin; scrollbar-color:#334155 transparent; }
  </style>
</head>
<body class="min-h-screen antialiased">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-10">
  <header class="flex items-center justify-between gap-4 mb-8">
    <a href="#" class="flex items-center gap-3" aria-label="Exchange Compare home">
      <div class="w-11 h-11 rounded-2xl bg-emerald-400 text-slate-950 grid place-items-center shadow-lg shadow-emerald-900/20"><i data-lucide="arrow-left-right"></i></div>
      <div><div class="font-bold tracking-tight text-xl">Rate<span class="text-emerald-400">Bridge</span></div><div class="text-xs muted">AED → BDT exchange monitor</div></div>
    </a>
    <div class="flex items-center gap-2">
      <span class="hidden sm:inline-flex items-center gap-2 text-xs muted"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> Reference data</span>
      <button id="themeBtn" class="btn panel rounded-xl p-3" aria-label="Toggle light or dark mode"><i data-lucide="sun" class="w-4 h-4"></i></button>
    </div>
  </header>

  <section class="mb-8">
    <div class="max-w-3xl">
      <p class="uppercase tracking-[.2em] text-xs font-semibold text-emerald-400 mb-3">Know your rate before you send</p>
      <h1 class="text-3xl sm:text-5xl font-bold tracking-tight leading-tight">More taka for every <span class="text-emerald-400">dirham.</span></h1>
      <p class="muted mt-4 text-sm sm:text-base max-w-2xl">Track a public reference rate, estimate conversions, and compare provider quotes when their data is available. Provider offers may include fees and payout conditions.</p>
    </div>
  </section>

  <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    <div class="panel rounded-2xl p-5 sm:p-6 lg:col-span-2 rise">
      <div class="flex items-start justify-between gap-3">
        <div><div class="text-sm muted">Reference exchange rate</div><div class="text-4xl sm:text-5xl font-bold tracking-tight mt-2" id="mainRate">— <span class="text-base font-medium muted">BDT / AED</span></div><div id="updatedAt" class="text-xs muted mt-3">Fetching latest available reference rate…</div></div>
        <div class="bg-emerald-400/10 text-emerald-300 rounded-xl p-3"><i data-lucide="trending-up" class="w-6 h-6"></i></div>
      </div>
      <div class="mt-5 pt-4 border-t border-slate-700/40 flex flex-wrap gap-2 items-center justify-between">
        <div class="text-xs muted">Source: <a class="text-emerald-400 underline underline-offset-4" href="https://www.exchangerate-api.com/" target="_blank" rel="noopener noreferrer">ExchangeRate-API Open Access</a></div>
        <button id="refreshBtn" class="btn inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-400 hover:bg-emerald-300 text-slate-950 font-semibold text-sm px-4 py-2.5"><i data-lucide="refresh-cw" class="w-4 h-4"></i><span>Refresh rates</span></button>
      </div>
    </div>
    <div class="panel rounded-2xl p-5 sm:p-6 rise">
      <div class="flex items-center justify-between"><div class="text-sm muted">Quick conversion</div><i data-lucide="calculator" class="w-5 h-5 text-emerald-400"></i></div>
      <label for="amount" class="block text-xs muted mt-5 mb-2">You send (AED)</label>
      <div class="relative"><input id="amount" class="field rounded-xl w-full p-4 pr-16 text-2xl font-semibold outline-none focus:ring-2 focus:ring-emerald-400/60" type="number" min="0" step="any" value="1000" inputmode="decimal"><span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm muted">AED</span></div>
      <div class="flex justify-between mt-4 text-sm"><span class="muted">Estimated amount</span><span class="font-bold text-emerald-400">BDT</span></div>
      <div id="converted" class="text-3xl font-bold mt-1">—</div>
      <p class="text-xs muted mt-3">Uses the reference rate only. Actual provider payouts may differ.</p>
    </div>
  </section>

  <section class="grid grid-cols-1 xl:grid-cols-5 gap-5 mb-5">
    <div class="panel rounded-2xl p-5 sm:p-6 xl:col-span-3">
      <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div><h2 class="font-semibold text-lg">Rate trend</h2><p class="text-xs muted mt-1">Daily AED → BDT reference history, when available</p></div>
        <span id="trendStatus" class="text-xs muted">Loading history…</span>
      </div>
      <div class="h-64 sm:h-72"><canvas id="trendChart" aria-label="Historical AED to BDT exchange rate chart" role="img"></canvas></div>
      <p class="text-xs muted mt-3">Historical observations may skip weekends and holidays. This is not an intraday trading chart.</p>
    </div>
    <div class="panel rounded-2xl p-5 sm:p-6 xl:col-span-2">
      <div class="flex items-center gap-2 mb-4"><i data-lucide="sliders-horizontal" class="w-5 h-5 text-emerald-400"></i><h2 class="font-semibold text-lg">Custom rate API</h2></div>
      <p class="text-sm muted mb-4">Optional: enter a JSON API URL that returns a numeric AED-to-BDT rate. The endpoint must allow browser CORS requests.</p>
      <label for="customUrl" class="block text-xs muted mb-2">API endpoint URL</label>
      <input id="customUrl" class="field rounded-xl w-full p-3 text-sm outline-none focus:ring-2 focus:ring-emerald-400/60" type="url" placeholder="https://example.com/api/rates">
      <label for="jsonPath" class="block text-xs muted mt-3 mb-2">JSON path to rate</label>
      <input id="jsonPath" class="field rounded-xl w-full p-3 text-sm outline-none focus:ring-2 focus:ring-emerald-400/60" type="text" value="rates.BDT" placeholder="rates.BDT">
      <button id="testCustom" class="btn mt-3 w-full rounded-xl border border-slate-600 hover:border-emerald-400 px-4 py-3 text-sm font-semibold">Test and add custom quote</button>
      <p id="customStatus" class="text-xs muted mt-3" role="status">Your URL is fetched in your browser, not by this server.</p>
    </div>
  </section>

  <section class="panel rounded-2xl overflow-hidden mb-5">
    <div class="p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div><h2 class="font-semibold text-lg">Exchange provider comparison</h2><p class="text-xs muted mt-1">Sorted highest to lowest by available quote. Confirm the final rate and fees with each provider.</p></div>
      <label class="flex items-center gap-2 text-xs muted">Sort
        <select id="sortMode" class="field rounded-lg px-3 py-2 outline-none"><option value="desc">Highest rate first</option><option value="asc">Lowest rate first</option><option value="name">Provider name</option></select>
      </label>
    </div>
    <div class="grid grid-cols-12 gap-2 px-5 sm:px-6 py-3 bg-slate-800/30 text-[10px] sm:text-xs uppercase tracking-wider muted">
      <div class="col-span-6 sm:col-span-4">Provider</div><div class="col-span-3 sm:col-span-2 text-right">Rate</div><div class="hidden sm:block sm:col-span-2 text-right">Fee</div><div class="hidden sm:block sm:col-span-2 text-right">Delivery</div><div class="col-span-3 sm:col-span-2 text-right">You receive</div>
    </div>
    <div id="providerList">
      <div class="p-6 text-sm muted">Loading reference data…</div>
    </div>
    <div class="p-4 sm:px-6 border-t border-slate-700/40 text-xs muted">“Unavailable” means this dashboard has no verified quote feed for that provider. It does not mean the provider has no exchange rate.</div>
  </section>

  <section class="panel rounded-2xl p-5 sm:p-6 mb-8">
    <div class="flex gap-3 items-start"><div class="p-2 rounded-xl bg-amber-400/10 text-amber-300"><i data-lucide="shield-check" class="w-5 h-5"></i></div><div><h2 class="font-semibold">Rates, fees and reliability</h2><p class="text-sm muted mt-2 leading-relaxed">The reference rate is an indicative market/reference conversion and may update only once per day. Remittance providers set their own rates, fees, payout methods and promotional offers. Compare the final BDT received after fees, and verify the quote on the provider’s official website before sending money.</p></div></div>
  </section>

  <footer class="flex flex-col sm:flex-row gap-3 justify-between text-xs muted pb-6">
    <p>© <span id="year"></span> RateBridge · Independent rate comparison tool</p>
    <p><a class="underline underline-offset-4 hover:text-emerald-400" href="https://www.exchangerate-api.com/" target="_blank" rel="noopener noreferrer">Rates by ExchangeRate-API</a> · <a class="underline underline-offset-4 hover:text-emerald-400" href="https://frankfurter.dev/" target="_blank" rel="noopener noreferrer">History by Frankfurter</a></p>
  </footer>
</div>

<script>
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const providers = [
    { name: 'Federal Exchange', url: 'https://federalexchange.ae/', fee: null, delivery: 'Check provider', rate: null, source: 'Official website' },
    { name: 'LuLu Exchange', url: 'https://luluexchange.com/', fee: null, delivery: 'Check provider', rate: null, source: 'Official website' },
    { name: 'Universal Exchange', url: 'https://www.universalexchange.net/', fee: null, delivery: 'Check provider', rate: null, source: 'Official website' },
    { name: 'Sharaf Exchange', url: 'https://sharafexchange.ae/', fee: null, delivery: 'Check provider', rate: null, source: 'Official website' },
    { name: 'Western Union', url: 'https://www.westernunion.com/ae/en/home.html', fee: null, delivery: 'Check provider', rate: null, source: 'Official website' }
  ];
  let referenceRate = null;
  let customQuote = null;
  let chart = null;
  let lastUpdated = null;

  const money = (n, digits = 2) => Number(n).toLocaleString('en-BD', {minimumFractionDigits: digits, maximumFractionDigits: digits});
  const escapeHtml = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const getAmount = () => Math.max(0, Number($('amount').value) || 0);

  function setMainRate(rate, updatedAt, source) {
    referenceRate = Number(rate);
    lastUpdated = updatedAt || new Date().toISOString();
    $('mainRate').innerHTML = `${money(referenceRate, 4)} <span class="text-base font-medium muted">BDT / AED</span>`;
    $('updatedAt').textContent = `Updated: ${updatedAt || 'time not supplied'} · ${source || 'Reference API'}`;
    $('updatedAt').title = 'This timestamp is supplied by the rate source where available.';
    updateConversion();
    renderProviders();
  }

  function updateConversion() {
    $('converted').textContent = referenceRate === null ? '—' : `৳ ${money(getAmount() * referenceRate)}`;
    renderProviders();
  }

  function renderProviders() {
    const rows = providers.map(p => ({...p}));
    if (customQuote) rows.push(customQuote);
    const sort = $('sortMode').value;
    rows.sort((a,b) => {
      if (sort === 'name') return a.name.localeCompare(b.name);
      if (a.rate == null && b.rate == null) return a.name.localeCompare(b.name);
      if (a.rate == null) return 1;
      if (b.rate == null) return -1;
      return sort === 'asc' ? a.rate - b.rate : b.rate - a.rate;
    });
    $('providerList').innerHTML = rows.map((p, i) => {
      const available = Number.isFinite(Number(p.rate)) && p.rate !== null;
      const receive = available ? `৳ ${money(Math.max(0, getAmount() * Number(p.rate) - (Number(p.fee) || 0)))}`
        : '<span class="muted">—</span>';
      const rateText = available ? money(p.rate, 4) : '<span class="muted text-xs">Unavailable</span>';
      const feeText = p.fee == null ? 'Not published' : `AED ${money(p.fee)}`;
      return `<div class="provider-row grid grid-cols-12 gap-2 items-center px-5 sm:px-6 py-4 rise">
        <div class="col-span-6 sm:col-span-4 min-w-0">
          <div class="font-semibold text-sm truncate">${escapeHtml(p.name)}</div>
          <div class="text-[11px] muted mt-1">${available ? escapeHtml(p.source || 'Configured API quote') : 'No verified API quote connected'}</div>
          <a class="text-[11px] text-emerald-400 hover:underline mt-1 inline-block" href="${escapeHtml(p.url)}" target="_blank" rel="noopener noreferrer">Open provider ↗</a>
        </div>
        <div class="col-span-3 sm:col-span-2 text-right font-semibold text-sm">${rateText}</div>
        <div class="hidden sm:block sm:col-span-2 text-right text-xs muted">${feeText}</div>
        <div class="hidden sm:block sm:col-span-2 text-right text-xs muted">${escapeHtml(p.delivery || 'Check provider')}</div>
        <div class="col-span-3 sm:col-span-2 text-right font-semibold text-sm ${available ? 'text-emerald-400' : 'muted'}">${receive}</div>
      </div>`;
    }).join('');
    if (!rows.length) $('providerList').innerHTML = '<div class="p-6 muted">No providers configured.</div>';
  }

  async function loadLatest() {
    const btn = $('refreshBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span><span>Refreshing…</span>';
    $('updatedAt').textContent = 'Fetching latest available reference rate…';
    try {
      const response = await fetch('?api=latest', {cache:'no-store'});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Rate service is temporarily unavailable.');
      setMainRate(data.rate, data.updatedAt, data.source);
    } catch (error) {
      $('updatedAt').textContent = error.message || 'Could not load rate. Please retry.';
      if (referenceRate === null) $('mainRate').innerHTML = 'Unavailable <span class="text-base font-medium muted">BDT / AED</span>';
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i><span>Refresh rates</span>';
      if (window.lucide) lucide.createIcons();
    }
  }

  async function loadHistory() {
    try {
      const response = await fetch('?api=history', {cache:'no-store'});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Historical data unavailable.');
      $('trendStatus').textContent = `${data.points.length} daily observations`;
      const ctx = $('trendChart').getContext('2d');
      if (chart) chart.destroy();
      chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.points.map(p => p.date),
          datasets: [{
            label: 'BDT per AED',
            data: data.points.map(p => p.rate),
            borderColor: '#34d399',
            backgroundColor: 'rgba(52,211,153,.12)',
            fill: true,
            tension: .32,
            borderWidth: 2,
            pointRadius: 2,
            pointHoverRadius: 5
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          interaction: {mode:'index', intersect:false},
          plugins: {legend:{display:false}, tooltip:{callbacks:{label: c => ` ${money(c.parsed.y,4)} BDT / AED`}}},
          scales: {
            x: {ticks:{color:'#94a3b8', maxTicksLimit:7}, grid:{display:false}},
            y: {ticks:{color:'#94a3b8'}, grid:{color:'rgba(148,163,184,.12)'}}
          }
        }
      });
    } catch (error) {
      $('trendStatus').textContent = 'History unavailable';
      const ctx = $('trendChart').getContext('2d');
      ctx.clearRect(0, 0, $('trendChart').width, $('trendChart').height);
      ctx.font = '14px system-ui';
      ctx.fillStyle = '#94a3b8';
      ctx.textAlign = 'center';
      ctx.fillText('Historical AED/BDT data is unavailable from this source.', $('trendChart').width/2, $('trendChart').height/2 - 8);
      ctx.fillText('The current reference rate and converter can still work.', $('trendChart').width/2, $('trendChart').height/2 + 16);
    }
  }

  function readPath(obj, path) {
    return path.split('.').filter(Boolean).reduce((value, key) => value == null ? undefined : value[key], obj);
  }

  async function testCustomApi() {
    const url = $('customUrl').value.trim();
    const path = $('jsonPath').value.trim() || 'rates.BDT';
    const status = $('customStatus');
    const button = $('testCustom');
    if (!url) { status.textContent = 'Enter a JSON API URL first.'; return; }
    let parsed;
    try { parsed = new URL(url); } catch (_) { status.textContent = 'Please enter a valid URL.'; return; }
    if (!['https:', 'http:'].includes(parsed.protocol)) { status.textContent = 'Only HTTP or HTTPS API URLs are supported.'; return; }
    button.disabled = true;
    button.textContent = 'Testing endpoint…';
    status.textContent = 'Requesting JSON from the endpoint…';
    try {
      const response = await fetch(url, {headers:{Accept:'application/json'}});
      if (!response.ok) throw new Error(`Endpoint returned HTTP ${response.status}.`);
      const json = await response.json();
      const rate = Number(readPath(json, path));
      if (!Number.isFinite(rate) || rate <= 0) throw new Error(`No positive numeric rate found at "${path}".`);
      customQuote = {
        name: 'Custom API quote',
        url: url,
        rate: rate,
        fee: 0,
        delivery: 'Depends on source',
        source: 'Custom JSON API',
        custom: true
      };
      status.textContent = `Success: 1 AED = ${money(rate,4)} BDT. Quote added to the comparison.`;
      renderProviders();
    } catch (error) {
      status.textContent = `${error.message || 'Could not fetch endpoint.'} Check the URL, JSON path and CORS policy.`;
    } finally {
      button.disabled = false;
      button.textContent = 'Test and add custom quote';
    }
  }

  $('amount').addEventListener('input', updateConversion);
  $('sortMode').addEventListener('change', renderProviders);
  $('refreshBtn').addEventListener('click', () => { loadLatest(); loadHistory(); });
  $('testCustom').addEventListener('click', testCustomApi);
  $('themeBtn').addEventListener('click', () => {
    document.body.classList.toggle('light');
    const light = document.body.classList.contains('light');
    document.querySelector('meta[name="theme-color"]').setAttribute('content', light ? '#f4f7fb' : '#0b1220');
    $('themeBtn').innerHTML = `<i data-lucide="${light ? 'moon' : 'sun'}" class="w-4 h-4"></i>`;
    if (window.lucide) lucide.createIcons();
    if (chart) {
      const tickColor = light ? '#64748b' : '#94a3b8';
      chart.options.scales.x.ticks.color = tickColor;
      chart.options.scales.y.ticks.color = tickColor;
      chart.update();
    }
  });

  $('year').textContent = new Date().getFullYear();
  if (window.lucide) lucide.createIcons();
  renderProviders();
  loadLatest();
  loadHistory();
})();
</script>
</body>
</html>
