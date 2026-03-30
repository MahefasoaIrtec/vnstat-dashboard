<?php
    function parseInterfaceList(?string $raw): array{
        if ($raw === null) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'all' || $raw === '*') {
            return [];
        }
        $items = preg_split('/[,\s]+/', $raw) ?: [];
        $items = array_map('trim', $items);
        $items = array_filter($items, static fn ($value) => $value !== '');
        return array_values(array_unique($items));
    }

    function e(string $value): string{
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    $defaultInterfaces = ['enp0s31f6', 'wlp2s0'];
    $interfaceLabels = ['enp0s31f6' => 'RJ45', 'wlp2s0' => 'Wi-Fi',];
    $selectedInterfaces = isset($_GET['interfaces']) ? parseInterfaceList((string) $_GET['interfaces']) : $defaultInterfaces;
    $apiUrl = 'api.php';
    if ($selectedInterfaces !== []) {
        $apiUrl .= '?' . http_build_query([
            'interfaces' => implode(',', $selectedInterfaces),
        ]);
    }

    $selectedInterfaceLabels = array_map(
        static fn (string $name): string => $interfaceLabels[$name] ?? $name,
        $selectedInterfaces
    );

    $filterText = $selectedInterfaces === [] ? 'Tous les ports' : implode(', ', $selectedInterfaceLabels);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>vnStat Dashboard</title>
<link rel="stylesheet" href="./css/main.css">
</head>
<body>
<main class="shell">
    <header class="hero">
        <div>
            <p class="eyebrow">vnStat Dashboard</p>
            <h1>Trafic reseau en direct</h1>
        </div>

        <div class="hero-meta">
            <div class="meta-chip">
                <span>Filtre actif</span>
                <strong><?= e($filterText) ?></strong>
            </div>
        </div>
    </header>

    <section class="status-row">
        <div id="status" class="status-pill">Chargement des données...</div>
        <div id="error" class="error-box" hidden></div>
    </section>

    <section id="summary" class="summary-grid" aria-live="polite"></section>
    <section id="cards" class="cards-grid"></section>
    <section id="emptyState" class="empty-state" hidden></section>
</main>

<script>
const API_URL = <?php echo json_encode($apiUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const SELECTED_INTERFACES = <?php echo json_encode($selectedInterfaces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const INTERFACE_LABELS = <?php echo json_encode($interfaceLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

const statusEl = document.getElementById('status');
const errorEl = document.getElementById('error');
const summaryEl = document.getElementById('summary');
const cardsEl = document.getElementById('cards');
const emptyEl = document.getElementById('emptyState');

let currentInterfaces = [];
let refreshTimer = null;
let resizeTimer = null;

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function formatBytes(bytes) {
    const units = ['o', 'Kio', 'Mio', 'Gio', 'Tio', 'Pio'];
    let value = Number(bytes) || 0;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    const digits = value >= 100 || unitIndex === 0 ? 0 : value >= 10 ? 1 : 2;
    return `${value.toFixed(digits)} ${units[unitIndex]}`;
}

function formatTimestamp(timestamp) {
    const value = Number(timestamp);
    if (!Number.isFinite(value) || value <= 0) {
        return '—';
    }

    return new Date(value * 1000).toLocaleString('fr-FR', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function formatPointLabel(point) {
    const hour = point?.time?.hour;
    const minute = point?.time?.minute;

    if (hour === undefined || minute === undefined) {
        return '';
    }

    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function getInterfaceLabel(iface) {
    const name = String(iface?.name || '');
    const alias = String(iface?.alias || '');

    if (name && INTERFACE_LABELS[name]) {
        return INTERFACE_LABELS[name];
    }

    if (alias) {
        return alias;
    }

    return 'Sans alias';
}

function getInterfacePoints(iface) {
    return Array.isArray(iface?.traffic?.fiveminute) ? iface.traffic.fiveminute : [];
}

function getPeriodEntry(iface, key) {
    return iface?.summary?.[key] || null;
}

function formatPeriodDate(entry) {
    const date = entry?.date || {};

    if (date.day !== undefined && date.month !== undefined && date.year !== undefined) {
        return `${String(date.day).padStart(2, '0')}/${String(date.month).padStart(2, '0')}/${date.year}`;
    }

    if (date.month !== undefined && date.year !== undefined) {
        return `${String(date.month).padStart(2, '0')}/${date.year}`;
    }

    if (date.year !== undefined) {
        return String(date.year);
    }

    return '—';
}

function formatTrafficSample(entry) {
    if (!entry) {
        return {
            main: '—',
            detail: 'Aucune donnée',
            note: '—',
        };
    }

    const rx = Number(entry.rx) || 0;
    const tx = Number(entry.tx) || 0;

    return {
        main: formatBytes(rx + tx),
        detail: `${formatBytes(rx)} RX · ${formatBytes(tx)} TX`,
        note: formatPeriodDate(entry),
    };
}

function formatEstimatedSample(entry) {
    if (!entry) {
        return {
            main: '—',
            detail: 'Aucune estimation',
            note: 'Estimé par vnStat',
        };
    }

    return {
        main: entry.total || '—',
        detail: `${entry.rx || '—'} RX · ${entry.tx || '—'} TX`,
        note: 'Estimé par vnStat',
    };
}

function createPeriodCardMarkup(title, sample) {
    return `
        <div class="period-card">
            <span>${escapeHtml(title)}</span>
            <strong>${escapeHtml(sample.main)}</strong>
            <small>${escapeHtml(sample.detail)}</small>
            <small class="period-note">${escapeHtml(sample.note)}</small>
        </div>
    `;
}

function showError(message) {
    errorEl.textContent = message;
    errorEl.hidden = false;
}

function clearError() {
    errorEl.textContent = '';
    errorEl.hidden = true;
}

function createStatCard(title, value, hint) {
    const article = document.createElement('article');
    article.className = 'stat-card';
    article.innerHTML = `
        <span>${escapeHtml(title)}</span>
        <strong>${escapeHtml(value)}</strong>
        <small>${escapeHtml(hint)}</small>
    `;
    return article;
}

function createInterfaceCard(iface) {
    const article = document.createElement('article');
    article.className = 'panel';

    const name = String(iface?.name || 'Interface inconnue');
    const label = getInterfaceLabel(iface);
    const points = getInterfacePoints(iface);
    const totalRx = Number(iface?.traffic?.total?.rx) || 0;
    const totalTx = Number(iface?.traffic?.total?.tx) || 0;
    const subtitle = label !== name ? label : 'Aucun alias';

    const daySample = formatTrafficSample(getPeriodEntry(iface, 'day'));
    const monthSample = formatTrafficSample(getPeriodEntry(iface, 'month'));
    const estimatedSample = formatEstimatedSample(getPeriodEntry(iface, 'estimated'));

    article.innerHTML = `
        <div class="panel-top">
            <div>
                <p class="eyebrow">Interface</p>
                <h2>${escapeHtml(name)}</h2>
                <p class="subtitle">${escapeHtml(subtitle)}</p>
            </div>
            <div class="panel-stats">
                <div class="metric">
                    <span>Download</span>
                    <strong>${escapeHtml(formatBytes(totalRx))}</strong>
                </div>
                <div class="metric">
                    <span>Upload</span>
                    <strong>${escapeHtml(formatBytes(totalTx))}</strong>
                </div>
            </div>
        </div>

        <div class="period-grid">
            ${createPeriodCardMarkup("Aujourd'hui", daySample)}
            ${createPeriodCardMarkup('Ce mois', monthSample)}
            ${createPeriodCardMarkup('Estimé fin de mois', estimatedSample)}
        </div>

        <div class="chart-box">
            <div class="chart-legend">
                <span class="legend"><i class="swatch swatch-rx"></i>Téléchargement</span>
                <span class="legend"><i class="swatch swatch-tx"></i>Envoi</span>
            </div>
            <canvas data-interface="${escapeHtml(name)}"></canvas>
        </div>

        <div class="panel-bottom">
            <span>Mis à jour le ${escapeHtml(formatTimestamp(iface?.updated?.timestamp))}</span>
            <span>${points.length} points / 5 min</span>
        </div>
    `;

    return article;
}

function drawChart(canvas, points) {
    const parent = canvas.parentElement;
    if (!parent) {
        return;
    }

    const width = Math.max(parent.clientWidth, 300);
    const height = 240;
    const dpr = window.devicePixelRatio || 1;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.width = '100%';
    canvas.style.height = `${height}px`;

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);

    const valuesRx = points.map((point) => Number(point?.rx) || 0);
    const valuesTx = points.map((point) => Number(point?.tx) || 0);
    const maxValue = Math.max(1, ...valuesRx, ...valuesTx);

    const left = 72;
    const right = 18;
    const top = 16;
    const bottom = 36;
    const chartWidth = width - left - right;
    const chartHeight = height - top - bottom;

    if (chartWidth <= 0 || chartHeight <= 0) {
        return;
    }

    ctx.fillStyle = 'rgba(15, 23, 42, 0.14)';
    ctx.fillRect(left, top, chartWidth, chartHeight);

    ctx.strokeStyle = 'rgba(148, 163, 184, 0.12)';
    ctx.lineWidth = 1;
    ctx.font = '12px "Trebuchet MS", sans-serif';
    ctx.fillStyle = 'rgba(226, 232, 240, 0.72)';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';

    for (let i = 0; i <= 4; i += 1) {
        const ratio = i / 4;
        const y = top + chartHeight - (chartHeight * ratio);
        ctx.beginPath();
        ctx.moveTo(left, y);
        ctx.lineTo(left + chartWidth, y);
        ctx.stroke();
        ctx.fillText(formatBytes(maxValue * ratio), left - 12, y);
    }

    if (points.length === 0) {
        ctx.save();
        ctx.fillStyle = 'rgba(226, 232, 240, 0.56)';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(
            'Aucune donnée pour la periode selectionnee',
            left + chartWidth / 2,
            top + chartHeight / 2,
        );
        ctx.restore();
        return;
    }

    const spacing = points.length > 1 ? chartWidth / (points.length - 1) : 0;
    const xForIndex = (index) => left + spacing * index;
    const yForValue = (value) => top + chartHeight - (value / maxValue) * chartHeight;

    function drawSeries(values, lineColor, fillTopColor, fillBottomColor) {
        if (!values.length) {
            return;
        }

        ctx.save();

        const fillGradient = ctx.createLinearGradient(0, top, 0, top + chartHeight);
        fillGradient.addColorStop(0, fillTopColor);
        fillGradient.addColorStop(1, fillBottomColor);

        ctx.beginPath();
        values.forEach((value, index) => {
            const x = xForIndex(index);
            const y = yForValue(value);
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.lineTo(xForIndex(values.length - 1), top + chartHeight);
        ctx.lineTo(xForIndex(0), top + chartHeight);
        ctx.closePath();
        ctx.fillStyle = fillGradient;
        ctx.fill();

        ctx.beginPath();
        values.forEach((value, index) => {
            const x = xForIndex(index);
            const y = yForValue(value);
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.strokeStyle = lineColor;
        ctx.lineWidth = 2.5;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.stroke();

        const lastIndex = values.length - 1;
        const lastX = xForIndex(lastIndex);
        const lastY = yForValue(values[lastIndex]);
        ctx.beginPath();
        ctx.arc(lastX, lastY, 3.5, 0, Math.PI * 2);
        ctx.fillStyle = lineColor;
        ctx.fill();

        ctx.restore();
    }

    drawSeries(valuesRx, '#38bdf8', 'rgba(56, 189, 248, 0.30)', 'rgba(56, 189, 248, 0.02)');
    drawSeries(valuesTx, '#fb7185', 'rgba(251, 113, 133, 0.24)', 'rgba(251, 113, 133, 0.02)');

    ctx.save();
    ctx.fillStyle = 'rgba(226, 232, 240, 0.65)';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';

    const labelStep = Math.max(1, Math.ceil(points.length / 6));
    points.forEach((point, index) => {
        if (index % labelStep !== 0 && index !== points.length - 1) {
            return;
        }

        const label = formatPointLabel(point);
        if (!label) {
            return;
        }

        ctx.fillText(label, xForIndex(index), top + chartHeight + 10);
    });

    ctx.restore();
}

function renderDashboard(interfaces) {
    currentInterfaces = Array.isArray(interfaces) ? interfaces : [];
    summaryEl.innerHTML = '';
    cardsEl.innerHTML = '';
    emptyEl.hidden = true;

    const totalRx = currentInterfaces.reduce(
        (sum, iface) => sum + (Number(iface?.traffic?.total?.rx) || 0),
        0,
    );
    const totalTx = currentInterfaces.reduce(
        (sum, iface) => sum + (Number(iface?.traffic?.total?.tx) || 0),
        0,
    );
    const latestInterface = currentInterfaces.reduce((latest, iface) => {
        const timestamp = Number(iface?.updated?.timestamp) || 0;
        if (!latest || timestamp > latest.timestamp) {
            return { timestamp, iface };
        }
        return latest;
    }, null);

    summaryEl.appendChild(
        createStatCard(
            'Interfaces visibles',
            String(currentInterfaces.length),
            SELECTED_INTERFACES.length ? 'Filtrees par configuration' : 'Toutes les interfaces',
        ),
    );
    summaryEl.appendChild(
        createStatCard('Download total', formatBytes(totalRx), 'Somme des ports affiches'),
    );
    summaryEl.appendChild(
        createStatCard('Upload total', formatBytes(totalTx), 'Somme des ports affiches'),
    );
    summaryEl.appendChild(
        createStatCard(
            'Derniere mise a jour',
            latestInterface ? formatTimestamp(latestInterface.timestamp) : '—',
            latestInterface?.iface?.name || '—',
        ),
    );

    if (currentInterfaces.length === 0) {
        const wanted = SELECTED_INTERFACES.length ? SELECTED_INTERFACES.join(', ') : 'toutes les interfaces';
        emptyEl.hidden = false;
        emptyEl.innerHTML = `
            <h2>Aucune interface a afficher</h2>
            <p>
                Le filtre actif ne retourne aucune interface. Verifie les noms passes en parametre:
                <strong>${escapeHtml(wanted)}</strong>.
            </p>
        `;
        statusEl.textContent = 'Aucune donnée trouvee';
        return;
    }

    currentInterfaces.forEach((iface) => {
        cardsEl.appendChild(createInterfaceCard(iface));
    });

    requestAnimationFrame(() => {
        cardsEl.querySelectorAll('canvas[data-interface]').forEach((canvas) => {
            const name = canvas.getAttribute('data-interface');
            const iface = currentInterfaces.find((item) => String(item?.name || '') === name);
            if (iface) {
                drawChart(canvas, getInterfacePoints(iface));
            }
        });
    });

    const updatedAt = new Date().toLocaleTimeString('fr-FR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
    statusEl.textContent = `Donnees actualisees a ${updatedAt}`;
}

async function loadDashboard() {
    try {
        clearError();
        statusEl.textContent = 'Chargement des données...';

        const response = await fetch(API_URL, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`Erreur HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (payload.error) {
            throw new Error(payload.error);
        }

        renderDashboard(Array.isArray(payload.interfaces) ? payload.interfaces : []);
    } catch (error) {
        showError(error?.message || 'Impossible de charger les données vnStat.');
        statusEl.textContent = 'Echec du chargement';
        currentInterfaces = [];
        summaryEl.innerHTML = '';
        cardsEl.innerHTML = '';
        emptyEl.hidden = false;
        emptyEl.innerHTML = `
            <h2>Impossible de charger les données</h2>
            <p>Verifie que <code>api.php</code> repond bien et que <code>vnstat --json</code> fonctionne sur le serveur.</p>
        `;
    }
}

function scheduleRefresh() {
    if (refreshTimer) {
        clearTimeout(refreshTimer);
    }

    refreshTimer = window.setTimeout(async () => {
        await loadDashboard();
        scheduleRefresh();
    }, 5000);
}

window.addEventListener('resize', () => {
    if (resizeTimer) {
        clearTimeout(resizeTimer);
    }

    resizeTimer = window.setTimeout(() => {
        if (currentInterfaces.length > 0) {
            renderDashboard(currentInterfaces);
        }
    }, 120);
});

loadDashboard().then(scheduleRefresh);
</script>
</body>
</html>
