// Personnalisation de l'apparence : affiche la valeur hexadécimale à côté de chaque sélecteur de couleur.
(() => {
    'use strict';

    document.querySelectorAll('[data-color-input]').forEach((input) => {
        const output = input.parentElement.querySelector('[data-color-value]');
        if (!output) return;
        input.addEventListener('input', () => { output.textContent = input.value.toUpperCase(); });
    });
})();
