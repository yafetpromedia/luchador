(() => {
    let lockCount = 0;
    let scrollY = 0;
    let lastFocus = null;

    const lockScroll = () => {
        lockCount += 1;
        if (lockCount !== 1) return;
        scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
    };

    const unlockScroll = () => {
        lockCount = Math.max(0, lockCount - 1);
        if (lockCount !== 0) return;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollY);
    };

    const nav = document.querySelector('[data-nav]');
    const navToggle = document.querySelector('[data-nav-toggle]');
    const navClose = document.querySelector('[data-nav-close]');
    const navBackdrop = document.querySelector('[data-nav-backdrop]');
    const sidebar = document.querySelector('[data-sidebar]');
    const sideToggle = document.querySelector('[data-sidebar-toggle]');
    const sideBackdrop = document.querySelector('[data-sidebar-backdrop]');

    const closeNav = () => {
        if (!document.body.classList.contains('nav-open')) return;
        document.body.classList.remove('nav-open');
        navToggle?.setAttribute('aria-expanded', 'false');
        unlockScroll();
        navToggle?.focus();
    };

    const closeSidebar = () => {
        if (!document.body.classList.contains('sidebar-open')) return;
        document.body.classList.remove('sidebar-open');
        sideToggle?.setAttribute('aria-expanded', 'false');
        unlockScroll();
        sideToggle?.focus();
    };

    navToggle?.addEventListener('click', () => {
        const open = !document.body.classList.contains('nav-open');
        if (open) {
            closeSidebar();
            lastFocus = document.activeElement;
            document.body.classList.add('nav-open');
            navToggle.setAttribute('aria-expanded', 'true');
            lockScroll();
            navClose?.focus();
        } else {
            closeNav();
        }
    });
    navClose?.addEventListener('click', closeNav);
    navBackdrop?.addEventListener('click', closeNav);
    nav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeNav));

    sideToggle?.addEventListener('click', () => {
        const open = !document.body.classList.contains('sidebar-open');
        if (open) {
            closeNav();
            lastFocus = document.activeElement;
            document.body.classList.add('sidebar-open');
            sideToggle.setAttribute('aria-expanded', 'true');
            lockScroll();
            sidebar?.querySelector('a')?.focus();
        } else {
            closeSidebar();
        }
    });
    sideBackdrop?.addEventListener('click', closeSidebar);
    sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));

    const lightboxLinks = Array.from(document.querySelectorAll('[data-lightbox]'));
    if (lightboxLinks.length) {
        const lightbox = document.createElement('div');
        lightbox.className = 'lightbox';
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.setAttribute('aria-label', 'Photograph');
        lightbox.innerHTML = '<button class="lightbox-close" type="button" aria-label="Close">' +
            '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
            (lightboxLinks.length > 1
                ? '<button class="lightbox-nav lightbox-prev" type="button" aria-label="Previous">' +
                  '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg></button>' +
                  '<button class="lightbox-nav lightbox-next" type="button" aria-label="Next">' +
                  '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>'
                : '') +
            '<img alt=""><p class="lightbox-caption"></p>';
        document.body.appendChild(lightbox);
        const lightboxImg = lightbox.querySelector('img');
        const caption = lightbox.querySelector('.lightbox-caption');
        let index = 0;

        const show = (i) => {
            index = (i + lightboxLinks.length) % lightboxLinks.length;
            const link = lightboxLinks[index];
            lightboxImg.src = link.getAttribute('href');
            lightboxImg.alt = link.querySelector('img')?.getAttribute('alt') || '';
            caption.textContent = link.getAttribute('data-caption') || '';
        };

        const closeLightbox = () => {
            if (!lightbox.classList.contains('is-open')) return;
            lightbox.classList.remove('is-open');
            document.body.classList.remove('lightbox-open');
            unlockScroll();
            lastFocus?.focus?.();
        };

        const openLightbox = (i) => {
            lastFocus = document.activeElement;
            show(i);
            lightbox.classList.add('is-open');
            document.body.classList.add('lightbox-open');
            lockScroll();
            lightbox.querySelector('.lightbox-close')?.focus();
        };

        lightbox.addEventListener('click', (event) => {
            if (event.target === lightbox || event.target.closest('.lightbox-close')) closeLightbox();
        });
        lightbox.querySelector('.lightbox-prev')?.addEventListener('click', (event) => {
            event.stopPropagation();
            show(index - 1);
        });
        lightbox.querySelector('.lightbox-next')?.addEventListener('click', (event) => {
            event.stopPropagation();
            show(index + 1);
        });
        document.addEventListener('keydown', (event) => {
            if (!lightbox.classList.contains('is-open')) return;
            if (event.key === 'Escape') closeLightbox();
            if (event.key === 'ArrowLeft') show(index - 1);
            if (event.key === 'ArrowRight') show(index + 1);
        });
        lightboxLinks.forEach((link, i) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                openLightbox(i);
            });
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeNav();
            closeSidebar();
        }
    });

    const countdownRoots = document.querySelectorAll('[data-countdown]');
    countdownRoots.forEach((root) => {
        const target = new Date(root.getAttribute('data-countdown') || '');
        if (Number.isNaN(target.getTime())) return;
        const live = root.querySelector('[data-countdown-live]');
        const done = root.querySelector('[data-countdown-done]');
        const nodes = {
            days: root.querySelector('[data-days]'),
            hours: root.querySelector('[data-hours]'),
            minutes: root.querySelector('[data-minutes]'),
            seconds: root.querySelector('[data-seconds]')
        };
        const pad = (value) => String(value).padStart(2, '0');
        const tick = () => {
            const diff = target.getTime() - Date.now();
            if (diff <= 0) {
                if (nodes.days) nodes.days.textContent = '0';
                if (nodes.hours) nodes.hours.textContent = '00';
                if (nodes.minutes) nodes.minutes.textContent = '00';
                if (nodes.seconds) nodes.seconds.textContent = '00';
                root.classList.add('is-done');
                if (live) live.hidden = true;
                if (done) done.hidden = false;
                return false;
            }
            const days = Math.floor(diff / 86400000);
            const hours = Math.floor((diff % 86400000) / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            if (nodes.days) nodes.days.textContent = String(days);
            if (nodes.hours) nodes.hours.textContent = pad(hours);
            if (nodes.minutes) nodes.minutes.textContent = pad(minutes);
            if (nodes.seconds) nodes.seconds.textContent = pad(seconds);
            return true;
        };
        if (tick()) {
            const id = window.setInterval(() => {
                if (!tick()) window.clearInterval(id);
            }, 1000);
        }
    });

    window.luchadorScroll = { lock: lockScroll, unlock: unlockScroll };

    const galleryFilters = document.querySelector('[data-gallery-filters]');
    if (galleryFilters) {
        const items = Array.from(document.querySelectorAll('[data-gallery-grid] a'));
        galleryFilters.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-gallery-filter]');
            if (!btn) return;
            const cat = btn.getAttribute('data-gallery-filter');
            galleryFilters.querySelectorAll('[data-gallery-filter]').forEach((chip) => {
                chip.classList.toggle('is-active', chip === btn);
            });
            items.forEach((item) => {
                const show = cat === 'all' || item.getAttribute('data-category') === cat;
                item.classList.toggle('is-hidden', !show);
            });
        });
    }

    if (document.body.classList.contains('onepage')) {
        const navLinks = Array.from(document.querySelectorAll('.site-nav a[href^="#"]'));
        const setCurrent = (id) => {
            navLinks.forEach((link) => {
                if (link.getAttribute('href') === '#' + id) {
                    link.setAttribute('aria-current', 'page');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        };
        const syncNav = () => {
            if (window.scrollY < 120) {
                setCurrent('home');
                return;
            }
            let current = 'home';
            navLinks.forEach((link) => {
                const id = (link.getAttribute('href') || '').replace('#', '');
                const section = id ? document.getElementById(id) : null;
                if (!section) return;
                const top = section.getBoundingClientRect().top;
                if (top <= 120) current = id;
            });
            setCurrent(current);
        };
        navLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                const id = (link.getAttribute('href') || '').replace('#', '');
                if (id !== 'home') return;
                event.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                setCurrent('home');
                history.replaceState(null, '', '#home');
            });
        });
        window.addEventListener('scroll', syncNav, { passive: true });
        syncNav();
    }

    document.querySelectorAll('[data-copy]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const value = btn.getAttribute('data-copy') || '';
            if (!value) return;
            const label = btn.textContent;
            try {
                await navigator.clipboard.writeText(value);
                btn.textContent = 'Copied';
            } catch (err) {
                const input = document.createElement('input');
                input.value = value;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                input.remove();
                btn.textContent = 'Copied';
            }
            window.setTimeout(() => {
                btn.textContent = label;
            }, 1600);
        });
    });

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const guestReadKey = 'luchador_notif_read';
    const guestReads = () => {
        try {
            const raw = JSON.parse(localStorage.getItem(guestReadKey) || '[]');
            return Array.isArray(raw) ? raw.map(Number) : [];
        } catch (err) {
            return [];
        }
    };
    const storeGuestReads = (ids) => {
        localStorage.setItem(guestReadKey, JSON.stringify([...new Set(ids)].slice(-200)));
    };

    document.querySelectorAll('[data-notifications]').forEach((root) => {
        const toggle = root.querySelector('.notif-toggle');
        const panel = root.querySelector('.notif-panel');
        const list = root.querySelector('[data-notif-list]');
        const countEl = root.querySelector('.notif-count');
        const readAll = root.querySelector('[data-notif-read-all]');
        const bootEl = root.querySelector('[data-notif-bootstrap]');
        let state = { items: [], unread: 0, latest_id: 0, authenticated: false, poll: '', stream: '', portal: root.getAttribute('data-portal') || 'public' };
        try {
            if (bootEl?.textContent) state = Object.assign(state, JSON.parse(bootEl.textContent));
        } catch (err) { /* keep defaults */ }

        const applyGuestReads = () => {
            if (state.authenticated) return;
            const read = new Set(guestReads());
            state.items = (state.items || []).map((item) => Object.assign({}, item, { read: item.read || read.has(Number(item.id)) }));
            state.unread = state.items.filter((item) => !item.read).length;
        };

        const setCount = (n) => {
            if (!countEl) return;
            countEl.textContent = n > 99 ? '99+' : String(n);
            countEl.hidden = n < 1;
            toggle?.setAttribute('aria-label', n > 0 ? `Notifications, ${n} unread` : 'Notifications');
        };

        const render = () => {
            applyGuestReads();
            setCount(state.unread || 0);
            if (!list) return;
            if (!state.items?.length) {
                list.innerHTML = '<p class="notif-empty">No notifications yet.</p>';
                return;
            }
            list.innerHTML = state.items.map((item) => {
                const href = item.url || '#';
                return `<a class="notif-item${item.read ? ' is-read' : ''}" href="${href}" data-notif-id="${item.id}">` +
                    `<span class="notif-item-title"></span>` +
                    (item.body ? `<span class="notif-item-body"></span>` : '') +
                    `<span class="notif-item-when"></span></a>`;
            }).join('');
            [...list.querySelectorAll('.notif-item')].forEach((el, i) => {
                const item = state.items[i];
                el.querySelector('.notif-item-title').textContent = item.title || '';
                const body = el.querySelector('.notif-item-body');
                if (body) body.textContent = item.body || '';
                el.querySelector('.notif-item-when').textContent = item.when || '';
            });
        };

        const toast = (item) => {
            if (panel && !panel.hidden) return;
            document.querySelector('.notif-toast')?.remove();
            const el = document.createElement('div');
            el.className = 'notif-toast';
            el.textContent = item.title || 'New notification';
            document.body.appendChild(el);
            window.setTimeout(() => el.remove(), 4200);
        };

        const post = async (body) => {
            if (!state.authenticated || !state.poll) return;
            await fetch(state.poll + (state.poll.includes('?') ? '&' : '?') + 'portal=' + encodeURIComponent(state.portal), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(Object.assign({ _csrf: csrf, portal: state.portal }, body))
            });
        };

        const refresh = async (announce) => {
            if (!state.poll) return;
            const url = state.poll + (state.poll.includes('?') ? '&' : '?') + 'portal=' + encodeURIComponent(state.portal);
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await response.json();
            const prev = state.latest_id || 0;
            const nextItems = data.items || [];
            const newest = nextItems.find((item) => Number(item.id) > prev);
            state = Object.assign(state, data);
            render();
            if (announce && newest && Number(newest.id) > prev) toast(newest);
        };

        const markRead = (id) => {
            if (!id) return;
            if (state.authenticated) {
                post({ action: 'read', id });
            } else {
                storeGuestReads(guestReads().concat(id));
            }
            state.items = (state.items || []).map((item) => Number(item.id) === Number(id) ? Object.assign({}, item, { read: true }) : item);
            state.unread = Math.max(0, (state.unread || 0) - 1);
            render();
        };

        toggle?.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = panel.hidden;
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) {
                panel.hidden = true;
                toggle?.setAttribute('aria-expanded', 'false');
            }
        });
        list?.addEventListener('click', (event) => {
            const item = event.target.closest('[data-notif-id]');
            if (!item) return;
            markRead(Number(item.getAttribute('data-notif-id')));
        });
        readAll?.addEventListener('click', () => {
            if (state.authenticated) {
                post({ action: 'read_all' });
            } else {
                storeGuestReads((state.items || []).map((item) => Number(item.id)));
            }
            state.items = (state.items || []).map((item) => Object.assign({}, item, { read: true }));
            state.unread = 0;
            render();
        });

        render();

        const startPoll = () => {
            window.setInterval(() => {
                if (document.hidden) return;
                refresh(true).catch(() => {});
            }, 8000);
        };

        if (state.stream && window.EventSource) {
            try {
                const connect = () => {
                    const es = new EventSource(state.stream + (state.stream.includes('?') ? '&' : '?') + 'portal=' + encodeURIComponent(state.portal) + '&since=' + encodeURIComponent(state.latest_id || 0));
                    es.addEventListener('notifications', () => {
                        refresh(true).catch(() => {});
                    });
                };
                connect();
            } catch (err) { /* poll still runs */ }
        }
        startPoll();
    });

    document.querySelectorAll('.stu-file input[type="file"]').forEach((input) => {
        const nameEl = input.closest('.stu-file')?.querySelector('[data-file-name]');
        if (!nameEl) return;
        const empty = nameEl.getAttribute('data-empty') || nameEl.textContent.trim();
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            nameEl.textContent = file ? file.name : empty;
        });
    });

    document.querySelectorAll('[data-kind-switch]').forEach((select) => {
        const root = select.closest('form');
        if (!root) return;
        const apply = () => {
            const kind = select.value;
            root.querySelectorAll('[data-kind-panel]').forEach((panel) => {
                const keys = (panel.getAttribute('data-kind-panel') || '').split(',').map((key) => key.trim());
                panel.hidden = keys.length > 0 && !keys.includes(kind);
            });
        };
        select.addEventListener('change', apply);
        apply();
    });

    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        const field = btn.closest('.password-field')?.querySelector('input');
        if (!field) return;
        const showIcon = btn.querySelector('[data-show]');
        const hideIcon = btn.querySelector('[data-hide]');
        btn.addEventListener('click', () => {
            const hide = field.type === 'text';
            field.type = hide ? 'password' : 'text';
            btn.setAttribute('aria-pressed', hide ? 'false' : 'true');
            btn.setAttribute('aria-label', hide ? 'Show password' : 'Hide password');
            if (showIcon) showIcon.hidden = !hide;
            if (hideIcon) hideIcon.hidden = hide;
        });
    });
})();
