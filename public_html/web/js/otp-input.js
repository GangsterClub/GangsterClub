document.addEventListener("DOMContentLoaded", (event) => {
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
    const handlePaste = (evt) => {
        evt.preventDefault();
        const pastedText = evt.clipboardData.getData('text').trim();
        if (!/^[0-9]{6}$/.test(pastedText)) { // Change {6} if needed.
            return;
        }
        const digits = pastedText.split('');
        let inputs = document.querySelectorAll('[data-focus-input-init]');
        inputs.forEach((input, index) => input.value = digits[index]);
        inputs[inputs.length - 1].focus();
    };

    // Apply listeners and "hack" value afterwards
    document.querySelectorAll('[data-focus-input-init]').forEach(function(element) {
        element.addEventListener('paste', handlePaste);
        element.addEventListener('keyup', function() {
            const prevId = this.getAttribute('data-focus-input-prev');
            const nextId = this.getAttribute('data-focus-input-next');
            focusNextInput(this, prevId, nextId);
        });

        // Hacking the values after applying the listeners makes focusNextInput() work as intended.
        element.value = element.getAttribute('placeholder');
    });
});
