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
        }, {
            confirmText: entry.cta_label,
            cancelText: close_label(),
            onCancel: function () {
                // In-place block page: leave this URL so Back isn't stuck on a dead end.
                if (!dd_gate.block_page) {
                    return;
                }
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.replace('/');
                }
            }
        });
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

    function scrub_dd_gate_param() {
        try {
            var url = new URL(window.location.href);
            if (!url.searchParams.has('dd_gate')) {
                return null;
            }
            var reason = url.searchParams.get('dd_gate');
            url.searchParams.delete('dd_gate');
            window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
            return reason;
        } catch (e) {
            return null;
        }
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
        if (dd_gate.notice) {
            show_gate_popup(dd_gate.notice);
            scrub_dd_gate_param();
            return;
        }

        // Legacy bounce landings (?dd_gate=login) stay in history forever. A logged-in
        // visitor pressing Back lands here and used to see "please log in" again —
        // scrub the flag and step forward to the page they came from.
        var staleReason = null;
        try {
            staleReason = new URL(window.location.href).searchParams.get('dd_gate');
        } catch (e) { /* ignore */ }

        scrub_dd_gate_param();

        if (staleReason === 'login' && dd_gate.logged_in) {
            try {
                window.history.forward();
            } catch (e) { /* ignore */ }
        }
    });
})();
