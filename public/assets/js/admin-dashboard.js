// Graphiques du tableau de bord (Chart.js, copie locale). Les mêmes données sont lisibles dans les tableaux « Voir les données ».
(() => {
    'use strict';

    const source = document.querySelector('[data-dashboard-data]');
    if (!source || !window.Chart) return;
    const data = JSON.parse(source.textContent || '{}');

    const css = getComputedStyle(document.documentElement);
    const color = (name, fallback) => (css.getPropertyValue(name) || '').trim() || fallback;
    const gold = color('--brand-accent', '#DFB05E');
    const bronze = color('--brand-accent-dark', '#806217');
    const muted = '#B9AEA4';
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = '#5A4A44';
    Chart.defaults.animation = reduce ? false : Chart.defaults.animation;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const euro = (v) => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v);
    const integerTicks = { precision: 0 };
    const make = (key, config) => {
        const canvas = document.querySelector(`[data-chart="${key}"]`);
        if (canvas && data[key]) new Chart(canvas, { ...config, options: { responsive: true, maintainAspectRatio: false, ...config.options } });
    };

    make('bookings', {
        type: 'bar',
        data: {
            labels: (data.bookings || []).map((w) => w.label),
            datasets: [
                { label: 'Terminés', data: data.bookings?.map((w) => w.done), backgroundColor: bronze, stack: 'rdv' },
                { label: 'À venir / en cours', data: data.bookings?.map((w) => w.upcoming), backgroundColor: gold, stack: 'rdv' },
                { label: 'Annulés', data: data.bookings?.map((w) => w.cancelled), backgroundColor: muted, stack: 'annul' },
            ],
        },
        options: {
            scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: integerTicks } },
            plugins: { tooltip: { callbacks: { title: (items) => `Semaine du ${items[0].label}` } } },
        },
    });

    make('revenue', {
        type: 'bar',
        data: {
            labels: (data.revenue || []).map((r) => r.label),
            datasets: [
                { label: 'Réalisé', data: data.revenue?.map((r) => r.done), backgroundColor: bronze },
                { label: 'Prévu', data: data.revenue?.map((r) => r.expected), backgroundColor: gold },
            ],
        },
        options: {
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: (v) => euro(v) } } },
            plugins: { tooltip: { callbacks: { label: (c) => `${c.dataset.label} : ${euro(c.parsed.y)}` } } },
        },
    });

    make('services', {
        type: 'bar',
        data: {
            labels: (data.services || []).map((s) => s.name),
            datasets: [{ label: 'Réservations', data: data.services?.map((s) => s.count), backgroundColor: gold, borderRadius: 6 }],
        },
        options: {
            indexAxis: 'y',
            scales: { x: { beginAtZero: true, ticks: integerTicks }, y: { grid: { display: false } } },
            plugins: { legend: { display: false } },
        },
    });

    make('visits', {
        type: 'line',
        data: {
            labels: (data.visits || []).map((d) => d.label),
            datasets: [{
                label: 'Pages vues', data: data.visits?.map((d) => d.views),
                borderColor: bronze, backgroundColor: 'rgba(223, 176, 94, .18)', fill: true, tension: .3, pointRadius: 2,
            }],
        },
        options: {
            scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: integerTicks } },
            plugins: { legend: { display: false } },
            interaction: { mode: 'index', intersect: false },
        },
    });

})();
