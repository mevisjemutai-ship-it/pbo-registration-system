/*
|------------------------------------------------------------------------------
| form-autosave.js
|------------------------------------------------------------------------------
|
| Keeps a copy of whatever is typed into a wizard page, in the browser, and
| offers it back if the page is reached again with the work missing.
|
| Why
| ---
| Staff lost work in four different ways during testing: a save that was
| refused and discarded the page, a session that ended mid-application, a
| POST the server rejected outright, and a sign-out that left no draft. In
| every one of them the typing was gone and had to be done again.
|
| Those four have four different causes and four different fixes. This is
| the one thing that helps in all of them, because it does not depend on the
| server working: the moment a field is typed into, a copy is in the
| browser, and it survives anything that happens to the request afterwards.
|
| What it does NOT do
| -------------------
| It is not a substitute for saving. The copy lives in one browser on one
| machine, nobody else can see it, and it is not a record of anything. It
| buys back the typing, not the save.
|
| How it behaves
| --------------
|   - writes a copy about a second after typing stops;
|   - on load, compares the copy with what the server rendered. If the copy
|     holds something the page does not, it offers to restore it - it never
|     overwrites the page on its own, because the server's version is the
|     saved one and silently replacing it would be worse than the problem;
|   - clears the copy once a save has gone through;
|   - never stores passwords, file inputs, or the security token.
|
| Requires: a form carrying data-autosave="<a key unique to this page and
| application>". Without it the script does nothing.
*/
(function () {
    'use strict';

    var SAVE_DELAY_MS = 900;
    var KEY_PREFIX = 'pbo.autosave.';
    var MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

    /*
    | Browser storage throws rather than returning null in a private window
    | and where site data is blocked, so every touch of it is wrapped. A
    | portal that breaks because a convenience is unavailable is worse than
    | one without the convenience.
    */
    function store() {
        try {
            var test = '__pbo__';
            window.localStorage.setItem(test, '1');
            window.localStorage.removeItem(test);

            return window.localStorage;
        } catch (e) {
            return null;
        }
    }

    var storage = store();

    if (!storage) {
        return;
    }

    var form = document.querySelector('form[data-autosave]');

    if (!form) {
        return;
    }

    var key = KEY_PREFIX + form.getAttribute('data-autosave');

    /* ---- what counts as worth keeping -------------------------------- */

    function skip(field) {
        if (!field.name || field.disabled) {
            return true;
        }

        if (field.type === 'password' || field.type === 'file') {
            return true;
        }

        /* The token is tied to a session; a stale one is worse than none. */
        if (field.name === 'csrf_token') {
            return true;
        }

        /* Hidden control fields describing the submit, not the data. */
        if (field.name === 'form_action' || field.name === 'wizard_destination') {
            return true;
        }

        return false;
    }

    function collect() {
        var data = {};

        Array.prototype.forEach.call(form.elements, function (field) {
            if (skip(field)) {
                return;
            }

            if (field.type === 'checkbox' || field.type === 'radio') {
                if (field.checked) {
                    (data[field.name] = data[field.name] || []).push(field.value);
                }

                return;
            }

            data[field.name] = field.value;
        });

        return data;
    }

    /* Is there anything in here a person actually typed? */
    function hasContent(data) {
        return Object.keys(data).some(function (name) {
            var value = data[name];

            if (Array.isArray(value)) {
                return value.length > 0;
            }

            return String(value || '').trim() !== '';
        });
    }

    /* ---- writing ------------------------------------------------------ */

    var timer = null;

    function write() {
        var data = collect();

        if (!hasContent(data)) {
            return;
        }

        try {
            storage.setItem(key, JSON.stringify({
                savedAt: Date.now(),
                fields: data
            }));
        } catch (e) {
            /* Quota, or storage disabled mid-session. Nothing to do. */
        }
    }

    function scheduleWrite() {
        window.clearTimeout(timer);
        timer = window.setTimeout(write, SAVE_DELAY_MS);
    }

    function clear() {
        try {
            storage.removeItem(key);
        } catch (e) {
            /* Nothing to do. */
        }
    }

    /* ---- reading back ------------------------------------------------- */

    function read() {
        var raw;

        try {
            raw = storage.getItem(key);
        } catch (e) {
            return null;
        }

        if (!raw) {
            return null;
        }

        var saved;

        try {
            saved = JSON.parse(raw);
        } catch (e) {
            clear();

            return null;
        }

        if (
            !saved
            || typeof saved !== 'object'
            || !saved.fields
            || Date.now() - (saved.savedAt || 0) > MAX_AGE_MS
        ) {
            clear();

            return null;
        }

        return saved;
    }

    function apply(fields) {
        Array.prototype.forEach.call(form.elements, function (field) {
            if (skip(field) || !(field.name in fields)) {
                return;
            }

            var value = fields[field.name];

            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = Array.isArray(value)
                    && value.indexOf(field.value) !== -1;
            } else {
                field.value = value;
            }

            /*
            | Tell the page's own scripts: county cards, the international
            | section and the KRA PIN rule all react to these events, and
            | setting .value in code fires neither on its own.
            */
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    /*
    | What the saved copy holds that the page, as the server rendered it,
    | does not. This is the whole judgement: if the server already has the
    | work, there is nothing to offer and nothing to interrupt anyone about.
    */
    function missingFromPage(fields) {
        var onPage = collect();
        var missing = 0;

        Object.keys(fields).forEach(function (name) {
            var savedValue = fields[name];
            var pageValue = onPage[name];

            if (Array.isArray(savedValue)) {
                if (savedValue.length && !(pageValue || []).length) {
                    missing++;
                }

                return;
            }

            if (
                String(savedValue || '').trim() !== ''
                && String(pageValue || '').trim() === ''
            ) {
                missing++;
            }
        });

        return missing;
    }

    function when(timestamp) {
        var minutes = Math.round((Date.now() - timestamp) / 60000);

        if (minutes < 1) {
            return 'a moment ago';
        }

        if (minutes < 60) {
            return minutes + (minutes === 1 ? ' minute ago' : ' minutes ago');
        }

        var hours = Math.round(minutes / 60);

        if (hours < 24) {
            return hours + (hours === 1 ? ' hour ago' : ' hours ago');
        }

        return 'on ' + new Date(timestamp).toLocaleDateString();
    }

    function offer(saved, count) {
        var bar = document.createElement('div');
        bar.className = 'autosave-offer';

        var text = document.createElement('span');
        text.textContent =
            'This page has ' + count + (count === 1 ? ' entry' : ' entries')
            + ' you typed ' + when(saved.savedAt)
            + ' that were never saved. Restore them?';

        var restore = document.createElement('button');
        restore.type = 'button';
        restore.className = 'btn btn-primary autosave-restore';
        restore.textContent = 'Restore what I typed';

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'btn btn-secondary autosave-dismiss';
        dismiss.textContent = 'Discard';

        restore.addEventListener('click', function () {
            apply(saved.fields);
            bar.remove();
        });

        dismiss.addEventListener('click', function () {
            clear();
            bar.remove();
        });

        bar.appendChild(text);
        bar.appendChild(restore);
        bar.appendChild(dismiss);

        form.parentNode.insertBefore(bar, form);
    }

    /* ---- wiring -------------------------------------------------------- */

    form.addEventListener('input', scheduleWrite);
    form.addEventListener('change', scheduleWrite);

    /*
    | Write immediately on the way out rather than waiting for the timer:
    | a submit that the server refuses still lands back on this page, and
    | the copy has to already exist by then.
    */
    form.addEventListener('submit', write);
    window.addEventListener('pagehide', write);

    /*
    | A save that went through tells us so: the page sets
    | data-autosave-saved="1" when the server confirmed it. Only then is
    | the copy safe to drop.
    */
    if (form.getAttribute('data-autosave-saved') === '1') {
        clear();

        return;
    }

    var saved = read();

    if (saved) {
        var count = missingFromPage(saved.fields);

        if (count > 0) {
            offer(saved, count);
        } else {
            /* The server has it. The copy has served its purpose. */
            clear();
        }
    }
}());
