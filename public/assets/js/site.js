// Site public (tous templates) : menu mobile, en-tête au défilement, visionneuse, carrousels, apparition des sections.
(() => {
    'use strict';

    document.body.classList.add('js');

    // --- Menu mobile ---
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('[data-site-nav]');
    if (toggle && nav) {
        const close = () => {
            toggle.setAttribute('aria-expanded', 'false');
            nav.classList.remove('is-open');
        };
        toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(open));
            nav.classList.toggle('is-open', open);
            if (open) nav.querySelector('a')?.focus();
        });
        nav.addEventListener('click', (e) => { if (e.target.closest('a')) close(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && nav.classList.contains('is-open')) { close(); toggle.focus(); }
        });
    }

    // --- Ombre sous l'en-tête une fois la page défilée ---
    const header = document.querySelector('[data-site-header]');
    if (header) {
        const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 8);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // --- Visionneuse plein écran des réalisations : <a data-lightbox="groupe" href="grande-image"> ---
    // Sans JavaScript (ou sans <dialog>), le lien ouvre simplement l'image.
    const lightboxLinks = document.querySelectorAll('a[data-lightbox]');
    if (lightboxLinks.length && typeof HTMLDialogElement === 'function') {
        const dialog = document.createElement('dialog');
        dialog.className = 'lightbox';
        dialog.setAttribute('aria-label', 'Réalisation en plein écran');
        dialog.innerHTML = '<div class="lightbox-inner"><figure class="lightbox-figure"><img alt=""><figcaption class="lightbox-caption" aria-live="polite"></figcaption></figure>'
            + '<button type="button" class="lightbox-btn lightbox-close" aria-label="Fermer">×</button>'
            + '<button type="button" class="lightbox-btn lightbox-prev" aria-label="Photo précédente">‹</button>'
            + '<button type="button" class="lightbox-btn lightbox-next" aria-label="Photo suivante">›</button></div>';
        document.body.append(dialog);

        const img = dialog.querySelector('img');
        const caption = dialog.querySelector('figcaption');
        const prev = dialog.querySelector('.lightbox-prev');
        const next = dialog.querySelector('.lightbox-next');
        let items = [];
        let index = 0;
        let opener = null;

        const show = (i) => {
            index = (i + items.length) % items.length;
            const link = items[index];
            img.src = link.href;
            img.alt = link.querySelector('img')?.alt || '';
            caption.textContent = `${link.dataset.caption || ''}${items.length > 1 ? ` (${index + 1}/${items.length})` : ''}`;
            prev.hidden = next.hidden = items.length < 2;
        };

        lightboxLinks.forEach((link) => link.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            items = [...document.querySelectorAll(`a[data-lightbox="${CSS.escape(link.dataset.lightbox)}"]`)];
            opener = link;
            show(items.indexOf(link));
            dialog.showModal();
        }));

        dialog.querySelector('.lightbox-close').addEventListener('click', () => dialog.close());
        prev.addEventListener('click', () => show(index - 1));
        next.addEventListener('click', () => show(index + 1));
        dialog.addEventListener('click', (e) => { if (e.target === dialog || e.target.classList.contains('lightbox-inner')) dialog.close(); });
        dialog.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') show(index - 1);
            if (e.key === 'ArrowRight') show(index + 1);
        });
        dialog.addEventListener('close', () => { img.removeAttribute('src'); opener?.focus(); });
    }

    // --- Carrousels : [data-carousel] avec une liste défilante ; les boutons défilent d'une carte ---
    document.querySelectorAll('[data-carousel]').forEach((carousel) => {
        const track = carousel.querySelector('.carousel-track');
        const controls = carousel.querySelector('[data-carousel-controls]');
        if (!track || !controls || track.scrollWidth <= track.clientWidth + 4) return;
        controls.hidden = false;
        const step = () => (track.querySelector('li')?.getBoundingClientRect().width || track.clientWidth) + 20;
        const behavior = document.documentElement.dataset.motion === 'none' || window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
        carousel.querySelector('[data-carousel-prev]')?.addEventListener('click', () => track.scrollBy({ left: -step(), behavior }));
        carousel.querySelector('[data-carousel-next]')?.addEventListener('click', () => track.scrollBy({ left: step(), behavior }));
    });

    // --- Apparition des blocs au défilement (désactivée si animations réduites ou niveau « none ») ---
    const motion = document.documentElement.dataset.motion;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const blocks = document.querySelectorAll('.reveal');
    if (motion === 'none' || reduce || !('IntersectionObserver' in window)) {
        blocks.forEach((el) => el.classList.add('is-visible'));
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    blocks.forEach((el) => observer.observe(el));
})();
