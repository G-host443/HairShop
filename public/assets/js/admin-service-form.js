// Fiche prestation : affiche le prix unique seulement pour le type « Prix unique »,
// masque le montant pour « Sur devis », et rend les photos triables.
(() => {
    'use strict';

    const simpleBlock = document.querySelector('[data-simple-price]');
    const kindSelect = document.querySelector('[data-price-kind]');
    const priceField = document.querySelector('[data-price-field]');

    const refresh = () => {
        const mode = document.querySelector('[data-pricing-mode]:checked')?.value;
        if (simpleBlock) simpleBlock.hidden = mode !== 'simple';
        if (priceField && kindSelect) priceField.hidden = kindSelect.value === 'quote';
    };

    document.querySelectorAll('[data-pricing-mode]').forEach((radio) => radio.addEventListener('change', refresh));
    kindSelect?.addEventListener('change', refresh);
    refresh();

    const gallery = document.querySelector('[data-gallery-list]');
    if (gallery && window.Sortable) {
        Sortable.create(gallery, { animation: 150, ghostClass: 'is-dragging' });
    }
})();
