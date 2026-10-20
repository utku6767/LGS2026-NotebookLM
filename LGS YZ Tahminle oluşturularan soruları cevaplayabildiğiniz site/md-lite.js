/*
 * md-lite.js — Markdown-flavoured question renderer for the LGS files.
 *
 * main/*.txt and lgs/*.txt are written as light Markdown: **bold**, *italic*,
 * "- " / "* " lists, "---" rules, trailing "\" hard breaks and $...$ LaTeX.
 * Dropping that text straight into innerHTML left the markers visible and,
 * worse, let the browser eat everything after a "<" (e.g. $1610<x<3200$).
 *
 * Everything is HTML-escaped first (so "<" survives as text for MathJax),
 * then the handful of Markdown constructs actually used are rebuilt.
 * $...$ spans are shielded so the bold/italic rules never touch TeX.
 */
(function (global) {
    'use strict';

    var SHIELD = '\u0001';

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function shieldMath(text, store) {
        return text.replace(/\$([^$\n]+)\$/g, function (span, body) {
            /* "$100 ve $80" is money, not TeX: only shield plausible math. */
            if (!looksLikeMath(body)) return span;
            store.push(span);
            return SHIELD + (store.length - 1) + SHIELD;
        });
    }

    var TEX_SIGNAL = /[\\^_{}=<>+\-*/|]|&lt;|&gt;/;

    function looksLikeMath(body) {
        if (!body || /^\s|\s$/.test(body) || /\s{2,}/.test(body)) return false;
        return TEX_SIGNAL.test(body) || !/\s/.test(body);
    }

    function unshieldMath(text, store) {
        return text.replace(new RegExp(SHIELD + '(\\d+)' + SHIELD, 'g'), function (_, i) {
            return store[Number(i)];
        });
    }

    /* Inline markup for text that is already HTML-escaped. */
    function inlineEscaped(escaped, store) {
        var out = shieldMath(escaped, store);
        /* A "$" left over belongs to price text, not TeX: keep MathJax away. */
        out = out.replace(/\$/g, '<span class="no-mathjax">$</span>');
        out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        out = out.replace(/(^|[^*])\*([^*\s][^*]*?)\*(?!\*)/g, '$1<em>$2</em>');
        out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
        return unshieldMath(out, store);
    }

    function inline(text) {
        return inlineEscaped(escapeHtml(text), []);
    }

    function block(text) {
        var store = [];
        var lines = escapeHtml(String(text == null ? '' : text).replace(/\r\n?/g, '\n')).split('\n');
        var out = [];
        var para = [];
        var items = null;

        function flushPara() {
            if (!para.length) return;
            out.push('<p>' + para.join('<br>') + '</p>');
            para = [];
        }

        function flushList() {
            if (!items) return;
            out.push('<ul>' + items.map(function (li) { return '<li>' + li + '</li>'; }).join('') + '</ul>');
            items = null;
        }

        function flushAll() {
            flushPara();
            flushList();
        }

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].replace(/\s+$/, '');
            var trimmed = line.trim();

            if (!trimmed) {
                flushAll();
                continue;
            }

            if (/^-{3,}$/.test(trimmed)) {
                flushAll();
                out.push('<hr>');
                continue;
            }

            var heading = trimmed.match(/^#{1,6}\s+(.*)$/);
            if (heading) {
                flushAll();
                out.push('<p class="md-heading">' + inlineEscaped(heading[1], store) + '</p>');
                continue;
            }

            var bullet = line.match(/^\s*[*-]\s+(.*)$/);
            if (bullet) {
                flushPara();
                if (!items) items = [];
                /* One-line runs like "* A  * B  * C" become three items. */
                bullet[1].split(/\s+\*\s+(?=\S)/).forEach(function (part) {
                    if (part.trim()) items.push(inlineEscaped(part.trim(), store));
                });
                continue;
            }

            /* Trailing backslash = Markdown hard break; the paragraph join handles it. */
            if (/\\$/.test(line)) line = line.slice(0, -1).replace(/\s+$/, '');

            flushList();
            para.push(inlineEscaped(line.trim(), store));
        }

        flushAll();
        return out.join('');
    }

    /*
     * Typeset with MathJax, waiting for the (async, CDN-loaded) library.
     * Questions render before MathJax is ready on a cold load; without this
     * the first question keeps its raw TeX.
     */
    function typeset(elements) {
        var mathJax = global.MathJax;
        var doc = global.document;

        function run() {
            if (!mathJax || !mathJax.typesetPromise) return Promise.resolve();
            return mathJax.typesetPromise(elements || undefined);
        }

        if (mathJax && mathJax.startup && mathJax.startup.promise) {
            return mathJax.startup.promise.then(run, run);
        }

        var script = doc && doc.getElementById('MathJax-script');
        if (!script) return Promise.resolve();

        return new Promise(function (resolve) {
            var settled = false;
            var finish = function () {
                if (settled) return;
                settled = true;
                resolve();
            };
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', finish, { once: true });
            setTimeout(finish, 4000);
        }).then(function () {
            return mathJax && mathJax.startup && mathJax.startup.promise;
        }).then(run, run);
    }

    global.LGSMarkdown = { block: block, inline: inline, typeset: typeset };
})(window);
