// Affiche le QR code d'activation de la double authentification (bibliothèque qrcode-generator, copie locale).
// Sans JavaScript, la clé reste affichée en clair pour une saisie manuelle.
(() => {
    'use strict';

    const box = document.querySelector('[data-qr]');
    if (!box || typeof window.qrcode !== 'function') return;

    const qr = window.qrcode(0, 'M');
    qr.addData(box.dataset.qr);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 3, scalable: true });
})();
