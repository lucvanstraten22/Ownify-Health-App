<?php
/**
 * The approved research behind Ownify's insights: the registry in the
 * database (research_sources, research_claims — migration 028), read the
 * same way by the insight engine, the detail view and the registry tool.
 *
 * config/research.php is the reviewed text; tools/research-registry.php
 * writes it into the database and checks that the two agree. At runtime
 * only the database counts, so every stored insight points at the exact
 * claim version it used (user_insights.claim_id), also after a claim has
 * been replaced by a newer version.
 *
 * Nothing here decides what a person is told: that is includes/insights/.
 * This file only says what the research is, for whom it holds, and how
 * strong it is (docs/RESEARCH.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

if (!function_exists('research_config')) {

    /** The three strengths, strongest first. */
    define('RESEARCH_STRENGTHS', ['zeer_betrouwbaar', 'betrouwbaar', 'beperkt']);

    /** How closely Ownify's data matches what a claim is about: steps down. */
    define('RESEARCH_APPLICABILITY', ['direct' => 0, 'proxy' => 1, 'weak' => 2]);

    /** The languages a publication can be written in: Ownify's own two. */
    define('RESEARCH_LANGUAGES', ['nl', 'en']);

    /** config/research.php as written: Dutch, untranslated. */
    function research_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__) . '/config/research.php';
    }

    /* ======================================================== records */

    /**
     * The canonical form of a record: keys sorted at every level (lists
     * kept in order), so the same content always hashes the same.
     */
    function research_canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map('research_canonical', $value);
    }

    function research_json(mixed $value): string
    {
        return (string) json_encode(research_canonical($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** sha256 of a reviewed record: what the database row must match. */
    function research_hash(array $record): string
    {
        return hash('sha256', research_json($record));
    }

    /**
     * A source from config/research.php as its research_sources row.
     *
     * @return array<string, mixed>
     */
    function research_source_row(string $code, array $source): array
    {
        $pdf = (array) ($source['pdf'] ?? []);

        return [
            'code'         => $code,
            'status'       => (string) $source['status'],
            'kind'         => (string) $source['kind'],
            'language'     => (string) $source['language'],
            'title'        => (string) $source['title'],
            'authors'      => $source['authors'] ?? null,
            'organisation' => $source['organisation'] ?? null,
            'published'    => (string) $source['published'],
            'citation'     => (string) $source['citation'],
            'url'          => (string) $source['url'],
            'doi'          => $source['doi'] ?? null,
            'pmid'         => $source['pmid'] ?? null,
            'pmcid'        => $source['pmcid'] ?? null,
            'other_id'     => $source['other_id'] ?? null,
            'pdf_mode'     => (string) ($pdf['mode'] ?? 'link'),
            'pdf_url'      => $pdf['url'] ?? null,
            'pdf_licence'  => (string) ($pdf['licence'] ?? ''),
            'pdf_hosted'   => $pdf['hosted'] ?? null,
            'pdf_embed'    => !empty($pdf['embed']) ? 1 : 0,
            'findings'     => (string) $source['findings'],
            'approved_on'  => (string) $source['approved_on'],
            'verified_on'  => (string) $source['verified_on'],
            'content_hash' => research_hash(['code' => $code] + $source),
        ];
    }

    /**
     * A claim from config/research.php as its research_claims row (without
     * the id the database gives it).
     *
     * @return array<string, mixed>
     */
    function research_claim_row(string $key, array $claim, array $sources): array
    {
        $source = $sources[$claim['source']] ?? null;

        return [
            'claim_key'        => $key,
            'version'          => (int) $claim['version'],
            'source_code'      => (string) $claim['source'],
            'topic'            => (string) $claim['topic'],
            'metric'           => $claim['metric'] ?? null,
            'age_min'          => $claim['age_min'] ?? null,
            'age_max'          => $claim['age_max'] ?? null,
            'strength'         => (string) $claim['strength'],
            'finding_json'     => research_json($claim['finding'] ?? []),
            'statement'        => (string) $claim['statement'],
            'population'       => (string) $claim['population'],
            'limitations_json' => research_json(array_values((array) ($claim['limitations'] ?? []))),
            'approved_on'      => (string) ($source['approved_on'] ?? ''),
            'content_hash'     => research_hash(['claim_key' => $key] + $claim),
        ];
    }

    /**
     * What is wrong with config/research.php itself, before anything is
     * written anywhere: every claim names one approved source, every value
     * is one the database can hold.
     *
     * @return list<string>
     */
    function research_config_problems(array $config): array
    {
        $problems = [];
        $sources  = (array) ($config['sources'] ?? []);

        foreach ($sources as $code => $source) {
            if (!preg_match('/^[A-Z]{3}-\d{2}$/', (string) $code)) {
                $problems[] = "source $code: the id is not like SLP-01";
            }
            if (($source['status'] ?? null) !== 'approved') {
                $problems[] = "source $code: only approved sources belong in the registry";
            }
            foreach (['kind', 'title', 'published', 'citation', 'url', 'findings', 'approved_on', 'verified_on'] as $field) {
                if (!is_string($source[$field] ?? null) || trim($source[$field]) === '') {
                    $problems[] = "source $code: $field is missing";
                }
            }
            if (!isset($config['kinds'][$source['kind'] ?? ''])) {
                $problems[] = "source $code: kind '" . ($source['kind'] ?? '') . "' is not in kinds";
            }
            if (!in_array($source['language'] ?? null, RESEARCH_LANGUAGES, true)) {
                $problems[] = "source $code: language must be one of " . implode(', ', RESEARCH_LANGUAGES);
            }
            $pdf = (array) ($source['pdf'] ?? []);
            if (!in_array($pdf['mode'] ?? null, ['hosted', 'official_pdf', 'link'], true)) {
                $problems[] = "source $code: pdf.mode must be hosted, official_pdf or link";
            }
            if (($pdf['mode'] ?? null) === 'hosted') {
                $file = dirname(__DIR__) . '/' . (string) ($pdf['hosted'] ?? '');
                if (!preg_match('~^assets/research/[A-Z]{3}-\d{2}\.pdf$~', (string) ($pdf['hosted'] ?? ''))) {
                    $problems[] = "source $code: a hosted pdf lives at assets/research/<id>.pdf";
                } elseif (!is_file($file)) {
                    $problems[] = "source $code: the hosted pdf " . $pdf['hosted'] . ' is not there';
                }
                if (!str_starts_with((string) ($pdf['licence'] ?? ''), 'CC BY ')) {
                    $problems[] = "source $code: only a CC BY licence may be hosted";
                }
            } elseif (($pdf['hosted'] ?? null) !== null) {
                $problems[] = "source $code: only pdf.mode hosted has a hosted file";
            }
            if (($pdf['mode'] ?? null) === 'official_pdf' && !is_string($pdf['url'] ?? null)) {
                $problems[] = "source $code: official_pdf needs the official pdf url";
            }
            foreach (['url' => $source['url'] ?? null, 'pdf.url' => $pdf['url'] ?? null] as $what => $url) {
                if ($url !== null && !preg_match('~^https://~', (string) $url)) {
                    $problems[] = "source $code: $what must be https";
                }
            }
            foreach (['approved_on', 'verified_on'] as $field) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($source[$field] ?? ''))) {
                    $problems[] = "source $code: $field is not a date";
                }
            }
        }

        foreach ((array) ($config['claims'] ?? []) as $key => $claim) {
            if (!preg_match('/^[a-z_]+(\.[a-z_]+)+$/', (string) $key) || strlen((string) $key) > 64) {
                $problems[] = "claim $key: the key is not like sleep.duration.adults";
            }
            if (!isset($sources[$claim['source'] ?? ''])) {
                $problems[] = "claim $key: its source " . ($claim['source'] ?? '?') . ' is not in the registry';
            }
            if (!is_int($claim['version'] ?? null) || $claim['version'] < 1) {
                $problems[] = "claim $key: version must be a whole number from 1";
            }
            if (!in_array($claim['strength'] ?? null, RESEARCH_STRENGTHS, true)) {
                $problems[] = "claim $key: strength must be one of " . implode(', ', RESEARCH_STRENGTHS);
            }
            foreach (['age_min', 'age_max'] as $field) {
                $age = $claim[$field] ?? null;
                if ($age !== null && (!is_int($age) || $age < 0 || $age > 120)) {
                    $problems[] = "claim $key: $field must be an age in years or null";
                }
            }
            if (isset($claim['age_min'], $claim['age_max']) && $claim['age_min'] > $claim['age_max']) {
                $problems[] = "claim $key: age_min is above age_max";
            }
            foreach (['statement', 'population'] as $field) {
                if (!is_string($claim[$field] ?? null) || trim($claim[$field]) === '') {
                    $problems[] = "claim $key: $field is missing";
                }
            }
            if (!is_array($claim['limitations'] ?? null) || $claim['limitations'] === [] || !array_is_list($claim['limitations'])) {
                $problems[] = "claim $key: limitations must be a list of at least one sentence";
            }
            foreach ((array) ($claim['finding']['bands'] ?? []) as $band) {
                if (!is_array($band) || count($band) !== 3 || !is_int($band[0])
                    || ($band[1] !== null && !is_int($band[1])) || !is_array($band[2])) {
                    $problems[] = "claim $key: a band is [age_min, age_max|null, values]";
                }
            }
        }

        return $problems;
    }

    /* ======================================================== reading */

    /** Whether migration 028's registry tables are there. */
    function research_installed(): bool
    {
        static $installed = null;

        if ($installed !== null) {
            return $installed;
        }
        if (!db_available()) {
            return false;
        }

        $tables = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('research_sources', 'research_claims')"
        );

        return $installed = ($tables === 2);
    }

    /** A research_claims row with its JSON read. */
    function research_claim_decode(array $row): array
    {
        $row['id']          = (int) $row['id'];
        $row['version']     = (int) $row['version'];
        $row['age_min']     = $row['age_min'] === null ? null : (int) $row['age_min'];
        $row['age_max']     = $row['age_max'] === null ? null : (int) $row['age_max'];
        $row['finding']     = json_decode((string) $row['finding_json'], true) ?: [];
        $row['limitations'] = json_decode((string) $row['limitations_json'], true) ?: [];
        unset($row['finding_json'], $row['limitations_json']);

        return $row;
    }

    /**
     * The active version of every claim whose source is still approved,
     * keyed by claim_key — what the engine may use today.
     *
     * @return array<string, array<string, mixed>>
     */
    function research_claims(): array
    {
        static $claims = null;

        if ($claims !== null) {
            return $claims;
        }
        if (!research_installed()) {
            return [];
        }

        $rows = db_all(
            "SELECT c.id, c.claim_key, c.version, c.source_code, c.topic, c.metric, c.age_min, c.age_max,
                    c.strength, c.finding_json, c.statement, c.population, c.limitations_json, c.content_hash
               FROM research_claims c
               JOIN research_sources s ON s.code = c.source_code AND s.status = 'approved'
              WHERE c.status = 'active'"
        );

        $claims = [];
        foreach ($rows as $row) {
            $claims[(string) $row['claim_key']] = research_claim_decode($row);
        }

        return $claims;
    }

    /** The active version of one claim, or null when it is not in use. */
    function research_claim(string $key): ?array
    {
        return research_claims()[$key] ?? null;
    }

    /**
     * Any version of a claim, by its id — also a retired one, so an insight
     * stored with an older version still shows the research it used.
     */
    function research_claim_by_id(int $id): ?array
    {
        if (!research_installed() || $id <= 0) {
            return null;
        }

        $row = db_one(
            'SELECT id, claim_key, version, source_code, topic, metric, age_min, age_max, strength,
                    finding_json, statement, population, limitations_json, content_hash, status
               FROM research_claims WHERE id = ?',
            [$id]
        );

        return $row === null ? null : research_claim_decode($row);
    }

    /** One source row, or null. */
    function research_source(string $code): ?array
    {
        if (!research_installed()) {
            return null;
        }

        return db_one('SELECT * FROM research_sources WHERE code = ?', [$code]);
    }

    /* ==================================================== applicability */

    /**
     * Whether a claim holds for this age. A claim with an age bound needs a
     * known age (null: no); one without a bound holds for everybody.
     */
    function research_age_fits(array $claim, ?int $age): bool
    {
        if ($claim['age_min'] === null && $claim['age_max'] === null) {
            return true;
        }
        if ($age === null) {
            return false;
        }

        return ($claim['age_min'] === null || $age >= $claim['age_min'])
            && ($claim['age_max'] === null || $age <= $claim['age_max']);
    }

    /**
     * The values of the band an age falls in — the source's own age
     * boundaries, never ones Ownify made up — or null.
     */
    function research_band(array $bands, ?int $age): ?array
    {
        if ($age === null) {
            return null;
        }

        foreach ($bands as [$min, $max, $values]) {
            if ($age >= (int) $min && ($max === null || $age <= (int) $max)) {
                return (array) $values;
            }
        }

        return null;
    }

    /**
     * The strength an insight shows: the claim's own, one step lower when
     * Ownify's data only approximates what the source measured (proxy), two
     * when it fits only loosely (weak). Null below Beperkt onderbouwd: then
     * there is no insight at all.
     */
    function research_strength_for(string $strength, string $applicability): ?string
    {
        $index = array_search($strength, RESEARCH_STRENGTHS, true);
        $steps = RESEARCH_APPLICABILITY[$applicability] ?? null;

        if ($index === false || $steps === null) {
            return null;
        }

        return RESEARCH_STRENGTHS[$index + $steps] ?? null;
    }

    /** The label a strength is shown with, in the request's language. */
    function research_strength_label(string $strength): string
    {
        return (string) (config_copy('research')['strengths'][$strength]['label'] ?? '');
    }

    /* ========================================================= detail */

    /**
     * The key a detail is found under: the claim version and how many steps
     * its label stands lower for how closely Ownify's data fits it there
     * (RESEARCH_APPLICABILITY), e.g. "c12-1". One claim can be used with
     * different fits in different places, and its label differs. Digits, so
     * the key is never mistaken for a word in either language.
     */
    function research_detail_id(int $claimId, string $applicability): string
    {
        return 'c' . $claimId . '-' . (RESEARCH_APPLICABILITY[$applicability] ?? 0);
    }

    /**
     * Everything "Uit onderzoek blijkt" shows for one claim version as it is
     * used ($applicability), in the request's language: the finding, the
     * claim, the source and how to read it. Null when the claim or its source
     * is not there, or when the label would fall below Beperkt onderbouwd.
     *
     * @return array<string, mixed>|null
     */
    function research_detail(int $claimId, string $applicability = 'direct'): ?array
    {
        $claim = research_claim_by_id($claimId);
        if ($claim === null) {
            return null;
        }

        $source = research_source((string) $claim['source_code']);
        $shown  = research_strength_for((string) $claim['strength'], $applicability);
        if ($source === null || $shown === null) {
            return null;
        }

        $copy = config_copy('research');

        /* The Dutch texts come from the database and are translated like
           any built-in string (lang/en/research.php). */
        $text = i18n_tree([
            'statement'    => $claim['statement'],
            'population'   => $claim['population'],
            'limitations'  => $claim['limitations'],
            'findings'     => (string) $source['findings'],
            'citation'     => (string) $source['citation'],
            'organisation' => $source['organisation'],
            'licence'      => (string) $source['pdf_licence'],
        ]);

        $pdf = match ((string) $source['pdf_mode']) {
            /* Relative, as every asset is: the website reads it from its own
               address, the Android app from the server it talks to. */
            'hosted' => [
                'mode'  => 'hosted',
                'href'  => ltrim((string) $source['pdf_hosted'], '/'),
                'embed' => true,
                'note'  => sprintf((string) $copy['detail']['pdf_hosted'], $text['licence']),
            ],
            'official_pdf' => [
                'mode'  => 'official_pdf',
                'href'  => (string) $source['pdf_url'],
                'embed' => (bool) $source['pdf_embed'],
                'note'  => (string) $copy['detail']['pdf_official'],
            ],
            default => [
                'mode'  => 'link',
                'href'  => $source['pdf_url'] ?? null,
                'embed' => false,
                'note'  => (string) $copy['detail']['pdf_none'],
            ],
        };
        $pdf['licence'] = $text['licence'];

        $language = (string) ($source['language'] ?? 'en');

        return [
            'id'           => research_detail_id((int) $claim['id'], $applicability),
            'claim_id'     => (int) $claim['id'],
            'claim_key'    => (string) $claim['claim_key'],
            'version'      => (int) $claim['version'],
            'retired'      => ($claim['status'] ?? 'active') !== 'active',
            'statement'    => $text['statement'],
            'population'   => $text['population'],
            'limitations'  => array_values((array) $text['limitations']),
            'strength'     => [
                'key'     => $shown,
                'label'   => (string) ($copy['strengths'][$shown]['label'] ?? ''),
                'means'   => (string) ($copy['strengths'][$shown]['means'] ?? ''),
                'lowered' => $shown !== $claim['strength'],
                'why'     => $shown !== $claim['strength'] ? (string) ($copy['applicability'][$applicability] ?? '') : null,
                'note'    => (string) ($copy['strength_note'] ?? ''),
            ],
            'source'       => [
                'code'         => (string) $source['code'],
                'kind'         => (string) ($copy['kinds'][$source['kind']] ?? ''),
                /* As published, in the publication's own language (`language`,
                   marked as such for a screen reader); `language_note` says so
                   where that is not the app's. */
                'title'        => (string) $source['title'],
                'language'     => $language,
                'language_note' => $language === i18n_locale() ? null : (string) ($copy['detail']['language'][$language] ?? ''),
                'authors'      => $source['authors'],
                'organisation' => $text['organisation'],
                'year'         => substr((string) $source['published'], 0, 4),
                'citation'     => $text['citation'],
                'findings'     => $text['findings'],
                'href'         => (string) $source['url'],
                'doi'          => $source['doi'],
                'pmid'         => $source['pmid'],
                'verified'     => sprintf((string) $copy['detail']['verified'], i18n_date((string) $source['verified_on'], 'day_month_year')),
            ],
            'pdf'          => $pdf,
        ];
    }

    /** The detail view's own words, for both apps. */
    function research_detail_copy(): array
    {
        return (array) config_copy('research')['detail'];
    }
}
