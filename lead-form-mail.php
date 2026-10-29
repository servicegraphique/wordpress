<?php
/**
 * HelloLead — endpoint d'envoi du formulaire de lead (2 étapes).
 *
 * Reçoit un POST (JSON ou formulaire) depuis les pages lead-assurances,
 * lead-assurances-rcs, lead-energies et lead-energies-rcs, puis envoie :
 *
 *   1. une notification au responsable du département (destinataire principal) ;
 *   2. une copie au chef de département (champ Cc) ;
 *   3. un e-mail de confirmation au prospect (accusé de réception).
 *
 * Fonctionne en PHP 7.4+ sans dépendance ni extension obligatoire
 * (mbstring/iconv sont utilisés s'ils sont disponibles, sinon repli interne).
 *
 * Installation : déposer ce fichier et `lead-form-config.php` à la racine du site.
 * Configuration : voir lead-form-config.php et docs/formulaire-lead-mails.md.
 *
 * @package HelloLead
 */

if (!defined('HL_LEAD_FORM_VERSION')) {
    define('HL_LEAD_FORM_VERSION', '1.0.0');
}

/* =====================================================================
 * Configuration
 * ================================================================== */

/**
 * Charge la configuration (valeurs par défaut + surcharge du fichier dédié).
 *
 * @param bool $reload Force la relecture du fichier (utile en recette/tests).
 * @return array
 */
function hl_lf_config($reload = false)
{
    static $config = null;

    if ($reload) {
        $config = null;
    }

    if ($config !== null) {
        return $config;
    }

    $defaults = array(
        'enabled'      => false, // passez à true une fois les adresses renseignées
        'transport'    => 'mail', // mail | smtp | wp_mail | log
        'timezone'     => 'Europe/Paris',
        'from'         => array('email' => 'no-reply@hellolead.fr', 'name' => 'HelloLead'),
        'reply_to'     => array('email' => 'contact@hellolead.fr', 'name' => 'HelloLead'),
        'site_url'     => 'https://www.hellodevis.fr',
        'log_dir'      => __DIR__ . '/logs',
        'log_enabled'  => true,
        'securite'     => array('referer_check' => false),
        'anti_spam'    => array(
            'min_fill_seconds' => 1.5,
            'rate_window'      => 900,
            'rate_max'         => 5,
            'rate_day_max'     => 20,
            'dedupe_seconds'   => 600,
        ),
        'smtp'         => array(
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls', // tls | ssl | none
            'username'   => '',
            'password'   => '',
            'timeout'    => 10,
        ),
        'wp_load_path' => '', // ex. '/var/www/site/wp-load.php' pour le transport wp_mail
        'forms'        => array(),
    );

    $file = __DIR__ . '/lead-form-config.php';
    if (is_file($file)) {
        $custom = require $file;
        if (is_array($custom)) {
            $defaults = hl_lf_array_merge($defaults, $custom);
        }
    }

    $config = $defaults;

    return $config;
}

/**
 * Fusion récursive simple (les scalaires de $override écrasent $base).
 *
 * @param array $base
 * @param array $override
 * @return array
 */
function hl_lf_array_merge(array $base, array $override)
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = hl_lf_array_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

/**
 * Paramètres par défaut d'un formulaire (utilisés si la config ne résout rien).
 *
 * @param array $config
 * @param string $key
 * @return array
 */
function hl_lf_resolve_form(array $config, $key)
{
    $forms = isset($config['forms']) && is_array($config['forms']) ? $config['forms'] : array();
    $entry = isset($forms[$key]) && is_array($forms[$key]) ? $forms[$key] : array();

    $fallback = isset($forms['default']) && is_array($forms['default']) ? $forms['default'] : array();

    $form = array(
        'label'       => 'Lead',
        'responsable' => array(),
        'chef'        => array(),
        'besoins'     => array(),
        'reply_to'    => $config['reply_to'],
        'enabled'     => null,
    );

    foreach (array($fallback, $entry) as $source) {
        foreach ($source as $field => $value) {
            if ($value === null || $value === '' || $value === array()) {
                continue;
            }
            $form[$field] = $value;
        }
    }

    $form['responsable'] = hl_lf_email_list($form['responsable']);
    $form['chef'] = hl_lf_email_list($form['chef']);
    $form['besoins'] = is_array($form['besoins']) ? array_values(array_filter($form['besoins'], 'is_string')) : array();

    if ($form['enabled'] === null) {
        $form['enabled'] = !empty($config['enabled']);
    }

    return $form;
}

/**
 * Normalise une liste d'adresses (string, "a, b" ou array).
 *
 * @param mixed $value
 * @return array
 */
function hl_lf_email_list($value)
{
    if (is_string($value)) {
        $value = preg_split('/[,;]+/', $value);
    }
    if (!is_array($value)) {
        return array();
    }

    $emails = array();
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = trim($item);
        if ($item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $item;
        }
    }

    return array_values(array_unique($emails));
}

/* =====================================================================
 * Utilitaires texte (sans dépendance à mbstring/iconv)
 * ================================================================== */

/**
 * Longueur en caractères UTF-8.
 *
 * @param string $value
 * @return int
 */
function hl_lf_strlen($value)
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value);

    return $count === false ? strlen($value) : (int) $count;
}

/**
 * Tronque une chaîne UTF-8 sans couper un caractère.
 *
 * @param string $value
 * @param int $max
 * @return string
 */
function hl_lf_substr($value, $max)
{
    if ($max <= 0 || hl_lf_strlen($value) <= $max) {
        return $value;
    }

    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $max, 'UTF-8');
    }

    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return substr($value, 0, $max);
    }

    return implode('', array_slice($chars, 0, $max));
}

/**
 * Retourne le libellé officiel correspondant à une valeur reçue, ou la
 * valeur nettoyée si aucune correspondance n'est trouvée.
 *
 * @param string $value
 * @param array  $allowed
 * @return string|null Libellé officiel, ou null si aucun ne correspond.
 */
function hl_lf_match_label($value, array $allowed)
{
    $needle = hl_lf_norm_label($value);
    foreach ($allowed as $label) {
        if (hl_lf_norm_label($label) === $needle) {
            return (string) $label;
        }
    }

    return null;
}

/**
 * Nettoie un champ texte : balises retirées, espaces compactés, longueur bornée.
 *
 * @param mixed $value
 * @param int $max
 * @return string
 */
function hl_lf_clean_text($value, $max)
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    $value = (string) $value;
    $value = str_replace(array("\r", "\n", "\t", "\0"), ' ', $value);
    $value = strip_tags($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string) $value);

    return hl_lf_substr($value, $max);
}

/**
 * Normalise un libellé pour comparaison : apostrophes typographiques,
 * espaces insécables, casse et espaces multiples. Évite qu'un besoin
 * disparaisse parce que la page envoie « d’électricité » et la
 * configuration « d'électricité ».
 *
 * @param string $value
 * @return string
 */
function hl_lf_norm_label($value)
{
    $value = (string) $value;
    $value = str_replace(array("\xE2\x80\x99", "\xE2\x80\x98", '`', '\xC2\xA0'), array("'", "'", "'", ' '), $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string) $value);

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Neutralise un éventuel injection d'en-tête (CR/LF).
 *
 * @param string $value
 * @return string
 */
function hl_lf_header_safe($value)
{
    $value = str_replace(array("\r", "\n", "\0"), ' ', (string) $value);
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim((string) $value);
}

/**
 * Indique si la chaîne est purement ASCII imprimable.
 *
 * @param string $value
 * @return bool
 */
function hl_lf_is_ascii($value)
{
    return !preg_match('/[^\x20-\x7E]/', $value);
}

/**
 * Encode un en-tête non-ASCII au format RFC 2047 (encoded-words base64).
 *
 * @param string $value
 * @return string
 */
function hl_lf_mime_header($value)
{
    $value = hl_lf_header_safe($value);

    if (hl_lf_is_ascii($value)) {
        return $value;
    }

    if (function_exists('mb_encode_mimeheader')) {
        $encoded = mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }
    }

    /*
     * Repli sans mbstring : on découpe la valeur en encoded-words d'au plus
     * 40 octets UTF-8 (56 caractères base64 + 12 de syntaxe = 68 colonnes),
     * pour que « Subject: » + l'encoded-word reste sous 78 colonnes.
     *
     * Deux pièges évités ici :
     *  - un encoded-word ne peut pas être coupé au milieu d'un caractère
     *    multi-octets ;
     *  - l'espace qui sépare deux encoded-words adjacents est ignoré au
     *    décodage (RFC 2047 §6.2) : les espaces doivent donc rester DANS les
     *    encoded-words, sinon « Bonjour Jean » devient « BonjourJean ».
     */
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    $chunks = array();
    $chunk = '';
    $lastSpace = -1;

    foreach ($chars as $char) {
        $chunk .= $char;

        if ($char === ' ') {
            $lastSpace = strlen($chunk);
        }

        if (strlen($chunk) >= 40) {
            // On coupe AVANT l'espace : elle reste ainsi en tête du morceau
            // suivant et aucun encoded-word ne se termine par une espace.
            $cut = $lastSpace - 1;
            if ($cut > 0 && strlen($chunk) - $cut <= 20) {
                $chunks[] = substr($chunk, 0, $cut);
                $chunk = substr($chunk, $cut);
            } else {
                $chunks[] = $chunk;
                $chunk = '';
            }
            $lastSpace = -1;
        }
    }

    if ($chunk !== '') {
        $chunks[] = $chunk;
    }

    $lines = array();
    foreach ($chunks as $part) {
        $lines[] = '=?UTF-8?B?' . base64_encode($part) . '?=';
    }

    return implode("\r\n ", $lines);
}

/**
 * Corps encodé en base64, lignes de 76 caractères.
 *
 * @param string $body
 * @return string
 */
function hl_lf_base64_body($body)
{
    return rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
}

/* =====================================================================
 * Sécurité / anti-spam
 * ================================================================== */

/**
 * Adresse IP du client (proxy-aware, tronquée pour le stockage).
 *
 * @param array $server
 * @return string
 */
function hl_lf_client_ip(array $server)
{
    $candidates = array('REMOTE_ADDR');

    if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
        array_unshift($candidates, 'HTTP_CF_CONNECTING_IP');
    }
    if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
        array_unshift($candidates, 'HTTP_X_FORWARDED_FOR');
    }

    foreach ($candidates as $key) {
        if (empty($server[$key])) {
            continue;
        }
        $value = (string) $server[$key];
        if (strpos($value, ',') !== false) {
            $value = trim(explode(',', $value)[0]);
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '0.0.0.0';
}

/**
 * Version pseudonymisée de l'IP, seule valeur conservée dans les journaux.
 *
 * L'IP en clair ne sert qu'à la limitation de débit (nom de fichier haché) ;
 * les journaux, eux, ne gardent qu'une empreinte non réversible : suffisant
 * pour repérer un abus, inutile pour réidentifier une personne (RGPD).
 *
 * @param string $ip
 * @return string
 */
function hl_lf_ip_hash($ip)
{
    return substr(hash('sha256', 'hl-lead-form|' . $ip), 0, 16);
}

/**
 * Prépare le dossier de logs (et le protège en Apache).
 *
 * @param array $config
 * @return string
 */
function hl_lf_log_dir(array $config)
{
    $dir = rtrim((string) $config['log_dir'], '/');

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (is_dir($dir) && !is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }

    return $dir;
}

/**
 * Journalise une entrée JSON (une ligne par événement).
 *
 * @param array $config
 * @param array $entry
 * @return void
 */
function hl_lf_log(array $config, array $entry)
{
    if (empty($config['log_enabled'])) {
        return;
    }

    $dir = hl_lf_log_dir($config);
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $entry['date'] = gmdate('c');
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line === false) {
        return;
    }

    @file_put_contents(
        $dir . '/leads-' . gmdate('Y-m') . '.jsonl',
        $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Limitation de débit par IP (fichiers JSON).
 *
 * @param array $config
 * @param string $ip
 * @return array{allowed: bool, retry_after: int}
 */
function hl_lf_rate_limit(array $config, $ip)
{
    $rules = isset($config['anti_spam']) && is_array($config['anti_spam']) ? $config['anti_spam'] : array();
    $window = isset($rules['rate_window']) ? (int) $rules['rate_window'] : 900;
    $max = isset($rules['rate_max']) ? (int) $rules['rate_max'] : 5;
    $dayMax = isset($rules['rate_day_max']) ? (int) $rules['rate_day_max'] : 20;

    $dir = hl_lf_log_dir($config) . '/rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_dir($dir)) {
        return array('allowed' => true, 'retry_after' => 0);
    }

    $file = $dir . '/' . sha1($ip) . '.json';
    $now = time();
    $hits = array();

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, function ($ts) use ($now) {
                return is_numeric($ts) && ($now - (int) $ts) < 86400;
            }));
        }
    }

    $inWindow = array();
    foreach ($hits as $ts) {
        if (($now - (int) $ts) < $window) {
            $inWindow[] = (int) $ts;
        }
    }

    if (count($inWindow) >= $max) {
        $oldest = min($inWindow);

        return array('allowed' => false, 'retry_after' => max(30, $window - ($now - $oldest)));
    }

    if (count($hits) >= $dayMax) {
        return array('allowed' => false, 'retry_after' => 3600);
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);

    return array('allowed' => true, 'retry_after' => 0);
}

/**
 * Détecte un renvoi du même formulaire (double clic, retry réseau).
 *
 * @param array $config
 * @param string $leadId
 * @return bool
 */
function hl_lf_is_duplicate(array $config, $leadId)
{
    if ($leadId === '') {
        return false;
    }

    $rules = isset($config['anti_spam']) && is_array($config['anti_spam']) ? $config['anti_spam'] : array();
    $seconds = isset($rules['dedupe_seconds']) ? (int) $rules['dedupe_seconds'] : 600;
    if ($seconds <= 0) {
        return false;
    }

    $dir = hl_lf_log_dir($config) . '/done';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_dir($dir)) {
        return false;
    }

    $now = time();
    foreach ((array) glob($dir . '/*.json') as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > 86400) {
            @unlink($file);
        }
    }

    $file = $dir . '/' . sha1($leadId) . '.json';
    if (is_file($file) && (@filemtime($file) ?: 0) > ($now - $seconds)) {
        return true;
    }

    @file_put_contents($file, json_encode(array('at' => $now)), LOCK_EX);

    return false;
}

/* =====================================================================
 * Validation des données
 * ================================================================== */

/**
 * Normalise un numéro de téléphone en chiffres (+ éventuel préfixe).
 *
 * @param string $value
 * @return string
 */
function hl_lf_normalize_phone($value)
{
    $value = (string) $value;
    $value = preg_replace('/[\s\.\-\(\)\/]/', '', $value);
    $value = preg_replace('/[^\d+]/', '', (string) $value);

    if (substr($value, 0, 4) === '0033') {
        $value = '+33' . substr($value, 4);
    }

    return $value;
}

/**
 * Valide un numéro (France métropolitaine, DOM ou international E.164).
 *
 * @param string $value
 * @return bool
 */
function hl_lf_is_valid_phone($value)
{
    $value = hl_lf_normalize_phone($value);

    if (preg_match('/^0[1-9]\d{8}$/', $value)) {
        return true;
    }
    if (preg_match('/^\+33[1-9]\d{8}$/', $value)) {
        return true;
    }
    if (preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
        return true;
    }

    return false;
}

/**
 * Formate un numéro français en « 06 12 34 56 78 ».
 *
 * @param string $value
 * @return string
 */
function hl_lf_format_phone($value)
{
    $normalized = hl_lf_normalize_phone($value);

    if (preg_match('/^0[1-9]\d{8}$/', $normalized)) {
        return trim(preg_replace('/(\d{2})(?=\d)/', '$1 ', $normalized));
    }
    if (preg_match('/^\+33([1-9]\d{8})$/', $normalized, $m)) {
        return trim(preg_replace('/(\d{2})(?=\d)/', '$1 ', '0' . $m[1]));
    }

    return (string) $value;
}

/**
 * Valide et normalise les données du formulaire.
 *
 * @param array $input
 * @param array $form
 * @return array{data: array, errors: array}
 */
function hl_lf_validate(array $input, array $form)
{
    $data = array();
    $errors = array();

    $data['nom'] = hl_lf_clean_text(isset($input['nom']) ? $input['nom'] : '', 80);
    if (hl_lf_strlen($data['nom']) < 2 || !preg_match('/\p{L}/u', $data['nom'])) {
        $errors['nom'] = 'Merci d’indiquer votre nom et prénom.';
    }

    $data['email'] = strtolower(hl_lf_clean_text(isset($input['email']) ? $input['email'] : '', 190));
    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Merci d’indiquer une adresse e-mail valide.';
    }

    $data['telephone'] = hl_lf_clean_text(isset($input['telephone']) ? $input['telephone'] : '', 30);
    if (!hl_lf_is_valid_phone($data['telephone'])) {
        $errors['telephone'] = 'Merci d’indiquer un numéro de téléphone valide.';
    } else {
        $data['telephone'] = hl_lf_format_phone($data['telephone']);
    }

    $data['code_postal'] = preg_replace('/\s+/', '', hl_lf_clean_text(isset($input['code_postal']) ? $input['code_postal'] : '', 10));
    if (!preg_match('/^\d{5}$/', $data['code_postal'])) {
        $errors['code_postal'] = 'Merci d’indiquer un code postal à 5 chiffres.';
    }

    $data['societe'] = hl_lf_clean_text(isset($input['societe']) ? $input['societe'] : '', 120);
    if (hl_lf_strlen($data['societe']) < 2) {
        $errors['societe'] = 'Merci d’indiquer le nom de votre société.';
    }

    $volumes = array('Moins de 100', '100 à 500', '500 à 1000', 'Plus de 1000');
    $data['volume'] = hl_lf_clean_text(isset($input['volume_souhaite']) ? $input['volume_souhaite'] : (isset($input['volume']) ? $input['volume'] : ''), 40);
    if (!in_array($data['volume'], $volumes, true)) {
        $errors['volume_souhaite'] = 'Merci de sélectionner un volume de leads.';
    }

    $besoins = isset($input['besoins']) ? $input['besoins'] : array();
    if (is_string($besoins)) {
        $besoins = preg_split('/\s*[|;,]\s*/', $besoins);
    }
    $besoins = is_array($besoins) ? $besoins : array();
    $cleanBesoins = array();
    $allowed = isset($form['besoins']) && is_array($form['besoins']) ? $form['besoins'] : array();
    foreach (array_slice($besoins, 0, 12) as $besoin) {
        $besoin = hl_lf_clean_text($besoin, 90);
        if ($besoin === '') {
            continue;
        }
        if (!empty($allowed)) {
            // Correspondance sur libellé normalisé (apostrophes typographiques,
            // espaces insécables, casse) puis on ne garde que la valeur de
            // référence : tout le reste est écarté (liste blanche).
            $besoin = hl_lf_match_label($besoin, $allowed);
            if ($besoin === null) {
                continue;
            }
        }
        $cleanBesoins[] = $besoin;
    }
    $data['besoins'] = array_values(array_unique($cleanBesoins));

    $data['page'] = hl_lf_clean_text(isset($input['page']) ? $input['page'] : '', 300);
    $data['lead_id'] = preg_match('/^[A-Za-z0-9\-]{8,64}$/', (string) (isset($input['lead_id']) ? $input['lead_id'] : ''))
        ? (string) $input['lead_id']
        : '';
    $data['consent'] = !empty($input['consent']);

    $data['utm'] = array();
    if (isset($input['utm']) && is_array($input['utm'])) {
        foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid') as $key) {
            if (!empty($input['utm'][$key])) {
                $data['utm'][$key] = hl_lf_clean_text($input['utm'][$key], 120);
            }
        }
    }

    return array('data' => $data, 'errors' => $errors);
}

/* =====================================================================
 * Rendu des e-mails
 * ================================================================== */

/**
 * Échappe une valeur pour le HTML.
 *
 * @param string $value
 * @return string
 */
function hl_lf_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Lignes du récapitulatif (label => valeur).
 *
 * @param array $data
 * @param array $meta
 * @param bool  $forClient Version épurée destinée au prospect.
 * @return array
 */
function hl_lf_recap_rows(array $data, array $meta, $forClient = false)
{
    if ($forClient) {
        return array(
            'Nom et prénom'   => $data['nom'],
            'Société'         => $data['societe'],
            'E-mail'          => $data['email'],
            'Téléphone'       => $data['telephone'],
            'Code postal'     => $data['code_postal'],
            'Volume souhaité' => $data['volume'] . ' leads / mois',
            'Besoins'         => !empty($data['besoins']) ? implode(' · ', $data['besoins']) : 'Non précisé',
        );
    }

    $rows = array(
        'Nom et prénom' => $data['nom'],
        'Société'       => $data['societe'],
        'E-mail'        => $data['email'],
        'Téléphone'     => $data['telephone'],
        'Code postal'   => $data['code_postal'],
        'Volume souhaité' => $data['volume'] . ' leads / mois',
        'Besoins'       => !empty($data['besoins']) ? implode(' · ', $data['besoins']) : 'Non précisé',
        'Formulaire'    => $meta['form_label'],
        'Page d’origine' => $meta['page'],
        'Reçue le'      => $meta['date_local'],
        'Référence'     => $meta['lead_id'] !== '' ? $meta['lead_id'] : '—',
    );

    if (!empty($data['utm'])) {
        $parts = array();
        foreach ($data['utm'] as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $rows['Traçage'] = implode(' · ', $parts);
    }

    return $rows;
}

/**
 * Version texte brut d'un récapitulatif.
 *
 * @param array $rows
 * @return string
 */
function hl_lf_recap_text(array $rows)
{
    $lines = array();
    $width = 0;
    foreach (array_keys($rows) as $label) {
        $width = max($width, hl_lf_strlen($label));
    }

    foreach ($rows as $label => $value) {
        $lines[] = str_pad($label, $width, ' ') . ' : ' . $value;
    }

    return implode("\n", $lines);
}

/**
 * Tableau HTML du récapitulatif (styles inline, compatibles clients mail).
 *
 * @param array $rows
 * @return string
 */
function hl_lf_recap_html(array $rows)
{
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:14px;color:#2f3033;">';

    $index = 0;
    foreach ($rows as $label => $value) {
        $background = ($index % 2 === 0) ? '#ffffff' : '#f7f8fa';
        $html .= '<tr style="background:' . $background . ';">'
            . '<th align="left" style="padding:9px 12px;font-weight:600;color:#5b6470;width:42%;border-bottom:1px solid #eceef1;">' . hl_lf_e($label) . '</th>'
            . '<td style="padding:9px 12px;border-bottom:1px solid #eceef1;">' . nl2br(hl_lf_e($value)) . '</td>'
            . '</tr>';
        $index++;
    }

    $html .= '</table>';

    return $html;
}

/**
 * Gabarit HTML commun.
 *
 * @param string $title
 * @param string $intro
 * @param string $content
 * @param string $footer
 * @return string
 */
function hl_lf_wrap_html($title, $intro, $content, $footer)
{
    return '<!DOCTYPE html>'
        . '<html lang="fr"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . hl_lf_e($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f2f3f5;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . hl_lf_e($intro) . '</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#f2f3f5;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:Inter,Helvetica,Arial,sans-serif;color:#2f3033;">'
        . '<tr><td style="height:6px;background:#eb5846;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:26px 28px 8px;">'
        . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#eb5846;font-weight:700;">HelloLead</div>'
        . '<h1 style="margin:10px 0 6px;font-size:21px;line-height:1.3;color:#14171a;">' . hl_lf_e($title) . '</h1>'
        . '<p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:#4b5563;">' . hl_lf_e($intro) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px 24px;">' . $content . '</td></tr>'
        . '<tr><td style="padding:16px 28px 22px;background:#fafbfc;border-top:1px solid #eceef1;font-size:12px;line-height:1.6;color:#7b8494;">'
        . $footer
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

/**
 * Builds the 3 messages (interne, copie chef, confirmation client).
 *
 * @param array $data
 * @param array $meta
 * @param array $form
 * @param array $config
 * @return array
 */
function hl_lf_build_messages(array $data, array $meta, array $form, array $config)
{
    $rows = hl_lf_recap_rows($data, $meta);
    $recapHtml = hl_lf_recap_html($rows);
    $recapText = hl_lf_recap_text($rows);
    $clientRows = hl_lf_recap_rows($data, $meta, true);
    $clientRecapHtml = hl_lf_recap_html($clientRows);
    $clientRecapText = hl_lf_recap_text($clientRows);
    $fromName = isset($config['from']['name']) ? $config['from']['name'] : 'HelloLead';
    $fromEmail = isset($config['from']['email']) ? $config['from']['email'] : 'no-reply@hellolead.fr';
    $messages = array();

    /* ---------- 1. Notification interne ---------- */
    $internalSubject = sprintf(
        '[Lead %s] %s — %s leads/mois',
        $form['label'],
        $data['societe'],
        $data['volume']
    );

    $recapShort = !empty($data['besoins']) ? implode(', ', $data['besoins']) : 'non précisé';
    $internalIntro = sprintf(
        'Nouvelle demande de lead : %s (%s) — %s leads/mois.',
        $data['societe'],
        $data['nom'],
        $data['volume']
    );

    $internalContent = $recapHtml
        . '<div style="margin-top:20px;padding:14px 16px;background:#fff7f5;border:1px solid #f6d5ce;border-radius:10px;font-size:13.5px;line-height:1.55;color:#7a2a20;">'
        . '<strong style="display:block;margin-bottom:6px;color:#a3352a;">Action attendue</strong>'
        . 'Recontacter ce prospect par téléphone ou par e-mail. Répondez directement à cet e-mail pour lui écrire.'
        . '</div>';

    $internalText = "NOUVELLE DEMANDE DE LEAD\n"
        . "========================\n\n"
        . $recapText . "\n\n"
        . "Besoins : " . $recapShort . "\n"
        . "Action attendue : recontacter ce prospect par téléphone ou par e-mail.\n"
        . "Répondez directement à cet e-mail pour lui écrire.\n";

    $footer = 'Demande reçue via ' . hl_lf_e($config['site_url']) . ' — '
        . hl_lf_e($meta['date_local']) . '<br>'
        . 'Référence : ' . hl_lf_e($meta['lead_id'] !== '' ? $meta['lead_id'] : '—');

    $messages['internal'] = array(
        'key'      => 'internal',
        'to'       => $form['responsable'],
        'cc'       => $form['chef'],
        'bcc'      => array(),
        // Répondre à la notification interne écrit directement au prospect.
        'reply_to' => array('email' => $data['email'], 'name' => $data['nom']),
        'from'     => array('email' => $fromEmail, 'name' => $fromName),
        'subject'  => $internalSubject,
        'html'     => hl_lf_wrap_html('Nouvelle demande de lead', $internalIntro, $internalContent, $footer),
        'text'     => $internalText,
        'label'    => 'notification responsable',
    );

    /* ---------- 2. Confirmation prospect ---------- */
    $contact = isset($config['reply_to']) && is_array($config['reply_to']) ? $config['reply_to'] : array();
    $contactEmail = !empty($contact['email']) ? $contact['email'] : $fromEmail;
    $contactName = !empty($contact['name']) ? $contact['name'] : $fromName;

    $firstName = $data['nom'];
    if (preg_match('/^(\S+)/u', $data['nom'], $m)) {
        $firstName = $m[1];
    }

    $clientSubject = 'Votre demande a bien été reçue — HelloLead';
    $clientIntro = 'Bonjour ' . $firstName . ', nous avons bien reçu votre demande : une équipe s’en charge.';

    $clientContent = '<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;color:#2f3033;">'
        . 'Merci de votre confiance. Votre demande de leads <strong>' . hl_lf_e($form['label']) . '</strong> a bien été enregistrée '
        . 'et a été transmise à notre équipe commerciale, qui en prend le suivi.'
        . '</p>'
        . '<p style="margin:0 0 18px;font-size:14.5px;line-height:1.6;color:#2f3033;">'
        . 'Un expert HelloLead revient vers vous pour préciser votre besoin et vous proposer un plan de leads adapté. '
        . 'Aucune action n’est nécessaire de votre part pour le moment.'
        . '</p>'
        . '<div style="margin:0 0 8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#7b8494;font-weight:700;">Récapitulatif de votre demande</div>'
        . $clientRecapHtml
        . '<p style="margin:18px 0 0;font-size:13.5px;line-height:1.6;color:#4b5563;">'
        . 'Une question ? Répondez simplement à cet e-mail'
        . ($contactEmail !== '' ? ' ou écrivez-nous à <a href="mailto:' . hl_lf_e($contactEmail) . '" style="color:#eb5846;text-decoration:none;">' . hl_lf_e($contactEmail) . '</a>' : '')
        . '.</p>';

    $clientText = 'Bonjour ' . $firstName . ",\n\n"
        . "Nous avons bien reçu votre demande : une équipe s'en charge.\n\n"
        . "Merci de votre confiance. Votre demande de leads " . $form['label'] . " a bien été enregistrée\n"
        . "et a été transmise à notre équipe commerciale, qui en prend le suivi.\n\n"
        . "Un expert HelloLead revient vers vous pour préciser votre besoin et vous proposer\n"
        . "un plan de leads adapté. Aucune action n'est nécessaire de votre part pour le moment.\n\n"
        . "Récapitulatif de votre demande\n"
        . "-----------------------------\n"
        . $clientRecapText . "\n\n"
        . "Une question ? Répondez simplement à cet e-mail"
        . ($contactEmail !== '' ? " ou écrivez-nous à " . $contactEmail : '') . ".\n\n"
        . "--\n"
        . $contactName . "\n"
        . $config['site_url'] . "\n";

    $messages['client'] = array(
        'key'      => 'client',
        'to'       => array($data['email']),
        'cc'       => array(),
        'bcc'      => array(),
        'from'     => array('email' => $fromEmail, 'name' => $contactName),
        'reply_to' => array('email' => $contactEmail, 'name' => $contactName),
        'subject'  => $clientSubject,
        'html'     => hl_lf_wrap_html(
            'Votre demande a bien été reçue',
            $clientIntro,
            $clientContent,
            'Vous recevez cet e-mail car vous avez envoyé une demande depuis ' . hl_lf_e($config['site_url']) . '.<br>'
            . 'Vos données sont traitées conformément à notre <a href="' . hl_lf_e(rtrim($config['site_url'], '/')) . '/politique-de-confidentialite-donnees-personnelles/" style="color:#eb5846;text-decoration:none;">politique de confidentialité</a>.'
        ),
        'text'     => $clientText,
        'label'    => 'confirmation prospect',
        'auto_generated' => true,
    );

    return $messages;
}

/* =====================================================================
 * Assemblage MIME & transports
 * ================================================================== */

/**
 * Assemble les en-têtes et le corps MIME d'un message.
 *
 * @param array $message
 * @return array{headers: array, body: string, to: array, cc: array}
 */
function hl_lf_build_mime(array $message)
{
    $boundary = 'hl-' . bin2hex(function_exists('random_bytes') ? random_bytes(12) : (string) mt_rand());
    $domain = 'hellolead.fr';
    if (!empty($message['from']['email']) && strpos($message['from']['email'], '@') !== false) {
        $domain = substr(strrchr($message['from']['email'], '@'), 1);
    }

    $headers = array();
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . $boundary . '@' . $domain . '>';
    $headers[] = 'From: ' . hl_lf_address($message['from']);
    $headers[] = 'To: ' . implode(', ', array_map('hl_lf_address', $message['to']));

    if (!empty($message['cc'])) {
        $headers[] = 'Cc: ' . implode(', ', array_map('hl_lf_address', $message['cc']));
    }
    if (!empty($message['bcc'])) {
        $headers[] = 'Bcc: ' . implode(', ', array_map('hl_lf_address', $message['bcc']));
    }
    if (!empty($message['reply_to']['email'])) {
        $headers[] = 'Reply-To: ' . hl_lf_address($message['reply_to']);
    }

    $headers[] = 'Subject: ' . hl_lf_mime_header($message['subject']);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'X-Mailer: HelloLead Lead Form ' . HL_LEAD_FORM_VERSION;

    if (!empty($message['auto_generated'])) {
        // RFC 3834 : empêche les réponses automatiques (absences) et les boucles.
        $headers[] = 'Auto-Submitted: auto-generated';
        $headers[] = 'X-Auto-Response-Suppress: All';
    }

    $body = "--" . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . hl_lf_base64_body($message['text']) . "\r\n\r\n"
        . "--" . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . hl_lf_base64_body($message['html']) . "\r\n\r\n"
        . "--" . $boundary . "--";

    return array(
        'headers' => $headers,
        'body'    => $body,
        'to'      => $message['to'],
        'cc'      => $message['cc'],
    );
}

/**
 * Formate une adresse « Nom <email> » (nom encodé si nécessaire).
 * Accepte une chaîne (« email ») ou un tableau (email + name).
 *
 * @param array|string $address
 * @return string
 */
function hl_lf_address($address)
{
    if (is_string($address)) {
        $address = array('email' => $address);
    }
    if (!is_array($address)) {
        return '';
    }

    $email = hl_lf_header_safe(isset($address['email']) ? $address['email'] : '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    $name = isset($address['name']) ? hl_lf_header_safe($address['name']) : '';
    if ($name === '') {
        return $email;
    }

    return hl_lf_mime_header($name) . ' <' . $email . '>';
}

/**
 * Envoie un message via le transport configuré.
 *
 * @param array $message
 * @param array $config
 * @return array{ok: bool, transport: string, error: string}
 */
function hl_lf_send(array $message, array $config)
{
    $mime = hl_lf_build_mime($message);
    $transport = isset($config['transport']) ? $config['transport'] : 'mail';

    if ($transport === 'log') {
        return hl_lf_send_log($message, $mime, $config);
    }

    if ($transport === 'wp_mail') {
        return hl_lf_send_wp_mail($message, $config);
    }

    if ($transport === 'smtp') {
        return hl_lf_send_smtp($message, $mime, $config);
    }

    return hl_lf_send_mail($message, $mime);
}

/**
 * Transport « mail() » (par défaut).
 *
 * @param array $message
 * @param array $mime
 * @return array{ok: bool, transport: string, error: string}
 */
function hl_lf_send_mail(array $message, array $mime)
{
    $headers = $mime['headers'];
    $to = implode(', ', array_map('hl_lf_address', $message['to']));

    // mail() reçoit To/Subject en arguments séparés.
    $filtered = array();
    foreach ($headers as $header) {
        if (stripos($header, 'To:') === 0) {
            continue;
        }
        $filtered[] = $header;
    }

    $subject = '';
    $finalHeaders = array();
    foreach ($filtered as $header) {
        if (stripos($header, 'Subject:') === 0) {
            $subject = trim(substr($header, strlen('Subject:')));
            continue;
        }
        $finalHeaders[] = $header;
    }

    if (!function_exists('mail')) {
        return array('ok' => false, 'transport' => 'mail', 'error' => 'La fonction mail() est désactivée.');
    }

    $sent = @mail($to, $subject, $mime['body'], implode("\r\n", $finalHeaders));

    return array(
        'ok'        => (bool) $sent,
        'transport' => 'mail',
        'error'     => $sent ? '' : 'mail() a renvoyé une erreur.',
    );
}

/**
 * Transport « wp_mail » (WordPress / PHPMailer, recommandé sur un site WP).
 *
 * @param array $message
 * @param array $config
 * @return array{ok: bool, transport: string, error: string}
 */
function hl_lf_send_wp_mail(array $message, array $config)
{
    if (!function_exists('wp_mail')) {
        $path = isset($config['wp_load_path']) ? (string) $config['wp_load_path'] : '';
        if ($path !== '' && is_file($path)) {
            ob_start();
            require_once $path;
            ob_end_clean();
        }
    }

    if (!function_exists('wp_mail')) {
        return array('ok' => false, 'transport' => 'wp_mail', 'error' => 'wp_mail() indisponible : vérifiez wp_load_path.');
    }

    $headers = array();
    foreach ($message['cc'] as $cc) {
        $headers[] = 'Cc: ' . hl_lf_address($cc);
    }
    foreach ($message['bcc'] as $bcc) {
        $headers[] = 'Bcc: ' . hl_lf_address($bcc);
    }
    if (!empty($message['reply_to']['email'])) {
        $headers[] = 'Reply-To: ' . hl_lf_address($message['reply_to']);
    }
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $from = $message['from'];
    $fromFilter = function ($email) use ($from) {
        return $from['email'];
    };
    $fromNameFilter = function ($name) use ($from) {
        return $from['name'];
    };
    add_filter('wp_mail_from', $fromFilter);
    add_filter('wp_mail_from_name', $fromNameFilter);

    $sent = wp_mail(
        array_map('hl_lf_address', $message['to']),
        $message['subject'],
        $message['html'],
        $headers
    );

    remove_filter('wp_mail_from', $fromFilter);
    remove_filter('wp_mail_from_name', $fromNameFilter);

    return array(
        'ok'        => (bool) $sent,
        'transport' => 'wp_mail',
        'error'     => $sent ? '' : 'wp_mail() a renvoyé une erreur.',
    );
}

/**
 * Transport SMTP natif (socket + STARTTLS/AUTH LOGIN).
 *
 * @param array $message
 * @param array $mime
 * @param array $config
 * @return array{ok: bool, transport: string, error: string}
 */
function hl_lf_send_smtp(array $message, array $mime, array $config)
{
    $smtp = isset($config['smtp']) && is_array($config['smtp']) ? $config['smtp'] : array();
    $connection = hl_lf_smtp_connect($smtp);

    if (!empty($connection['error'])) {
        return array('ok' => false, 'transport' => 'smtp', 'error' => $connection['error']);
    }

    $envelope = array();
    foreach (array_merge($message['to'], $message['cc'], $message['bcc']) as $address) {
        if (is_string($address)) {
            $address = array('email' => $address);
        }
        if (!empty($address['email'])) {
            $envelope[] = $address['email'];
        }
    }

    $raw = implode("\r\n", $mime['headers']) . "\r\n\r\n" . $mime['body'];
    $result = hl_lf_smtp_conversation($connection['conn'], array(
        'from'        => $message['from']['email'],
        'recipients'  => array_values(array_unique($envelope)),
        'data'        => $raw,
        'username'    => isset($smtp['username']) ? (string) $smtp['username'] : '',
        'password'    => isset($smtp['password']) ? (string) $smtp['password'] : '',
    ));

    hl_lf_smtp_close($connection['conn']);

    $result['transport'] = 'smtp';

    return $result;
}

/**
 * Ouvre une connexion SMTP.
 *
 * @param array $smtp
 * @return array{conn: mixed, error: string}
 */
function hl_lf_smtp_connect(array $smtp)
{
    $host = isset($smtp['host']) ? (string) $smtp['host'] : '';
    $port = isset($smtp['port']) ? (int) $smtp['port'] : 587;
    $encryption = isset($smtp['encryption']) ? (string) $smtp['encryption'] : 'tls';
    $timeout = isset($smtp['timeout']) ? (int) $smtp['timeout'] : 10;

    if ($host === '') {
        return array('conn' => null, 'error' => 'Hôte SMTP non configuré.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));

    $conn = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

    if (!$conn) {
        return array('conn' => null, 'error' => 'Connexion SMTP impossible : ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($conn, $timeout);

    $conn = array(
        'stream'     => $conn,
        'encryption' => $encryption,
        'host'       => $host,
    );

    return array('conn' => $conn, 'error' => '');
}

/**
 * Ferme une connexion SMTP.
 *
 * @param mixed $conn
 * @return void
 */
function hl_lf_smtp_close($conn)
{
    if (is_array($conn) && isset($conn['stream']) && is_resource($conn['stream'])) {
        @fwrite($conn['stream'], "QUIT\r\n");
        @fclose($conn['stream']);
    }
}

/**
 * Dialogue SMTP (testable : $conn accepte un faux adaptateur write/read/close).
 *
 * @param mixed $conn
 * @param array $options
 * @return array{ok: bool, error: string}
 */
function hl_lf_smtp_conversation($conn, array $options)
{
    $read = function () use ($conn) {
        if (is_array($conn) && isset($conn['stream'])) {
            return hl_lf_smtp_read_response($conn['stream']);
        }
        if (is_array($conn) && isset($conn['read']) && is_callable($conn['read'])) {
            return call_user_func($conn['read']);
        }

        return array('code' => 0, 'text' => 'Connexion invalide');
    };

    $write = function ($data) use ($conn) {
        if (is_array($conn) && isset($conn['stream'])) {
            return (bool) @fwrite($conn['stream'], $data);
        }
        if (is_array($conn) && isset($conn['write']) && is_callable($conn['write'])) {
            return (bool) call_user_func($conn['write'], $data);
        }

        return false;
    };

    $startTls = function () use ($conn, $write, $read) {
        if (!is_array($conn) || !isset($conn['stream'])) {
            return true;
        }

        $write("STARTTLS\r\n");
        $response = $read();
        if ((int) $response['code'] !== 220) {
            return false;
        }

        return (bool) @stream_socket_enable_crypto($conn['stream'], true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    };

    $expect = function ($codes) use ($read) {
        $response = $read();
        if (!in_array((int) $response['code'], (array) $codes, true)) {
            return array('ok' => false, 'error' => 'SMTP ' . $response['code'] . ' : ' . $response['text']);
        }

        return array('ok' => true, 'error' => '');
    };

    $response = $read();
    if ((int) $response['code'] !== 220) {
        return array('ok' => false, 'error' => 'Accueil SMTP inattendu : ' . $response['code'] . ' ' . $response['text']);
    }

    $host = isset($conn['host']) ? $conn['host'] : 'localhost';
    $encryption = isset($conn['encryption']) ? $conn['encryption'] : 'tls';

    $write('EHLO ' . $host . "\r\n");
    $ehlo = $read();
    if ((int) $ehlo['code'] !== 250) {
        $write('HELO ' . $host . "\r\n");
        $helo = $read();
        if ((int) $helo['code'] !== 250) {
            return array('ok' => false, 'error' => 'EHLO/HELO refusé : ' . $ehlo['code']);
        }
    }

    if ($encryption === 'tls') {
        if (!$startTls()) {
            return array('ok' => false, 'error' => 'STARTTLS refusé par le serveur.');
        }
        $write('EHLO ' . $host . "\r\n");
        $read();
    }

    if (!empty($options['username'])) {
        $write("AUTH LOGIN\r\n");
        $auth = $expect(334);
        if (!$auth['ok']) {
            return $auth;
        }
        $write(base64_encode($options['username']) . "\r\n");
        $auth = $expect(334);
        if (!$auth['ok']) {
            return $auth;
        }
        $write(base64_encode((string) $options['password']) . "\r\n");
        $auth = $expect(235);
        if (!$auth['ok']) {
            return $auth;
        }
    }

    $write('MAIL FROM:<' . $options['from'] . ">\r\n");
    $from = $expect(250);
    if (!$from['ok']) {
        return $from;
    }

    foreach ($options['recipients'] as $recipient) {
        $write('RCPT TO:<' . $recipient . ">\r\n");
        $rcpt = $read();
        if (!in_array((int) $rcpt['code'], array(250, 251), true)) {
            return array('ok' => false, 'error' => 'Destinataire refusé (' . $recipient . ') : ' . $rcpt['code'] . ' ' . $rcpt['text']);
        }
    }

    $write("DATA\r\n");
    $data = $expect(354);
    if (!$data['ok']) {
        return $data;
    }

    $payload = preg_replace('/^\./m', '..', (string) $options['data']);
    $write($payload . "\r\n.\r\n");
    $end = $expect(250);
    if (!$end['ok']) {
        return $end;
    }

    $write("QUIT\r\n");

    return array('ok' => true, 'error' => '');
}

/**
 * Lit une réponse SMTP multi-lignes.
 *
 * @param resource $stream
 * @return array{code: int, text: string}
 */
function hl_lf_smtp_read_response($stream)
{
    $text = '';
    $code = 0;

    while (($line = @fgets($stream, 1024)) !== false) {
        $text .= $line;
        $code = (int) substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return array('code' => $code, 'text' => trim($text));
}

/**
 * Transport « log » : écrit les .eml sur disque (développement / recette).
 *
 * @param array $message
 * @param array $mime
 * @param array $config
 * @return array{ok: bool, transport: string, error: string}
 */
function hl_lf_send_log(array $message, array $mime, array $config)
{
    $dir = hl_lf_log_dir($config) . '/mail';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return array('ok' => false, 'transport' => 'log', 'error' => 'Dossier de logs non accessible.');
    }

    $name = gmdate('Ymd-His') . '-' . $message['key'] . '-' . bin2hex(function_exists('random_bytes') ? random_bytes(4) : (string) mt_rand()) . '.eml';
    $raw = implode("\r\n", $mime['headers']) . "\r\n\r\n" . $mime['body'];
    $written = @file_put_contents($dir . '/' . $name, $raw);

    return array(
        'ok'        => $written !== false,
        'transport' => 'log',
        'error'     => $written === false ? 'Écriture du fichier .eml impossible.' : '',
    );
}

/* =====================================================================
 * Traitement de la requête
 * ================================================================== */

/**
 * Traite une soumission et retourne la réponse HTTP (testable sans globals).
 *
 * @param array  $server Variables serveur ($_SERVER).
 * @param array  $input  Données décodées du POST.
 * @param array  $config Configuration.
 * @return array{status: int, body: array, headers: array}
 */
function hl_lf_handle(array $server, array $input, array $config)
{
    // Fuseau appliqué ici aussi : hl_lf_handle() est appelable seule (tests, CLI).
    if (!empty($config['timezone'])) {
        @date_default_timezone_set($config['timezone']);
    }

    $ip = hl_lf_client_ip($server);
    $formKey = isset($input['formulaire']) ? hl_lf_clean_text($input['formulaire'], 60) : '';
    if ($formKey === '' && isset($input['form'])) {
        $formKey = hl_lf_clean_text($input['form'], 60);
    }

    $form = hl_lf_resolve_form($config, $formKey);

    if (empty($form['enabled'])) {
        hl_lf_log($config, array(
            'event' => 'refusé',
            'raison' => 'formulaire non activé',
            'form' => $formKey,
            'ip' => hl_lf_ip_hash($ip),
        ));

        return array(
            'status'  => 503,
            'body'    => array(
                'ok'      => false,
                'code'    => 'not_configured',
                'message' => 'Le formulaire n’est pas encore activé côté serveur.',
            ),
            'headers' => array('Retry-After' => '300'),
        );
    }

    if (empty($form['responsable'])) {
        hl_lf_log($config, array('event' => 'refusé', 'raison' => 'aucun responsable configuré', 'form' => $formKey, 'ip' => hl_lf_ip_hash($ip)));

        return array(
            'status'  => 503,
            'body'    => array('ok' => false, 'code' => 'not_configured', 'message' => 'Aucun destinataire configuré.'),
            'headers' => array(),
        );
    }

    // Piège à robots : champ caché rempli => on simule un succès.
    if (!empty($input['website'])) {
        hl_lf_log($config, array('event' => 'spam', 'raison' => 'honeypot', 'form' => $formKey, 'ip' => hl_lf_ip_hash($ip)));

        return array('status' => 200, 'body' => array('ok' => true, 'spam' => true), 'headers' => array());
    }

    $rules = isset($config['anti_spam']) && is_array($config['anti_spam']) ? $config['anti_spam'] : array();

    // Délai minimum de remplissage (horodatage client).
    if (!empty($input['ts']) && is_numeric($input['ts'])) {
        $elapsed = (microtime(true) * 1000 - (float) $input['ts']) / 1000;
        $min = isset($rules['min_fill_seconds']) ? (float) $rules['min_fill_seconds'] : 1.5;
        // Démarrage instantané => robot ; léger décalage d'horloge (< 5 s) toléré.
        if ($elapsed < $min && $elapsed > -5) {
            hl_lf_log($config, array('event' => 'spam', 'raison' => 'remplissage trop rapide', 'form' => $formKey, 'ip' => hl_lf_ip_hash($ip)));

            return array(
                'status'  => 422,
                'body'    => array('ok' => false, 'code' => 'spam', 'message' => 'Votre demande n’a pas pu être vérifiée. Merci de réessayer.'),
                'headers' => array(),
            );
        }
    }

    // Anti-doublon.
    $leadId = preg_match('/^[A-Za-z0-9\-]{8,64}$/', (string) (isset($input['lead_id']) ? $input['lead_id'] : ''))
        ? (string) $input['lead_id']
        : '';
    if (hl_lf_is_duplicate($config, $leadId)) {
        return array('status' => 200, 'body' => array('ok' => true, 'duplicate' => true), 'headers' => array());
    }

    // Limitation de débit.
    $rate = hl_lf_rate_limit($config, $ip);
    if (!$rate['allowed']) {
        hl_lf_log($config, array('event' => 'refusé', 'raison' => 'rate limit', 'form' => $formKey, 'ip' => hl_lf_ip_hash($ip)));

        return array(
            'status'  => 429,
            'body'    => array('ok' => false, 'code' => 'rate_limited', 'message' => 'Trop de demandes envoyées récemment. Merci de réessayer plus tard.'),
            'headers' => array('Retry-After' => (string) $rate['retry_after']),
        );
    }

    // Validation.
    $result = hl_lf_validate($input, $form);
    $data = $result['data'];
    $errors = $result['errors'];

    if (!empty($errors)) {
        hl_lf_log($config, array('event' => 'invalide', 'form' => $formKey, 'ip' => hl_lf_ip_hash($ip), 'erreurs' => array_keys($errors)));

        return array(
            'status'  => 400,
            'body'    => array(
                'ok'      => false,
                'code'    => 'validation',
                'message' => 'Certains champs sont incomplets.',
                'errors'  => $errors,
            ),
            'headers' => array(),
        );
    }

    $meta = array(
        'form_key'   => $formKey,
        'form_label' => $form['label'],
        'page'       => $data['page'] !== '' ? $data['page'] : (isset($server['HTTP_REFERER']) ? (string) $server['HTTP_REFERER'] : ''),
        'lead_id'    => $data['lead_id'],
        'date_local' => date('d/m/Y H:i'),
        'ip'         => $ip,
        'user_agent' => hl_lf_clean_text(isset($server['HTTP_USER_AGENT']) ? $server['HTTP_USER_AGENT'] : '', 180),
    );

    $messages = hl_lf_build_messages($data, $meta, $form, $config);

    $report = array();
    $failures = array();

    foreach ($messages as $key => $message) {
        if (empty($message['to'])) {
            $report[$key] = array('ok' => false, 'transport' => '', 'error' => 'Aucun destinataire.');
            $failures[] = $key;
            continue;
        }

        $sent = hl_lf_send($message, $config);
        $report[$key] = $sent;

        if (!$sent['ok']) {
            $failures[] = $key;
            if (function_exists('error_log')) {
                @error_log('[HelloLead] Envoi "' . $key . '" en échec : ' . $sent['error']);
            }
        }
    }

    $internalOk = isset($report['internal']['ok']) && $report['internal']['ok'];

    hl_lf_log($config, array(
        'event'    => $internalOk ? 'lead' : 'échec',
        'form'     => $formKey,
        'lead_id'  => $data['lead_id'],
        'nom'      => $data['nom'],
        'email'    => $data['email'],
        'societe'  => $data['societe'],
        'volume'   => $data['volume'],
        'besoins'  => $data['besoins'],
        'page'     => $meta['page'],
        'ip'       => hl_lf_ip_hash($ip),
        'envois'   => $report,
        'echecs'   => $failures,
    ));

    if (!$internalOk) {
        return array(
            'status'  => 500,
            'body'    => array(
                'ok'      => false,
                'code'    => 'delivery_failed',
                'message' => 'L’envoi a échoué. Merci de réessayer dans quelques instants.',
            ),
            'headers' => array(),
        );
    }

    return array(
        'status'  => 200,
        'body'    => array(
            'ok'      => true,
            'lead_id' => $data['lead_id'],
            'message' => 'Demande envoyée.',
            'warnings' => $failures,
        ),
        'headers' => array(),
    );
}

/**
 * Exécute l'endpoint sur la requête courante.
 *
 * @return void
 */
function hl_lf_run()
{
    $config = hl_lf_config();

    if (!empty($config['timezone'])) {
        @date_default_timezone_set($config['timezone']);
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo json_encode(array('ok' => false, 'code' => 'method_not_allowed', 'message' => 'Méthode non autorisée.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $raw = is_string($raw) ? $raw : '';

    if (strlen($raw) > 32768) {
        http_response_code(413);
        echo json_encode(array('ok' => false, 'code' => 'too_large', 'message' => 'Requête trop volumineuse.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $input = array();
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';

    if (strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : array();
    } else {
        $input = is_array($_POST) ? $_POST : array();
        if ($input === array() && $raw !== '') {
            parse_str($raw, $parsed);
            $input = is_array($parsed) ? $parsed : array();
        }
    }

    $result = hl_lf_handle($_SERVER, $input, $config);

    http_response_code((int) $result['status']);

    foreach ((array) $result['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


/* =====================================================================
 * Exploitation : purge RGPD et diagnostic (ligne de commande)
 * ================================================================== */

/**
 * Supprime les journaux et compteurs plus anciens que $days jours.
 *
 * Appelée par cron (`php lead-form-mail.php --purge=180`), jamais par HTTP :
 * la fonction n'est atteignable que depuis la ligne de commande.
 *
 * @param array $config
 * @param int   $days
 * @return array{removed: int, kept: int, dirs: array}
 */
function hl_lf_purge(array $config, $days = 180)
{
    $days = max(1, (int) $days);
    $limit = time() - ($days * 86400);
    $dir = rtrim((string) $config['log_dir'], '/');
    $report = array('removed' => 0, 'kept' => 0, 'dirs' => array());

    if ($dir === '' || !is_dir($dir)) {
        return $report;
    }

    $files = array();
    foreach (array('leads-*.jsonl', 'mail/*.eml', 'rate/*.json', 'seen/*.json') as $pattern) {
        foreach ((array) glob($dir . '/' . $pattern) as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }
    }

    foreach ($files as $file) {
        $expired = false;
        $name = basename($file);

        // Journaux mensuels : on se fie au mois du nom (YYYY-MM), plus fiable
        // que la date de dernière écriture.
        if (preg_match('/^leads-(\d{4})-(\d{2})\.jsonl$/', $name, $m)) {
            $endOfMonth = gmmktime(0, 0, 0, (int) $m[2] + 1, 1, (int) $m[1]);
            $expired = $endOfMonth < $limit;
        } else {
            $mtime = @filemtime($file);
            $expired = $mtime !== false && $mtime < $limit;
        }

        if ($expired) {
            if (@unlink($file)) {
                $report['removed']++;
            }
            continue;
        }

        $report['kept']++;
    }

    $report['dirs'][] = $dir;

    return $report;
}

/**
 * Diagnostic de configuration, à lancer sur le serveur avant mise en ligne.
 *
 * @param array $config
 * @return array<int, array{level: string, message: string}>
 */
function hl_lf_doctor(array $config)
{
    $lines = array();
    $add = function ($level, $message) use (&$lines) {
        $lines[] = array('level' => $level, 'message' => $message);
    };

    $add(empty($config['enabled']) ? 'ERREUR' : 'OK', empty($config['enabled'])
        ? 'Formulaire désactivé : l’endpoint répond 503 et aucun e-mail ne part.'
        : 'Formulaire activé.');

    $transport = isset($config['transport']) ? (string) $config['transport'] : 'mail';
    $add(in_array($transport, array('mail', 'smtp', 'wp_mail', 'log'), true) ? 'OK' : 'ERREUR',
        'Transport : ' . $transport);

    if ($transport === 'mail' && !function_exists('mail')) {
        $add('ERREUR', 'La fonction mail() est désactivée sur ce serveur : utilisez smtp ou wp_mail.');
    }
    if ($transport === 'smtp' && empty($config['smtp']['host'])) {
        $add('ERREUR', 'Transport smtp sans hôte configuré.');
    }

    $from = isset($config['from']['email']) ? (string) $config['from']['email'] : '';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $add('ERREUR', 'Adresse d’expédition invalide : ' . $from);
    } else {
        $add('OK', 'Expéditeur : ' . $from);
    }

    $fromDomain = strpos($from, '@') !== false ? substr(strrchr($from, '@'), 1) : '';
    $siteHost = parse_url(isset($config['site_url']) ? $config['site_url'] : '', PHP_URL_HOST);
    if ($fromDomain !== '' && $siteHost) {
        $siteHost = preg_replace('/^www\./', '', (string) $siteHost);
        if ($fromDomain !== $siteHost) {
            $add('ATTENTION', 'L’expéditeur (' . $fromDomain . ') diffère du domaine du site (' . $siteHost
                . ') : vérifiez SPF/DKIM et l’alignement DMARC.');
        }
    }

    $forms = isset($config['forms']) && is_array($config['forms']) ? $config['forms'] : array();
    foreach ($forms as $key => $form) {
        if ($key === 'default') {
            continue;
        }
        // Même normalisation que l'envoi réel : une chaîne « a@x, b@y » est valide.
        $responsable = hl_lf_email_list(isset($form['responsable']) ? $form['responsable'] : array());
        $chef = hl_lf_email_list(isset($form['chef']) ? $form['chef'] : array());

        $invalid = array();
        foreach (array_merge($responsable, $chef) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $address;
            }
        }

        if (empty($responsable)) {
            $add('ERREUR', $key . ' : aucun responsable (destinataire principal).');
        } elseif ($invalid !== array()) {
            $add('ERREUR', $key . ' : adresse(s) invalide(s) ' . implode(', ', $invalid));
        } elseif (empty($chef)) {
            $add('ATTENTION', $key . ' : pas de chef de département en copie.');
        } else {
            $add('OK', $key . ' → ' . implode(', ', $responsable) . ' · copie ' . implode(', ', $chef));
        }

        foreach (array_merge($responsable, $chef) as $address) {
            if (strpos($address, '@hellolead.fr') !== false) {
                $add('ATTENTION', $key . ' : adresse d’exemple encore présente (' . $address . ').');
            }
        }
    }

    $dir = rtrim((string) $config['log_dir'], '/');
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        $add('ERREUR', 'Dossier de journaux impossible à créer : ' . $dir);
    } elseif (!is_writable($dir)) {
        $add('ERREUR', 'Dossier de journaux non inscriptible : ' . $dir);
    } else {
        $add('OK', 'Journaux : ' . $dir);
    }

    return $lines;
}

/**
 * Point d'entrée ligne de commande (cron de purge, diagnostic).
 *
 * @param array $argv
 * @return void
 */
function hl_lf_cli($argv = array())
{
    $config = hl_lf_config();
    $args = array_slice((array) $argv, 1);

    if ($args === array() || in_array('--help', $args, true) || in_array('-h', $args, true)) {
        echo "HelloLead — formulaire de lead\n\n";
        echo "  php lead-form-mail.php --doctor          Diagnostic de configuration\n";
        echo "  php lead-form-mail.php --purge[=JOURS]   Purge RGPD (180 jours par défaut)\n";
        return;
    }

    foreach ($args as $arg) {
        if ($arg === '--doctor') {
            foreach (hl_lf_doctor($config) as $line) {
                printf("[%-9s] %s\n", $line['level'], $line['message']);
            }
            continue;
        }

        if (strpos($arg, '--purge') === 0) {
            $days = 180;
            if (strpos($arg, '=') !== false) {
                $days = (int) substr($arg, strpos($arg, '=') + 1);
            }
            $report = hl_lf_purge($config, $days);
            printf("Purge : %d fichier(s) supprimé(s), %d conservé(s) dans %s\n",
                $report['removed'], $report['kept'], $config['log_dir']);
            continue;
        }

        echo 'Option inconnue : ' . $arg . "\n";
    }
}

if (!defined('HL_LEAD_FORM_NO_AUTORUN')) {
    if (PHP_SAPI === 'cli') {
        hl_lf_cli(isset($argv) ? (array) $argv : array());
    } else {
        hl_lf_run();
    }
}
