// Éditeur d'articles (Quill 2). Sans JavaScript, le champ texte HTML reste utilisable.
// Le contenu est toujours nettoyé côté serveur (liste blanche) : l'éditeur n'est qu'un confort de saisie.
(() => {
    'use strict';

    const form = document.querySelector('[data-blog-form]');
    const wrapper = form?.querySelector('[data-editor]');
    const surface = form?.querySelector('[data-editor-surface]');
    const source = form?.querySelector('[data-editor-source]');

    // --- Champs de date visibles seulement pour « Programmer » ---
    const scheduleFields = form?.querySelector('[data-schedule-fields]');
    const syncSchedule = () => {
        const checked = form.querySelector('[data-publication]:checked');
        if (scheduleFields) scheduleFields.hidden = !checked || checked.value !== 'schedule';
    };
    form?.querySelectorAll('[data-publication]').forEach((r) => r.addEventListener('change', syncSchedule));
    if (form) syncSchedule();

    if (!form || !wrapper || !surface || !source || !window.Quill) return;

    // Image de la bibliothèque : on garde son identifiant (data-media-id) pour savoir où elle est utilisée.
    const BaseImage = window.Quill.import('formats/image');
    class MediaImage extends BaseImage {
        static create(value) {
            const data = typeof value === 'string' ? { src: value } : (value || {});
            const node = super.create(data.src || '');
            if (data.id) node.setAttribute('data-media-id', String(data.id));
            node.setAttribute('alt', data.alt || '');
            return node;
        }

        static value(node) {
            return { src: node.getAttribute('src'), id: node.getAttribute('data-media-id'), alt: node.getAttribute('alt') || '' };
        }
    }
    MediaImage.blotName = 'image';
    MediaImage.tagName = 'IMG';
    window.Quill.register(MediaImage, true);

    const quill = new window.Quill(surface, {
        theme: 'snow',
        placeholder: 'Rédigez votre article…',
        modules: {
            toolbar: {
                container: [
                    [{ header: [2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ list: 'ordered' }, { list: 'bullet' }, 'blockquote'],
                    ['link', 'image'],
                    ['clean'],
                ],
                handlers: {
                    image() {
                        if (!window.MediaPicker) return;
                        const range = quill.getSelection(true);
                        window.MediaPicker.open('Insérer une image', (item) => {
                            quill.insertEmbed(range.index, 'image', { src: item.medium || item.url, id: item.id, alt: item.alt }, 'user');
                            quill.setSelection(range.index + 1, 0);
                            window.MediaPicker.close();
                        }, surface);
                    },
                },
            },
        },
    });

    // Libellés accessibles des boutons de la barre d'outils.
    const labels = {
        'ql-bold': 'Gras', 'ql-italic': 'Italique', 'ql-underline': 'Souligné', 'ql-strike': 'Barré',
        'ql-blockquote': 'Citation', 'ql-link': 'Lien', 'ql-image': 'Image de la bibliothèque', 'ql-clean': 'Retirer la mise en forme',
    };
    wrapper.querySelectorAll('.ql-toolbar button').forEach((button) => {
        const cls = [...button.classList].find((c) => labels[c]);
        if (cls) button.setAttribute('aria-label', labels[cls]);
        if (button.classList.contains('ql-list')) button.setAttribute('aria-label', button.value === 'ordered' ? 'Liste numérotée' : 'Liste à puces');
    });

    if (source.value.trim() !== '') {
        quill.setContents(quill.clipboard.convert({ html: source.value }), 'silent');
    }
    wrapper.hidden = false;
    source.hidden = true;

    form.addEventListener('submit', () => {
        source.value = quill.getLength() > 1 ? quill.root.innerHTML : '';
    });
})();
