/*
|------------------------------------------------------------------------------
| location-suggest.js
|------------------------------------------------------------------------------
|
| Type-ahead suggestions for County, Sub-County and Postal Code.
|
| The fields stay ordinary text inputs - staff can still type anything, which
| is the behaviour create.php deliberately chose. All this does is attach a
| <datalist> so the browser proposes correct spellings while they type.
|
| Covers three places at once:
|   1. the head office address fields (#county, #sub_county, #postal_code)
|   2. the parent organization fields, if present
|   3. the per-county operating-location rows, which are built by JS after
|      this script runs - handled by delegation, so no extra wiring is needed
|      when a county is ticked.
|
| Expects window.KENYA_LOCATIONS, injected by the page:
|   { counties: [...], subCounties: { "Kiambu": [...] }, postal: [{code,label}] }
|
| Degrades silently: no data, no suggestions, no errors, form unchanged.
*/
(function () {
    'use strict';

    var DATA = window.KENYA_LOCATIONS || {};
    var COUNTIES = DATA.counties || [];
    var SUBS = DATA.subCounties || {};
    var POSTAL = DATA.postal || [];

    if (!COUNTIES.length) {
        return;
    }

    /* Mirrors slugifyCounty() in create.php. */
    function slugifyCounty(county) {
        return String(county)
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    /* Mirrors normalizeCountyName() in create.php, for loose matching. */
    function normalizeCounty(county) {
        return String(county)
            .trim()
            .replace(/\s+/g, ' ')
            .toLowerCase()
            .replace(/\s+county$/, '')
            .replace(/\s+city$/, '')
            .replace(/[^a-z0-9]+/g, '');
    }

    var bySlug = {};
    var byNormalized = {};

    COUNTIES.forEach(function (name) {
        bySlug[slugifyCounty(name)] = name;
        byNormalized[normalizeCounty(name)] = name;
    });

    /* ---------------------------------------------------------------- */

    function makeList(id, values, labelled) {
        var existing = document.getElementById(id);

        if (existing) {
            existing.innerHTML = '';
        } else {
            existing = document.createElement('datalist');
            existing.id = id;
            document.body.appendChild(existing);
        }

        values.forEach(function (entry) {
            var option = document.createElement('option');

            if (labelled) {
                option.value = entry.code;
                option.label = entry.label;
                option.textContent = entry.label;
            } else {
                option.value = entry;
            }

            existing.appendChild(option);
        });

        return existing;
    }

    function attach(input, listId) {
        if (input && !input.disabled) {
            input.setAttribute('list', listId);
            input.setAttribute('autocomplete', 'off');
        }
    }

    /* ---- shared lists ------------------------------------------------ */

    makeList('kenya-county-list', COUNTIES, false);

    if (POSTAL.length) {
        makeList('kenya-postal-list', POSTAL, true);
    }

    /* Sub-county lists are built per county, once, on first use. */
    var builtSubLists = {};

    function subListFor(countyValue) {
        var name = byNormalized[normalizeCounty(countyValue)];

        if (!name || !SUBS[name] || !SUBS[name].length) {
            return null;
        }

        var id = 'kenya-sub-list-' + slugifyCounty(name);

        if (!builtSubLists[id]) {
            makeList(id, SUBS[name], false);
            builtSubLists[id] = true;
        }

        return id;
    }

    /* ---- head office + parent organization --------------------------- */

    var countyInput = document.getElementById('county');
    var subCountyInput = document.getElementById('sub_county');
    var postalInput = document.getElementById('postal_code');

    attach(countyInput, 'kenya-county-list');

    if (POSTAL.length) {
        attach(postalInput, 'kenya-postal-list');
        attach(document.getElementById('parent_postal_code'), 'kenya-postal-list');
    }

    function refreshHeadOfficeSubCounty() {
        if (!countyInput || !subCountyInput) {
            return;
        }

        var listId = subListFor(countyInput.value);

        if (listId) {
            attach(subCountyInput, listId);
            subCountyInput.placeholder = 'Start typing, or pick from the list';
        } else {
            subCountyInput.removeAttribute('list');
            subCountyInput.placeholder = 'Enter Sub-County';
        }
    }

    if (countyInput && subCountyInput) {
        countyInput.addEventListener('input', refreshHeadOfficeSubCounty);
        countyInput.addEventListener('change', refreshHeadOfficeSubCounty);
        refreshHeadOfficeSubCounty();
    }

    /* ---- operating-location rows (added dynamically) ------------------ */

    /*
    | Those inputs are named operating_locations[<key>][<field>].
    |
    | On create.php the key is a county slug, so the county is known from the
    | markup. On edit.php and edit-submitted.php the rows are numbered
    | (operating_locations[0][county]) and the county is a free-text box in
    | the same row - so fall back to reading that box's current value.
    */

    function rowCountyValue(field) {
        var match = field.name.match(/^operating_locations\[([^\]]+)\]\[/);

        if (!match) {
            return '';
        }

        var sibling = document.getElementsByName(
            'operating_locations[' + match[1] + '][county]'
        )[0];

        return sibling ? sibling.value : '';
    }

    function wireOperatingField(field) {
        if (!field || !field.name) {
            return;
        }

        var match = field.name.match(/^operating_locations\[([^\]]+)\]\[([^\]]+)\]$/);

        if (!match) {
            return;
        }

        var key = match[2];

        /*
        | The county box itself: the same 47 names as everywhere else.
        | Wired once - the list never changes.
        */
        if (key === 'county') {
            if (field.dataset.kenyaWired !== '1') {
                attach(field, 'kenya-county-list');
                field.dataset.kenyaWired = '1';
            }

            return;
        }

        if (key === 'postal_code') {
            if (POSTAL.length && field.dataset.kenyaWired !== '1') {
                attach(field, 'kenya-postal-list');
                field.dataset.kenyaWired = '1';
            }

            return;
        }

        if (key !== 'sub_county') {
            return;
        }

        /*
        | Sub-county is NOT wired once and left alone: the staff member can
        | change the county in the same row afterwards, and the old county's
        | list would then be wrong. Re-resolve every time.
        */
        var countyName = bySlug[match[1]] || rowCountyValue(field);
        var listId = countyName ? subListFor(countyName) : null;

        if (listId) {
            attach(field, listId);
        } else {
            field.removeAttribute('list');
        }
    }

    document.addEventListener('focusin', function (event) {
        wireOperatingField(event.target);
    });

    /*
    | Typing a county into a numbered row re-points that row's sub-county
    | list at the right county.
    */
    document.addEventListener('input', function (event) {
        var field = event.target;

        if (
            !field
            || !field.name
            || !/^operating_locations\[[^\]]+\]\[county\]$/.test(field.name)
        ) {
            return;
        }

        var key = field.name.match(/^operating_locations\[([^\]]+)\]/)[1];

        var sub = document.getElementsByName(
            'operating_locations[' + key + '][sub_county]'
        )[0];

        if (sub) {
            wireOperatingField(sub);
        }
    });

    /* Catch rows that appear without ever being focused (restored drafts). */
    if (typeof MutationObserver === 'function') {
        var host = document.getElementById('operating-location-fields')
            || document.querySelector('[data-operating-location]')
            || document.body;

        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }

                    if (node.matches && node.matches('input')) {
                        wireOperatingField(node);
                    }

                    if (node.querySelectorAll) {
                        Array.prototype.forEach.call(
                            node.querySelectorAll('input'),
                            wireOperatingField
                        );
                    }
                });
            });
        }).observe(host, { childList: true, subtree: true });
    }
}());
