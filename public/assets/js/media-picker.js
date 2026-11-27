// Sélecteur d'images de la bibliothèque.
// - [data-media-field]   : une seule image (champ caché [data-media-input] + aperçu) ;
// - [data-media-gallery] : plusieurs images (liste [data-gallery-list], un champ caché par image).
(() => {
    'use strict';

    const modalEl = document.getElementById('media-picker');
    if (!modalEl || !window.bootstrap) return;

    const modal = new bootstrap.Modal(modalEl);
    const grid = modalEl.querySelector('[data-picker-grid]');
    const empty = modalEl.querySelector('[data-picker-empty]');
    const more = modalEl.querySelector('[data-picker-more]');
    const search = modalEl.querySelector('[data-picker-search]');
    const title = modalEl.querySelector('.modal-title');
    const endpoint = modalEl.dataset.endpoint;

    let onSelect = null;   // action à exécuter au clic sur une image
    let returnFocus = null;
    let page = 1;
    let pages = 1;
    let query = '';
    let timer = null;

    const load = async (reset) => {
        if (reset) { page = 1; grid.innerHTML = ''; }
        const url = `${endpoint}?type=image&page=${page}&q=${encodeURIComponent(query)}`;
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            grid.innerHTML = '<p class="text-danger">Impossible de charger la bibliothèque.</p>';
            return;
        }
        const data = await response.json();
        pages = data.pages;

        data.items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-media-card admin-media-pick';
            button.setAttribute('role', 'listitem');
            button.setAttribute('aria-label', `Choisir ${item.name}`);

            const thumb = document.createElement('span');
            thumb.className = 'admin-media-thumb';
            const img = document.createElement('img');
            img.src = item.thumb;
            img.alt = item.alt;
            img.loading = 'lazy';
            thumb.appendChild(img);

            const name = document.createElement('span');
            name.className = 'admin-media-name';
            name.textContent = item.name;

            const meta = document.createElement('span');
            meta.className = 'admin-media-meta';
            meta.textContent = `${item.width} × ${item.height} · ${item.size}`;

            button.append(thumb, name, meta);
            button.addEventListener('click', () => onSelect && onSelect(item, button));
            grid.appendChild(button);
        });

        empty.hidden = grid.children.length > 0;
        more.hidden = page >= pages;
    };

    const open = (heading, handler, trigger) => {
        onSelect = handler;
        returnFocus = trigger;
        title.textContent = heading;
        modal.show();
        if (!grid.children.length) load(true);
    };

    modalEl.addEventListener('hidden.bs.modal', () => returnFocus?.focus());

    const thumbImg = (item) => {
        const img = document.createElement('img');
        img.src = item.thumb;
        img.alt = item.alt;
        return img;
    };

    // --- Image unique ---
    document.querySelectorAll('[data-media-field]').forEach((field) => {
        const chooseBtn = field.querySelector('[data-media-choose]');
        const clearBtn = field.querySelector('[data-media-clear]');
        chooseBtn.addEventListener('click', () => open('Choisir une image', (item) => {
            field.querySelector('[data-media-input]').value = item.id;
            const preview = field.querySelector('[data-media-preview]');
            preview.replaceChildren(thumbImg(item));
            clearBtn.hidden = false;
            modal.hide();
        }, chooseBtn));
        clearBtn.addEventListener('click', () => {
            field.querySelector('[data-media-input]').value = '';
            field.querySelector('[data-media-preview]').innerHTML = '<span class="text-secondary small">Aucune image</span>';
            clearBtn.hidden = true;
            chooseBtn.focus();
        });
    });

    // --- Galerie (plusieurs images) ---
    document.querySelectorAll('[data-media-gallery]').forEach((gallery) => {
        const list = gallery.querySelector('[data-gallery-list]');
        const inputName = gallery.dataset.inputName;
        const addBtn = gallery.querySelector('[data-gallery-add]');

        const bindRemove = (li) => li.querySelector('[data-gallery-remove]').addEventListener('click', () => { li.remove(); addBtn.focus(); });
        list.querySelectorAll('li').forEach(bindRemove);

        addBtn.addEventListener('click', () => open('Ajouter des photos (cliquez sur chaque photo voulue)', (item, button) => {
            if (list.querySelector(`li[data-id="${item.id}"]`)) return;
            const li = document.createElement('li');
            li.dataset.id = item.id;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = inputName;
            input.value = item.id;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn-close';
            remove.setAttribute('aria-label', 'Retirer cette photo');
            remove.dataset.galleryRemove = '';
            li.append(input, thumbImg(item), remove);
            list.appendChild(li);
            bindRemove(li);
            button.classList.add('is-picked');
        }, addBtn));
    });

    // Utilisable par d'autres scripts (éditeur d'articles : insertion d'une image).
    window.MediaPicker = { open, close: () => modal.hide() };

    more.addEventListener('click', () => { page++; load(false); });
    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => { query = search.value.trim(); load(true); }, 300);
    });
})();
