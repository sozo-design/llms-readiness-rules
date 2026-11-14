<?php
/**
 * SOZO Design Ltd
 *
 * Signs rules.json with the Ed25519 secret key in RULES_SIGNING_KEY (base64) and writes the detached signature
 * (base64) to the output file, then checks it against public-key.txt so a wrong secret fails here, not on sites.
 *
 * Usage: RULES_SIGNING_KEY=... php scripts/sign.php rules.json rules.json.sig
 */

declare(strict_types=1);

[$script, $input, $output] = $argv + [null, '', ''];
$secret = (string)getenv('RULES_SIGNING_KEY');
if ($input === '' || $output === '' || $secret === '') {
    fwrite(STDERR, "Usage: RULES_SIGNING_KEY=... php scripts/sign.php rules.json rules.json.sig\n");
    exit(2);
}

$key = sodium_base642bin(trim($secret), SODIUM_BASE64_VARIANT_ORIGINAL);
$content = (string)file_get_contents($input);
$signature = sodium_crypto_sign_detached($content, $key);
$public = sodium_base642bin(trim((string)file_get_contents(__DIR__ . '/../public-key.txt')), SODIUM_BASE64_VARIANT_ORIGINAL);
if (!sodium_crypto_sign_verify_detached($signature, $content, $public)) {
    fwrite(STDERR, "ERROR: the signing key does not match public-key.txt.\n");
    exit(1);
}
sodium_memzero($key);
file_put_contents($output, base64_encode($signature) . "\n");
echo "Signed $input.\n";
