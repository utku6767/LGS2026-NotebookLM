<?php
declare(strict_types=1);

/*
 * Deneme modu — gerçek LGS düzeni.
 *
 * Sözel 75 dk (Türkçe 20 · Sosyal 10 · Din 10 · İngilizce 10)
 * Sayısal 80 dk (Matematik 20 · Fen 20)
 * Maraton 155 dk (hepsi, 90 soru)
 *
 * Sorular lgs/ klasöründeki txt dosyalarından okunur. Süre sunucu
 * saatinden işler (deneme_api.php), cihaz kapansa da devam eder.
 */

require __DIR__ . '/auth.php';
session_boot();

debug_handle_post('deneme.php');

$user  = auth_user();
$debug = debug_enabled();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Deneme | LGS Çözüm</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        window.MathJax = {
            tex: {
                inlineMath: [['$', '$'], ['\\(', '\\)']],
                displayMath: [['$$', '$$'], ['\\[', '\\]']],
                processEscapes: true
            },
            options: {
                ignoreHtmlClass: 'no-mathjax',
                processHtmlClass: 'math'
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js" id="MathJax-script" async></script>
    <link rel="stylesheet" href="deneme.css?v=<?= (int) @filemtime(__DIR__ . '/deneme.css') ?>">
</head>
<body data-user="<?= e($user['username'] ?? '') ?>" data-debug="<?= $debug ? '1' : '0' ?>">

<?php if ($user === null): ?>
    <div class="gate">
        <div class="gate-card">
            <h1>📝 DENEME</h1>
            <p>Deneme modu süreli çalışır ve süre sunucuda işler. Bu yüzden bir hesaba giriş yapman gerekiyor.</p>
            <a class="capsule is-primary" href="login.php">Giriş Yap</a>
            <a class="capsule" href="index.php">⬅ Menüye dön</a>
        </div>
    </div>
<?php else: ?>

<div id="loading-screen" class="screen active">
    <div class="loading-box">
        <div class="spinner"></div>
        <p>Deneme yükleniyor…</p>
    </div>
</div>

<!-- 1. MOD SEÇİMİ -->
<div id="mode-screen" class="screen">
    <div class="top-bar">
        <a class="back-btn" href="index.php">← Menü</a>
        <div class="subject-info">
            <strong>DENEME</strong>
            <div class="subject-stats">Süre sunucu saatinden işler</div>
        </div>
        <?php if ($debug): ?>
            <span class="debug-chip" title="Debug / admin modu açık">🛠 ADMIN</span>
        <?php endif; ?>
    </div>

    <div class="wrap">
        <h1>Gerçek LGS Düzeni</h1>
        <p class="subtitle">Bir mod seç; süre başlar ve sen çıkasan da durmaz.</p>

        <div id="active-attempt-banner" class="banner" hidden></div>

        <div class="capsule-grid" id="mode-capsules"></div>

        <label class="check-row" for="show-answers">
            <input type="checkbox" id="show-answers">
            <span class="check-box">✓</span>
            <span>
                <b>Cevapları hemen göster</b>
                <small>Kapalıysa sınav boyunca geri bildirim görmezsin; bitirince analiz sayfasında tüm cevapları görürsün.</small>
            </span>
        </label>

        <div class="banner is-soft">
            ⏱ Süre sunucuda tutulur. Sekmeyi kapatsan, hatta bilgisayarı kapatsan bile geri sayım devam eder;
            tekrar girdiğinde kalan süreden devam edersin.
        </div>
    </div>
</div>

<!-- 2. DERS SEÇİMİ -->
<div id="subjects-screen" class="screen">
    <div class="top-bar">
        <button class="back-btn" onclick="backToModes()">← Mod seçimi</button>
        <div class="subject-info">
            <strong id="subjects-title">DERSLER</strong>
            <div class="timer" id="subjects-timer">--:--</div>
        </div>
        <?php if ($debug): ?>
            <div class="debug-tools">
                <button class="debug-btn" onclick="debugFinish()" title="Süreyi beklemeden denemeyi bitir">⏱ BİTİR</button>
                <button class="debug-btn" onclick="debugShorten(10)" title="Süreyi 10 saniyeye indir">⚡ 10sn</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="wrap">
        <p class="subtitle" id="subjects-subtitle"></p>
        <div class="capsule-grid" id="subject-capsules"></div>
        <button class="capsule is-danger" onclick="askFinish()">🏁 DENEMEYİ BİTİR</button>
    </div>
</div>

<!-- 3. SORULAR -->
<div id="questions-screen" class="screen">
    <div class="top-bar">
        <button class="back-btn" onclick="backToSubjects()">← Dersler</button>
        <div class="subject-info">
            <strong id="question-subject">DERS</strong>
            <div class="timer" id="questions-timer">--:--</div>
        </div>
        <?php if ($debug): ?>
            <div class="debug-tools">
                <button class="debug-btn" onclick="debugFinish()" title="Süreyi beklemeden denemeyi bitir">⏱ BİTİR</button>
                <button class="debug-btn" onclick="debugShorten(10)" title="Süreyi 10 saniyeye indir">⚡ 10sn</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="quiz-content">
        <div class="quiz-progress">
            <span id="quiz-answered">0 / 0 cevaplandı</span>
            <span id="quiz-answered-subject">bu derste 0 / 0</span>
        </div>
        <div class="question-text math" id="question-text"></div>
        <div class="options-container math" id="options-container"></div>
        <div class="bottom-nav">
            <button class="nav-btn" id="prev-btn">◀ ÖNCEKİ</button>
            <div class="q-input-group">
                <input type="number" id="q-input" min="1" value="1">
                <span>/ <span id="total-q">0</span></span>
            </div>
            <button class="nav-btn" id="next-btn">SONRAKİ ▶</button>
        </div>
    </div>
</div>

<!-- 4. ANALİZ -->
<div id="analyze-screen" class="screen">
    <div class="top-bar">
        <button class="back-btn" onclick="backToModes()">← Mod seçimi</button>
        <div class="subject-info">
            <strong id="analyze-title">DENEME ANALİZİ</strong>
            <div class="subject-stats" id="analyze-subtitle"></div>
        </div>
    </div>

    <div class="wrap">
        <div class="analyze-total" id="analyze-total"></div>
        <div id="analyze-subjects"></div>
        <h2 class="section-title">📄 Ders seç, cevapları incele</h2>
        <p class="subtitle">Bir derse dokun: doğru / yanlış / boş / normal olmak üzere dört gezinme biçimiyle soruları tek tek gez.</p>
        <div class="analyze-actions">
            <button class="capsule is-primary" onclick="askNewAttempt()">🔄 YENİ DENEME BAŞLAT</button>
            <a class="capsule" href="index.php">⬅ MENÜYE DÖN</a>
        </div>
    </div>
</div>

<!-- 5. CEVAP İNCELEME -->
<div id="review-screen" class="screen">
    <div class="top-bar">
        <button class="back-btn" onclick="backToAnalysis()">← Analiz</button>
        <div class="subject-info">
            <strong id="review-subject">DERS</strong>
            <div class="subject-stats" id="review-position">0 / 0</div>
        </div>
    </div>

    <div class="quiz-content">
        <div class="review-tabs" id="review-tabs"></div>
        <p class="filter-desc" id="filter-desc"></p>
        <div id="review-body">
            <div class="question-text math" id="review-question"></div>
            <div class="options-container math" id="review-options"></div>
            <div class="bottom-nav">
                <button class="nav-btn" id="review-prev">◀ ÖNCEKİ</button>
                <div class="q-input-group">
                    <input type="number" id="review-input" min="1" value="1">
                    <span>/ <span id="review-total">0</span></span>
                </div>
                <button class="nav-btn" id="review-next">SONRAKİ ▶</button>
            </div>
        </div>
        <div class="review-empty" id="review-empty" hidden></div>
    </div>
</div>

<div id="confirm-overlay">
    <div class="confirm-box">
        <h3 id="confirm-title">Emin misin?</h3>
        <p id="confirm-text"></p>
        <div class="confirm-buttons">
            <button class="capsule" onclick="closeConfirm()">İPTAL</button>
            <button class="capsule is-danger" id="confirm-ok">ONAYLA</button>
        </div>
    </div>
</div>

<div id="toast" hidden></div>

<script src="md-lite.js?v=<?= (int) @filemtime(__DIR__ . '/md-lite.js') ?>"></script>
<script>
    if (localStorage.getItem('sensorySensitiveMode') === 'false') {
        document.body.classList.add('reduced-sensory');
    }

    const SUBJECT_META = {
        turkce:    { label: 'TÜRKÇE',          icon: '📖' },
        sosyal:    { label: 'SOSYAL BİLGİLER', icon: '🌍' },
        din:       { label: 'DİN KÜLTÜRÜ',     icon: '🕌' },
        ingilizce: { label: 'İNGİLİZCE',       icon: '🇬🇧' },
        matematik: { label: 'MATEMATİK',       icon: '📐' },
        fen:       { label: 'FEN BİLİMLERİ',   icon: '🔬' }
    };

    const MODE_META = {
        sozel:   { label: 'SÖZEL',   icon: '📖', description: 'Türkçe · Sosyal · Din · İngilizce' },
        sayisal: { label: 'SAYISAL', icon: '📐', description: 'Matematik · Fen' },
        maraton: { label: 'MARATON', icon: '🏁', description: 'Bütün dersler' }
    };

    /* Gerçek LGS soru dağılımı — lgs/ dosyalarından bu kadar soru alınır. */
    const SUBJECT_QUOTA = { turkce: 20, sosyal: 10, din: 10, ingilizce: 10, matematik: 20, fen: 20 };
    const OPTION_LETTERS = ['A', 'B', 'C', 'D'];

    let MODES = {};
    let attempts = {};
    let mode = null;
    let attempt = null;
    let questions = {};
    let currentSubject = null;
    let currentIndex = 0;
    let remaining = 0;
    let deadlineMs = null;
    let timerId = null;
    let finishing = false;

    const $ = id => document.getElementById(id);

    function showScreen(id) {
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
        $(id).classList.add('active');
        window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
    }

    function renderMath() {
        const targets = Array.prototype.slice.call(arguments).filter(Boolean);
        LGSMarkdown.typeset(targets.length ? targets : undefined).catch(() => {});
    }

    function toast(text, isError = false) {
        const el = $('toast');
        el.textContent = text;
        el.classList.toggle('is-error', isError);
        el.hidden = false;
        clearTimeout(el.__timer);
        el.__timer = setTimeout(() => { el.hidden = true; }, 3200);
    }

    function askConfirm(title, text, onOk) {
        $('confirm-title').textContent = title;
        $('confirm-text').textContent = text;
        const ok = $('confirm-ok');
        ok.onclick = () => {
            closeConfirm();
            onOk();
        };
        $('confirm-overlay').classList.add('active');
    }

    function closeConfirm() {
        $('confirm-overlay').classList.remove('active');
    }

    async function getState() {
        const res = await fetch('deneme_api.php', { cache: 'no-store' });
        if (res.status === 401) {
            location.replace('login.php');
            throw new Error('auth');
        }
        if (!res.ok) throw new Error('state ' + res.status);
        return res.json();
    }

    async function post(payload) {
        const res = await fetch('deneme_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        let data = {};
        try { data = await res.json(); } catch (e) { data = {}; }

        if (!res.ok || !data.ok) {
            const err = new Error(data.error || ('http ' + res.status));
            err.data = data;
            throw err;
        }

        if (data.attempt) {
            applyAttempt(data.attempt);
        }
        return data;
    }

    /* Sunucudan gelen taze kalan süreyi yerel bitiş anına çevirir;
       böylece ileri/geri gezinmek sayaçı sıfırlamaz. */
    function applyAttempt(next) {
        attempt = next;
        attempts[attempt.mode] = attempt;

        if (!attempt.finished && typeof attempt.remaining === 'number') {
            deadlineMs = Date.now() + attempt.remaining * 1000;
            remaining = Math.max(0, attempt.remaining);
            renderTimer();
        }
    }

    /* ---------- soru okuma ---------- */

    function parseQuestions(rawText) {
        if (!rawText) return [];
        const questions = [];
        const blocks = rawText.split(/## \d+\.\s*Soru Tipi:/).slice(1);

        for (const block of blocks) {
            const soruMatch = block.match(/\*\*Soru Kökü:\*\*\s*([\s\S]*?)\*\*Şıklar:\*\*/);
            if (!soruMatch) continue;

            const aMatch = block.match(/(?:^|\n)-\s*A\)\s*(.*)/);
            const bMatch = block.match(/(?:^|\n)-\s*B\)\s*(.*)/);
            const cMatch = block.match(/(?:^|\n)-\s*C\)\s*(.*)/);
            const dMatch = block.match(/(?:^|\n)-\s*D\)\s*(.*)/);
            const ansMatch = block.match(/Cevap:\s*([A-D])\./i);

            questions.push({
                text: soruMatch[1].trim(),
                options: {
                    A: aMatch ? aMatch[1].trim() : '',
                    B: bMatch ? bMatch[1].trim() : '',
                    C: cMatch ? cMatch[1].trim() : '',
                    D: dMatch ? dMatch[1].trim() : ''
                },
                correct: ansMatch ? ansMatch[1].toUpperCase() : 'A'
            });
        }

        return questions;
    }

    async function loadSubject(subject) {
        if (questions[subject]) return questions[subject];

        const response = await fetch(`lgs/${subject}.txt?v=${Date.now()}`);
        if (!response.ok) throw new Error(`lgs/${subject}.txt bulunamadı`);

        const quota = SUBJECT_QUOTA[subject] || 999;
        questions[subject] = parseQuestions(await response.text()).slice(0, quota);

        return questions[subject];
    }

    function modeSubjects() {
        if (MODES[mode] && Array.isArray(MODES[mode].subjects)) return MODES[mode].subjects;
        return Object.keys(SUBJECT_META);
    }

    /* ---------- cevap yardımcıları ---------- */

    function answers() {
        return attempt && attempt.answers ? attempt.answers : {};
    }

    function answerOf(subject, index) {
        const bucket = answers()[subject] || {};
        return bucket[index] || bucket[String(index)] || null;
    }

    function answeredCountIn(subject) {
        const bucket = answers()[subject] || {};
        return Object.keys(bucket).filter(k => OPTION_LETTERS.includes(bucket[k])).length;
    }

    function answeredTotal() {
        return modeSubjects().reduce((sum, s) => sum + answeredCountIn(s), 0);
    }

    function quotaTotal() {
        return modeSubjects().reduce((sum, s) => sum + ((questions[s] || []).length), 0);
    }

    /* ---------- süre ---------- */

    function formatTime(seconds) {
        const s = Math.max(0, Math.floor(seconds));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        const two = n => String(n).padStart(2, '0');
        return h > 0 ? `${two(h)}:${two(m)}:${two(sec)}` : `${two(m)}:${two(sec)}`;
    }

    function renderTimer() {
        const text = attempt && attempt.finished ? 'BİTTİ' : formatTime(remaining);
        ['subjects-timer', 'questions-timer'].forEach(id => {
            const el = $(id);
            if (!el) return;
            el.textContent = text;
            el.classList.toggle('is-warning', !attempt?.finished && remaining <= 900 && remaining > 300);
            el.classList.toggle('is-danger', !attempt?.finished && remaining <= 300);
        });
    }

    function tick() {
        if (deadlineMs === null) return;
        remaining = Math.max(0, Math.round((deadlineMs - Date.now()) / 1000));
        renderTimer();

        if (remaining <= 0) {
            stopTimer();
            finishExam(true);
        }
    }

    function startTimer() {
        stopTimer();
        if (!attempt || attempt.finished || deadlineMs === null) return;
        tick();
        timerId = setInterval(tick, 1000);
    }

    function stopTimer() {
        if (timerId) clearInterval(timerId);
        timerId = null;
    }

    function syncTimer(res) {
        if (res && res.attempt) {
            applyAttempt(res.attempt);
        }
    }

    /* ---------- 1. mod ekranı ---------- */

    function renderModeCapsules() {
        const container = $('mode-capsules');
        container.innerHTML = Object.keys(MODES).map(name => {
            const meta = MODE_META[name] || { label: name.toUpperCase(), icon: '📝', description: '' };
            const minutes = MODES[name].minutes;
            const subjectCount = MODES[name].subjects.length;
            const active = attempts[name];

            let badge = '';
            if (active && !active.finished) {
                badge = `<span class="capsule-badge is-live">⏱ ${formatTime(active.remaining)}</span>`;
            } else if (active && active.finished) {
                badge = '<span class="capsule-badge">📄 analiz hazır</span>';
            }

            return `<button class="capsule mode-capsule" data-mode="${name}">
                <span class="capsule-head"><span class="capsule-icon">${meta.icon}</span> ${meta.label}</span>
                <small>${minutes} dk · ${subjectCount} ders</small>
                <small class="capsule-desc">${meta.description}</small>
                ${badge}
            </button>`;
        }).join('');

        container.querySelectorAll('.mode-capsule').forEach(btn => {
            btn.addEventListener('click', () => selectMode(btn.dataset.mode));
        });
    }

    function renderActiveBanner() {
        const banner = $('active-attempt-banner');
        const running = Object.keys(attempts).filter(m => attempts[m] && !attempts[m].finished);

        if (!running.length) {
            banner.hidden = true;
            return;
        }

        banner.hidden = false;
        banner.classList.add('is-live');
        banner.innerHTML = '⏱ <b>Devam eden deneme var:</b> ' + running.map(m => {
            const meta = MODE_META[m] || { label: m.toUpperCase() };
            return `${meta.label} — kalan ${formatTime(attempts[m].remaining)}`;
        }).join(' · ') + '<br><small>Kaldığın yerden devam etmek için moda dokun.</small>';
    }

    async function selectMode(name) {
        mode = name;
        const checkbox = $('show-answers');

        /* Modda hiç soru yoksa boşuna süre başlatma. */
        let available = 0;
        for (const subject of modeSubjects()) {
            try { available += (await loadSubject(subject)).length; } catch (e) { /* dosya yok */ }
        }

        if (!available) {
            toast('Bu modda soru yok. lgs/ klasörüne soru ekle (biçim: lgs/FORMAT.txt).', true);
            return;
        }

        try {
            await post({ action: 'start', mode: name, showAnswers: checkbox.checked });
        } catch (e) {
            toast('Deneme başlatılamadı. Bağlantını kontrol et.', true);
            return;
        }

        checkbox.checked = Boolean(attempt.showAnswers);
        route();
    }

    function route() {
        if (!attempt) {
            backToModes();
            return;
        }
        if (attempt.finished) {
            stopTimer();
            openAnalysis();
            return;
        }
        openSubjects();
    }

    async function backToModes() {
        stopTimer();

        /* Kalan süreler sunucudan tazelensin. */
        if (attempt) {
            try {
                const state = await getState();
                attempts = state.attempts || {};
            } catch (e) {
                /* önbellekteki değerlerle devam */
            }
        }

        renderModeCapsules();
        renderActiveBanner();
        showScreen('mode-screen');
    }

    /* ---------- 2. ders ekranı ---------- */

    async function openSubjects() {
        showScreen('subjects-screen');
        startTimer();

        const meta = MODE_META[mode] || { label: 'DENEME' };
        $('subjects-title').textContent = meta.label;

        const container = $('subject-capsules');
        container.innerHTML = modeSubjects().map(subject => {
            const info = SUBJECT_META[subject] || { label: subject, icon: '📘' };
            return `<button class="capsule subject-capsule" data-subject="${subject}">
                <span class="capsule-head"><span class="capsule-icon">${info.icon}</span> ${info.label}</span>
                <small class="subject-count" data-count="${subject}">yükleniyor…</small>
                <small>${answeredCountIn(subject)} cevaplandı</small>
            </button>`;
        }).join('');

        container.querySelectorAll('.subject-capsule').forEach(btn => {
            btn.addEventListener('click', () => openSubject(btn.dataset.subject));
        });

        for (const subject of modeSubjects()) {
            try {
                const list = await loadSubject(subject);
                const el = container.querySelector(`[data-count="${subject}"]`);
                if (el) el.textContent = list.length + ' soru';
            } catch (e) {
                const el = container.querySelector(`[data-count="${subject}"]`);
                if (el) el.textContent = 'dosya yok';
            }
        }

        $('subjects-subtitle').textContent =
            `${attempt.totalMinutes} dakika · ${answeredTotal()} / ${quotaTotal()} cevaplandı`;
    }

    function backToSubjects() {
        openSubjects();
    }

    async function openSubject(subject) {
        currentSubject = subject;
        currentIndex = 0;
        showScreen('questions-screen');
        $('question-subject').textContent = (SUBJECT_META[subject] || {}).label || subject;

        try {
            await loadSubject(subject);
        } catch (e) {
            $('question-text').textContent = `lgs/${subject}.txt okunamadı.`;
            $('options-container').innerHTML = '';
            return;
        }

        if (!questions[subject].length) {
            $('question-text').textContent = `Bu derste soru yok. lgs/${subject}.txt dosyasına soru ekle (biçim: lgs/FORMAT.txt).`;
            $('options-container').innerHTML = '';
            $('total-q').textContent = '0';
            $('q-input').value = 1;
            return;
        }

        renderQuestion();
    }

    /* ---------- 3. soru ekranı ---------- */

    function renderQuestion() {
        const list = questions[currentSubject] || [];
        const question = list[currentIndex];
        if (!question) return;

        const stored = answerOf(currentSubject, currentIndex);
        const locked = Boolean(attempt.showAnswers && stored);
        const questionEl = $('question-text');
        const optionsEl = $('options-container');

        if (window.MathJax && window.MathJax.typesetClear) window.MathJax.typesetClear([questionEl, optionsEl]);

        questionEl.innerHTML = LGSMarkdown.block(question.text);
        $('total-q').textContent = list.length;
        $('q-input').value = currentIndex + 1;
        $('q-input').max = list.length;

        optionsEl.innerHTML = OPTION_LETTERS.map(letter => {
            const classes = ['option-btn'];
            if (stored === letter) classes.push('is-selected');
            if (locked && letter === question.correct) classes.push('correct');
            if (locked && stored === letter && stored !== question.correct) classes.push('incorrect');

            return `<button class="${classes.join(' ')}" data-letter="${letter}" ${locked ? 'disabled' : ''}>
                <span class="option-icon">${letter})</span>
                <span class="option-text">${LGSMarkdown.inline(question.options[letter] || '')}</span>
            </button>`;
        }).join('');

        optionsEl.querySelectorAll('.option-btn').forEach(btn => {
            btn.addEventListener('click', () => chooseOption(btn.dataset.letter));
        });

        $('prev-btn').disabled = currentIndex === 0;
        $('next-btn').disabled = currentIndex >= list.length - 1;

        const totalAnswered = answeredTotal();
        $('quiz-answered').textContent = `${totalAnswered} / ${quotaTotal()} cevaplandı`;
        $('quiz-answered-subject').textContent = `bu derste ${answeredCountIn(currentSubject)} / ${list.length}`;

        renderMath(questionEl, optionsEl);
    }

    async function chooseOption(letter) {
        if (!attempt || attempt.finished) return;
        if (attempt.showAnswers && answerOf(currentSubject, currentIndex)) return;

        const list = questions[currentSubject] || [];
        const question = list[currentIndex];
        if (!question) return;

        /* Anında geri bildirim: doğru/yanlış hemen boyanır. */
        if (attempt.showAnswers) {
            const chosen = letter;
            const correct = question.correct;
            $('options-container').querySelectorAll('.option-btn').forEach(btn => {
                btn.disabled = true;
                if (btn.dataset.letter === correct) btn.classList.add('correct');
                if (btn.dataset.letter === chosen && chosen !== correct) btn.classList.add('incorrect');
            });
        } else {
            $('options-container').querySelectorAll('.option-btn').forEach(btn => {
                btn.classList.toggle('is-selected', btn.dataset.letter === letter);
            });
        }

        try {
            const res = await post({
                action: 'answer',
                mode,
                subject: currentSubject,
                index: currentIndex,
                selected: letter
            });
            syncTimer(res);
        } catch (e) {
            if (e.data && e.data.attempt && e.data.attempt.finished) {
                attempts[mode] = e.data.attempt;
                attempt = e.data.attempt;
                toast('Süre doldu, deneme bitti.', true);
                finishExam(true);
                return;
            }
            toast('Cevap kaydedilemedi.', true);
        }

        $('quiz-answered').textContent = `${answeredTotal()} / ${quotaTotal()} cevaplandı`;
        $('quiz-answered-subject').textContent = `bu derste ${answeredCountIn(currentSubject)} / ${list.length}`;
    }

    $('prev-btn').addEventListener('click', () => {
        if (currentIndex > 0) { currentIndex -= 1; renderQuestion(); }
    });

    $('next-btn').addEventListener('click', () => {
        const list = questions[currentSubject] || [];
        if (currentIndex < list.length - 1) { currentIndex += 1; renderQuestion(); }
    });

    $('q-input').addEventListener('change', e => {
        const list = questions[currentSubject] || [];
        let value = parseInt(e.target.value, 10);
        if (isNaN(value)) value = 1;
        value = Math.min(Math.max(value, 1), Math.max(list.length, 1));
        currentIndex = value - 1;
        renderQuestion();
    });

    async function finishExam(auto) {
        if (finishing) return;
        finishing = true;
        stopTimer();

        try {
            await post({ action: 'finish', mode });
        } catch (e) {
            toast('Deneme kapatılamadı, analiz yerel verilerle gösteriliyor.', true);
            if (attempt) attempt.finished = true;
        }

        finishing = false;
        if (auto) toast('Süre doldu — deneme otomatik bitti.');
        openAnalysis();
    }

    function askFinish() {
        askConfirm(
            'Denemeyi bitir',
            'Bitirdikten sonra cevaplarını değiştiremezsin. Analiz sayfası açılacak.',
            () => finishExam(false)
        );
    }

    function askNewAttempt() {
        askConfirm(
            'Yeni deneme',
            'Bu moddaki kayıtlı deneme ve cevapları silinecek. Yeni bir süre başlar.',
            async () => {
                try {
                    await post({ action: 'reset', mode });
                } catch (e) {
                    toast('Deneme sıfırlanamadı.', true);
                    return;
                }
                delete attempts[mode];
                attempt = null;
                questions = {};
                $('show-answers').checked = false;
                backToModes();
            }
        );
    }

    /* ---------- 4. analiz ---------- */

    async function openAnalysis() {
        showScreen('analyze-screen');
        stopTimer();

        const meta = MODE_META[mode] || { label: 'DENEME' };
        $('analyze-title').textContent = meta.label + ' ANALİZİ';
        $('analyze-subtitle').textContent = attempt.finished
            ? `${attempt.totalMinutes} dk · ${formatTime(Number(attempt.remaining) || 0)} kaldı`
            : '';

        for (const subject of modeSubjects()) {
            try { await loadSubject(subject); } catch (e) { questions[subject] = questions[subject] || []; }
        }

        let totalQuestions = 0;
        let totalAnswered = 0;
        let totalCorrect = 0;
        let totalWrong = 0;

        const subjectStats = modeSubjects().map(subject => {
            const list = questions[subject] || [];
            let answered = 0;
            let correct = 0;

            list.forEach((question, index) => {
                const given = answerOf(subject, index);
                if (!given) return;
                answered += 1;
                if (given === question.correct) correct += 1;
            });

            totalQuestions += list.length;
            totalAnswered += answered;
            totalCorrect += correct;
            totalWrong += answered - correct;

            return {
                subject,
                total: list.length,
                answered,
                correct,
                wrong: answered - correct,
                empty: Math.max(0, list.length - answered),
                percent: answered ? Math.round((correct / answered) * 100) : 0
            };
        });

        const overallPercent = totalAnswered ? Math.round((totalCorrect / totalAnswered) * 100) : 0;
        const netPercent = totalQuestions ? Math.round((totalCorrect / totalQuestions) * 100) : 0;

        $('analyze-total').innerHTML = `
            <div class="big-percent">%${overallPercent}</div>
            <p class="subtitle">Başarı = (Doğru / Cevaplanan) × 100</p>
            <div class="stat-row">
                <div class="stat-item"><div class="stat-label">SORU</div><div class="stat-value">${totalQuestions}</div></div>
                <div class="stat-item"><div class="stat-label">ÇÖZÜLEN</div><div class="stat-value">${totalAnswered}</div></div>
                <div class="stat-item"><div class="stat-label">DOĞRU</div><div class="stat-value success">${totalCorrect}</div></div>
                <div class="stat-item"><div class="stat-label">YANLIŞ</div><div class="stat-value danger">${totalWrong}</div></div>
            </div>
            <p class="analyze-note">Sınavın tamamına göre net oran: <b>%${netPercent}</b> · boş: <b>${Math.max(0, totalQuestions - totalAnswered)}</b></p>
        `;

        $('analyze-subjects').innerHTML = subjectStats.map(stat => {
            const info = SUBJECT_META[stat.subject] || { label: stat.subject, icon: '📘' };
            return `<button type="button" class="analyze-card is-clickable" data-subject="${stat.subject}">
                <div class="analyze-card-head">
                    <span>${info.icon} ${info.label}</span>
                    <b class="${stat.percent >= 50 ? 'success' : 'danger'}">%${stat.percent}</b>
                </div>
                <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:${stat.percent}%"></div></div>
                <div class="analyze-card-foot">
                    <span>✅ ${stat.correct}</span>
                    <span>❌ ${stat.wrong}</span>
                    <span>➖ ${stat.empty}</span>
                    <span>📘 ${stat.answered} / ${stat.total}</span>
                    <span class="analyze-card-go">cevapları incele →</span>
                </div>
            </button>`;
        }).join('');

        $('analyze-subjects').querySelectorAll('.analyze-card').forEach(card => {
            card.addEventListener('click', () => openReview(card.dataset.subject, 'normal'));
        });
    }

    /* ---------- gizli debug araçları (Konami kodu) ---------- */

    async function debugFinish() {
        if (!mode) {
            toast('Debug: önce bir mod seç.', true);
            return;
        }

        try {
            await post({ action: 'debug_finish', mode: mode });
        } catch (e) {
            toast('Debug: deneme bitirilemedi.', true);
            return;
        }

        stopTimer();
        if (attempt) attempt.finished = true;
        renderTimer();
        toast('🛠 Debug: deneme anında bitirildi.');
        openAnalysis();
    }

    async function debugShorten(seconds) {
        if (!mode) {
            toast('Debug: önce bir mod seç.', true);
            return;
        }

        try {
            await post({ action: 'debug_deadline', mode: mode, seconds: seconds });
        } catch (e) {
            toast('Debug: süre kısaltılamadı.', true);
            return;
        }

        startTimer();
        toast(`🛠 Debug: ${seconds} saniye kaldı.`);
    }

    /* ---------- 5. cevap inceleme: doğru / yanlış / boş / normal ---------- */

    const REVIEW_FILTERS = {
        normal:  { label: 'NORMAL',  tone: 'normal',  description: 'Bütün sorular — baştan sona sırayla gezersin.' },
        correct: { label: 'DOĞRU',   tone: 'success', description: 'Yalnızca doğru cevapladığın sorular arasında gezinirsin.' },
        wrong:   { label: 'YANLIŞ',  tone: 'danger',  description: 'Yalnızca yanlış cevapladığın sorular arasında gezinirsin.' },
        blank:   { label: 'BOŞ',     tone: 'blank',   description: 'Yalnızca boş bıraktığın sorular arasında gezinirsin.' }
    };

    let reviewSubject = null;
    let reviewFilter = 'normal';
    let reviewIndexes = [];
    let reviewPos = 0;

    function questionState(subject, index) {
        const given = answerOf(subject, index);
        const question = (questions[subject] || [])[index];
        if (!given || !question) return 'empty';
        return given === question.correct ? 'ok' : 'bad';
    }

    function buildReviewList() {
        reviewIndexes = (questions[reviewSubject] || [])
            .map((question, index) => index)
            .filter(index => {
                const state = questionState(reviewSubject, index);
                if (reviewFilter === 'correct') return state === 'ok';
                if (reviewFilter === 'wrong') return state === 'bad';
                if (reviewFilter === 'blank') return state === 'empty';
                return true;
            });

        reviewPos = Math.min(reviewPos, Math.max(reviewIndexes.length - 1, 0));
    }

    function openReview(subject, filter) {
        reviewSubject = subject;
        reviewFilter = filter || 'normal';
        reviewPos = 0;
        buildReviewList();

        $('review-subject').textContent = (SUBJECT_META[subject] || {}).label || subject;
        showScreen('review-screen');
        renderReview();
    }

    function setReviewFilter(filter) {
        reviewFilter = filter;
        reviewPos = 0;
        buildReviewList();
        renderReview();
    }

    function renderReview() {
        const meta = REVIEW_FILTERS[reviewFilter];
        const list = questions[reviewSubject] || [];
        const info = SUBJECT_META[reviewSubject] || { label: reviewSubject, icon: '📘' };

        $('review-tabs').innerHTML = Object.keys(REVIEW_FILTERS).map(key => {
            const tab = REVIEW_FILTERS[key];
            return `<button class="filter-tab is-${tab.tone} ${key === reviewFilter ? 'active' : ''}" data-filter="${key}">
                ${tab.label}
            </button>`;
        }).join('');

        $('review-tabs').querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', () => setReviewFilter(tab.dataset.filter));
        });

        $('filter-desc').textContent = `${info.icon} ${meta.description}`;

        const emptyBox = $('review-empty');
        const body = $('review-body');

        if (!reviewIndexes.length) {
            body.hidden = true;
            emptyBox.hidden = false;

            if (meta.tone === 'success') emptyBox.textContent = 'Bu kategoride soru yok — hiç doğru cevabın yok.';
            else if (meta.tone === 'danger') emptyBox.textContent = 'Bu kategoride soru yok — hiç yanlışın yok, hepsi doğru ya da boş.';
            else if (meta.tone === 'blank') emptyBox.textContent = 'Bu kategoride soru yok — hiç boş bırakmamışsın.';
            else emptyBox.textContent = 'Soru bulunamadı.';

            $('review-position').textContent = `0 / ${list.length}`;
            return;
        }

        body.hidden = false;
        emptyBox.hidden = true;

        const index = reviewIndexes[reviewPos];
        const question = list[index];
        const given = answerOf(reviewSubject, index);
        const reviewQuestionEl = $('review-question');
        const reviewOptionsEl = $('review-options');

        if (window.MathJax && window.MathJax.typesetClear) window.MathJax.typesetClear([reviewQuestionEl, reviewOptionsEl]);

        reviewQuestionEl.innerHTML = LGSMarkdown.block(question.text);

        reviewOptionsEl.innerHTML = OPTION_LETTERS.map(letter => {
            const classes = ['option-btn', 'is-readonly'];
            if (letter === question.correct) classes.push('correct');
            if (given === letter && given !== question.correct) classes.push('incorrect');
            if (given === letter) classes.push('is-selected');

            let mark = '';
            if (letter === question.correct) mark = '✅';
            if (given === letter && given !== question.correct) mark = '❌';

            return `<div class="${classes.join(' ')}">
                <span class="option-icon">${letter})</span>
                <span class="option-text">${LGSMarkdown.inline(question.options[letter] || '')}</span>
                <span class="review-option-mark">${mark}</span>
            </div>`;
        }).join('');

        $('review-position').textContent = `${reviewPos + 1} / ${reviewIndexes.length}`;
        $('review-input').value = reviewPos + 1;
        $('review-input').max = reviewIndexes.length;
        $('review-total').textContent = reviewIndexes.length;
        $('review-prev').disabled = reviewPos === 0;
        $('review-next').disabled = reviewPos >= reviewIndexes.length - 1;

        renderMath(reviewQuestionEl, reviewOptionsEl);
    }

    function moveReview(step) {
        if (!reviewIndexes.length) return;
        reviewPos = Math.min(Math.max(reviewPos + step, 0), reviewIndexes.length - 1);
        renderReview();
    }

    function backToAnalysis() {
        openAnalysis();
    }

    $('review-prev').addEventListener('click', () => moveReview(-1));
    $('review-next').addEventListener('click', () => moveReview(1));
    $('review-input').addEventListener('change', e => {
        let value = parseInt(e.target.value, 10);
        if (isNaN(value)) value = 1;
        value = Math.min(Math.max(value, 1), Math.max(reviewIndexes.length, 1));
        reviewPos = value - 1;
        renderReview();
    });

    /* ---------- açılış ---------- */

    (async () => {
        try {
            const state = await getState();
            MODES = state.modes || {};
            attempts = state.attempts || {};
        } catch (e) {
            if (e.message !== 'auth') {
                toast('Deneme durumu okunamadı. Kurulumu (install.php) kontrol et.', true);
            }
            MODES = {
                sozel:   { minutes: 75,  subjects: ['turkce', 'sosyal', 'din', 'ingilizce'] },
                sayisal: { minutes: 80,  subjects: ['matematik', 'fen'] },
                maraton: { minutes: 155, subjects: Object.keys(SUBJECT_META) }
            };
            attempts = {};
        }

        renderModeCapsules();
        renderActiveBanner();
        showScreen('mode-screen');
    })();

    document.addEventListener('visibilitychange', async () => {
        if (document.hidden || !attempt || attempt.finished) return;

        try {
            const state = await getState();
            attempts = state.attempts || attempts;
            if (attempts[mode]) {
                if (attempts[mode].finished) {
                    applyAttempt(attempts[mode]);
                    stopTimer();
                    toast('Süre dolmuş — deneme bitmiş görünüyor.', true);
                    openAnalysis();
                    return;
                }
                applyAttempt(attempts[mode]);
            }
        } catch (e) {
            /* sessiz geç */
        }
    });

    window.addEventListener('beforeunload', () => stopTimer());
</script>
<script src="konami.js?v=<?= (int) @filemtime(__DIR__ . '/konami.js') ?>"></script>
<?php endif; ?>
</body>
</html>
