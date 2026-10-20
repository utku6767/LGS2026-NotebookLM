-- ============================================================
--  LGS Etkileşimli Soru Çözümü — veritabanı şeması
--  Hedef    : InfinityFree (MariaDB 10.x) / MySQL 5.7+
--  Karakter : utf8mb4 / utf8mb4_unicode_ci
--
--  KURULUM (phpMyAdmin):
--    1. Panel > MySQL Databases > veritabanını oluştur
--       (adı hesaba göre if0_XXXXXXXX_lgs gibi olur)
--    2. phpMyAdmin'i aç, SOLDAN o veritabanını seç
--    3. Import > lgs.sql > Go
--
--  Bu dosyada veritabanı adı sabit değildir, USE satırı bilerek yoktur;
--  böylece her hesapta aynı dosya çalışır. Komut satırından yüklerken
--  veritabanını ayrıca belirt:
--    mysql -h sqlXXX.infinityfree.com -u if0_XXXXXXXX -p if0_XXXXXXXX_lgs < lgs.sql
--
--  Alternatif: tarayıcıdan install.php'yi aç. .env'deki veritabanına bağlanır,
--  veritabanı yoksa oluşturmayı dener, bu şemayı uygular ve eski
--  answers / subject_state tablolarını tek satırlık bloğa taşır.
--
--  TASARIM: bir kullanıcının bütün çözümleri TEK satırda durur.
--  Lichess bir oyunun bütün hamlelerini tek bir PGN metninde saklar;
--  burada da soru cevapları progress.data içindeki tek bir JSON
--  metninde saklanır. Soru başına satır yoktur, ders başına satır yoktur:
--  kullanıcı başına 1 satır.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+03:00';

-- InfinityFree'de veritabanı kontrol panelinden açılır ve kullanıcıya
-- CREATE DATABASE yetkisi verilmez. Kendi sunucunuzda çalıştırıyorsanız
-- aşağıdaki satırın yorumunu kaldırıp adı kendi veritabanınıza göre düzeltin.
-- CREATE DATABASE IF NOT EXISTS `lgs`
--   DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `lgs`;

-- Sıfırdan kurmak (mevcut kayıtları silmek) isterseniz şu satırların yorumunu kaldırın:
-- DROP TABLE IF EXISTS `remember_tokens`;
-- DROP TABLE IF EXISTS `progress`;
-- DROP TABLE IF EXISTS `answers`;
-- DROP TABLE IF EXISTS `subject_state`;
-- DROP TABLE IF EXISTS `users`;


-- ------------------------------------------------------------
--  users — hesaplar
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(32)      NOT NULL,
  `display_name`  VARCHAR(60)      NOT NULL,
  `password_hash` VARCHAR(255)     NOT NULL,
  `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME         NULL DEFAULT NULL,
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
  `is_admin`      TINYINT(1)       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bu şema e-posta istemez; hesaplar kullanıcı adı + şifre ile çalışır.
-- Daha önce e-posta sütunuyla kurulum yaptıysanız phpMyAdmin'de bir kez çalıştırın:
-- ALTER TABLE `users` DROP COLUMN `email`;

-- Admin / debug bayrağı.
-- Giriş sayfasında Konami kodu (↑↑↓↓←→←→BA Enter) yalnızca O OTURUM için
-- debug araçlarını açar. Kalıcı admin (her cihaz, her oturum) için:
--   UPDATE `users` SET `is_admin` = 1 WHERE `username` = 'kullanici_adi';
-- Geri almak için aynı satırda 1 yerine 0 yazın.
-- Admin hesaplar deneme sayfasında "⏱ BİTİR" ve "⚡ 10sn" araçlarını görür.


-- ------------------------------------------------------------
--  progress — kullanıcı başına TEK satır
--
--  data : JSON metni, uygulamadaki localStorage['lgsProgress'] ile aynı şekil
--         {"matematik":{"0":{"selected":"B","isCorrect":true},"_total":20},
--          "turkce":{...},
--          "_meta":{"lastVisited":{"matematik":7}}}
--         Anahtar = 0 tabanlı soru sırası, seçenek A-D.
--  answered / correct : bloğu açmadan okunabilen özet sayaçlar
--         (Lichess'in nb_moves / status sütunlarının karşılığı);
--         her push'ta yeniden hesaplanır.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `progress` (
  `user_id`    INT UNSIGNED      NOT NULL,
  `data`       MEDIUMTEXT        NOT NULL,
  `answered`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `correct`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  deneme_attempts — süreli deneme (sözel / sayısal / maraton)
--  Kullanıcı başına mod başına tek satır. Süre sunucu saatinden
--  hesaplanır: deadline_at = started_at + (75 | 80 | 155) dakika.
--  data : {"turkce":{"0":"B","1":"A"}, "fen":{...}} — verilen cevaplar
--  finished_at dolduysa deneme bitmiştir (elle ya da süre dolunca).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deneme_attempts` (
  `user_id`      INT UNSIGNED NOT NULL,
  `mode`         VARCHAR(16)  NOT NULL,
  `show_answers` TINYINT(1)   NOT NULL DEFAULT 0,
  `started_at`   DATETIME     NOT NULL,
  `deadline_at`  DATETIME     NOT NULL,
  `finished_at`  DATETIME     NULL DEFAULT NULL,
  `data`         MEDIUMTEXT   NOT NULL,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `mode`),
  CONSTRAINT `fk_deneme_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  remember_tokens — "Beni hatırla" kalıcı giriş jetonları
--  Çerezde yalnızca selector:validator düz metin gider; validator'ın
--  kendisi veritabanında saklanmaz, sha256 özeti tutulur.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED    NOT NULL,
  `selector`       CHAR(16)        NOT NULL,
  `validator_hash` CHAR(64)        NOT NULL,
  `expires_at`     DATETIME        NOT NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `ix_remember_user` (`user_id`),
  KEY `ix_remember_expiry` (`expires_at`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
--  Eski kurulumdan geçiş (install.php otomatik yapar)
--  answers + subject_state tablolarındaki kayıtlar progress.data
--  bloğuna taşındıktan sonra bu iki tablo silinir.
-- ------------------------------------------------------------
-- DROP TABLE IF EXISTS `answers`;
-- DROP TABLE IF EXISTS `subject_state`;


-- ------------------------------------------------------------
--  Örnek sorgular
-- ------------------------------------------------------------
-- Kullanıcı özeti (bloğu açmadan):
-- SELECT u.username, p.answered, p.correct, p.updated_at
--   FROM users u
--   LEFT JOIN progress p ON p.user_id = u.id
--  ORDER BY p.correct DESC, p.updated_at DESC;

-- Matematikte çözülen soru sayısı (JSON içinden):
-- SELECT u.username,
--        JSON_LENGTH(JSON_EXTRACT(p.data, '$.matematik')) AS matematik_anahtar
--   FROM users u
--   JOIN progress p ON p.user_id = u.id
--  WHERE u.username = 'ornek_kullanici';
