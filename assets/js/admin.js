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

    const reportForm = document.querySelector('[data-report-form]');
    reportForm?.addEventListener('change', (event) => {
        if (event.target.matches('[data-report-refresh]')) {
            reportForm.requestSubmit();
        }
    });
})();
