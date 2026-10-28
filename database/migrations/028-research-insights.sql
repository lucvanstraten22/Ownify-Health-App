-- ============================================================================
--  028 — research and insights: the approved research registry, each
--        person's daily insights, and a separate consent for Gemini wording
--                                                                    (29.0)
-- ----------------------------------------------------------------------------
--  Additive, plus one guarded removal. Nothing existing is rewritten:
--
--    research_sources   one row per approved publication: its title, people,
--                       year, links, identifiers, licence and what may be
--                       done with its PDF. Only sources the owner approved
--                       one by one (config/research.php, docs/RESEARCH.md).
--    research_claims    one row per VERSION of each claim Ownify uses: the
--                       finding with its units, the population and ages it
--                       holds for, its strength and its limitations. A new
--                       meaning is a new version; the old one is retired,
--                       never deleted, so an insight stored with it still
--                       shows the research it used.
--    insight_days       per person per local day: the lock that makes one
--                       request work the day out, when it was worked out,
--                       whether data changed since, and Gemini's one call
--                       with its tokens (docs/INSIGHTS.md).
--    user_insights      per person per local day per slot (sleep,
--                       nutrition, training, body, suggestion): the rule,
--                       the claim version, the strength shown, the derived
--                       facts the text is written from — numbers, never raw
--                       records — and Gemini's wording where there is one.
--    user_profiles      ai_insights_consent (+ _version, _at): Gemini may
--                       reword insights only after this separate yes. The
--                       chat's ai_consent is a different question and is not
--                       read for this.
--    data_sources       the 'apple_health' row is deleted — Ownify does not
--                       support Apple Health — but only when no record, link
--                       or device refers to it.
--
--  Every table that holds a person's insights hangs off users with ON DELETE
--  CASCADE: deleting an account deletes them with it.
--
--  The registry seed at the end is generated from config/research.php
--  (php tools/research-registry.php --write) and is INSERT IGNORE: it never
--  overwrites a newer registry that --sync wrote. tools/research-registry.php
--  --check says whether a database holds the reviewed registry.
--
--  Until this is imported the Overview keeps its empty cards and nothing
--  else changes (insights_installed(), research_installed()).
--
--  NO `USE` STATEMENT. phpMyAdmin: select the database first. Back up first.
--  Command line: mysql -u USER -p DATABASE < this file. Re-running it is safe.
-- ============================================================================

-- The registry's texts hold letters and dashes outside ASCII (ë, –, …). Read
-- this file as UTF-8 whatever the client's default is: a command-line client
-- defaulting to latin1 would otherwise store them garbled.
SET NAMES utf8mb4;

-- 1. The approved publications.
CREATE TABLE IF NOT EXISTS `research_sources` (
    `code`          VARCHAR(16)  NOT NULL COMMENT 'Stable id, e.g. SLP-01 (config/research.php)',
    `status`        ENUM('approved','withdrawn') NOT NULL DEFAULT 'approved' COMMENT 'Only approved sources support an insight',
    `kind`          VARCHAR(32)  NOT NULL COMMENT 'guideline, consensus, meta_analysis … (config/research.php kinds)',
    `language`      CHAR(2)      NOT NULL DEFAULT 'en' COMMENT 'The language the publication is written in: nl or en',
    `title`         VARCHAR(400) NOT NULL COMMENT 'Exactly as published, in that language',
    `authors`       VARCHAR(255) NULL,
    `organisation`  VARCHAR(191) NULL,
    `published`     VARCHAR(10)  NOT NULL COMMENT 'YYYY or YYYY-MM-DD, as the source dates itself',
    `citation`      VARCHAR(255) NOT NULL,
    `url`           VARCHAR(500) NOT NULL COMMENT 'The original publication',
    `doi`           VARCHAR(100) NULL,
    `pmid`          VARCHAR(16)  NULL,
    `pmcid`         VARCHAR(16)  NULL,
    `other_id`      VARCHAR(100) NULL COMMENT 'ISBN or publication number',
    `pdf_mode`      ENUM('hosted','official_pdf','link') NOT NULL COMMENT 'hosted: CC BY, served by Ownify; official_pdf: fetched by the device from the publisher; link: the publisher only',
    `pdf_url`       VARCHAR(500) NULL COMMENT 'The official PDF or reading page',
    `pdf_licence`   VARCHAR(100) NOT NULL,
    `pdf_hosted`    VARCHAR(100) NULL COMMENT 'hosted only: the unmodified copy under assets/research/',
    `pdf_embed`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'official_pdf: the publisher allows it inside a page',
    `findings`      TEXT         NOT NULL COMMENT 'What the source found, in Dutch (English: lang/en/research.php)',
    `approved_on`   DATE         NOT NULL,
    `verified_on`   DATE         NOT NULL,
    `content_hash`  CHAR(64)     NOT NULL COMMENT 'sha256 of the reviewed record (tools/research-registry.php)',
    `synced_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. The claims Ownify uses, one row per version.
CREATE TABLE IF NOT EXISTS `research_claims` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `claim_key`        VARCHAR(64)  NOT NULL COMMENT 'Stable, e.g. sleep.duration.adults',
    `version`          SMALLINT UNSIGNED NOT NULL COMMENT 'Raised when the claim''s meaning, numbers or text change',
    `source_code`      VARCHAR(16)  NOT NULL,
    `status`           ENUM('active','retired') NOT NULL DEFAULT 'active' COMMENT 'One active version per claim; retired ones stay for the insights that used them',
    `topic`            VARCHAR(64)  NOT NULL,
    `metric`           VARCHAR(64)  NULL,
    `age_min`          TINYINT UNSIGNED NULL COMMENT 'Years, inclusive; NULL: no lower bound',
    `age_max`          TINYINT UNSIGNED NULL COMMENT 'Years, inclusive; NULL: no upper bound',
    `strength`         ENUM('zeer_betrouwbaar','betrouwbaar','beperkt') NOT NULL COMMENT 'The evidence for this claim itself',
    `finding_json`     TEXT NOT NULL COMMENT 'The numbers with their units, machine-readable; age bands as the source draws them',
    `statement`        TEXT NOT NULL COMMENT 'The claim Ownify uses, in Dutch',
    `population`       VARCHAR(255) NOT NULL,
    `limitations_json` TEXT NOT NULL COMMENT 'JSON list of Dutch sentences',
    `approved_on`      DATE NOT NULL,
    `content_hash`     CHAR(64) NOT NULL COMMENT 'sha256 of the reviewed record',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `retired_at`       DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_claim_version` (`claim_key`, `version`),
    KEY `idx_claim_status` (`status`, `topic`),
    KEY `idx_claim_source` (`source_code`),
    CONSTRAINT `fk_claim_source` FOREIGN KEY (`source_code`) REFERENCES `research_sources` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Each person's day: worked out once, by one request.
--    The first request of a local day inserts this row (INSERT IGNORE on the
--    primary key) and works the day out; any request that finds it
--    'running' reads what is stored. A 'running' row older than two minutes
--    (a request that died) may be taken over, by a compare-and-set on
--    started_at. A save or sync after evaluated_at sets data_changed_at, and
--    the next load reassesses the stored insights — without a new Gemini
--    call and without a second suggestion.
CREATE TABLE IF NOT EXISTS `insight_days` (
    `user_id`          BIGINT UNSIGNED NOT NULL,
    `local_date`       DATE NOT NULL COMMENT 'The day in Europe/Amsterdam, Ownify''s one clock',
    `state`            ENUM('running','done') NOT NULL DEFAULT 'running',
    `started_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the running claim was taken',
    `evaluated_at`     DATETIME NULL COMMENT 'The last full evaluation or reassessment',
    `data_changed_at`  DATETIME NULL COMMENT 'A save or sync after evaluated_at: reassess on the next load',
    `evaluations`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Today''s evaluations, the first and each reassessment',
    `facts_hash`       CHAR(64) NULL COMMENT 'The derived facts the day was worked out from',
    `engine_version`   VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'config/insights.php engine_version',
    `phrase_state`     ENUM('none','done','skipped','failed') NOT NULL DEFAULT 'none' COMMENT 'Gemini''s wording: only with ai_insights_consent',
    `phrase_note`      VARCHAR(24) NULL COMMENT 'Why skipped or failed: consent, budget, pace, quota, invalid, timeout …',
    `gemini_calls`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'At most one a day',
    `input_tokens`     INT UNSIGNED NOT NULL DEFAULT 0,
    `output_tokens`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Thinking included, as Gemini counts it',
    `cached_tokens`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'usageMetadata.cachedContentTokenCount, when Gemini reports it',
    PRIMARY KEY (`user_id`, `local_date`),
    CONSTRAINT `fk_insight_days_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. The insights themselves: one per slot per local day, the same for the
--    website and the Android app.
CREATE TABLE IF NOT EXISTS `user_insights` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `local_date`     DATE NOT NULL,
    `slot`           VARCHAR(16) NOT NULL COMMENT 'sleep, nutrition, training, body; suggestion (Eén kleine suggestie, at most one a day)',
    `rule_key`       VARCHAR(64) NOT NULL COMMENT 'config/insights.php',
    `outcome`        VARCHAR(32) NOT NULL COMMENT 'below, within, above … : which sentence',
    `claim_id`       INT UNSIGNED NOT NULL COMMENT 'The exact claim version used',
    `strength`       ENUM('zeer_betrouwbaar','betrouwbaar','beperkt') NOT NULL COMMENT 'As shown: the claim''s, lowered where Ownify''s data only approximates it',
    `applicability`  ENUM('direct','proxy','weak') NOT NULL,
    `facts_json`     TEXT NOT NULL COMMENT 'The derived facts the sentence is written from: numbers and units, never raw records, never a birth date',
    `fingerprint`    CHAR(64) NOT NULL COMMENT 'sha256 of rule, outcome, claim and the rounded facts',
    `rank_score`     SMALLINT NOT NULL DEFAULT 0,
    `phrased_locale` CHAR(2) NULL COMMENT 'The language Gemini wrote in; the template is shown in any other',
    `phrased_body`   VARCHAR(600) NULL COMMENT 'Gemini''s validated wording of the body; NULL: the template',
    `phrased_model`  VARCHAR(80) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `valid_until`    DATETIME NOT NULL COMMENT 'The end of its local day',
    `withdrawn_at`   DATETIME NULL COMMENT 'A reassessment found it no longer holds',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_insight_slot` (`user_id`, `local_date`, `slot`),
    KEY `idx_user_insight_rule` (`user_id`, `rule_key`, `local_date`),
    KEY `idx_user_insight_claim` (`claim_id`),
    CONSTRAINT `fk_user_insights_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_insights_claim` FOREIGN KEY (`claim_id`) REFERENCES `research_claims` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. The separate consent: Gemini may reword insights. NULL never asked,
--    1 allowed, 0 declined or withdrawn. config/insights.php
--    consent_version names the wording; a new version asks everybody again.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles' AND column_name = 'ai_insights_consent') > 0,
    'SELECT ''user_profiles.ai_insights_consent already there'' AS note',
    'ALTER TABLE `user_profiles` ADD COLUMN `ai_insights_consent` TINYINT(1) NULL DEFAULT NULL
        COMMENT ''Gemini may reword insights: NULL never asked, 1 allowed, 0 declined or withdrawn'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles' AND column_name = 'ai_insights_consent_version') > 0,
    'SELECT ''user_profiles.ai_insights_consent_version already there'' AS note',
    'ALTER TABLE `user_profiles` ADD COLUMN `ai_insights_consent_version` VARCHAR(32) NULL DEFAULT NULL
        COMMENT ''The wording that was answered (config/insights.php consent_version)'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'user_profiles' AND column_name = 'ai_insights_consent_at') > 0,
    'SELECT ''user_profiles.ai_insights_consent_at already there'' AS note',
    'ALTER TABLE `user_profiles` ADD COLUMN `ai_insights_consent_at` DATETIME NULL DEFAULT NULL
        COMMENT ''When the insight-wording consent was last answered'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Apple Health is not supported: its catalogue row goes, but only when
--    nothing refers to it — no record (the five tables whose source_id
--    points here), no link, no pairing code and no device naming it. When
--    something does, the row stays and nothing is lost.
DELETE FROM `data_sources`
 WHERE `code` = 'apple_health'
   AND NOT EXISTS (SELECT 1 FROM `health_metrics`    r WHERE r.`source_id` = `data_sources`.`id`)
   AND NOT EXISTS (SELECT 1 FROM `sleep_sessions`    r WHERE r.`source_id` = `data_sources`.`id`)
   AND NOT EXISTS (SELECT 1 FROM `workouts`          r WHERE r.`source_id` = `data_sources`.`id`)
   AND NOT EXISTS (SELECT 1 FROM `nutrition_entries` r WHERE r.`source_id` = `data_sources`.`id`)
   AND NOT EXISTS (SELECT 1 FROM `user_measurements` r WHERE r.`source_id` = `data_sources`.`id`)
   AND NOT EXISTS (SELECT 1 FROM `user_integrations`        r WHERE r.`provider` = 'apple_health')
   AND NOT EXISTS (SELECT 1 FROM `integration_oauth_states` r WHERE r.`provider` = 'apple_health')
   AND NOT EXISTS (SELECT 1 FROM `device_pairing_codes`     r WHERE r.`provider` = 'apple_health')
   AND NOT EXISTS (SELECT 1 FROM `user_devices`             r WHERE r.`provider` = 'apple_health');

-- 7. The approved registry.
-- >>> REGISTRY SEED: generated by php tools/research-registry.php --write. Do not edit by hand.
-- 17 approved sources and 25 claims (config/research.php).
INSERT IGNORE INTO `research_sources` (`code`, `status`, `kind`, `language`, `title`, `authors`, `organisation`, `published`, `citation`, `url`, `doi`, `pmid`, `pmcid`, `other_id`, `pdf_mode`, `pdf_url`, `pdf_licence`, `pdf_hosted`, `pdf_embed`, `findings`, `approved_on`, `verified_on`, `content_hash`) VALUES
    ('SLP-01', 'approved', 'health_info', 'en', 'About Sleep', NULL, 'Centers for Disease Control and Prevention (CDC)', '2024-05-15', 'CDC, Sleep, About Sleep (15 mei 2024)', 'https://www.cdc.gov/sleep/about/', NULL, NULL, NULL, NULL, 'link', NULL, 'Webpagina, geen pdf', NULL, 0, 'Aanbevolen slaap per dag: 6–12 jaar 9–12 uur, 13–17 jaar 8–10 uur, 18–60 jaar 7 uur of meer, 61–64 jaar 7–9 uur en vanaf 65 jaar 7–8 uur.', '2026-10-27', '2026-10-27', 'adf97249fe11bd1480fd11ba7d20c2d5f985dc3e83afb7bde780d0db14117c62'),
    ('SLP-02', 'approved', 'consensus', 'en', 'Recommended Amount of Sleep for a Healthy Adult: A Joint Consensus Statement of the American Academy of Sleep Medicine and Sleep Research Society', 'Watson NF, Badr MS, Belenky G, et al.', 'American Academy of Sleep Medicine en Sleep Research Society', '2015', 'Sleep 2015;38(6):843–844', 'https://doi.org/10.5665/sleep.4716', '10.5665/sleep.4716', '26039963', 'PMC4434546', NULL, 'link', 'https://pmc.ncbi.nlm.nih.gov/articles/PMC4434546/', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Volwassenen horen regelmatig 7 uur of meer per nacht te slapen. Regelmatig korter dan 7 uur slapen hangt samen met onder meer gewichtstoename, diabetes, hoge bloeddruk, hart- en vaatziekten, depressie en een hoger sterfterisico. Meer dan 9 uur kan passend zijn voor jonge volwassenen, bij het inhalen van slaaptekort of bij ziekte; voor anderen is onzeker of dat een risico geeft.', '2026-10-27', '2026-10-27', '4ee06bef2d78ae467f90aafcab4967b243fe60626e9dd134cc1c2c81e5b0c747'),
    ('SLP-03', 'approved', 'consensus', 'en', 'National Sleep Foundation''s sleep time duration recommendations: methodology and results summary', 'Hirshkowitz M, Whiton K, Albert SM, et al.', 'National Sleep Foundation', '2015', 'Sleep Health 2015;1(1):40–43', 'https://doi.org/10.1016/j.sleh.2014.12.010', '10.1016/j.sleh.2014.12.010', '29073412', NULL, NULL, 'link', 'https://www.sleephealthjournal.org/article/S2352721815000157/pdf', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Een panel van deskundigen noemt als passende slaapduur: schoolkinderen 9–11 uur, tieners (14–17) 8–10 uur, jongvolwassenen en volwassenen (18–64) 7–9 uur en ouderen (65+) 7–8 uur. Wat daarbuiten valt kan passend zijn, maar grote afwijkingen zijn zeldzaam.', '2026-10-27', '2026-10-27', 'cc85e70e1228b7aa3d67531940917a5fba5c2a5ce276c784abca0bd03e730927'),
    ('SLP-04', 'approved', 'consensus', 'en', 'Recommended Amount of Sleep for Pediatric Populations: A Consensus Statement of the American Academy of Sleep Medicine', 'Paruthi S, Brooks LJ, D''Ambrosio C, et al.', 'American Academy of Sleep Medicine', '2016', 'J Clin Sleep Med 2016;12(6):785–786', 'https://doi.org/10.5664/jcsm.5866', '10.5664/jcsm.5866', '27250809', 'PMC4877308', NULL, 'link', 'https://pmc.ncbi.nlm.nih.gov/articles/PMC4877308/', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Kinderen van 6–12 jaar horen 9–12 uur per 24 uur te slapen en tieners van 13–18 jaar 8–10 uur. Regelmatig korter slapen hangt samen met problemen met aandacht, gedrag en leren, en met meer ongelukken, hoge bloeddruk, overgewicht, diabetes en depressie.', '2026-10-27', '2026-10-27', 'b9f848958ebe2351033af6a2a6a02b8e754220fdbbe4730e96b5959fc9bd0110'),
    ('SLP-05', 'approved', 'consensus', 'en', 'The importance of sleep regularity: a consensus statement of the National Sleep Foundation sleep timing and variability panel', 'Sletten TL, Weaver MD, Foster RG, et al.', 'National Sleep Foundation', '2023', 'Sleep Health 2023;9(6):801–820', 'https://doi.org/10.1016/j.sleh.2023.07.016', '10.1016/j.sleh.2023.07.016', '37684151', NULL, NULL, 'link', 'https://www.sleephealthjournal.org/article/S2352721823001663/pdf', 'Gratis te lezen, alle rechten voorbehouden', NULL, 0, 'Na een literatuuronderzoek concludeerde een panel van slaap- en ritmedeskundigen dat regelmatige tijden van inslapen en opstaan belangrijk zijn voor gezondheid, veiligheid en prestaties. Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen.', '2026-10-27', '2026-10-27', '22b398f9d3acfa7730628adc2a466482c81beac21f9671a42fde6aed2603a463'),
    ('SLP-06', 'approved', 'validation_study', 'en', 'Performance of seven consumer sleep-tracking devices compared with polysomnography', 'Chinoy ED, Cuellar JA, Huwa KE, et al.', NULL, '2021', 'Sleep 2021;44(5):zsaa291', 'https://doi.org/10.1093/sleep/zsaa291', '10.1093/sleep/zsaa291', '33378539', 'PMC8120339', NULL, 'link', 'https://academic.oup.com/sleep/article-pdf/44/5/zsaa291/37933402/zsaa291.pdf', 'CC BY-NC 4.0', NULL, 0, 'In een slaaplaboratorium (34 gezonde jonge volwassenen, 3 nachten) herkenden zeven consumentenapparaten slaap goed, maar wakker zijn veel minder goed. Slaapfasen kwamen wisselend overeen en op onrustige nachten werkten de apparaten slechter.', '2026-10-27', '2026-10-27', '7b30b39f9a6bc0cf9f6398f0eb416ca3856e30d8a8ae05a2cb361a95d0edd719'),
    ('ACT-01', 'approved', 'guideline', 'en', 'WHO guidelines on physical activity and sedentary behaviour', NULL, 'Wereldgezondheidsorganisatie (WHO)', '2020-11-25', 'WHO, Genève 2020, 104 pagina''s', 'https://www.who.int/publications/i/item/9789240015128', NULL, NULL, NULL, 'ISBN 9789240015128', 'official_pdf', 'https://iris.who.int/server/api/core/bitstreams/faa83413-d89e-4be9-bb01-b24671aef7ca/content', 'CC BY-NC-SA 3.0 IGO', NULL, 0, 'Volwassenen (18–64): per week minstens 150–300 minuten matig of 75–150 minuten zwaar intensieve aerobe activiteit, plus spierversterkende activiteiten op 2 of meer dagen (sterke aanbeveling, matige zekerheid). Vanaf 65: hetzelfde, plus gevarieerde activiteit met nadruk op balans en kracht op 3 of meer dagen. 5–17 jaar: gemiddeld 60 minuten per dag. Beperk zitten; een beetje bewegen is beter dan niets.', '2026-10-27', '2026-10-27', '33c18ff96c3f52c9f7855aa610bc4945a08faa9adead92da73d96687bcf7829b'),
    ('ACT-02', 'approved', 'guideline', 'nl', 'Beweegrichtlijnen 2017', NULL, 'Gezondheidsraad', '2017-08-22', 'Gezondheidsraad, Den Haag 2017, publicatienr. 2017/08', 'https://www.gezondheidsraad.nl/documenten/adviezen/2017/08/22/beweegrichtlijnen-2017', NULL, NULL, NULL, 'Gezondheidsraad nr. 2017/08', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2017/08/22/beweegrichtlijnen-2017/kernadvies-Beweegrichtlijnen-2017.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Bewegen is goed, meer bewegen is beter. Volwassenen en ouderen: minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, minstens twee keer per week spier- en botversterkende activiteiten (ouderen met balansoefeningen) en voorkom veel stilzitten. Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.', '2026-10-27', '2026-10-27', '87a589ee44e18620ca9efc1da5190a0a6e6afdaf2fbc6666ebbcc27b7cfa7c8a'),
    ('ACT-03', 'approved', 'systematic_review', 'en', 'Daily steps and health outcomes in adults: a systematic review and dose-response meta-analysis', 'Ding D, Nguyen B, Nau T, et al.', NULL, '2025', 'Lancet Public Health 2025;10(8):e668–e681', 'https://doi.org/10.1016/S2468-2667(25)00164-1', '10.1016/S2468-2667(25)00164-1', '40713949', NULL, NULL, 'link', 'https://www.thelancet.com/pdfs/journals/lanpub/PIIS2468-2667(25)00164-1.pdf', 'CC BY-NC 4.0', NULL, 0, 'In 57 studies uit 35 cohorten hing 7.000 stappen per dag, vergeleken met 2.000, samen met 47% minder sterfte, 25% minder hart- en vaatziekten, 38% minder dementie en 22% minder depressieve klachten. Voor sterfte vlakte het verband af rond 5.000–7.000 stappen. De zekerheid van het bewijs was voor de meeste uitkomsten matig.', '2026-10-27', '2026-10-27', '5430795da8d32be65b37edd2e8c9ecaa419bc71b8397bb0a28b33a603ffa7e42'),
    ('ACT-04', 'approved', 'meta_analysis', 'en', 'Daily steps and all-cause mortality: a meta-analysis of 15 international cohorts', 'Paluch AE, Bajpai S, Bassett DR, et al.', 'Steps for Health Collaborative', '2022', 'Lancet Public Health 2022;7(3):e219–e228', 'https://doi.org/10.1016/S2468-2667(21)00302-9', '10.1016/S2468-2667(21)00302-9', '35247352', 'PMC9289978', NULL, 'link', 'https://www.thelancet.com/article/S2468266721003029/pdf', 'CC BY-NC-ND 4.0', NULL, 0, 'In 15 cohorten (47.471 volwassenen) hing meer stappen per dag samen met een steeds lager sterfterisico, tot het afvlakte rond 6.000–8.000 stappen bij 60-plussers en rond 8.000–10.000 bij volwassenen onder de 60.', '2026-10-27', '2026-10-27', '5b1b9a2c2f9517aa17c442da4de7588ebd4fb73584dca13bfdd76aa969ba671d'),
    ('MEA-01', 'approved', 'umbrella_review', 'en', 'Keeping Pace with Wearables: A Living Umbrella Review of Systematic Reviews Evaluating the Accuracy of Consumer Wearable Technologies in Health Measurement', 'Doherty C, Baldwin M, Keogh A, et al.', NULL, '2024', 'Sports Med 2024;54(11):2907–2926', 'https://doi.org/10.1007/s40279-024-02077-2', '10.1007/s40279-024-02077-2', '39080098', 'PMC11560992', NULL, 'hosted', 'https://link.springer.com/content/pdf/10.1007/s40279-024-02077-2.pdf', 'CC BY 4.0', 'assets/research/MEA-01.pdf', 0, 'Een overzicht van 24 systematische reviews (249 validatiestudies): hartslag wijkt gemiddeld ongeveer 3% af, stappen worden meestal iets ondergeschat (−9% tot 12%), energieverbruik kan flink afwijken (−21% tot +15%), zuurstofsaturatie tot 2 procentpunt, en slaaptijd wordt meestal overschat, vaak met meer dan 10%. Ongeveer 11% van de apparaten is voor minstens één meting gevalideerd.', '2026-10-27', '2026-10-27', 'e909ddbbae61a3560405654e79e4fbeb2e8d9d821c5f456a3d88cc06c4a99199'),
    ('NUT-01', 'approved', 'reference_values', 'nl', 'Voedingsnormen voor vetten, vetzuren, verteerbare koolhydraten en voedingsvezels', NULL, 'Gezondheidsraad', '2026-07-07', 'Gezondheidsraad, Den Haag 2026, publicatienr. 2026/14 (gecorrigeerde pdf 7 september 2026)', 'https://www.gezondheidsraad.nl/documenten/2026/07/07/advies-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels', NULL, NULL, NULL, 'Gezondheidsraad nr. 2026/14', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2026/07/07/advies-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels/14-voedingsnormen-voor-vetten-vetzuren-verteerbare-koolhydraten-en-voedingsvezels-erratum.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Totaal vet: 20–40% van de energie (vanaf 4 jaar); de kwaliteit van het voedingspatroon telt meer dan de hoeveelheid vet. Verzadigd vet en vrije suikers: zo laag mogelijk, met 10% van de energie als tussenstap. Koolhydraten: geen percentage, wel vooral uit volkoren, groente, fruit, peulvruchten en zuivel. Vezels: 3,0–3,5 gram per megajoule, ofwel 12,6–14,6 gram per 1.000 kcal.', '2026-10-27', '2026-10-27', '5a09324afbd361321c07d4f0f453eb949640b902461fbc78b5f6e4c2eb09957d'),
    ('NUT-02', 'approved', 'reference_values', 'nl', 'Voedingsnormen voor eiwitten', NULL, 'Gezondheidsraad', '2021-03-02', 'Gezondheidsraad, Den Haag 2021, publicatienr. 2021/10', 'https://www.gezondheidsraad.nl/documenten/2021/03/02/voedingsnormen-voor-eiwitten', NULL, NULL, NULL, 'Gezondheidsraad nr. 2021/10', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2021/03/02/voedingsnormen-voor-eiwitten/Advies-Voedingsnormen-voor-eiwitten-referentiewaarden-voor-de-inname-van-eiwitten.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Volwassenen hebben gemiddeld 0,66 gram eiwit per kilogram lichaamsgewicht per dag nodig; de aanbevolen hoeveelheid is 0,83 gram per kilogram per dag. Er is geen overtuigend bewijs dat alle ouderen meer nodig hebben; sommige groepen, zoals kwetsbare ouderen, mogelijk wel.', '2026-10-27', '2026-10-27', '4901044d9921ea27f18fe884f4eb5750edcc2732d94a275f9cacbeb246833c3f'),
    ('NUT-03', 'approved', 'guideline', 'nl', 'Richtlijnen goede voeding 2015', NULL, 'Gezondheidsraad', '2015-11-04', 'Gezondheidsraad, Den Haag 2015, publicatienr. 2015/24, ISBN 978-94-6281-089-1', 'https://www.gezondheidsraad.nl/documenten/adviezen/2015/11/04/richtlijnen-goede-voeding-2015', NULL, NULL, NULL, 'Gezondheidsraad nr. 2015/24', 'official_pdf', 'https://www.gezondheidsraad.nl/site/binaries/site-content/collections/documents/2015/11/04/richtlijnen-goede-voeding-2015/201524_Richtlijnen+goede+voeding+2015.pdf', 'Gratis te lezen, auteursrecht voorbehouden', NULL, 1, 'Eet meer plantaardig en minder dierlijk: dagelijks minstens 200 gram groente en 200 gram fruit, minstens 90 gram volkoren, wekelijks peulvruchten, 15 gram ongezouten noten, enkele porties zuivel en wekelijks vis. Beperk rood en vooral bewerkt vlees, drink zo min mogelijk suikerhoudende dranken, geen of hooguit één glas alcohol per dag, en maximaal 6 gram zout per dag.', '2026-10-27', '2026-10-27', 'd4b8e3e614ad827cffbbe81b129c9089bcd015fc6d6a7a88601a2a2fc0e869d7'),
    ('NUT-04', 'approved', 'systematic_review', 'en', 'Comparison of predictive equations for resting metabolic rate in healthy nonobese and obese adults: a systematic review', 'Frankenfield D, Roth-Yousey L, Compher C', 'American Dietetic Association', '2005', 'J Am Diet Assoc 2005;105(5):775–789', 'https://doi.org/10.1016/j.jada.2005.02.005', '10.1016/j.jada.2005.02.005', '15883556', NULL, NULL, 'link', NULL, 'Niet vrij beschikbaar (abonnement)', NULL, 0, 'Van de vier meest gebruikte formules voorspelde Mifflin-St Jeor het rustmetabolisme het vaakst binnen 10% van de gemeten waarde, bij mensen met en zonder obesitas. Voor individuen blijven er opvallende afwijkingen.', '2026-10-27', '2026-10-27', '9c6561716d7653816381b5a54f4abfa08123c462d603d042ede57e1e12e94a0c'),
    ('NUT-05', 'approved', 'position_stand', 'en', 'International Society of Sports Nutrition Position Stand: protein and exercise', 'Jäger R, Kerksick CM, Campbell BI, et al.', 'International Society of Sports Nutrition', '2017', 'J Int Soc Sports Nutr 2017;14:20', 'https://doi.org/10.1186/s12970-017-0177-8', '10.1186/s12970-017-0177-8', '28642676', 'PMC5477153', NULL, 'hosted', 'https://jissn.biomedcentral.com/track/pdf/10.1186/s12970-017-0177-8', 'CC BY 4.0', 'assets/research/NUT-05.pdf', 0, 'Voor het opbouwen en behouden van spiermassa is 1,4–2,0 gram eiwit per kilogram lichaamsgewicht per dag voldoende voor de meeste mensen die sporten. Bij krachtsporters die afvallen kan meer nodig zijn om spiermassa te behouden.', '2026-10-27', '2026-10-27', '196181096b929753d9f09cf3bd9a65a670e13ce82df3702bfd68a55edf376cc9'),
    ('BOD-01', 'approved', 'fact_sheet', 'en', 'Obesity and overweight', NULL, 'Wereldgezondheidsorganisatie (WHO)', '2026-10-07', 'WHO-feitenblad, bijgewerkt 7 oktober 2026', 'https://www.who.int/news-room/fact-sheets/detail/obesity-and-overweight', NULL, NULL, NULL, NULL, 'link', NULL, 'Webpagina, geen pdf', NULL, 0, 'Bij volwassenen (18+) noemt de WHO een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas. Voor 5–19 jaar gelden groeicurves. Ook buikomvang kan worden gebruikt.', '2026-10-27', '2026-10-27', '1962faca69ebefc11a2aeab92a00ab735f0d63444ee9c1c2376887efd6b07f0e');

INSERT IGNORE INTO `research_claims` (`claim_key`, `version`, `source_code`, `topic`, `metric`, `age_min`, `age_max`, `strength`, `finding_json`, `statement`, `population`, `limitations_json`, `approved_on`, `content_hash`) VALUES
    ('sleep.duration.cdc', 1, 'SLP-01', 'sleep.duration', 'sleep_minutes', 6, NULL, 'betrouwbaar', '{"bands":[[6,12,{"max":12,"min":9}],[13,17,{"max":10,"min":8}],[18,60,{"max":null,"min":7}],[61,64,{"max":9,"min":7}],[65,null,{"max":8,"min":7}]],"unit":"hours"}', 'Per leeftijd een aanbevolen slaapduur: 7 uur of meer van 18 tot 60 jaar, 7–9 uur van 61 tot 64, 7–8 uur vanaf 65, en 8–10 uur voor tieners van 13 tot 17.', 'Iedereen vanaf 6 jaar, zonder slaapstoornis.', '["Een samenvatting van twee panels met iets andere leeftijdsgrenzen.","Iedereen heeft een eigen slaapbehoefte.","Een wearable schat slaaptijd vaak te hoog in."]', '2026-10-27', '5d8a00365ec3542271dba7a30c9553096f1601f5b18aed46638531478b136846'),
    ('sleep.duration.adults', 1, 'SLP-02', 'sleep.duration', 'sleep_minutes', 18, 60, 'betrouwbaar', '{"min":7,"unit":"hours"}', 'Volwassenen van 18 tot 60 jaar: regelmatig minstens 7 uur slaap per nacht. Korter slapen hangt samen met meer gezondheidsrisico''s.', 'Gezonde volwassenen van 18 tot 60 jaar.', '["Gebaseerd op verbanden, niet op bewezen oorzaken.","Gaat alleen over duur, niet over kwaliteit of tijdstip.","Niet voor mensen met een slaapstoornis."]', '2026-10-27', '24245c0d0357306097b0216d4204106828e08351c2ec9e1c317793d8cd74837d'),
    ('sleep.duration.nsf', 1, 'SLP-03', 'sleep.duration', 'sleep_minutes', 14, NULL, 'betrouwbaar', '{"bands":[[14,17,{"max":10,"min":8}],[18,64,{"max":9,"min":7}],[65,null,{"max":8,"min":7}]],"unit":"hours"}', 'Bij volwassenen past 7–9 uur slaap, bij 65-plussers 7–8 uur en bij tieners 8–10 uur.', 'Gezonde mensen zonder slaapstoornis.', '["Een consensus van deskundigen; de bereiken zijn geen diagnose.","Daarbuiten slapen kan passend zijn."]', '2026-10-27', 'f91d282c9baa218926fb6612a8f25f34e13e2099f9968976f37b3ad376681f4b'),
    ('sleep.duration.youth', 1, 'SLP-04', 'sleep.duration', 'sleep_minutes', 6, 18, 'betrouwbaar', '{"bands":[[6,12,{"max":12,"min":9}],[13,18,{"max":10,"min":8}]],"unit":"hours"}', 'Kinderen van 6 tot 12 jaar: 9–12 uur slaap per 24 uur; tieners van 13 tot 18 jaar: 8–10 uur.', 'Kinderen en tieners.', '["Gebaseerd op verbanden, niet op bewezen oorzaken.","Iedereen heeft een eigen slaapbehoefte."]', '2026-10-27', '2b9a2b40f98da136530d1b3bf7229762d10a4793a6ebf6591163393e38195a46'),
    ('sleep.regularity', 1, 'SLP-05', 'sleep.regularity', 'sleep_timing', NULL, NULL, 'betrouwbaar', '{"consistent_timing":true,"threshold_minutes":null}', 'Regelmatige tijden van inslapen en opstaan zijn belangrijk voor gezondheid, veiligheid en prestaties.', 'Volwassenen en jongeren.', '["Vooral observationeel onderzoek.","Er is geen aanbevolen aantal minuten speling.","Wie doordeweeks te weinig slaapt, kan baat hebben bij inhalen op vrije dagen."]', '2026-10-27', 'c89364b26d9fbbd1a5c55438373bc4dc855fb2cfe183204019d1beb50f364150'),
    ('sleep.tracking', 1, 'SLP-06', 'measurement.sleep', 'sleep_stages', NULL, NULL, 'beperkt', '{"sleep_sensitivity_min":0.93,"stages":"inconsistent","wake_specificity":[0.18,0.54]}', 'Wearables herkennen slaap goed, maar wakker liggen en slaapfasen zoals diep en REM veel minder betrouwbaar.', 'Gezonde jonge volwassenen, apparaten uit 2019.', '["Eén kleine studie.","Oudere apparaten dan die van nu."]', '2026-10-27', '9da1f5f48d1a4674ba6e6ff9ca9ecc442b54f96c2f55abd808442f386b509783'),
    ('activity.who.adults', 1, 'ACT-01', 'activity.aerobic', 'activity_minutes_week', 18, 64, 'zeer_betrouwbaar', '{"moderate_minutes_week":[150,300],"strength_days_week":2,"vigorous_minutes_week":[75,150]}', 'Per week 150–300 minuten matig of 75–150 minuten zwaar intensief bewegen, plus spierversterkende activiteiten op minstens 2 dagen.', 'Volwassenen van 18 tot 64 jaar, ook met een chronische aandoening.', '["Alle beweging telt mee, ook wandelen, fietsen en klussen.","Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', 'ab1b1f1cb75a12cb404ceb6cb70fe4e5ba05d962046ecf6c7867d2ff8aefa04f'),
    ('activity.who.older', 1, 'ACT-01', 'activity.aerobic', 'activity_minutes_week', 65, NULL, 'zeer_betrouwbaar', '{"balance_strength_days_week":3,"moderate_minutes_week":[150,300],"strength_days_week":2,"vigorous_minutes_week":[75,150]}', 'Vanaf 65 jaar dezelfde hoeveelheid beweging, plus op minstens 3 dagen per week activiteiten voor balans en kracht.', 'Volwassenen vanaf 65 jaar.', '["Alle beweging telt mee, ook wandelen, fietsen en klussen.","Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', 'd99372f410a1ee8665abb96139c112c90c207906a74b56f438e81aaea103c591'),
    ('activity.who.some', 1, 'ACT-01', 'activity.any', NULL, 5, NULL, 'zeer_betrouwbaar', '{"any_activity_better_than_none":true}', 'Een beetje bewegen is beter dan niets: zitten vervangen door bewegen van elke intensiteit, ook licht, levert gezondheidswinst op.', 'Iedereen vanaf 5 jaar.', '["Een algemene aanbeveling, geen voorspelling voor één persoon."]', '2026-10-27', 'ba82753189ca6f67f6aa5c3531c16d94072d94b47f11456a4132d3a6555d01a8'),
    ('activity.gr.adults', 1, 'ACT-02', 'activity.aerobic', 'activity_minutes_week', 18, NULL, 'zeer_betrouwbaar', '{"moderate_minutes_week":150,"strength_bone_per_week":2}', 'Minstens 150 minuten per week matig intensief bewegen, verspreid over diverse dagen, en minstens twee keer per week spier- en botversterkende activiteiten.', 'Volwassenen en ouderen (ouderen ook balansoefeningen).', '["Alle beweging telt mee, ook wandelen en fietsen.","Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', '16d7f32007a89e29f0c9748f3f9ebfd3a67748c2aacdc29c2ce5b34e97e7b9ac'),
    ('activity.gr.youth', 1, 'ACT-02', 'activity.aerobic', 'activity_minutes_day', 4, 17, 'zeer_betrouwbaar', '{"moderate_minutes_day":60,"strength_bone_per_week":3}', 'Kinderen van 4 tot 18 jaar: elke dag minstens een uur matig intensief bewegen en minstens drie keer per week spier- en botversterkende activiteiten.', 'Kinderen en jongeren van 4 tot 18 jaar.', '["Ownify ziet alleen de trainingen die je vastlegt."]', '2026-10-27', '2e22344702a47771534578efaf4b6223613df7b136f50d6621afc84040be21cb'),
    ('activity.steps.ding', 1, 'ACT-03', 'activity.steps', 'steps_day', 18, NULL, 'betrouwbaar', '{"comparison_steps":2000,"levelling_steps":[5000,7000],"reference_steps":7000}', 'Meer stappen per dag hangt samen met minder gezondheidsrisico''s; rond 7.000 stappen per dag was dat verband al duidelijk.', 'Volwassenen.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Voor de meeste uitkomsten maar enkele studies.","Stappentellers tellen meestal iets te weinig."]', '2026-10-27', '099a012b6b7807de646097a6cee88256069ffc7f899b99aacdc69ec9fae0ff38'),
    ('activity.steps.paluch', 1, 'ACT-04', 'activity.steps', 'steps_day', 18, NULL, 'betrouwbaar', '{"bands":[[18,59,{"plateau":[8000,10000]}],[60,null,{"plateau":[6000,8000]}]]}', 'Het verband met een lager sterfterisico vlakt af rond 8.000–10.000 stappen per dag onder de 60 en rond 6.000–8.000 vanaf 60 jaar.', 'Volwassenen.', '["Observationeel onderzoek: een verband, geen bewezen oorzaak.","Gaat alleen over sterfte."]', '2026-10-27', '599589ad38d771895af6f72b4f4067dbfffa44f5b683fa105c07d2a5eee74849'),
    ('measurement.wearables', 1, 'MEA-01', 'measurement.accuracy', NULL, NULL, NULL, 'betrouwbaar', '{"energy_error_pct":[-21,15],"heart_rate_bias_pct":3,"sleep_time":"overestimated","spo2_difference_max":2,"steps_error_pct":[-9,12]}', 'Wearables meten hartslag en stappen redelijk nauwkeurig, maar energieverbruik en slaaptijd veel minder: calorieën kunnen ruim 20% afwijken en slaaptijd wordt meestal overschat.', 'Consumentenwearables, vooral bij gezonde volwassenen.', '["Studies gebruiken verschillende methoden.","Apparaten veranderen sneller dan het onderzoek."]', '2026-10-27', 'd444c356f3b97ea7973aaba07974e26489a6160f9a763a35ce73355ccf6e8f00'),
    ('nutrition.fat', 1, 'NUT-01', 'nutrition.fat', 'fat_energy_pct', 4, NULL, 'zeer_betrouwbaar', '{"energy_pct":[20,40]}', 'Totaal vet: 20–40% van je energie. De kwaliteit van wat je eet telt meer dan de hoeveelheid vet.', 'Iedereen vanaf 4 jaar.', '["Ownify ziet wat je logt, niet alles wat je eet."]', '2026-10-27', 'fdcdf4bf6dcdba0ebac419ffbfc5d0d3ab79aef9dd49901730fbaee7dcfcb790'),
    ('nutrition.saturated_fat', 1, 'NUT-01', 'nutrition.saturated_fat', 'saturated_fat_energy_pct', NULL, NULL, 'zeer_betrouwbaar', '{"as_low_as_possible":true,"interim_energy_pct_max":10}', 'Verzadigd vet: zo laag mogelijk, met 10% van je energie als tussenstap.', 'Iedereen.', '["Ownify ziet wat je logt, niet alles wat je eet."]', '2026-10-27', '98cafa493cae7666a1c533b11624de4c6a8d0be0b07c05dca1c49d3f89d27f6f'),
    ('nutrition.carbohydrates', 1, 'NUT-01', 'nutrition.carbohydrates', 'carbs_energy_pct', NULL, NULL, 'zeer_betrouwbaar', '{"percentage_norm":false}', 'Koolhydraten hebben geen percentagenorm: het gaat om de bron, vooral volkoren, groente, fruit, peulvruchten en zuivel.', 'Iedereen.', '["Ownify ziet niet uit welke producten je koolhydraten komen."]', '2026-10-27', '25dc365d79c2890168c81e8b93d53baf8bb76c5820d23a5d0e4ecb635bfdfc0d'),
    ('nutrition.free_sugars', 1, 'NUT-01', 'nutrition.sugars', 'free_sugars_energy_pct', NULL, NULL, 'zeer_betrouwbaar', '{"as_low_as_possible":true,"interim_energy_pct_max":10}', 'Vrije suikers: zo laag mogelijk, met 10% van je energie als tussenstap.', 'Iedereen.', '["Je apps loggen alle suikers samen, ook die in fruit en melk; vrije suikers zijn daaruit niet af te lezen."]', '2026-10-27', 'fc2ba7b820ecd8bb08c74c39ce7bab6147b15f4be367d3ceb3e86af906fc69ee'),
    ('nutrition.fibre', 1, 'NUT-01', 'nutrition.fibre', 'fibre_per_1000_kcal', 2, NULL, 'zeer_betrouwbaar', '{"g_per_1000_kcal":[12.6,14.6],"g_per_mj":[3.0,3.5]}', 'Vezels: 3,0–3,5 gram per megajoule, dat is 12,6–14,6 gram per 1.000 kcal, uit volkoren, groente, fruit, peulvruchten en noten.', 'Iedereen vanaf 2 jaar.', '["Ownify ziet wat je logt, niet alles wat je eet."]', '2026-10-27', 'acb476216e7bf0b862ddb2afb032d45a5c4e4eb3540ee164394c9db7b67ff5de'),
    ('nutrition.protein', 1, 'NUT-02', 'nutrition.protein', 'protein_g_per_kg', 18, NULL, 'zeer_betrouwbaar', '{"average_requirement_g_per_kg":0.66,"g_per_kg_day":0.83}', 'Volwassenen: aanbevolen 0,83 gram eiwit per kilogram lichaamsgewicht per dag.', 'Volwassenen, ook ouderen.', '["Ownify ziet wat je logt, niet alles wat je eet.","Je eiwitdoel in Ownify hangt af van je activiteit en kan hoger liggen."]', '2026-10-27', '3fc1e73f33173c71ac4c5d4b7468de4df11c610b78879b807690c9d6847d7a44'),
    ('nutrition.protein.exercise', 1, 'NUT-05', 'nutrition.protein', 'protein_g_per_kg', 18, NULL, 'beperkt', '{"applies_to":"exercising","g_per_kg_day":[1.4,2.0]}', 'Voor mensen die sporten noemt een sportvoedingsvereniging 1,4–2,0 gram eiwit per kilogram per dag voldoende om spiermassa op te bouwen en te behouden.', 'Gezonde mensen die sporten.', '["Het standpunt van een vereniging, geen officiële norm.","Voor de algemene bevolking geldt 0,83 gram per kilogram."]', '2026-10-27', 'c7fc3e7f4901350aa41849f58fb7ff2df2cfd59b582f3b736d8297a7c4433fdb'),
    ('nutrition.salt', 1, 'NUT-03', 'nutrition.salt', 'salt_g_day', 18, NULL, 'zeer_betrouwbaar', '{"salt_g_day_max":6,"salt_per_sodium":2.5}', 'Maximaal 6 gram zout per dag.', 'Volwassenen.', '["Apps loggen zout dat je zelf toevoegt meestal niet."]', '2026-10-27', '7282ed7b7e605457ed0672431d3d75e99997664b119b9d6f7a523835bf797ba7'),
    ('nutrition.pattern', 1, 'NUT-03', 'nutrition.pattern', NULL, NULL, NULL, 'zeer_betrouwbaar', '{"plant_based":true}', 'Gezond eten is meer plantaardig en minder dierlijk: veel groente, fruit, volkoren, peulvruchten en noten, en weinig rood en bewerkt vlees en suikerhoudende dranken.', 'De algemene bevolking.', '["Deels bijgewerkt: in 2025 kwam een nieuw advies over eiwitbronnen; groente, fruit en granen volgen.","Ownify ziet niet welke producten je eet."]', '2026-10-27', 'c779f4e79055aa66494616139e60c169e260b3d4bd98904df369283ac1174504'),
    ('nutrition.energy_estimate', 1, 'NUT-04', 'nutrition.energy', 'energy_target', 18, NULL, 'betrouwbaar', '{"within_pct":10}', 'Je energiedoel is een schatting met de Mifflin-St Jeor-formule. Die zit bij de meeste mensen binnen 10% van het gemeten rustverbruik, maar kan per persoon meer afwijken.', 'Gezonde volwassenen, met en zonder obesitas.', '["Gaat over rustverbruik; de factor voor je activiteit is een tweede schatting.","Onderzoek uit 2005."]', '2026-10-27', '2295540cc48f33ffaf14cb44892af77123d74cb0a2abae87f0f0309f00cdc334'),
    ('body.bmi', 1, 'BOD-01', 'body.bmi', 'bmi', 18, NULL, 'zeer_betrouwbaar', '{"obesity_min":30,"overweight_min":25}', 'De WHO noemt bij volwassenen een BMI van 25 of hoger overgewicht en van 30 of hoger obesitas.', 'Volwassenen vanaf 18 jaar.', '["BMI zegt niets over je lichaamssamenstelling, zoals spiermassa.","Een indeling voor groepen, geen oordeel over één persoon."]', '2026-10-27', '546aa5643ef389f01368f92612f142487ad703a90f2d4685bf5f2e799cacb871');
-- <<< REGISTRY SEED

-- 8. What is there now — every value 1 (tools/migration-028-test.php reads
--    this last line).
SELECT
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'research_sources') AS research_sources,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'research_claims')  AS research_claims,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'insight_days')     AS insight_days,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_insights')    AS user_insights,
    (SELECT COUNT(*) = 3 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
        AND column_name IN ('ai_insights_consent', 'ai_insights_consent_version', 'ai_insights_consent_at')) AS consent_columns,
    (SELECT COUNT(*) >= 17 FROM `research_sources` WHERE `status` = 'approved') AS sources_seeded,
    (SELECT COUNT(*) >= 25 FROM `research_claims` WHERE `status` = 'active') AS claims_seeded;
