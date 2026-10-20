<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
session_boot();

$user = auth_user();

if ($user === null) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İlerleme Seçimi | LGS Çözüm</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="auth.css?v=<?= (int) @filemtime(__DIR__ . '/auth.css') ?>">
</head>
<body data-user="<?= e($user['username']) ?>">
    <a class="auth-brand" href="index.php">
        <span class="auth-brand-icon">🎯</span>
        <span>LGS ÇÖZÜM</span>
    </a>

    <div class="sync-wrap" id="sync-wrap" hidden>
        <div class="sync-head">
            <h1>Hangi İlerlemeyle Devam Edesin?</h1>
            <p id="sync-lead">Bu cihazdaki ilerleme ile hesabındaki ilerleme farklı. Hangisini kullanmak istediğini seç.</p>
        </div>

        <div class="sync-grid">
            <section class="sync-card" id="card-cloud">
                <div class="sync-card-head">
                    <div>
                        <h2>☁️ Bulut</h2>
                        <span class="sync-source">Hesabına kayıtlı ilerleme</span>
                    </div>
                    <span class="sync-badge" id="badge-cloud" hidden>Daha fazla</span>
                </div>
                <div class="sync-totals" id="totals-cloud"></div>
                <div class="sync-rows" id="rows-cloud"></div>
            </section>

            <section class="sync-card" id="card-local">
                <div class="sync-card-head">
                    <div>
                        <h2>💾 Yerel</h2>
                        <span class="sync-source">Bu cihazdaki (tarayıcı) ilerleme</span>
                    </div>
                    <span class="sync-badge" id="badge-local" hidden>Daha fazla</span>
                </div>
                <div class="sync-totals" id="totals-local"></div>
                <div class="sync-rows" id="rows-local"></div>
            </section>
        </div>

        <div id="sync-message" class="auth-message" hidden></div>

        <div class="sync-actions">
            <button class="sync-btn" data-choice="cloud">
                ☁️ Bulut ile devam et
                <small>Bu cihazdaki ilerleme buluttakiyle değiştirilir.</small>
            </button>
            <button class="sync-btn" data-choice="local">
                💾 Yerel ile devam et
                <small>Bu cihazdaki ilerleme hesabına yüklenir; buluttaki ilerleme bununla değiştirilir.</small>
            </button>
            <button class="sync-btn is-primary" data-choice="merge">
                🔀 İkisini birleştir
                <small>Tüm çözülen sorular birleşir; bir soru bir tarafta doğruysa doğru cevap korunur.</small>
            </button>
        </div>

        <p class="sync-note">Seçim yaptıktan sonra çözdüğün sorular otomatik olarak hesabına kaydedilir.</p>
    </div>

    <div class="sync-wrap" id="sync-loading">
        <div class="sync-head">
            <h1 class="skeleton-block" style="height: 34px; width: 70%; margin: 0 auto;">&nbsp;</h1>
        </div>
        <div class="sync-grid">
            <div class="sync-card skeleton-block" style="height: 300px;">&nbsp;</div>
            <div class="sync-card skeleton-block" style="height: 300px;">&nbsp;</div>
        </div>
    </div>

    <script>
        const SUBJECTS = ['matematik', 'turkce', 'fen', 'din', 'sosyal', 'ingilizce'];
        const LABELS = {
            matematik: '📐 Matematik',
            turkce: '📖 Türkçe',
            fen: '🔬 Fen Bilimleri',
            din: '🕌 Din Kültürü',
            sosyal: '🌍 Sosyal Bilgiler',
            ingilizce: '🇬🇧 İngilizce'
        };

        const wrap = document.getElementById('sync-wrap');
        const loading = document.getElementById('sync-loading');
        const message = document.getElementById('sync-message');

        function readLocal() {
            try {
                const parsed = JSON.parse(localStorage.getItem('lgsProgress'));
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (e) {
                return {};
            }
        }

        function subjectStats(progress, subject) {
            const bucket = progress[subject] || {};
            let solved = 0, correct = 0;
            for (const key in bucket) {
                if (key === '_total') continue;
                const entry = bucket[key];
                if (entry && entry.selected) {
                    solved++;
                    if (entry.isCorrect) correct++;
                }
            }
            return {
                solved,
                correct,
                wrong: solved - correct,
                percent: solved ? Math.round((correct / solved) * 100) : 0
            };
        }

        function overallStats(progress) {
            const totals = { solved: 0, correct: 0, wrong: 0, percent: 0 };
            for (const subject of SUBJECTS) {
                const s = subjectStats(progress, subject);
                totals.solved += s.solved;
                totals.correct += s.correct;
                totals.wrong += s.wrong;
            }
            totals.percent = totals.solved ? Math.round((totals.correct / totals.solved) * 100) : 0;
            return totals;
        }

        function fingerprint(progress) {
            const parts = [];
            for (const subject of SUBJECTS) {
                const bucket = progress[subject] || {};
                const keys = Object.keys(bucket)
                    .filter(k => k !== '_total' && bucket[k] && bucket[k].selected)
                    .map(Number)
                    .sort((a, b) => a - b);
                parts.push(subject + ':' + keys.map(k => k + bucket[k].selected + (bucket[k].isCorrect ? '1' : '0')).join(','));
            }
            return parts.join('|');
        }

        function mergeProgress(cloud, local) {
            const merged = { _meta: { lastVisited: {} } };
            for (const subject of SUBJECTS) {
                const fromCloud = cloud[subject] || {};
                const fromLocal = local[subject] || {};
                const bucket = {};

                for (const key in fromCloud) {
                    if (key !== '_total' && fromCloud[key] && fromCloud[key].selected) bucket[key] = fromCloud[key];
                }
                for (const key in fromLocal) {
                    if (key === '_total') continue;
                    if (!fromLocal[key] || !fromLocal[key].selected) continue;

                    const existing = bucket[key];
                    if (!existing) {
                        bucket[key] = fromLocal[key];
                        continue;
                    }
                    // İki tarafta da çözülmüş: doğru cevap yanlışın üstünde kalır.
                    if (!existing.isCorrect && fromLocal[key].isCorrect) {
                        bucket[key] = fromLocal[key];
                    }
                }

                const total = Math.max(Number(fromCloud._total) || 0, Number(fromLocal._total) || 0);
                if (total) bucket._total = total;
                merged[subject] = bucket;

                const visited = Math.max(
                    Number(cloud._meta?.lastVisited?.[subject]) || 0,
                    Number(local._meta?.lastVisited?.[subject]) || 0
                );
                if (visited) merged._meta.lastVisited[subject] = visited;
            }
            return merged;
        }

        function renderTotals(container, stats) {
            container.innerHTML = `
                <div class="sync-stat"><b>${stats.solved}</b><small>Çözülen</small></div>
                <div class="sync-stat"><b class="is-success">${stats.correct}</b><small>Doğru</small></div>
                <div class="sync-stat"><b class="is-danger">${stats.wrong}</b><small>Yanlış</small></div>
                <div class="sync-stat"><b class="is-primary">%${stats.percent}</b><small>Başarı</small></div>
            `;
        }

        function renderRows(container, progress) {
            container.innerHTML = SUBJECTS.map(subject => {
                const s = subjectStats(progress, subject);
                const total = Number((progress[subject] || {})._total) || 0;
                return `<div class="sync-row">
                    <span class="sync-row-name">${LABELS[subject]}</span>
                    <span class="sync-row-value"><em>${s.solved}</em>${total ? ' / ' + total : ''} çözülen · <em>%${s.percent}</em> başarı</span>
                </div>`;
            }).join('');
        }

        function persist(progress) {
            localStorage.setItem('lgsProgress', JSON.stringify(progress));
        }

        async function push(progress) {
            const res = await fetch('progress.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'push', progress })
            });
            if (!res.ok) throw new Error('push failed');
        }

        function finish() {
            location.replace('index.php');
        }

        function showError(text) {
            message.textContent = text;
            message.classList.remove('is-success');
            message.classList.add('is-error');
            message.hidden = false;
            document.querySelectorAll('.sync-btn').forEach(b => { b.disabled = false; });
        }

        function renderComparison(local, cloud) {
            const localStats = overallStats(local);
            const cloudStats = overallStats(cloud);

            renderTotals(document.getElementById('totals-local'), localStats);
            renderTotals(document.getElementById('totals-cloud'), cloudStats);
            renderRows(document.getElementById('rows-local'), local);
            renderRows(document.getElementById('rows-cloud'), cloud);

            const cloudScore = cloudStats.solved * 1000 + cloudStats.correct;
            const localScore = localStats.solved * 1000 + localStats.correct;

            if (cloudScore > localScore) {
                document.getElementById('badge-cloud').hidden = false;
                document.getElementById('card-cloud').classList.add('is-leading');
            } else if (localScore > cloudScore) {
                document.getElementById('badge-local').hidden = false;
                document.getElementById('card-local').classList.add('is-leading');
            } else {
                document.getElementById('sync-lead').textContent =
                    'İki taraftaki ilerleme farklı ama çözülen soru sayısı eşit. Hangisiyle devam etmek istersin?';
            }

            loading.hidden = true;
            wrap.hidden = false;
        }

        document.querySelectorAll('.sync-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                document.querySelectorAll('.sync-btn').forEach(b => { b.disabled = true; });
                message.hidden = true;

                const local = readLocal();
                const cloud = window.__cloudProgress || {};

                try {
                    if (btn.dataset.choice === 'cloud') {
                        persist(cloud);
                    } else if (btn.dataset.choice === 'local') {
                        await push(local);
                    } else {
                        const merged = mergeProgress(cloud, local);
                        persist(merged);
                        await push(merged);
                    }
                    finish();
                } catch (e) {
                    showError('İlerleme kaydedilemedi. İnternet bağlantını kontrol edip tekrar dene.');
                }
            });
        });

        (async () => {
            let cloud = null;
            try {
                const res = await fetch('progress.php', { cache: 'no-store' });
                if (res.status === 401) {
                    location.replace('login.php');
                    return;
                }
                if (!res.ok) throw new Error('load failed');
                const data = await res.json();
                cloud = data.progress || {};
            } catch (e) {
                // Static preview without PHP: nothing to sync, keep using local data.
                finish();
                return;
            }

            const local = readLocal();
            const localEmpty = overallStats(local).solved === 0;
            const cloudEmpty = overallStats(cloud).solved === 0;

            if (localEmpty && cloudEmpty) {
                finish();
                return;
            }
            if (localEmpty) {
                persist(cloud);
                finish();
                return;
            }
            if (cloudEmpty) {
                // Güncelleme öncesinden yerel verisi olan kullanıcı: hesabında ilerleme yok.
                // Sessizce yüklemek yerine seçim ekranını göster.
                window.__cloudProgress = cloud;
                renderComparison(local, cloud);
                document.getElementById('sync-lead').textContent =
                    'Bu cihazda kayıtlı ilerlemen var, hesabında ise henüz ilerleme yok. ' +
                    'Yerel ilerlemeni hesabına kaydetmek için "Yerel ile devam et" ya da "İkisini birleştir" seçeneğini kullan.';
                const cloudNote = document.querySelector('.sync-btn[data-choice="cloud"] small');
                if (cloudNote) {
                    cloudNote.textContent = 'Hesabında ilerleme yok — bu cihazdaki ilerleme silinir.';
                }
                return;
            }
            if (fingerprint(local) === fingerprint(cloud)) {
                finish();
                return;
            }

            window.__cloudProgress = cloud;
            renderComparison(local, cloud);
        })();
    </script>
</body>
</html>
