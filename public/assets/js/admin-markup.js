// Barre de mise en forme des champs « texte mis en forme » : insère la syntaxe simple
// (**gras**, *italique*, ## intertitre, - liste, [lien](adresse)) autour de la sélection.
(() => {
    'use strict';

    document.querySelectorAll('[data-markup-toolbar]').forEach((toolbar) => {
        const textarea = document.getElementById(toolbar.dataset.markupToolbar);
        if (!textarea) return;
        toolbar.hidden = false;

        const replaceSelection = (before, after, placeholder) => {
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selected = textarea.value.slice(start, end) || placeholder;
            textarea.setRangeText(before + selected + after, start, end, 'end');
            textarea.setSelectionRange(start + before.length, start + before.length + selected.length);
            textarea.focus();
        };

        const prefixLines = (prefix, placeholder) => {
            const value = textarea.value;
            const start = value.lastIndexOf('\n', textarea.selectionStart - 1) + 1;
            let end = value.indexOf('\n', textarea.selectionEnd);
            if (end === -1) end = value.length;
            const block = value.slice(start, end) || placeholder;
            const prefixed = block.split('\n').map((line) => (line.startsWith(prefix) ? line : prefix + line)).join('\n');
            textarea.setRangeText(prefixed, start, end, 'end');
            textarea.focus();
        };

        toolbar.addEventListener('click', (event) => {
            const button = event.target.closest('[data-markup]');
            if (!button) return;

            switch (button.dataset.markup) {
                case 'bold': replaceSelection('**', '**', 'texte en gras'); break;
                case 'italic': replaceSelection('*', '*', 'texte en italique'); break;
                case 'heading': prefixLines('## ', 'Intertitre'); break;
                case 'list': prefixLines('- ', 'élément'); break;
                case 'link': {
                    const url = window.prompt('Adresse du lien (ex. /contact ou https://…)', '/');
                    if (url) replaceSelection('[', `](${url.trim()})`, 'texte du lien');
                    break;
                }
                default: break;
            }
        });
    });
})();
