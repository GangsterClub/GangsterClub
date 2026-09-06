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
                    const configuredBase = new URL(
                        document.querySelector('base').href,
                        window.location.href
                    );
                    const basePath = configuredBase.pathname.endsWith('/')
                        ? configuredBase.pathname
                        : `${configuredBase.pathname}/`;
                    const appBase = new URL(basePath, window.location.origin);
                    const recoveryUrl = new URL('login/recovery', appBase).href;
                    const recoveryCodesUrl = new URL('account/recovery-codes', appBase).href;
                    const loginUrl = new URL('login', appBase).href;
                    let redirectTarget = loginUrl;

                    if (payload.redirect === recoveryUrl) {
                        redirectTarget = recoveryUrl;
                    } else if (payload.redirect === recoveryCodesUrl) {
                        redirectTarget = recoveryCodesUrl;
                    }

                    window.location.assign(redirectTarget);
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
        const template = document.getElementById('recovery-codes-presented-template');
        if (!panel || !(template instanceof HTMLTemplateElement)) {
            throw new Error('Recovery-code modal template is unavailable.');
        }

        const content = template.content.cloneNode(true);
        const list = content.querySelector('[data-recovery-codes-list]');
        const warning = content.querySelector('[data-recovery-codes-warning]');
        const forms = Array.from(content.querySelectorAll('[data-recovery-form]'));
        const csrfInputs = forms.map((form) =>
            form.querySelector('input[name="_csrf_token"]')
        );
        if (!list || !warning || forms.length !== 3 || csrfInputs.some((input) => !input)) {
            throw new Error('Recovery-code modal template is incomplete.');
        }

        payload.codes.forEach((code) => {
            const item = document.createElement('li');
            const codeElement = document.createElement('code');
            codeElement.textContent = String(code);
            item.appendChild(codeElement);
            list.appendChild(item);
        });

        csrfInputs.forEach((input) => {
            input.value = csrfToken;
        });

        Array.from(panel.children).forEach((child) => {
            if (child.tagName !== 'HEADER' && !child.matches('[data-modal-status]')) {
                child.remove();
            }
        });

        panel.appendChild(content);
        forms.forEach(bindForm);
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
