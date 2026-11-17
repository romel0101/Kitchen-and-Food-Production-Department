/*
 * Real-time filtering for the list pages (Expiry, Inventory, Waste).
 *
 * The server still does the filtering, so totals, sorting, pagination and the print report stay correct. This script
 * only removes the page reload: it fetches the filtered page in the background and swaps the results into place while
 * you type. The search box is never replaced, so focus and cursor stay where they are.
 *
 *   <form id="filterForm" data-live="#cards,#results"> ...inputs... </form>
 *
 *   data-live lists the regions to refresh from every response. Links inside those regions (and the Clear link in the
 *   form) that point at the same page - sort headers, pagination, metric cards - load in place too.
 *   If anything unexpected happens (session expired, server error) it falls back to a normal page load.
 */
(function () {
    'use strict';
    var form = document.getElementById('filterForm');
    if (!form || !form.dataset.live || !window.fetch || !window.URLSearchParams || !window.AbortController) return;

    var selectors = form.dataset.live.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var pageName  = new FormData(form).get('page');
    var controller = null, timer = null, seq = 0;

    function regions() {
        return selectors.map(function (sel) { return document.querySelector(sel); }).filter(Boolean);
    }

    // The address for the current form values. Empty values and "All" defaults are left out to keep it short.
    function urlFromForm() {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            if (key === 'csrf') return;
            value = String(value).trim();
            if (value === '') return;
            var el = form.querySelector('[name="' + key + '"]');
            if (key !== 'page' && el && el.tagName === 'SELECT' && el.selectedIndex === 0 && el.options[0].value === value) return;   // first option = "no filter"
            params.set(key, value);
        });
        return location.pathname + '?' + params.toString();
    }

    // Puts the controls back in line with an address (after a link click or the browser's back button).
    function syncForm(url) {
        var params = new URL(url, location.href).searchParams;
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.name === 'csrf' || el.name === 'page') return;
            if (el.type === 'hidden') { el.value = params.get(el.name) || ''; return; }
            if (params.has(el.name)) el.value = params.get(el.name);
            else el.value = el.tagName === 'SELECT' ? (el.options[0] ? el.options[0].value : '') : '';
        });
    }

    function setBusy(on) {
        regions().forEach(function (r) {
            r.classList.toggle('opacity-60', on);
            r.setAttribute('aria-busy', on ? 'true' : 'false');
        });
    }

    // mode: 'replace' while typing, 'push' for deliberate clicks, 'none' for the back button.
    function load(url, mode) {
        var mine = ++seq;
        if (controller) controller.abort();
        controller = new AbortController();
        setBusy(true);

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
            .then(function (res) {
                if (!res.ok || (res.redirected && /login/i.test(res.url))) throw new Error('fallback');
                return res.text();
            })
            .then(function (html) {
                if (mine !== seq) return;                                   // a newer request is already on its way
                var doc   = new DOMParser().parseFromString(html, 'text/html');
                var fresh = selectors.map(function (sel) { return doc.querySelector(sel); });
                if (fresh.some(function (f) { return !f; })) throw new Error('fallback');
                selectors.forEach(function (sel, i) { document.querySelector(sel).innerHTML = fresh[i].innerHTML; });
                if (mode === 'push')         history.pushState(null, '', url);
                else if (mode === 'replace') history.replaceState(null, '', url);
                setBusy(false);
                document.dispatchEvent(new CustomEvent('live-filter:updated'));
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                window.location.href = url;                                 // anything unexpected: a normal page load
            });
    }

    form.addEventListener('submit', function (e) {                          // Enter in the search box
        e.preventDefault();
        clearTimeout(timer);
        load(urlFromForm(), 'push');
    });
    form.addEventListener('input', function (e) {                           // typing: short pause, then refresh
        if (!e.target.matches('input[type="search"], input[type="text"]')) return;
        clearTimeout(timer);
        timer = setTimeout(function () { load(urlFromForm(), 'replace'); }, 180);
    });
    form.addEventListener('change', function (e) {                          // dropdowns and dates: immediately
        if (!e.target.matches('select, input[type="date"]')) return;
        clearTimeout(timer);
        load(urlFromForm(), 'push');
    });

    document.addEventListener('click', function (e) {                       // sort headers, pagination, cards, Clear
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
        if (!form.contains(a) && !regions().some(function (r) { return r.contains(a); })) return;
        var u = new URL(a.href, location.href);
        if (u.origin !== location.origin || u.searchParams.get('page') !== pageName || u.searchParams.has('action')) return;
        e.preventDefault();
        clearTimeout(timer);
        syncForm(u.href);
        load(u.pathname + u.search, 'push');
    });

    window.addEventListener('popstate', function () {
        clearTimeout(timer);
        syncForm(location.href);
        load(location.pathname + location.search, 'none');
    });
})();
