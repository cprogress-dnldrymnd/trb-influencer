(function () {
    'use strict';

    if (typeof dd_gate === 'undefined' || !dd_gate.enabled) {
        return;
    }

    function close_label() {
        return (typeof dd_messages !== 'undefined' && dd_messages.dd_msg_gate_close) || 'Close';
    }

    function show_gate_popup(entry) {
        window.ddConfirm(entry.message, function () {
            window.location.replace(entry.cta_url);
        }, { confirmText: entry.cta_label, cancelText: close_label() });
    }

    function normalize_path(pathname) {
        if (pathname === '') {
            pathname = '/';
        }
        return pathname.charAt(pathname.length - 1) === '/' ? pathname : pathname + '/';
    }

    function find_gate_entry(pathname) {
        if (Object.prototype.hasOwnProperty.call(dd_gate.paths, pathname)) {
            return dd_gate.paths[pathname];
        }
        for (var i = 0; i < dd_gate.prefixes.length; i++) {
            var rule = dd_gate.prefixes[i];
            if (pathname.indexOf(rule.prefix) === 0) {
                return rule;
            }
        }
        return null;
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target.closest ? event.target.closest('a[href]') : null;
        if (!link) {
            return;
        }

        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 ||
            href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0 || link.target === '_blank') {
            return;
        }

        var url;
        try {
            url = new URL(href, window.location.href);
        } catch (e) {
            return;
        }

        if (url.origin !== window.location.origin) {
            return;
        }

        var entry = find_gate_entry(normalize_path(url.pathname));
        if (!entry) {
            return;
        }

        event.preventDefault();
        show_gate_popup(entry);
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (!dd_gate.notice) {
            return;
        }

        show_gate_popup(dd_gate.notice);

        var url = new URL(window.location.href);
        url.searchParams.delete('dd_gate');
        window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
    });
})();
