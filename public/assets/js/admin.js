// Administration — comportements communs (sans dépendance autre que Bootstrap).
(() => {
    'use strict';

    // Bouton « afficher / masquer » des champs mot de passe.
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        const input = document.getElementById(button.dataset.togglePassword);
        if (!input) return;

        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            button.querySelector('.bi')?.classList.toggle('bi-eye', !show);
            button.querySelector('.bi')?.classList.toggle('bi-eye-slash', show);
        });
    });

    // Confirmation avant une action irréversible : <form data-confirm="Message">.
    // Évite aussi le double envoi d'un formulaire (double clic).
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                return;
            }
            form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; });
        });
    });
})();
