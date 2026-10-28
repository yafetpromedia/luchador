(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    window.luchador = {
        csrf,
        async request(url, options = {}) {
            const headers = Object.assign({
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }, options.headers || {});
            if (options.body && !(options.body instanceof FormData) && typeof options.body !== 'string') {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(Object.assign({ _csrf: csrf }, options.body));
            }
            const response = await fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' }));
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(data.error || 'Something went wrong. Please try again.');
            }
            return data;
        }
    };

    const modal = document.getElementById('confirm-modal');
    let pendingForm = null;
    let lastFocus = null;
    const lock = window.luchadorScroll;

    const openModal = (form, title, text, okLabel) => {
        if (!modal || !form) return;
        pendingForm = form;
        lastFocus = document.activeElement;
        modal.querySelector('#confirm-title').textContent = title;
        modal.querySelector('#confirm-text').textContent = text;
        const ok = modal.querySelector('[data-confirm-ok]');
        if (ok) ok.textContent = okLabel || 'Confirm';
        modal.hidden = false;
        document.body.classList.add('modal-open');
        lock?.lock();
        ok?.focus();
    };

    const closeModal = () => {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        lock?.unlock();
        pendingForm = null;
        lastFocus?.focus?.();
    };

    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (event) => {
            event.preventDefault();
            openModal(
                el.closest('form'),
                el.getAttribute('data-confirm-title') || 'Are you sure?',
                el.getAttribute('data-confirm') || 'This action cannot be undone.',
                el.getAttribute('data-confirm-ok') || 'Confirm'
            );
        });
    });
    modal?.querySelectorAll('[data-confirm-cancel]').forEach((btn) => btn.addEventListener('click', closeModal));
    modal?.querySelector('[data-confirm-ok]')?.addEventListener('click', () => {
        if (pendingForm) pendingForm.submit();
        closeModal();
    });
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            event.preventDefault();
            event.stopImmediatePropagation();
            closeModal();
        }
    });

    const loadImageBitmap = (file) => new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Could not read image'));
        };
        img.src = url;
    });

    const compressImageFile = async (file, { maxEdge = 2000, quality = 0.82, maxBytes = 3.5 * 1024 * 1024 } = {}) => {
        if (!(file instanceof File) || !file.type.startsWith('image/')) return file;
        if (file.type === 'image/gif') return file;
        if (file.size <= maxBytes && file.size < 1.2 * 1024 * 1024) return file;
        try {
            const img = await loadImageBitmap(file);
            const w = img.naturalWidth || img.width;
            const h = img.naturalHeight || img.height;
            if (!w || !h) return file;
            const scale = Math.min(1, maxEdge / Math.max(w, h));
            const tw = Math.max(1, Math.round(w * scale));
            const th = Math.max(1, Math.round(h * scale));
            if (scale >= 0.98 && file.size <= maxBytes) return file;
            const canvas = document.createElement('canvas');
            canvas.width = tw;
            canvas.height = th;
            const ctx = canvas.getContext('2d');
            if (!ctx) return file;
            ctx.drawImage(img, 0, 0, tw, th);
            const preferWebp = file.type === 'image/webp';
            const mime = preferWebp ? 'image/webp' : 'image/jpeg';
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, mime, quality));
            if (!blob || blob.size <= 0 || blob.size >= file.size * 0.98) return file;
            const base = (file.name || 'photo').replace(/\.[^.]+$/, '');
            const ext = mime === 'image/webp' ? 'webp' : 'jpg';
            return new File([blob], base + '.' + ext, { type: mime, lastModified: Date.now() });
        } catch (err) {
            return file;
        }
    };

    const replaceInputFiles = (input, files) => {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
    };

    document.querySelectorAll('form[data-compress-images]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.imagesReady === '1') {
                form.dataset.imagesReady = '0';
                return;
            }
            const inputs = Array.from(form.querySelectorAll('input[type="file"]')).filter((input) => (
                input.files && input.files.length > 0
                && (!input.accept || input.accept.includes('image') || input.accept === '')
            ));
            if (!inputs.length) return;

            event.preventDefault();
            event.stopPropagation();

            const btn = form.querySelector('button[type="submit"]');
            const previousLabel = btn ? (btn.dataset.label || btn.textContent || '') : '';
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.dataset.label = previousLabel;
                btn.textContent = btn.getAttribute('data-compress-label') || 'Preparing photos…';
            }
            form.setAttribute('aria-busy', 'true');

            try {
                for (const input of inputs) {
                    const next = [];
                    for (const file of Array.from(input.files || [])) {
                        next.push(await compressImageFile(file));
                    }
                    replaceInputFiles(input, next);
                }
                form.dataset.imagesReady = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(btn || undefined);
                } else {
                    form.submit();
                }
            } catch (err) {
                form.dataset.imagesReady = '0';
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.textContent = previousLabel || 'Upload photos';
                }
                form.removeAttribute('aria-busy');
                window.alert('Could not prepare the photos for upload. Try fewer or smaller images.');
            }
        }, true);
    });

    document.querySelectorAll('form[data-loading]').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.dataset.label = btn.textContent || '';
                const label = (btn.getAttribute('data-loading-label') || 'Saving…');
                btn.textContent = label;
            }
            form.setAttribute('aria-busy', 'true');
        });
    });

    const viewModal = document.getElementById('student-view-modal');
    const formModal = document.getElementById('student-form-modal');
    const studentForm = document.getElementById('student-form');
    let studentRecord = null;
    let formPreviewUrl = '';
    const fillText = (id, value, fallback = '—') => {
        const el = document.getElementById(id);
        if (el) el.textContent = value || fallback;
    };
    const fillBadge = (id, status, label) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.replaceChildren();
        const badge = document.createElement('span');
        badge.className = 'badge badge-' + (status || 'pending');
        badge.textContent = label || '—';
        el.appendChild(badge);
    };
    const initialsFrom = (name) => {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '+';
        const first = parts[0][0] || '';
        const last = parts.length > 1 ? (parts[parts.length - 1][0] || '') : '';
        return (first + last).toUpperCase();
    };
    const setFormValue = (id, value) => {
        const el = document.getElementById(id);
        if (!el) return;
        const next = value ?? '';
        if (el.tagName === 'SELECT' && next !== '') {
            const exists = Array.from(el.options).some((opt) => opt.value === next);
            if (!exists) {
                const opt = document.createElement('option');
                opt.value = next;
                opt.textContent = next;
                el.appendChild(opt);
            }
        }
        el.value = next;
    };
    const setFormPhoto = (src, name) => {
        const preview = document.getElementById('student-form-preview');
        const initials = document.getElementById('student-form-initials');
        if (!preview || !initials) return;
        if (src) {
            preview.hidden = false;
            preview.src = src;
            preview.alt = name || '';
            initials.hidden = true;
        } else {
            preview.hidden = true;
            preview.removeAttribute('src');
            preview.alt = '';
            initials.hidden = false;
            initials.textContent = initialsFrom(name);
        }
    };
    const resetStudentForm = () => {
        if (!studentForm) return;
        studentForm.reset();
        const sectionSelect = document.getElementById('section');
        if (sectionSelect) {
            Array.from(sectionSelect.querySelectorAll('option:not([data-official]):not([value=""])')).forEach((opt) => opt.remove());
        }
        setFormValue('student-form-id', '');
        setFormValue('grade', studentForm.getAttribute('data-default-grade') || '');
        setFormValue('quantity', '1');
        setFormValue('status', 'pending');
        setFormValue('payment_status', 'unpaid');
        const remove = document.getElementById('remove_photo');
        if (remove) remove.checked = false;
        const removeWrap = document.getElementById('student-form-remove-wrap');
        if (removeWrap) removeWrap.hidden = true;
        if (formPreviewUrl) {
            URL.revokeObjectURL(formPreviewUrl);
            formPreviewUrl = '';
        }
        setFormPhoto('', '');
        const title = document.getElementById('student-form-title');
        const kicker = document.getElementById('student-form-kicker');
        const submit = document.getElementById('student-form-submit');
        if (title) title.textContent = 'Add student';
        if (kicker) kicker.textContent = 'New record';
        if (submit) submit.textContent = 'Add student';
    };
    const fillStudentForm = (data) => {
        resetStudentForm();
        if (!data || !data.id) return;
        setFormValue('student-form-id', String(data.id));
        setFormValue('student_name', data.name || '');
        setFormValue('student_code', data.code || '');
        setFormValue('phone_number', data.student_phone || '');
        setFormValue('mother_name', data.mother_name || '');
        setFormValue('mother_phone', data.mother_phone || '');
        setFormValue('father_name', data.father_name || '');
        setFormValue('father_phone', data.father_phone || '');
        setFormValue('grade', data.grade || studentForm.getAttribute('data-default-grade') || '');
        setFormValue('section', data.section || '');
        setFormValue('gender', data.gender || '');
        setFormValue('size', data.size || '');
        setFormValue('quantity', String(data.quantity || 1));
        setFormValue('status', data.status || 'pending');
        setFormValue('payment_status', data.payment || 'unpaid');
        setFormValue('notes', data.notes || '');
        setFormPhoto(data.photo || '', data.name || '');
        const removeWrap = document.getElementById('student-form-remove-wrap');
        if (removeWrap) removeWrap.hidden = !data.photo;
        const title = document.getElementById('student-form-title');
        const kicker = document.getElementById('student-form-kicker');
        const submit = document.getElementById('student-form-submit');
        if (title) title.textContent = 'Update student';
        if (kicker) kicker.textContent = data.code ? 'ID ' + data.code : 'Existing record';
        if (submit) submit.textContent = 'Save student';
    };
    const closeStudentForm = () => {
        if (!formModal || formModal.hidden) return;
        formModal.hidden = true;
        if ((modal?.hidden !== false) && (viewModal?.hidden !== false)) {
            document.body.classList.remove('modal-open');
            lock?.unlock();
        }
        lastFocus?.focus?.();
    };
    const openStudentForm = (data) => {
        if (!formModal || !studentForm) return;
        lastFocus = document.activeElement;
        if (viewModal && !viewModal.hidden) {
            viewModal.hidden = true;
        }
        if (data && data.id) {
            fillStudentForm(data);
        } else {
            resetStudentForm();
        }
        formModal.hidden = false;
        document.body.classList.add('modal-open');
        lock?.lock();
        document.getElementById('student_name')?.focus();
    };
    const openStudent = (data) => {
        if (!viewModal || !data) return;
        lastFocus = document.activeElement;
        const photo = document.getElementById('student-view-photo');
        const initials = document.getElementById('student-view-initials');
        if (data.photo) {
            photo.hidden = false;
            photo.src = data.photo;
            photo.alt = data.name || '';
            initials.hidden = true;
        } else {
            photo.hidden = true;
            photo.removeAttribute('src');
            initials.hidden = false;
            initials.textContent = data.initials || '?';
        }
        fillText('student-view-name', data.name, 'Student');
        fillText('student-view-id', data.code ? 'Student ID ' + data.code : 'Student ID not set');
        fillText('student-view-grade', data.grade);
        fillText('student-view-section', data.section, 'Not set');
        fillText('student-view-size', data.size, 'Not set');
        fillText('student-view-quantity', String(data.quantity ?? 1));
        fillBadge('student-view-status', data.status, data.status_label);
        fillBadge('student-view-payment', data.payment, data.payment_label);
        fillText('student-view-created', data.created, 'Unknown');
        fillText('student-view-updated', data.updated, 'No later changes recorded');

        const fillPhone = (linkId, emptyId, phone, extraHasValue) => {
            const link = document.getElementById(linkId);
            const empty = document.getElementById(emptyId);
            const value = (phone || '').trim();
            if (link) {
                if (value) {
                    link.hidden = false;
                    link.textContent = value;
                    link.href = 'tel:' + value.replace(/[^\d+]/g, '');
                } else {
                    link.hidden = true;
                    link.textContent = '';
                    link.removeAttribute('href');
                }
            }
            if (empty) empty.hidden = !!(value || extraHasValue);
        };
        fillPhone('student-view-student-phone', 'student-view-student-phone-empty', data.student_phone, false);
        const motherName = document.getElementById('student-view-mother-name');
        if (motherName) {
            motherName.textContent = data.mother_name || '';
            motherName.hidden = !data.mother_name;
        }
        fillPhone('student-view-mother-phone', 'student-view-mother-empty', data.mother_phone, !!data.mother_name);
        const fatherName = document.getElementById('student-view-father-name');
        if (fatherName) {
            fatherName.textContent = data.father_name || '';
            fatherName.hidden = !data.father_name;
        }
        fillPhone('student-view-father-phone', 'student-view-father-empty', data.father_phone, !!data.father_name);

        const notesWrap = document.getElementById('student-view-notes-wrap');
        const notesEl = document.getElementById('student-view-notes');
        if (data.notes) {
            notesWrap.hidden = false;
            notesEl.textContent = data.notes;
        } else {
            notesWrap.hidden = true;
            notesEl.textContent = '';
        }

        studentRecord = data;
        const edit = document.getElementById('student-view-edit');
        if (edit) {
            edit.hidden = !data.edit_url;
        }
        const deleteId = document.getElementById('student-view-delete-id');
        const deleteForm = document.getElementById('student-view-delete');
        if (deleteForm) {
            deleteForm.hidden = !data.can_delete;
            if (deleteId) deleteId.value = String(data.id || '');
        }

        if (formModal && !formModal.hidden) {
            formModal.hidden = true;
        }
        viewModal.hidden = false;
        document.body.classList.add('modal-open');
        lock?.lock();
        viewModal.querySelector('[data-student-close]')?.focus();
    };
    const closeStudent = () => {
        if (!viewModal || viewModal.hidden) return;
        viewModal.hidden = true;
        if ((modal?.hidden !== false) && (formModal?.hidden !== false)) {
            document.body.classList.remove('modal-open');
            lock?.unlock();
        }
        lastFocus?.focus?.();
    };
    document.addEventListener('click', (event) => {
        const formTrigger = event.target.closest('[data-student-form]');
        if (formTrigger) {
            event.preventDefault();
            const raw = formTrigger.getAttribute('data-student-form') || '';
            if (raw === 'new') {
                openStudentForm(null);
                return;
            }
            try {
                openStudentForm(JSON.parse(raw));
            } catch (err) {
                return;
            }
            return;
        }
        const trigger = event.target.closest('[data-student-open]');
        if (!trigger) return;
        event.preventDefault();
        try {
            openStudent(JSON.parse(trigger.getAttribute('data-student-open') || '{}'));
        } catch (err) {
            return;
        }
    });
    document.getElementById('student-view-edit')?.addEventListener('click', () => {
        if (!studentRecord) return;
        closeStudent();
        openStudentForm(studentRecord);
    });
    viewModal?.querySelectorAll('[data-student-close]').forEach((btn) => btn.addEventListener('click', closeStudent));
    viewModal?.addEventListener('click', (event) => {
        if (event.target === viewModal) closeStudent();
    });
    formModal?.querySelectorAll('[data-student-form-close]').forEach((btn) => btn.addEventListener('click', closeStudentForm));
    formModal?.addEventListener('click', (event) => {
        if (event.target === formModal) closeStudentForm();
    });
    document.getElementById('student_name')?.addEventListener('input', (event) => {
        const preview = document.getElementById('student-form-preview');
        if (preview && !preview.hidden) return;
        const initials = document.getElementById('student-form-initials');
        if (initials) initials.textContent = initialsFrom(event.target.value);
    });
    document.getElementById('photo')?.addEventListener('change', (event) => {
        const file = event.target.files && event.target.files[0];
        if (formPreviewUrl) {
            URL.revokeObjectURL(formPreviewUrl);
            formPreviewUrl = '';
        }
        if (!file) return;
        formPreviewUrl = URL.createObjectURL(file);
        setFormPhoto(formPreviewUrl, document.getElementById('student_name')?.value || '');
        const removeWrap = document.getElementById('student-form-remove-wrap');
        if (removeWrap) removeWrap.hidden = true;
        const remove = document.getElementById('remove_photo');
        if (remove) remove.checked = false;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal && !modal.hidden) return;
        if (formModal && !formModal.hidden) {
            event.preventDefault();
            closeStudentForm();
            return;
        }
        if (!viewModal || viewModal.hidden) return;
        closeStudent();
    });
    if (window.__openStudentForm) {
        openStudentForm(window.__openStudentForm === true ? null : window.__openStudentForm);
    } else if (window.__openStudent) {
        openStudent(window.__openStudent);
    }

    document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
        form.querySelector('select')?.addEventListener('change', () => {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Saving…';
            }
            form.submit();
        });
    });

    const selectForm = document.getElementById('student-select-form');
    if (selectForm) {
        const matchingInput = document.getElementById('delete-matching');
        const countEl = selectForm.querySelector('[data-selected-count]');
        const deleteBtn = selectForm.querySelector('button[type="submit"]');
        const pageCheck = document.querySelector('[data-select-page-check]');
        const matchingTotal = parseInt(selectForm.getAttribute('data-matching-count') || '0', 10) || 0;
        const boxes = () => Array.from(document.querySelectorAll('input[name="ids[]"][data-student-id]'));
        const uniqueIds = () => [...new Set(boxes().map((el) => el.value))];
        const setMatching = (on) => {
            if (matchingInput) matchingInput.value = on ? '1' : '0';
        };
        const syncId = (id, checked) => {
            boxes().filter((el) => el.value === String(id)).forEach((el) => {
                el.checked = checked;
            });
        };
        const selectedCount = () => {
            if (matchingInput && matchingInput.value === '1') return matchingTotal;
            return uniqueIds().filter((id) => boxes().some((el) => el.value === id && el.checked)).length;
        };
        const updateBar = () => {
            const n = selectedCount();
            if (countEl) countEl.textContent = String(n);
            if (deleteBtn) {
                deleteBtn.disabled = n === 0;
                deleteBtn.setAttribute('data-confirm-title', n === matchingTotal && matchingInput?.value === '1'
                    ? 'Delete all matching students?'
                    : 'Delete selected students?');
                deleteBtn.setAttribute(
                    'data-confirm',
                    'Delete ' + n + ' student' + (n === 1 ? '' : 's') + '? Records are permanently removed. Linked student logins will be disabled.'
                );
            }
            if (pageCheck) {
                const pageIds = uniqueIds();
                const checkedPage = pageIds.filter((id) => boxes().some((el) => el.value === id && el.checked)).length;
                pageCheck.checked = pageIds.length > 0 && checkedPage === pageIds.length;
                pageCheck.indeterminate = checkedPage > 0 && checkedPage < pageIds.length;
            }
        };
        const setPage = (checked) => {
            uniqueIds().forEach((id) => syncId(id, checked));
        };
        selectForm.querySelector('[data-select-page]')?.addEventListener('click', () => {
            setMatching(false);
            setPage(true);
            updateBar();
        });
        pageCheck?.addEventListener('change', () => {
            setMatching(false);
            setPage(pageCheck.checked);
            updateBar();
        });
        selectForm.querySelector('[data-select-matching]')?.addEventListener('click', () => {
            setMatching(true);
            setPage(true);
            updateBar();
        });
        selectForm.querySelector('[data-select-none]')?.addEventListener('click', () => {
            setMatching(false);
            setPage(false);
            updateBar();
        });
        document.addEventListener('change', (event) => {
            const box = event.target.closest?.('input[name="ids[]"][data-student-id]');
            if (!box) return;
            setMatching(false);
            syncId(box.value, box.checked);
            updateBar();
        });
        updateBar();
    }

    const payForm = document.getElementById('payment-select-form');
    if (payForm) {
        const countEl = payForm.querySelector('[data-selected-count]');
        const pageCheck = document.querySelector('#verify [data-select-page-check]');
        const buttons = () => Array.from(payForm.querySelectorAll('button[type="submit"]'));
        const boxes = () => Array.from(document.querySelectorAll('input[name="ids[]"][data-payment-id]'));
        const selected = () => boxes().filter((el) => el.checked);
        const updateBar = () => {
            const n = selected().length;
            if (countEl) countEl.textContent = String(n);
            buttons().forEach((btn) => {
                btn.disabled = n === 0;
            });
            if (pageCheck) {
                const all = boxes();
                const checked = selected().length;
                pageCheck.checked = all.length > 0 && checked === all.length;
                pageCheck.indeterminate = checked > 0 && checked < all.length;
            }
        };
        pageCheck?.addEventListener('change', () => {
            boxes().forEach((el) => {
                el.checked = pageCheck.checked;
            });
            updateBar();
        });
        document.addEventListener('change', (event) => {
            if (event.target.closest?.('input[name="ids[]"][data-payment-id]')) {
                updateBar();
            }
        });
        updateBar();
    }

    document.querySelectorAll('select[data-autosubmit]').forEach((el) => {
        el.addEventListener('change', () => el.form?.requestSubmit());
    });

    document.querySelector('[data-culture-expand-all]')?.addEventListener('click', () => {
        document.querySelectorAll('[data-culture-graph] details').forEach((el) => {
            el.open = true;
        });
    });
    document.querySelector('[data-culture-collapse-all]')?.addEventListener('click', () => {
        document.querySelectorAll('[data-culture-graph] details').forEach((el) => {
            el.open = false;
        });
    });

    document.querySelectorAll('[data-culture-group-picker]').forEach((root) => {
        let roster = [];
        let selected = { male: [], female: [], pending: [] };
        try {
            roster = JSON.parse(root.getAttribute('data-roster') || '[]');
        } catch (e) {
            roster = [];
        }
        try {
            selected = Object.assign(selected, JSON.parse(root.getAttribute('data-selected') || '{}'));
        } catch (e) {
            // keep defaults
        }
        selected.male = Array.isArray(selected.male) ? selected.male : [];
        selected.female = Array.isArray(selected.female) ? selected.female : [];
        selected.pending = Array.isArray(selected.pending) ? selected.pending : [];

        let lane = 'male';
        const searchInput = root.querySelector('[data-culture-live-search]');
        const hiddenBox = root.querySelector('[data-culture-hidden-inputs]');
        const pendingWrap = root.querySelector('[data-culture-pending-wrap]');
        const pendingBox = root.querySelector('[data-culture-pending]');

        const takenIds = () => {
            const ids = new Set();
            selected.male.forEach((s) => ids.add(Number(s.id)));
            selected.female.forEach((s) => ids.add(Number(s.id)));
            selected.pending.forEach((s) => ids.add(Number(s.id)));
            return ids;
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        const cardHtml = (student, { checked = false, removable = false, busy = false } = {}) => {
            const meta = [student.code || 'No ID', student.section].filter(Boolean).join(' · ');
            const busyNote = busy && student.busyGroup
                ? 'Already in ' + student.busyGroup
                : (removable ? 'Uncheck to remove' : '');
            return (
                '<label class="culture-pick-card' + (busy ? ' is-busy' : '') + '">' +
                '<input type="checkbox" ' + (checked ? 'checked' : '') + (busy ? ' disabled' : '') +
                ' data-culture-id="' + Number(student.id) + '">' +
                '<span class="culture-pick-main"><strong>' + escapeHtml(student.name) + '</strong>' +
                '<span class="muted">' + escapeHtml(meta) + '</span></span>' +
                (busyNote ? '<span class="muted">' + escapeHtml(busyNote) + '</span>' : '') +
                '</label>'
            );
        };

        const syncHidden = () => {
            if (!hiddenBox) return;
            const parts = [];
            selected.male.forEach((s) => {
                parts.push('<input type="hidden" name="male_ids[]" value="' + Number(s.id) + '">');
            });
            selected.female.forEach((s) => {
                parts.push('<input type="hidden" name="female_ids[]" value="' + Number(s.id) + '">');
            });
            hiddenBox.innerHTML = parts.join('');
        };

        const renderLane = (key) => {
            const panel = root.querySelector('[data-culture-lane-panel="' + key + '"]');
            if (!panel) return;
            const list = selected[key] || [];
            const selectedBox = panel.querySelector('[data-culture-selected]');
            const empty = panel.querySelector('[data-culture-empty]');
            const count = panel.querySelector('[data-culture-count]');
            if (count) count.textContent = list.length + ' selected';
            if (selectedBox) {
                selectedBox.innerHTML = list.map((s) => cardHtml(s, { checked: true, removable: true })).join('');
            }
            if (empty) empty.hidden = list.length > 0;
        };

        const renderPending = () => {
            if (!pendingWrap || !pendingBox) return;
            pendingWrap.hidden = selected.pending.length < 1;
            pendingBox.innerHTML = selected.pending.map((s) => (
                '<article class="culture-unknown-card" data-pending-id="' + Number(s.id) + '">' +
                '<div><strong>' + escapeHtml(s.name) + '</strong>' +
                '<p class="muted">' + escapeHtml(s.code || 'No ID') + '</p></div>' +
                '<div class="row-actions">' +
                '<button type="button" class="btn btn-sm btn-ghost" data-pending-to="male">Male</button>' +
                '<button type="button" class="btn btn-sm btn-ghost" data-pending-to="female">Female</button>' +
                '</div></article>'
            )).join('');
        };

        const renderResults = () => {
            const query = (searchInput?.value || '').trim().toLowerCase();
            const taken = takenIds();
            root.querySelectorAll('[data-culture-lane-panel]').forEach((panel) => {
                const key = panel.getAttribute('data-culture-lane-panel');
                const results = panel.querySelector('[data-culture-results]');
                const label = panel.querySelector('[data-culture-results-label]');
                const empty = panel.querySelector('[data-culture-results-empty]');
                const active = key === lane;
                if (!active || query === '') {
                    if (results) results.innerHTML = '';
                    if (label) label.hidden = true;
                    if (empty) empty.hidden = true;
                    return;
                }
                const matches = roster.filter((s) => {
                    if (taken.has(Number(s.id))) return false;
                    if (s.busyGroup) return false;
                    const hay = ((s.name || '') + ' ' + (s.code || '') + ' ' + (s.section || '')).toLowerCase();
                    return hay.includes(query);
                }).slice(0, 40);
                if (label) label.hidden = false;
                if (empty) empty.hidden = matches.length > 0;
                if (results) {
                    results.innerHTML = matches.map((s) => cardHtml(s, { checked: false })).join('');
                }
            });
        };

        const renderAll = () => {
            renderLane('male');
            renderLane('female');
            renderPending();
            renderResults();
            syncHidden();
        };

        const removeId = (id) => {
            const num = Number(id);
            selected.male = selected.male.filter((s) => Number(s.id) !== num);
            selected.female = selected.female.filter((s) => Number(s.id) !== num);
            selected.pending = selected.pending.filter((s) => Number(s.id) !== num);
        };

        const addStudent = (student, toLane) => {
            if (!student || (toLane !== 'male' && toLane !== 'female')) return;
            if (student.busyGroup) return;
            removeId(student.id);
            selected[toLane].push({
                id: Number(student.id),
                name: student.name || '',
                code: student.code || '',
            });
            renderAll();
        };

        root.querySelectorAll('[data-culture-lane]').forEach((btn) => {
            btn.addEventListener('click', () => {
                lane = btn.getAttribute('data-culture-lane') === 'female' ? 'female' : 'male';
                root.querySelectorAll('[data-culture-lane]').forEach((el) => {
                    el.classList.toggle('is-active', el === btn);
                });
                renderResults();
            });
        });

        const runSearch = () => renderResults();
        searchInput?.addEventListener('input', runSearch);
        searchInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                runSearch();
            }
        });
        root.querySelector('[data-culture-live-search-btn]')?.addEventListener('click', runSearch);

        root.addEventListener('change', (event) => {
            const input = event.target.closest('input[data-culture-id]');
            if (!input) return;
            const id = Number(input.getAttribute('data-culture-id'));
            const panel = input.closest('[data-culture-lane-panel]');
            const panelLane = panel?.getAttribute('data-culture-lane-panel');
            if (!input.checked) {
                removeId(id);
                renderAll();
                return;
            }
            const student = roster.find((s) => Number(s.id) === id)
                || selected.male.find((s) => Number(s.id) === id)
                || selected.female.find((s) => Number(s.id) === id)
                || selected.pending.find((s) => Number(s.id) === id);
            addStudent(student, panelLane || lane);
        });

        pendingBox?.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-pending-to]');
            if (!btn) return;
            const card = btn.closest('[data-pending-id]');
            const id = Number(card?.getAttribute('data-pending-id'));
            const student = selected.pending.find((s) => Number(s.id) === id)
                || roster.find((s) => Number(s.id) === id);
            addStudent(student, btn.getAttribute('data-pending-to'));
        });

        renderAll();
    });

    const reportForm = document.querySelector('[data-report-form]');
    reportForm?.addEventListener('change', (event) => {
        if (event.target.matches('[data-report-refresh]')) {
            reportForm.requestSubmit();
        }
    });
})();
