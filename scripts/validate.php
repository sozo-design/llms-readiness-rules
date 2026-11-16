<?php
/**
 * SOZO Design Ltd
 *
 * Checks rules.json before it is published: the same format rules as the module's RuleSetParser, every check
 * type known to the module, and (with --published) a revision higher than the one already published.
 *
 * Usage: php scripts/validate.php rules.json [--published=https://.../rules.json]
 * Exit codes: 0 valid, 1 not valid, 2 wrong usage.
 */

declare(strict_types=1);

// The check types Sozo_LlmsGenerator v400.0.0 knows (etc/di.xml, CheckPool). A rule with another type shows as
// "needs a module update" on every site, so add types here only when a module release has them.
const KNOWN_TYPES = [
    'homepage_loads', 'robots_txt', 'robots_user_agents', 'sitemap', 'llms_txt_exists', 'llms_txt_length',
    'llms_txt_links', 'llms_txt_section', 'content_without_js', 'html_metadata', 'json_ld_types',
    'json_ld_properties', 'trust_pages', 'bot_access', 'not_found',
];

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/validate.php rules.json [--published=URL]\n");
    exit(2);
}
$published = '';
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--published=')) {
        $published = substr($arg, 12);
    }
}

$errors = [];
$error = static function (string $message) use (&$errors): void {
    $errors[] = $message;
};

$json = (string)file_get_contents($file);
if (strlen($json) > 262144) {
    $error('The file is larger than 256 KB.');
}
$data = json_decode($json, true);
if (!is_array($data) || array_is_list($data)) {
    fwrite(STDERR, "ERROR: not a JSON object.\n");
    exit(1);
}

$id = static fn($value, int $max): bool => is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,' . ($max - 1) . '}$/', $value) === 1;
$text = static function ($value, int $min, int $max): bool {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return false;
    }
    $length = mb_strlen(trim((string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $value)));

    return $length >= $min && $length <= $max;
};

if (($data['schema'] ?? null) !== 1) {
    $error('"schema" must be 1.');
}
if (!is_int($data['revision'] ?? null) || $data['revision'] < 1) {
    $error('"revision" must be a whole number of at least 1.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['published'] ?? ''))) {
    $error('"published" must be a date (YYYY-MM-DD).');
}

$groups = [];
$groupList = $data['groups'] ?? null;
if (!is_array($groupList) || !array_is_list($groupList) || $groupList === [] || count($groupList) > 12) {
    $error('"groups" must be a list of 1 to 12 groups.');
    $groupList = [];
}
foreach ($groupList as $i => $group) {
    if (!is_array($group) || !$id($group['id'] ?? null, 31) || isset($groups[$group['id']])) {
        $error("Group $i needs a unique id (lower-case letters, digits, underscores).");
        continue;
    }
    $groups[$group['id']] = true;
    if (!$text($group['label'] ?? null, 1, 40) || !$text($group['description'] ?? '', 0, 300)) {
        $error("Group {$group['id']}: label 1-40 characters, description up to 300.");
    }
}

$checks = $data['checks'] ?? null;
if (!is_array($checks) || !array_is_list($checks) || $checks === [] || count($checks) > 100) {
    $error('"checks" must be a list of 1 to 100 checks.');
    $checks = [];
}
$seen = [];
foreach ($checks as $i => $check) {
    $name = is_array($check) && is_string($check['id'] ?? null) ? $check['id'] : "#$i";
    if (!is_array($check) || !$id($check['id'] ?? null, 63) || isset($seen[$check['id']])) {
        $error("Check $name needs a unique id.");
        continue;
    }
    $seen[$check['id']] = true;
    if (!isset($groups[$check['group'] ?? ''])) {
        $error("Check $name: unknown group.");
    }
    if (!in_array($check['type'] ?? '', KNOWN_TYPES, true)) {
        $error("Check $name: type \"" . ($check['type'] ?? '') . '" is not in the released module.');
    }
    if (!$text($check['title'] ?? null, 1, 120)) {
        $error("Check $name: title must be 1 to 120 characters.");
    }
    if (!$text($check['description'] ?? '', 0, 1000) || !$text($check['fix'] ?? '', 0, 1500)) {
        $error("Check $name: description up to 1,000 characters, fix up to 1,500.");
    }
    $weight = $check['weight'] ?? 1;
    if ((!is_int($weight) && !is_float($weight)) || $weight < 0 || $weight > 10) {
        $error("Check $name: weight must be a number from 0 to 10.");
    }
    if (!is_bool($check['scored'] ?? true)) {
        $error("Check $name: scored must be true or false.");
    }
    $params = $check['params'] ?? [];
    if (!is_array($params) || ($params !== [] && array_is_list($params)) || strlen((string)json_encode($params)) > 8192) {
        $error("Check $name: params must be an object of at most 8 KB.");
    }
    // Same rule as the module's RuleSetParser::PATH_PATTERN: paths on the shop only ("/path"), never a URL.
    $paths = [];
    if (($check['type'] ?? '') === 'sitemap' && is_array($params) && array_key_exists('paths', $params)) {
        $paths = is_array($params['paths']) ? $params['paths'] : [null];
    }
    if (($check['type'] ?? '') === 'trust_pages' && is_array($params) && is_array($params['pages'] ?? null)) {
        foreach ($params['pages'] as $page) {
            if (is_array($page) && array_key_exists('fallback', $page)) {
                $paths[] = $page['fallback'];
            }
        }
    }
    foreach ($paths as $path) {
        if (!is_string($path) || !preg_match('#^/(?![/\\\\])[^\x00-\x20\x7f\\\\]{0,199}\z#', $path)) {
            $error("Check $name: paths must start with a single \"/\" and have no spaces or control characters.");
        }
    }
    $learn = $check['learn_more'] ?? '';
    if ($learn !== '' && (!is_string($learn) || strlen($learn) > 300 || !preg_match('#^https://[a-z0-9.-]+(/[^\s"<>]*)?\z#i', $learn))) {
        $error("Check $name: learn_more must be an https URL.");
    }
}

if ($published !== '' && is_int($data['revision'] ?? null)) {
    $current = @file_get_contents($published);
    if ($current !== false) {
        $currentData = json_decode($current, true);
        $currentRevision = is_array($currentData) ? (int)($currentData['revision'] ?? 0) : 0;
        if ($current !== $json && $data['revision'] <= $currentRevision) {
            $error("\"revision\" must be higher than the published revision ($currentRevision): sites ignore older or equal revisions.");
        }
    }
}

foreach ($errors as $message) {
    fwrite(STDERR, (getenv('GITHUB_ACTIONS') ? '::error::' : 'ERROR: ') . $message . "\n");
}
if ($errors !== []) {
    exit(1);
}
echo 'rules.json is valid: revision ' . $data['revision'] . ', ' . count($checks) . " checks.\n";
