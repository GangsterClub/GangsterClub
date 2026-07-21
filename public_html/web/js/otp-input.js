window.onload = () => {
    // use this simple function to automatically focus on the next input
    function focusNextInput(el, prevId, nextId) {
        if (el.value.length === 0) {
            if (prevId) {
                document.getElementById(prevId).focus();
            }
        }

        if (el.value.length !== 0) {
            if (nextId) {
                document.getElementById(nextId).focus();
            }
        }
    }

    // Handling pasted OTP of 6 digits
    function handlePaste(evt, inputs) {
        evt.preventDefault();

        const pastedText = evt.clipboardData.getData('text').trim();

        if (!/^\d{6}$/.test(pastedText)) {
            return;
        }

        const digits = pastedText.split('');
        const inputElements = Array.from(inputs).filter(
            input => input.tagName.toLowerCase() === 'input'
        );

        if (inputElements.length < digits.length) {
            return;
        }

        const digitIterator = digits.values();

        inputElements.forEach((input) => {
            const { value: digit, done } = digitIterator.next();

            if (done) {
                return;
            }

            if (typeof input.value !== 'undefined' && /^\d$/.test(digit)) {
                input.value = digit;
            }
        });

        inputElements.at(-1)?.focus();
    }

    // Apply listeners and "hack" value afterwards
    document.querySelectorAll('[data-focus-input-init]').forEach(function (element) {
        element.addEventListener('paste', function (evt) {
            handlePaste(evt, document.querySelectorAll('[data-focus-input-init]'));
        });
        element.addEventListener('keyup', function () {
            const prevId = this.getAttribute('data-focus-input-prev');
            const nextId = this.getAttribute('data-focus-input-next');
            focusNextInput(this, prevId, nextId);
        });

        // Hacking the values after applying the listeners makes focusNextInput() work as intended.
        element.value = element.getAttribute('placeholder');
    });
};
