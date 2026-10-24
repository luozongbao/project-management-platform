<?php
/**
 * Slug helpers — issue-035 v2.0 / Appendix C.
 *
 * A "slug" is a stable, lowercase, hyphen-separated, human-readable path
 * segment that identifies a record, e.g. "North Riverside Housing" →
 * "north-riverside-housing", served at /projects/north-riverside-housing.
 *
 * TWO RULES GOVERN THIS FILE:
 *
 * 1. SLUGS ARE IMMUTABLE (C.2 R15, C.5). A slug is assigned once, when the
 *    record is created, and is stored in its own column. Renaming a record
 *    does NOT regenerate its slug — otherwise every URL already sent to a
 *    client, bookmarked, or pasted into chat would 404. Callers that need
 *    a slug for a NEW row use generateUniqueSlug(); callers that merely
 *    need to display an existing one read the column.
 *
 * 2. THE PHP AND SQL IMPLEMENTATIONS MUST AGREE BYTE-FOR-BYTE (C.4). The
 *    backfill in database/migrations/20261022_add_slugs.sql runs
 *    slug_base() in SQL; this file runs slugify() in PHP. If the two ever
 *    disagree, a row migrated today would get a different URL than the same
 *    name inserted tomorrow.
 *
 *    This is why NEITHER implementation uses iconv(). The spec suggests
 *    iconv('UTF-8','ASCII//TRANSLIT'), but that is glibc/locale-dependent
 *    and cannot be mirrored in SQL. Measured on this stack:
 *
 *        iconv('…','ASCII//TRANSLIT', 'Café')  →  "Caf\'e"   (apostrophe!)
 *        iconv('…','ASCII//TRANSLIT', 'naïve') →  'na"ive'   (double quote!)
 *        iconv('…','ASCII//TRANSLIT', '项目')  →  FALSE      (warning emitted)
 *
 *    So both sides instead apply an EXPLICIT transliteration map
 *    (slug_translit_map() here, the REPLACE() chain in the migration) and
 *    then the same collapse → trim → cap pipeline. Characters outside the
 *    map — notably CJK — are treated as separators and dropped, which is
 *    exactly what REGEXP_REPLACE('[^a-z0-9]+', '-') does on the SQL side.
 *    database/slug_routing_test.php (T4/T6) asserts the two agree on a
 *    battery of inputs.
 *
 * Normalisation order (C.4, R16):
 *   1. lowercase (UTF-8 aware)
 *   2. transliterate diacritics via the explicit map
 *   3. replace every run of characters outside [a-z0-9] with a single '-'
 *   4. trim leading/trailing '-'
 *   5. truncate to 60 chars, then trim a trailing '-' again
 *   6. (caller) fall back to 'item-<id>' if the result is empty
 */

// The three lookup helpers below use Database::getInstance(). Requiring it
// here rather than relying on the caller keeps this file self-contained: the
// front controller loads slug.php before it knows which script it will run,
// so a load order that happens to work in the test would not survive there.
require_once __DIR__ . '/Database.php';

/**
 * The explicit Latin-1/Latin-Extended transliteration map.
 *
 * Keys are already-lowercased because slugify() lowercases before
 * consulting this map. Multi-character values ("ss", "ae", "oe", "th",
 * "ij") are expansions, matching the SQL REPLACE chain.
 *
 * Kept as a static so the array is built once per request rather than on
 * every call inside a loop over a project list.
 */
function slug_translit_map(): array {
    static $map = [
        // Latin-1 supplement + Latin Extended-A, lowercase forms.
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'æ' => 'ae',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'd',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
        'ß' => 'ss',
        'ł' => 'l', 'đ' => 'd', 'ħ' => 'h', 'ĳ' => 'ij',
    ];

    return $map;
}

/**
 * Normalise a name into the base part of a slug (steps 1-5 of C.4).
 *
 * Returns '' when the name contains nothing sluggable (e.g. a pure-CJK
 * name like "项目"), which is the signal for the caller to apply the
 * 'item-<id>' fallback — this function does not know the row id.
 *
 * Note the /u modifier: without it preg_replace operates on bytes and a
 * multi-byte character can be split, producing mojibake instead of a clean
 * separator.
 *
 * @param string $name Raw, user-supplied record name.
 * @return string Lowercase [a-z0-9-] string, capped at 60 chars, no leading/trailing '-'.
 */
function slugify(string $name): string {
    // Step 1 — lowercase, UTF-8 aware (mb_strtolower, not strtolower: the
    // latter is byte-wise and leaves multi-byte capitals untouched).
    $slug = mb_strtolower($name, 'UTF-8');

    // Step 2 — transliterate the diacritics we support. strtr() with an
    // array does NOT re-scan its own replacements, so 'æ' → 'ae' will not
    // then be re-transliterated. That is the behaviour we want and it
    // matches the sequential REPLACE() chain in the SQL migration for the
    // inputs that matter (the REPLACE chain differs only on pathological
    // names that contain both a source and a target glyph; see the
    // slug_routing_test.php T4 corpus for the agreed-upon cases).
    $slug = strtr($slug, slug_translit_map());

    // Step 3 — every remaining run of non-[a-z0-9] becomes one '-'.
    // This is where unmapped characters (CJK, Greek, Cyrillic, emoji,
    // punctuation) get turned into separators, matching the SQL side's
    // REGEXP_REPLACE('[^a-z0-9]+', '-', … COLLATE utf8mb4_bin).
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
    if ($slug === null) {
        // preg_replace returns null on a malformed UTF-8 subject (e.g. a
        // name containing invalid byte sequences). Fall back to the
        // 'empty' signal rather than emitting a half-built slug.
        return '';
    }

    // Step 4 — trim separators from both ends.
    $slug = trim($slug, '-');

    // Step 5 — cap at 60 chars, then re-trim: the cut can expose a trailing
    // '-' that step 4 had already removed from the original tail.
    if (strlen($slug) > 60) {
        $slug = rtrim(substr($slug, 0, 60), '-');
    }

    return $slug;
}

/**
 * Build a slug that is unique within one table (C.2 R16, C.5).
 *
 * Applies the 'item-<id>' fallback for names that normalise to nothing, then
 * appends '-2', '-3', … until the value is free in $table.
 *
 * Uniqueness is PER TABLE (C.2): projects and tasks may both hold
 * 'site-survey' because they are addressed under different route prefixes
 * (/projects/site-survey vs /tasks/site-survey). $table therefore MUST be
 * one of the three hard-coded allow-listed names below — it is interpolated
 * into SQL and cannot be bound as a parameter.
 *
 * @param string $name  Raw record name.
 * @param string $table One of 'projects' | 'tasks' | 'contacts'.
 * @param int    $excludeId Optional. Row id to ignore when checking for
 *                         collisions — pass this on UPDATE so re-saving a
 *                         record does not collide with itself.
 * @return string A slug that is unique within $table.
 */
function generateUniqueSlug(string $name, string $table, int $excludeId = 0): string {
    $allowed = ['projects', 'tasks', 'contacts'];
    if (!in_array($table, $allowed, true)) {
        // Never interpolate an unvalidated identifier into SQL.
        throw new InvalidArgumentException(
            'generateUniqueSlug(): $table must be one of ' . implode(', ', $allowed)
        );
    }

    $base = slugify($name);

    // C.4 step 6 — a name made entirely of symbols (or entirely of
    // characters we do not transliterate, e.g. "项目") yields no slug, so
    // fall back to a stable, human-recognisable id-based value. NOTE: the
    // migration's SQL fallback uses the row id too, so the two agree.
    if ($base === '') {
        // Without an id yet (insert path, id not known) we cannot build
        // 'item-<id>'. Use a timestamp-free placeholder that still ends up
        // unique because the suffix loop below disambiguates it.
        $base = $excludeId > 0 ? 'item-' . $excludeId : 'item';
    }

    $slug = $base;
    $suffix = 2;

    $db = Database::getInstance();

    // The loop is deliberately unbounded-with-escape rather than a bounded
    // retry: an unbounded WHILE with a suffix that strictly increases will
    // terminate as soon as a free value is found, and every iteration is a
    // single indexed lookup on uq_<table>_slug.
    while (true) {
        $sql = "SELECT id FROM {$table} WHERE slug = ?";
        $params = [$slug];

        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $existing = $db->fetchOne($sql, $params);
        if (!$existing) {
            return $slug;
        }

        $slug = $base . '-' . $suffix;
        $suffix++;
    }
}

/**
 * Resolve a slug to its row id for one of the three slug tables.
 *
 * Used by the front controller (includes/Router.php) to turn
 * /projects/<slug> into the numeric id the existing page bodies expect,
 * so the v2.0 URL layer can sit in front of untouched v1.8.0 query code.
 *
 * Returns null when the slug does not exist — the caller MUST then emit a
 * 404, not a redirect to the list (C.2 R13).
 *
 * @param string $slug  Slug from the URL path.
 * @param string $table One of 'projects' | 'tasks' | 'contacts'.
 * @return int|null
 */
function findIdBySlug(string $slug, string $table): ?int {
    $allowed = ['projects', 'tasks', 'contacts'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException(
            'findIdBySlug(): $table must be one of ' . implode(', ', $allowed)
        );
    }

    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT id FROM {$table} WHERE slug = ? LIMIT 1", [$slug]);

    return $row ? (int)$row['id'] : null;
}

/**
 * Fetch a single record's slug by id, or '' when it has none.
 *
 * The link-migration audit (C.2 "Internal link migration") has call sites
 * such as redirect('project_detail.php?id=' . $id) that need the slug but
 * do not have the full row in hand. Per C.2 the dev must FETCH the slug
 * rather than re-derive it from the name — re-deriving breaks R15 the
 * moment the name was edited after the slug was assigned.
 *
 * @param string $table One of 'projects' | 'tasks' | 'contacts'.
 * @param int    $id    Row id.
 * @return string The stored slug, or '' if not found / not yet backfilled.
 */
function getSlugById(string $table, int $id): string {
    $allowed = ['projects', 'tasks', 'contacts'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException(
            'getSlugById(): $table must be one of ' . implode(', ', $allowed)
        );
    }

    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT slug FROM {$table} WHERE id = ? LIMIT 1", [$id]);

    return $row ? (string)$row['slug'] : '';
}