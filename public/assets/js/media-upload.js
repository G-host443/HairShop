// Bibliothèque de médias : import par glisser-déposer ou sélection, avec progression.
// Sans JavaScript, le formulaire reste utilisable (bouton « Importer » dans <noscript>).
(() => {
    'use strict';

    const form = document.querySelector('[data-dropzone]');
    if (!form) return;

    const input = form.querySelector('[data-dropzone-input]');
    const status = form.querySelector('[data-dropzone-status]');
    const token = form.querySelector('input[name="_token"]').value;
    const folderId = form.querySelector('input[name="folder_id"]').value;
    let uploading = false;

    const addLine = (text, kind) => {
        const li = document.createElement('li');
        li.className = kind === 'error' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-secondary';
        li.textContent = text;
        status.appendChild(li);
        return li;
    };

    // Envoi d'un fichier à la fois : progression lisible et pas de dépassement de post_max_size.
    const uploadOne = (file) => new Promise((resolve) => {
        const line = addLine(`${file.name} — envoi 0 %`);
        const data = new FormData();
        data.append('_token', token);
        data.append('folder_id', folderId);
        data.append('files[]', file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', token);
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) line.textContent = `${file.name} — envoi ${Math.round((e.loaded / e.total) * 100)} %`;
        });
        xhr.addEventListener('load', () => {
            let body = null;
            try { body = JSON.parse(xhr.responseText); } catch (e) { /* réponse non JSON */ }
            if (xhr.status === 200 && body && body.created.length) {
                line.textContent = `${file.name} — importé`;
                line.className = 'text-success';
                resolve(true);
            } else {
                const message = body?.errors?.[0]?.message || body?.error?.message || 'échec de l\'import';
                line.textContent = `${file.name} — ${message}`;
                line.className = 'text-danger';
                resolve(false);
            }
        });
        xhr.addEventListener('error', () => {
            line.textContent = `${file.name} — connexion interrompue`;
            line.className = 'text-danger';
            resolve(false);
        });
        xhr.send(data);
    });

    const uploadAll = async (files) => {
        if (uploading || !files.length) return;
        uploading = true;
        status.innerHTML = '';
        let ok = 0;
        for (const file of files) {
            if (await uploadOne(file)) ok++;
        }
        uploading = false;
        if (ok > 0) {
            addLine(`${ok} fichier${ok > 1 ? 's importés' : ' importé'}. Actualisation…`, 'ok');
            setTimeout(() => window.location.reload(), 900);
        }
    };

    input.addEventListener('change', () => uploadAll([...input.files]));

    ['dragenter', 'dragover'].forEach((type) => form.addEventListener(type, (e) => {
        e.preventDefault();
        form.classList.add('is-dragover');
    }));
    ['dragleave', 'drop'].forEach((type) => form.addEventListener(type, (e) => {
        e.preventDefault();
        form.classList.remove('is-dragover');
    }));
    form.addEventListener('drop', (e) => uploadAll([...e.dataTransfer.files]));
})();
