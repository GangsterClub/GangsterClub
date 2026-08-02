document.addEventListener('DOMContentLoaded', () => {
    const statusFor = (form) => {
        const dialogStatus = form.closest('dialog')?.querySelector('[data-modal-status]');
        return dialogStatus || document.getElementById('recovery-flow-status');
    };

    const showStatus = (status, success, message) => {
        if (!status) {
            return;
        }

        status.textContent = message || '';
        status.className = success
            ? 'mb-4 rounded border border-green-500 bg-green-50 p-3 text-sm text-green-900 dark:bg-green-950/30 dark:text-green-100'
            : 'mb-4 rounded border border-red-500 bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950/30 dark:text-red-100';
        status.focus();
    };

    const setBusy = (form, busy, submitter = null) => {
        const controls = Array.from(form.querySelectorAll('button, input, select, textarea'));
        controls.forEach((control) => {
            control.disabled = busy;
        });
        form.toggleAttribute('aria-busy', busy);

        if (!submitter) {
            return controls;
        }

        if (busy) {
            submitter.dataset.originalLabel = submitter.textContent || '';
            const spinner = document.createElement('span');
            spinner.className = 'h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent';
            spinner.setAttribute('aria-hidden', 'true');
            const pendingLabel =
                form.dataset.pendingLabel ||
                form.closest('dialog')?.dataset.pendingLabel ||
                submitter.dataset.originalLabel;
            submitter.replaceChildren(spinner, document.createTextNode(pendingLabel));
        } else if (submitter.dataset.originalLabel) {
            submitter.textContent = submitter.dataset.originalLabel;
            delete submitter.dataset.originalLabel;
        }

        return controls;
    };

    const bindForm = (form) => {
        if (form.dataset.ajaxBound === 'true') {
            return;
        }
        form.dataset.ajaxBound = 'true';

        form.addEventListener('submit', async (event) => {
            if (!window.fetch || !form.reportValidity()) {
                return;
            }

            event.preventDefault();

            const status = statusFor(form);
            const submitter =
                event.submitter instanceof HTMLButtonElement ||
                event.submitter instanceof HTMLInputElement
                    ? event.submitter
                    : null;

            // Build this before disabling controls.
            const formData = new FormData(form);

            // Include the clicked submit button, because FormData(form) omits it.
            if (submitter?.name) {
                formData.set(submitter.name, submitter.value);
            }

            const csrfToken =
                formData.get('_csrf_token')?.toString() || '';

            const controls = setBusy(form, true, submitter);

            try {
                const actionUrl = form.getAttribute('action') || window.location.href;
                const response = await fetch(actionUrl, {
                    method: form.method || 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                const contentType = response.headers.get('content-type') || '';

                if (!contentType.includes('application/json')) {
                    throw new Error(`Expected JSON, received HTTP ${response.status}`);
                }

                const payload = await response.json();

                if (![200, 422, 429].includes(response.status)) {
                    throw new Error(`Unexpected HTTP ${response.status}`);
                }

                showStatus(status, Boolean(payload.success), payload.message);

                if (payload.csrfToken) {
                    document
                        .querySelectorAll('input[name="_csrf_token"]')
                        .forEach((input) => {
                            input.value = payload.csrfToken;
                        });
                }

                if (payload.success && Array.isArray(payload.codes)) {
                    renderCodes(payload, payload.csrfToken || csrfToken);
                } else if (payload.success && payload.redirect) {
                    let baseUri = document.querySelector("base").href;
                    baseUri = baseUri.substr(0, baseUri.length-1);
                    const redirectAllowList = [
                        baseUri + '/login/recovery',
                        baseUri + '/account/recovery-codes'
                    ];
                    if (redirectAllowList.indexOf(payload.redirect) > -1) {
                        window.location.assign(payload.redirect);
                    } else {
                        window.location.assign('/login');
                    }
                }
            } catch (error) {
                console.error(error);

                const failureMessage =
                    form.dataset.failureMessage ||
                    form.closest('dialog')?.dataset.failureMessage ||
                    'The request could not be completed. Please try again.';

                showStatus(status, false, failureMessage);
            } finally {
                controls.forEach((control) => {
                    control.disabled = false;
                });

                form.removeAttribute('aria-busy');
                setBusy(form, false, submitter);
            }
        });
    };

    const renderCodes = (payload, csrfToken) => {
        const panel = document.querySelector('#recovery-flow-modal .modal-panel');
        if (!panel) {
            return;
        }

        Array.from(panel.children).forEach((child) => {
            if (child.tagName !== 'HEADER' && !child.matches('[data-modal-status]')) {
                child.remove();
            }
        });

        const warning = document.createElement('p');
        warning.className =
            'my-4 rounded border border-red-400 bg-red-50 p-4 font-bold text-red-900 dark:bg-red-950/30 dark:text-red-100';
        warning.textContent = payload.displayWarning || '';
        panel.appendChild(warning);

        const list = document.createElement('ol');
        list.className =
            'my-5 grid gap-2 rounded border border-gray-300 p-4 font-mono text-lg sm:grid-cols-2 dark:border-gray-700';
        list.setAttribute('aria-label', payload.codesListLabel || '');
        payload.codes.forEach((code) => {
            const item = document.createElement('li');
            const codeElement = document.createElement('code');
            codeElement.textContent = String(code);
            item.appendChild(codeElement);
            list.appendChild(item);
        });
        panel.appendChild(list);

        const acknowledgement = document.createElement('form');
        acknowledgement.method = 'POST';
        acknowledgement.className = 'space-y-4';
        acknowledgement.innerHTML =
            '<input type="hidden" name="_csrf_token">' +
            '<input type="hidden" name="action" value="acknowledge">' +
            '<label class="flex items-start gap-2"><input type="checkbox" name="saved_codes" value="1" required>' +
            '<span></span></label>' +
            '<button class="btn-primary w-full" type="submit"></button>';
        acknowledgement.querySelector('input[name="_csrf_token"]').value = csrfToken;
        acknowledgement.querySelector('span').textContent = payload.acknowledgementLabel || '';
        acknowledgement.querySelector('button').textContent = payload.activateLabel || '';
        panel.appendChild(acknowledgement);
        bindForm(acknowledgement);

        const unavailable = document.createElement('form');
        unavailable.method = 'POST';
        unavailable.className = 'mt-3';
        unavailable.innerHTML =
            '<input type="hidden" name="_csrf_token">' +
            '<input type="hidden" name="action" value="codes_unavailable">' +
            '<button class="text-sm underline" type="submit"></button>';
        unavailable.querySelector('input[name="_csrf_token"]').value = csrfToken;
        unavailable.querySelector('button').textContent = payload.unavailableLabel || '';
        panel.appendChild(unavailable);
        bindForm(unavailable);

        const cancel = document.createElement('form');
        cancel.method = 'POST';
        cancel.className = 'mt-3';
        cancel.innerHTML =
            '<input type="hidden" name="_csrf_token">' +
            '<input type="hidden" name="action" value="cancel">' +
            '<button class="btn-secondary w-full" type="submit"></button>';
        cancel.querySelector('input[name="_csrf_token"]').value = csrfToken;
        cancel.querySelector('button').textContent = payload.cancelLabel || '';
        panel.appendChild(cancel);
        bindForm(cancel);

        warning.setAttribute('tabindex', '-1');
        warning.focus();
    };

    document
        .querySelectorAll('[data-recovery-form], [data-security-modal-form]')
        .forEach(bindForm);

    document
        .querySelectorAll('[data-js-only]')
        .forEach((element) => {
            element.hidden = false;
        });
});
