// Listes triables par glisser-déposer : <ul data-sortable="/url/de/sauvegarde"> avec des <li data-id="…">.
// Le nouvel ordre est envoyé en JSON ({ ids: [...] }) avec le jeton CSRF.
(() => {
    'use strict';

    if (!window.Sortable) return;
    const token = document.querySelector('input[name="_token"]')?.value || '';

    const notify = (list, ok) => {
        let status = list.parentElement.querySelector('.admin-sort-status');
        if (!status) {
            status = document.createElement('p');
            status.className = 'admin-sort-status small mt-2 mb-0';
            status.setAttribute('role', 'status');
            list.after(status);
        }
        status.textContent = ok ? 'Nouvel ordre enregistré.' : 'L\'ordre n\'a pas pu être enregistré. Rechargez la page.';
        status.className = `admin-sort-status small mt-2 mb-0 ${ok ? 'text-success' : 'text-danger'}`;
    };

    document.querySelectorAll('[data-sortable]').forEach((list) => {
        Sortable.create(list, {
            handle: '.admin-drag',
            animation: 150,
            ghostClass: 'is-dragging',
            onEnd: async () => {
                const ids = [...list.children].map((li) => Number(li.dataset.id)).filter(Boolean);
                try {
                    const response = await fetch(list.dataset.sortable, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token },
                        body: JSON.stringify({ ids }),
                    });
                    notify(list, response.ok);
                } catch (e) {
                    notify(list, false);
                }
            },
        });
    });
})();
