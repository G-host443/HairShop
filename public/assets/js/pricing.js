// Sélecteur de tarifs : catégorie → coiffure → taille → longueur → options → total estimé → réservation.
// Les données viennent d'un bloc <script type="application/json" data-pricing-data> voisin.
// Le prix affiché est indicatif : il est recalculé et vérifié par le serveur au moment de réserver.
(() => {
    'use strict';

    const euro = (value) => new Intl.NumberFormat('fr-FR', {
        style: 'currency', currency: 'EUR', minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
    }).format(value);

    const duration = (minutes) => {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        if (h === 0) return `${m} min`;
        return `${h} h${m ? ' ' + String(m).padStart(2, '0') : ''}`;
    };

    const el = (tag, attrs = {}, ...children) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([key, value]) => {
            if (value === null || value === undefined || value === false) return;
            if (key === 'class') node.className = value;
            else if (key === 'text') node.textContent = value;
            else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
            else node.setAttribute(key, value === true ? '' : value);
        });
        children.flat().forEach((child) => child && node.append(child));
        return node;
    };

    const priceNode = (cls, price, original, prefix = '') => el('span', { class: cls },
        original ? el('s', { class: 'price-original', text: euro(original), 'aria-label': `au lieu de ${euro(original)}` }) : null,
        original ? ' ' : null,
        `${prefix}${euro(price)}`);

    const optionPriceText = (option) => {
        if (option.kind === 'quote') return 'sur devis';
        if (!option.price) return 'offert';
        return `${option.kind === 'from' ? 'à partir de ' : ''}+${euro(option.price)}`;
    };

    function mount(app, data) {
        const categories = data.categories || [];
        if (!categories.length) return;

        const single = Boolean(data.single);
        const booking = data.booking || { mode: 'contact', contact_url: '/contact', label: 'Demander ce rendez-vous' };
        const uid = Math.random().toString(36).slice(2, 8);
        const state = { cat: 0, svc: 0, variant: 0, length: null, options: {} };

        const service = () => categories[state.cat].services[state.svc];
        const variant = () => service().variants[state.variant] || null;
        const cell = () => (state.length === null || !variant() ? null : variant().prices[state.length]);

        const chosenOptions = () => service().option_groups.flatMap((group) =>
            group.options.filter((option) => (state.options[group.id] || []).includes(option.id)));

        function quote() {
            const svc = service();
            let label;
            let kind;
            let price;
            let minutes;

            if (svc.simple) {
                label = svc.name;
                kind = svc.simple.kind;
                price = svc.simple.price;
                minutes = svc.duration;
            } else {
                const c = cell();
                if (!c) return null;
                label = `${svc.name} taille ${variant().label} — ${c.length}`;
                kind = c.kind;
                price = c.price;
                minutes = c.duration ?? variant().duration ?? svc.duration;
            }

            const options = chosenOptions();
            const isQuote = kind === 'quote' || options.some((o) => o.kind === 'quote');
            const isFrom = kind === 'from' || options.some((o) => o.kind === 'from');
            const total = isQuote ? null : options.reduce((sum, o) => sum + (o.price || 0), price || 0);
            minutes += options.reduce((sum, o) => sum + (o.minutes || 0), 0);
            const fullLabel = [label, ...options.map((o) => o.name.toLowerCase())].join(' + ');

            return { fullLabel, total, isQuote, isFrom, minutes };
        }

        function bookingUrl(q) {
            if (booking.mode === 'online') {
                // Parcours de réservation en ligne : la sélection est reprise et revérifiée par le serveur.
                const params = new URLSearchParams({ service: service().id, c: '1' });
                const c = cell();
                if (!service().simple && c) {
                    params.set('variant', variant().id);
                    params.set('length', c.length_id);
                }
                chosenOptions().forEach((o) => params.append('options[]', o.id));
                return `${booking.booking_url}?${params.toString()}`;
            }
            const estimate = q.isQuote ? ' (prix sur devis)' : ` (estimation ${q.isFrom ? 'à partir de ' : ''}${euro(q.total)})`;
            const message = `Bonjour, je souhaite réserver : ${q.fullLabel}${estimate}.`;
            if (booking.mode === 'whatsapp' && booking.whatsapp) {
                return `https://wa.me/${booking.whatsapp}?text=${encodeURIComponent(message)}`;
            }
            return `${booking.contact_url}?message=${encodeURIComponent(message)}#contact`;
        }

        function chipGroup(label, items, current, onPick, extraClass = '') {
            return el('div', { class: `chip-row chip-row-start ${extraClass}`, role: 'group', 'aria-label': label },
                items.map((text, index) => el('button', {
                    type: 'button',
                    class: 'chip',
                    'aria-pressed': String(index === current),
                    text,
                    onclick: () => onPick(index),
                })));
        }

        function render(focusSelector) {
            const svc = service();
            const v = variant();
            const q = quote();
            app.replaceChildren();

            // --- Catégories et coiffures ---
            if (!single) {
                if (categories.length > 1) {
                    app.append(chipGroup('Catégorie', categories.map((c) => c.name), state.cat, (index) => {
                        Object.assign(state, { cat: index, svc: 0, variant: 0, length: null, options: {} });
                        render(`[data-focus="cat-${index}"]`);
                    }, 'pricing-cats'));
                    app.lastChild.querySelectorAll('button').forEach((b, i) => { b.dataset.focus = `cat-${i}`; });
                }
                const services = categories[state.cat].services;
                app.append(chipGroup('Coiffure', services.map((s) => s.name), state.svc, (index) => {
                    Object.assign(state, { svc: index, variant: 0, length: null, options: {} });
                    render(`[data-focus="svc-${index}"]`);
                }, 'pricing-services'));
                app.lastChild.querySelectorAll('button').forEach((b, i) => { b.dataset.focus = `svc-${i}`; });
            }

            // --- Panneau de la coiffure choisie ---
            const panel = el('div', { class: 'pricing-panel card' });
            const image = v && v.image ? v.image : null;
            if (image) {
                panel.append(el('figure', { class: 'pricing-panel-media' }, el('img', { src: image.src, alt: image.alt || `${svc.name} taille ${v.label}`, loading: 'lazy' })));
            } else {
                panel.classList.add('no-media');
            }

            const body = el('div', { class: 'pricing-panel-body' });
            if (!single) {
                body.append(el('div', { class: 'pricing-panel-head' },
                    el('h3', { class: 'pricing-panel-title', text: svc.name }),
                    el('a', { href: svc.url, class: 'pricing-panel-link', text: 'Voir la fiche' })));
            }
            // Sur une fiche prestation (single), l'offre est déjà annoncée par la page.
            if (svc.promotion && !single) {
                body.append(el('p', { class: 'promo-notice' },
                    el('span', { class: 'promo-badge', text: svc.promotion.badge }), ' ',
                    el('strong', { text: svc.promotion.name }), ' — prix remisé déjà affiché.'));
            }
            if (svc.alert) body.append(el('p', { class: 'notice', text: svc.alert }));

            if (svc.simple) {
                body.append(svc.simple.kind === 'quote'
                    ? el('p', { class: 'pricing-simple-price', text: 'Sur devis' })
                    : priceNode('pricing-simple-price', svc.simple.price, svc.simple.original, svc.simple.kind === 'from' ? 'À partir de ' : ''));
            } else {
                body.append(el('p', { class: 'pricing-step-label', id: `size-${uid}`, text: 'Taille des tresses' }));
                body.append(chipGroup('Taille des tresses', svc.variants.map((x) => x.label), state.variant, (index) => {
                    // On garde la longueur choisie si elle existe aussi dans la nouvelle taille.
                    const previous = cell() ? cell().length_id : null;
                    state.variant = index;
                    const match = svc.variants[index].prices.findIndex((p) => p.length_id === previous);
                    state.length = match >= 0 ? match : null;
                    render(`[data-focus="size-${index}"]`);
                }, 'pricing-sizes'));
                body.lastChild.querySelectorAll('button').forEach((b, i) => { b.dataset.focus = `size-${i}`; });
                if (v && v.note) body.append(el('p', { class: 'text-muted pricing-variant-note', text: v.note }));

                body.append(el('p', { class: 'pricing-step-label', text: 'Longueur' }));
                body.append(el('ul', { class: 'length-list', role: 'list' }, (v ? v.prices : []).map((p, index) => el('li', {},
                    el('button', {
                        type: 'button',
                        class: 'length-option',
                        'aria-pressed': String(index === state.length),
                        'data-focus': `len-${index}`,
                        onclick: () => { state.length = index; render(`[data-focus="len-${index}"]`); },
                    },
                    el('span', { class: 'length-option-name', text: p.length }),
                    el('span', { class: 'pricing-item-leader', 'aria-hidden': 'true' }),
                    p.kind === 'quote'
                        ? el('span', { class: 'length-option-price', text: 'sur devis' })
                        : priceNode('length-option-price', p.price, p.original))))));
            }

            // --- Options ---
            svc.option_groups.forEach((group) => {
                const name = `opt-${uid}-${group.id}`;
                const selected = state.options[group.id] || [];
                const fieldset = el('fieldset', { class: 'pricing-options' }, el('legend', { text: group.label + (group.selection === 'single' ? ' (une seule au choix)' : '') }));
                const onChange = (event) => {
                    const checked = [...fieldset.querySelectorAll('input:checked')].map((i) => Number(i.value)).filter(Boolean);
                    state.options[group.id] = checked;
                    render(`#${CSS.escape(event.target.id)}`); // le focus reste sur la case cliquée
                };
                if (group.selection === 'single' && !group.required) {
                    fieldset.append(el('label', { class: 'pricing-option' },
                        el('input', { type: 'radio', name, value: '0', id: `${name}-0`, checked: selected.length === 0, onchange: onChange }),
                        el('span', { text: 'Non merci' })));
                }
                group.options.forEach((option) => {
                    fieldset.append(el('label', { class: 'pricing-option' },
                        el('input', {
                            type: group.selection === 'single' ? 'radio' : 'checkbox',
                            name, value: String(option.id), id: `${name}-${option.id}`,
                            checked: selected.includes(option.id), onchange: onChange,
                        }),
                        el('span', {},
                            el('span', { class: 'pricing-option-name', text: option.name }),
                            ' ',
                            el('small', { class: 'pricing-option-price', text: optionPriceText(option) }),
                            option.description ? el('small', { class: 'pricing-option-desc', text: option.description }) : null)));
                });
                body.append(fieldset);
            });

            // --- Récapitulatif ---
            const summary = el('div', { class: 'pricing-summary', 'aria-live': 'polite' });
            if (q) {
                const totalText = q.isQuote
                    ? 'Sur devis : le prix vous sera confirmé'
                    : `${q.isFrom ? 'À partir de ' : ''}${euro(q.total)}`;
                summary.append(
                    el('p', { class: 'pricing-summary-label', text: q.fullLabel }),
                    el('p', { class: 'pricing-summary-total' }, el('span', { text: 'Total estimé' }), el('strong', { text: totalText })),
                    el('p', { class: 'pricing-summary-duration', text: `Durée estimée : environ ${duration(q.minutes)}` }),
                    el('a', {
                        class: 'btn btn-primary pricing-book',
                        href: bookingUrl(q),
                        target: booking.mode === 'whatsapp' ? '_blank' : null,
                        rel: booking.mode === 'whatsapp' ? 'noopener' : null,
                        text: booking.label,
                    }));
            } else {
                summary.append(el('p', { class: 'pricing-summary-hint', text: 'Choisissez une longueur pour afficher le prix.' }));
            }
            body.append(summary);

            panel.append(body);
            app.append(panel);

            if (focusSelector) app.querySelector(focusSelector)?.focus();
        }

        render();
        app.hidden = false;
        app.closest('section, .service-detail-body')?.classList.add('has-pricing-app');
    }

    document.querySelectorAll('[data-pricing-app]').forEach((app) => {
        const source = app.parentElement.querySelector('[data-pricing-data]');
        if (!source) return;
        try {
            mount(app, JSON.parse(source.textContent));
        } catch (e) {
            // Données illisibles : la liste statique reste affichée.
            app.hidden = true;
        }
    });
})();
