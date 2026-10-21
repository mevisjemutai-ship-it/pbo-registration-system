/*
|------------------------------------------------------------------------------
| registry-export.js
|------------------------------------------------------------------------------
|
| The export and print buttons on the List of PBOs page.
|
| Two scopes, and the page says plainly which one is in force:
|
|   nothing ticked -> everything matching the current filters, however many
|                     pages that runs to. This is what "print the whole
|                     register" means, and it must not quietly mean "print
|                     the 50 rows that happen to be on screen".
|
|   rows ticked    -> only those rows.
|
| Ticks are remembered across pages of the same result set, so somebody can
| work through four pages picking organizations and then print the lot. They
| are dropped when the filters change, because a tick made under one filter
| is not an answer to a different question.
*/
(function () {
    'use strict';

    var root = document.querySelector('[data-registry-actions]');

    if (!root) {
        return;
    }

    var exportUrl = root.getAttribute('data-export-url');
    var filterQuery = root.getAttribute('data-filter-query') || '';
    var total = parseInt(root.getAttribute('data-total') || '0', 10);

    var scopeLabel = root.querySelector('[data-scope-label]');
    var clearButton = root.querySelector('[data-clear-selection]');
    var pageToggle = document.querySelector('[data-select-page]');
    var ticks = Array.prototype.slice.call(
        document.querySelectorAll('[data-registry-tick]')
    );

    /*
    | Selections live in sessionStorage, keyed by the filters: close the
    | tab and they are gone, change the filter and they are gone. Both are
    | the right behaviour for something this short-lived.
    */
    var STORAGE_KEY = 'pbo.registry.selection';

    function storage() {
        try {
            var probe = '__pbo__';
            window.sessionStorage.setItem(probe, '1');
            window.sessionStorage.removeItem(probe);

            return window.sessionStorage;
        } catch (e) {
            return null;
        }
    }

    var store = storage();

    function load() {
        if (!store) {
            return [];
        }

        try {
            var saved = JSON.parse(store.getItem(STORAGE_KEY) || 'null');

            if (!saved || saved.filters !== filterQuery) {
                return [];
            }

            return Array.isArray(saved.ids) ? saved.ids : [];
        } catch (e) {
            return [];
        }
    }

    function save(ids) {
        if (!store) {
            return;
        }

        try {
            if (ids.length === 0) {
                store.removeItem(STORAGE_KEY);
            } else {
                store.setItem(STORAGE_KEY, JSON.stringify({
                    filters: filterQuery,
                    ids: ids
                }));
            }
        } catch (e) {
            /* Storage full or blocked; selection just will not survive paging. */
        }
    }

    var selected = load();

    function isSelected(id) {
        return selected.indexOf(id) !== -1;
    }

    function setSelected(id, on) {
        var at = selected.indexOf(id);

        if (on && at === -1) {
            selected.push(id);
        } else if (!on && at !== -1) {
            selected.splice(at, 1);
        }

        save(selected);
        render();
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : many);
    }

    function render() {
        ticks.forEach(function (tick) {
            tick.checked = isSelected(tick.value);
        });

        var onPage = ticks.length;
        var tickedOnPage = ticks.filter(function (t) { return t.checked; }).length;

        if (pageToggle) {
            pageToggle.checked = onPage > 0 && tickedOnPage === onPage;
            pageToggle.indeterminate = tickedOnPage > 0 && tickedOnPage < onPage;
        }

        if (clearButton) {
            clearButton.hidden = selected.length === 0;
        }

        if (!scopeLabel) {
            return;
        }

        if (selected.length === 0) {
            scopeLabel.textContent =
                'Nothing ticked — actions apply to all '
                + plural(total, 'matching organization', 'matching organizations');
        } else {
            scopeLabel.textContent =
                plural(selected.length, 'organization ticked', 'organizations ticked')
                + ' — actions apply to '
                + (selected.length === 1 ? 'it' : 'those')
                + ' only';
        }
    }

    /* ---- the buttons -------------------------------------------------- */

    function go(what) {
        var parts = what.split('-');
        var query = filterQuery;

        query += (query ? '&' : '')
            + 'mode=' + encodeURIComponent(parts[0])
            + '&format=' + encodeURIComponent(parts[1]);

        if (selected.length > 0) {
            query += '&ids=' + encodeURIComponent(selected.join(','));
        }

        var url = exportUrl + '?' + query;

        if (parts[1] === 'print') {
            /*
            | A new window, so the register stays where it is and the
            | print view can close itself afterwards.
            */
            window.open(url, '_blank', 'noopener');
        } else {
            /* A download: no navigation, the page stays put. */
            window.location.href = url;
        }
    }

    root.querySelectorAll('[data-export]').forEach(function (button) {
        button.addEventListener('click', function () {
            var what = button.getAttribute('data-export');

            /*
            | Full details of a large set is a slow, heavy document. Ask
            | once rather than let somebody start a 500-page print by
            | mistake.
            */
            if (
                what.indexOf('details') === 0
                && selected.length === 0
                && total > 25
            ) {
                var ok = window.confirm(
                    'This will produce the full details of all ' + total
                    + ' organizations, one per page.\n\n'
                    + 'Tick the ones you want first if you only need some.\n\n'
                    + 'Continue?'
                );

                if (!ok) {
                    return;
                }
            }

            go(what);
        });
    });

    /* ---- ticking ------------------------------------------------------- */

    ticks.forEach(function (tick) {
        tick.addEventListener('change', function () {
            setSelected(tick.value, tick.checked);
        });
    });

    if (pageToggle) {
        pageToggle.addEventListener('change', function () {
            var on = pageToggle.checked;

            ticks.forEach(function (tick) {
                var at = selected.indexOf(tick.value);

                if (on && at === -1) {
                    selected.push(tick.value);
                } else if (!on && at !== -1) {
                    selected.splice(at, 1);
                }
            });

            save(selected);
            render();
        });
    }

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            selected = [];
            save(selected);
            render();
        });
    }

    render();
}());
