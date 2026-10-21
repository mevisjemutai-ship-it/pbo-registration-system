/*
|------------------------------------------------------------------------------
| password-reveal.js
|------------------------------------------------------------------------------
|
| Adds a "show / hide" button to every password box on the page.
|
| Staff were typing a password they could not see, getting it wrong, and being
| locked out - worse on the change-password and reset-password pages, where two
| boxes have to match and neither is visible.
|
| Nothing is hard-coded: the script finds input[type="password"] itself, so any
| password field added later gets the button with no extra work.
|
| Deliberate behaviour:
|   - the field goes back to hidden when the form is submitted, so a revealed
|     password is never left on screen afterwards;
|   - the button is type="button", so it can never submit the form by accident;
|   - it is skipped on fields marked data-no-reveal, if one ever needs that.
|
| Degrades silently: no password fields, nothing happens.
*/
(function () {
    'use strict';

    var EYE_OPEN =
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        + '<path d="M12 5c-5 0-9 4.6-10 7 1 2.4 5 7 10 7s9-4.6 10-7c-1-2.4-5-7-10-7Z'
        + 'm0 11.5A4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 0 1 0 9Zm0-2a2.5 2.5 0 1 0 0-5 '
        + '2.5 2.5 0 0 0 0 5Z"/></svg>';

    var EYE_SHUT =
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        + '<path d="M2.1 3.5 3.5 2.1l18.4 18.4-1.4 1.4-3.3-3.3A11.6 11.6 0 0 1 12 19'
        + 'c-5 0-9-4.6-10-7a14.6 14.6 0 0 1 4.3-5.2L2.1 3.5Zm5.6 5.6A4.5 4.5 0 0 0 '
        + '14.9 16.3l-1.5-1.5a2.5 2.5 0 0 1-3.2-3.2L7.7 9.1ZM12 5c5 0 9 4.6 10 7a14.7'
        + ' 14.7 0 0 1-3 4l-1.5-1.4A4.5 4.5 0 0 0 11.4 7.6L9.9 6.1A11.4 11.4 0 0 1 12'
        + ' 5Z"/></svg>';

    function label(shown) {
        return shown ? 'Hide password' : 'Show password';
    }

    function attach(input) {
        if (
            !input
            || input.dataset.revealWired === '1'
            || input.hasAttribute('data-no-reveal')
        ) {
            return;
        }

        input.dataset.revealWired = '1';

        var wrap = document.createElement('span');
        wrap.className = 'password-field';

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-reveal';
        button.innerHTML = EYE_OPEN;
        button.setAttribute('aria-label', label(false));
        button.setAttribute('aria-pressed', 'false');
        button.title = label(false);

        function setShown(shown) {
            input.type = shown ? 'text' : 'password';
            button.innerHTML = shown ? EYE_SHUT : EYE_OPEN;
            button.setAttribute('aria-label', label(shown));
            button.setAttribute('aria-pressed', shown ? 'true' : 'false');
            button.title = label(shown);
        }

        button.addEventListener('click', function () {
            var shown = input.type === 'text';

            setShown(!shown);

            /* Keep the caret where it was - the field keeps focus. */
            input.focus();
        });

        wrap.appendChild(button);

        /* Never leave a password on screen after the form goes. */
        if (input.form) {
            input.form.addEventListener('submit', function () {
                setShown(false);
            });
        }
    }

    function scan(root) {
        var fields = (root || document).querySelectorAll('input[type="password"]');

        Array.prototype.forEach.call(fields, attach);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            scan(document);
        });
    } else {
        scan(document);
    }

    /* Password boxes that appear later (modals, added rows). */
    if (typeof MutationObserver === 'function') {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }

                    if (node.matches && node.matches('input[type="password"]')) {
                        attach(node);
                    }

                    if (node.querySelectorAll) {
                        scan(node);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
}());
