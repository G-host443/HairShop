// Aperçu avant publication : bascule ordinateur / mobile (390 px) et rechargement du cadre.
(() => {
    'use strict';

    const wrapper = document.querySelector('[data-preview]');
    const frame = document.querySelector('[data-preview-frame]');
    if (!wrapper || !frame) return;

    const buttons = document.querySelectorAll('[data-preview-device]');
    const setDevice = (device) => {
        wrapper.classList.toggle('is-mobile', device === 'mobile');
        buttons.forEach((b) => {
            const active = b.dataset.previewDevice === device;
            b.classList.toggle('active', active);
            b.setAttribute('aria-pressed', String(active));
        });
        try { sessionStorage.setItem('admin-preview-device', device); } catch (e) { /* stockage indisponible */ }
    };

    buttons.forEach((b) => b.addEventListener('click', () => setDevice(b.dataset.previewDevice)));

    let saved = null;
    try { saved = sessionStorage.getItem('admin-preview-device'); } catch (e) { /* stockage indisponible */ }
    if (saved === 'mobile') setDevice('mobile');

    document.querySelector('[data-preview-reload]')?.addEventListener('click', () => {
        frame.contentWindow?.location.reload();
    });
})();
