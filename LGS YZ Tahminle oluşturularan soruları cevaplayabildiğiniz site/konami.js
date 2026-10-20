/*
 * Konami kodu: ↑ ↑ ↓ ↓ ← → ← → B A Enter
 *
 * Girilince gizli debug modu açılır/kapanır. İstek AJAX ile değil, gerçek bir
 * form POST ile gönderilir ve ayrı bir uca değil, bulunduğun sayfaya gider
 * (login.php / index.php / deneme.php). Böylece hostinglerin yeni dosyalara
 * veya AJAX isteklerine uyguladığı engeller devreye girmez.
 *
 * Sayfa bayrağı yazıp kendine ?konami=on|off ile döner; bildirimi bu dosya
 * gösterir.
 *
 * Debug modu sadece senin oturumunda geçerlidir: deneme süresini anında
 * bitirme / kısaltma araçlarını açar. Kapatmak için .env: LGS_DEBUG=0
 */
(function () {
    'use strict';

    /* Hangi sürümün yüklendiğini tarayıcı konsolunda görmek için. */
    window.LGS_KONAMI_VERSION = '4';
    if (window.console && console.info) {
        console.info('[LGS] konami.js v4 — sayfaya form POST');
    }

    const SEQUENCE = [
        'ArrowUp', 'ArrowUp',
        'ArrowDown', 'ArrowDown',
        'ArrowLeft', 'ArrowRight',
        'ArrowLeft', 'ArrowRight',
        'b', 'a', 'Enter'
    ];

    let progress = 0;
    let submitting = false;

    function normalize(event) {
        if (event.key === ' ' || event.code === 'Space') return ' ';
        return event.key.length === 1 ? event.key.toLowerCase() : event.key;
    }

    function notify(text, isError) {
        const box = document.createElement('div');
        box.textContent = text;
        box.style.cssText = [
            'position:fixed',
            'left:50%',
            'bottom:28px',
            'transform:translateX(-50%)',
            'z-index:9999',
            'max-width:90vw',
            'padding:12px 20px',
            'border-radius:999px',
            'font:700 13px/1.5 system-ui,sans-serif',
            'text-align:center',
            'color:#fff',
            'background:' + (isError ? 'rgba(244,63,94,0.92)' : 'rgba(30,25,50,0.96)'),
            'border:1px solid ' + (isError ? 'rgba(244,63,94,0.8)' : 'rgba(168,85,247,0.55)'),
            'box-shadow:0 12px 32px rgba(0,0,0,0.45)'
        ].join(';');

        document.body.appendChild(box);
        setTimeout(() => box.remove(), 4200);
    }

    function toggle() {
        if (submitting) return;
        submitting = true;

        const form = document.createElement('form');
        form.method = 'post';
        form.action = location.pathname + location.search;
        form.style.display = 'none';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'lgs_konami';
        input.value = '1';
        form.appendChild(input);

        document.body.appendChild(form);
        form.submit();
    }

    document.addEventListener('keydown', function (event) {
        const key = normalize(event);

        if (key === SEQUENCE[progress]) {
            progress += 1;
            if (progress === SEQUENCE.length) {
                progress = 0;
                toggle();
            }
            return;
        }

        progress = key === SEQUENCE[0] ? 1 : 0;
    });

    /* Sayfa dönüşü: ?konami=on | off | blocked */
    try {
        const params = new URLSearchParams(location.search);
        const flag = params.get('konami');

        if (flag === 'on' || flag === 'off' || flag === 'blocked') {
            if (flag === 'blocked') {
                notify('Debug modu bu kurulumda kapalı (LGS_DEBUG=0).', true);
            } else if (flag === 'on' && params.get('admin') === '1') {
                notify('🛠 Bu hesap kalıcı admin (users.is_admin=1) — araçlar her zaman açık.', false);
            } else if (flag === 'on') {
                notify('🛠 ADMIN modu açık — deneme sayfasında bitirme/kısaltma araçları görünür.', false);
            } else {
                notify('🛠 ADMIN modu kapandı.', false);
            }

            params.delete('konami');
            params.delete('admin');
            const query = params.toString();
            history.replaceState(null, '', location.pathname + (query ? '?' + query : '') + location.hash);
        }
    } catch (e) {
        /* URLSearchParams yoksa sessiz geç */
    }
})();
