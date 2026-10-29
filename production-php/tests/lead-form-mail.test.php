<?php
/**
 * Tests de l'endpoint d'envoi de leads.
 *
 * Lancement : php tests/lead-form-mail.test.php
 * (aucune dépendance, aucun accès réseau : le transport « log » écrit des .eml
 * dans un dossier temporaire, le transport SMTP est joué par un faux serveur)
 *
 * @package HelloLead
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('HL_LEAD_FORM_NO_AUTORUN', true);

$tmp = rtrim(sys_get_temp_dir(), '/') . '/hl-lf-tests-' . substr(sha1((string) microtime(true)), 0, 8);
mkdir($tmp, 0777, true);
mkdir($tmp . '/logs', 0777, true);

$endpointSource = dirname(__DIR__) . '/lead-form-mail.php';
if (!is_file($endpointSource)) {
    echo "Endpoint introuvable : $endpointSource\n";
    exit(1);
}

copy($endpointSource, $tmp . '/lead-form-mail.php');

/**
 * Écrit une configuration de test puis (re)charge l'endpoint.
 *
 * @param array $overrides
 * @return void
 */
function hl_test_boot(array $overrides = array())
{
    global $tmp;

    $config = array(
        'enabled'   => true,
        'transport' => 'log',
        'timezone'  => 'Europe/Paris',
        'from'      => array('email' => 'no-reply@hellolead.fr', 'name' => 'HelloLead'),
        'reply_to'  => array('email' => 'contact@hellolead.fr', 'name' => 'HelloLead'),
        'site_url'  => 'https://www.hellodevis.fr',
        'log_dir'   => $tmp . '/logs',
        'forms'     => array(
            'lead-assurances' => array(
                'label'       => 'Assurances pro',
                'responsable' => array('responsable.assurances@hellolead.fr'),
                'chef'        => array('chef.departement.assurances@hellolead.fr'),
                'besoins'     => array(
                    'Assurances professionnelles I.A.R.D',
                    'Gestion de Patrimoine',
                ),
            ),
            'lead-energies' => array(
                'label'       => 'Énergies pro',
                'responsable' => 'responsable.energies@hellolead.fr, second@hellolead.fr',
                'chef'        => array('chef.departement.energies@hellolead.fr'),
                'besoins'     => array("Fourniture d'électricité", 'Fourniture de gaz'),
            ),
            'default' => array(
                'label'       => 'Contact',
                'responsable' => array('contact@hellolead.fr'),
            ),
        ),
        'anti_spam' => array(
            'min_fill_seconds' => 1.5,
            'rate_window'      => 900,
            'rate_max'         => 5,
            'rate_day_max'     => 20,
            'dedupe_seconds'   => 600,
        ),
    );

    $config = hl_test_merge($config, $overrides);
    file_put_contents($tmp . '/lead-form-config.php', '<?php return ' . var_export($config, true) . ';');

    return $config;
}

/**
 * Fusion récursive de tableaux pour les besoins des tests.
 *
 * @param array $base
 * @param array $overrides
 * @return array
 */
function hl_test_merge(array $base, array $overrides)
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = hl_test_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

hl_test_boot();
require $tmp . '/lead-form-mail.php';

/* =====================================================================
 * Micro framework d'assertions
 * ================================================================== */

$GLOBALS['hl_tests'] = array('pass' => 0, 'fail' => 0, 'failures' => array());

/**
 * Vérifie une condition.
 *
 * @param bool $condition
 * @param string $label
 * @return void
 */
function ok($condition, $label, $detail = '')
{
    if ($condition) {
        $GLOBALS['hl_tests']['pass']++;
        echo "  ✓ $label\n";
        return;
    }

    $GLOBALS['hl_tests']['fail']++;
    $GLOBALS['hl_tests']['failures'][] = $label . ($detail !== '' ? ' » ' . $detail : '');
    echo "  ✗ $label" . ($detail !== '' ? '  » ' . $detail : '') . "\n";
}

/**
 * Compare deux valeurs.
 *
 * @param mixed $expected
 * @param mixed $actual
 * @param string $label
 * @return void
 */
function same($expected, $actual, $label)
{
    $ok = $expected === $actual;
    if (!$ok) {
        $label .= ' (attendu : ' . var_export($expected, true) . ', obtenu : ' . var_export($actual, true) . ')';
    }
    ok($ok, $label);
}

/**
 * Titre de section.
 *
 * @param string $title
 * @return void
 */
function section($title)
{
    echo "\n$title\n";
}

/* =====================================================================
 * Jeu de données valide
 * ================================================================== */

/**
 * Construit une soumission valide.
 *
 * @param array $overrides
 * @return array
 */
function payload(array $overrides = array())
{
    $base = array(
        'formulaire' => 'lead-assurances',
        'nom'        => 'Marie Dupont',
        'email'      => 'marie.dupont@acme.fr',
        'telephone'  => '0612345678',
        'code_postal' => '75011',
        'societe'    => 'ACME Assurances',
        'volume'     => '100 à 500',
        'volume_souhaite' => '100 à 500',
        'besoins'    => array('Assurances professionnelles I.A.R.D'),
        'page'       => 'https://www.hellodevis.fr/lead-assurances/',
        'lead_id'    => 'hl-' . substr(sha1((string) mt_rand()), 0, 12),
        'ts'         => sprintf('%.0f', (microtime(true) - 10) * 1000),
        'consent'    => true,
        'utm'        => array('utm_source' => 'google', 'utm_campaign' => 'assurances-pro'),
    );

    return hl_test_merge($base, $overrides);
}

// Nouvelle IP à chaque test pour éviter la limitation de débit.
$GLOBALS['hl_ip_counter'] = 0;

/**
 * Retourne un $_SERVER simulé avec une IP unique.
 *
 * @param array $extra
 * @return array
 */
function server(array $extra = array())
{
    $GLOBALS['hl_ip_counter']++;

    return array_merge(array(
        'REQUEST_METHOD' => 'POST',
        'REMOTE_ADDR'    => '203.0.113.' . $GLOBALS['hl_ip_counter'],
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (test)',
    ), $extra);
}

/**
 * Compte les .eml générés par le transport « log ».
 *
 * @return array
 */
function eml_files()
{
    global $tmp;
    $files = glob($tmp . '/logs/mail/*.eml');

    return is_array($files) ? $files : array();
}

/**
 * Décode le corps base64 d'une partie MIME.
 *
 * @param string $raw
 * @param string $contentType
 * @return string
 */
function mime_part($raw, $contentType)
{
    $parts = preg_split('/--hl-[a-f0-9]+/', $raw);
    foreach ((array) $parts as $part) {
        if (stripos($part, 'Content-Type: ' . $contentType) !== false) {
            $chunks = explode("\r\n\r\n", $part, 2);
            if (count($chunks) === 2) {
                return (string) base64_decode(preg_replace('/\s+/', '', $chunks[1]), false);
            }
        }
    }

    return '';
}

/* =====================================================================
 * 1. Configuration
 * ================================================================== */

section('1. Configuration');
$config = hl_lf_config(true);
ok(is_array($config), 'La configuration se charge');
same('log', $config['transport'], 'Le transport de test est utilisé');
$form = hl_lf_resolve_form($config, 'lead-energies');
same(array('responsable.energies@hellolead.fr', 'second@hellolead.fr'), $form['responsable'], 'Liste « responsable » normalisée depuis une chaîne');
same(array('chef.departement.energies@hellolead.fr'), $form['chef'], 'Le chef de département est résolu');
same('Contact', hl_lf_resolve_form($config, 'inconnu')['label'], 'Formulaire inconnu : repli sur « default »');

/* =====================================================================
 * 2. Utilitaires texte
 * ================================================================== */

section('2. Utilitaires texte (sans mbstring)');
same(7, hl_lf_strlen('Énergie'), 'Longueur UTF-8 correcte');
same('Éner', hl_lf_substr('Énergie', 4), 'Troncature UTF-8 sans casser un caractère');
same('Hello', hl_lf_clean_text("  <b>Hello</b>\n\r  ", 40), 'Nettoyage : balises et espaces');
$encoded = hl_lf_mime_header('Nouvelle demande — Énergies');
ok(strpos($encoded, '=?UTF-8?B?') === 0, 'En-tête non ASCII encodé en RFC 2047');
ok(strpos(hl_lf_mime_header('HelloLead'), '=?') === false, 'En-tête ASCII laissé tel quel');
$safe = hl_lf_header_safe("Simple\r\nBcc: pirate@example.com");
ok(strpos($safe, "\r") === false && strpos($safe, "\n") === false, 'Injection d’en-tête neutralisée (aucun saut de ligne)');
ok(strpos($safe, 'Simple') === 0, 'Valeur d’en-tête conservée');

/* =====================================================================
 * 3. Téléphone
 * ================================================================== */

section('3. Téléphone');
ok(hl_lf_is_valid_phone('06 12 34 56 78'), 'Numéro français avec espaces accepté');
ok(hl_lf_is_valid_phone('+33 6 12 34 56 78'), 'Numéro +33 accepté');
ok(hl_lf_is_valid_phone('0033612345678'), 'Préfixe 0033 accepté');
ok(!hl_lf_is_valid_phone('06 12 34'), 'Numéro trop court refusé');
ok(!hl_lf_is_valid_phone('abcdefghij'), 'Numéro non numérique refusé');
same('06 12 34 56 78', hl_lf_format_phone('+33612345678'), 'Formatage FR depuis +33');

/* =====================================================================
 * 4. Validation des données
 * ================================================================== */

section('4. Validation');
$valid = hl_lf_validate(payload(), $form);
same(array(), $valid['errors'], 'Soumission valide : aucune erreur');
same('100 à 500', $valid['data']['volume'], 'Volume conservé');

$invalid = hl_lf_validate(payload(array(
    'nom'        => '',
    'email'      => 'pas-un-email',
    'telephone'  => '123',
    'code_postal' => '75',
    'societe'    => '',
    'volume_souhaite' => 'Beaucoup',
    'besoins'    => array('Besoin inconnu'),
)), $form);
ok(isset($invalid['errors']['nom']), 'Nom manquant détecté');
ok(isset($invalid['errors']['email']), 'E-mail invalide détecté');
ok(isset($invalid['errors']['telephone']), 'Téléphone invalide détecté');
ok(isset($invalid['errors']['code_postal']), 'Code postal invalide détecté');
ok(isset($invalid['errors']['societe']), 'Société manquante détectée');
ok(isset($invalid['errors']['volume_souhaite']), 'Volume hors liste détecté');
same(array(), $invalid['data']['besoins'], 'Besoins hors liste blanche écartés');

/* =====================================================================
 * 5. Fabrication des e-mails
 * ================================================================== */

section('5. Fabrication des e-mails');
$data = hl_lf_validate(payload(), $form)['data'];
$meta = array(
    'form_key'   => 'lead-assurances',
    'form_label' => 'Assurances pro',
    'page'       => 'https://www.hellodevis.fr/lead-assurances/',
    'lead_id'    => $data['lead_id'],
    'date_local' => '29/10/2026 10:00',
    'ip'         => '203.0.113.1',
    'user_agent' => 'Mozilla/5.0',
);
$formAssurances = hl_lf_resolve_form($config, 'lead-assurances');
$messages = hl_lf_build_messages($data, $meta, $formAssurances, $config);

same(array('internal', 'client'), array_keys($messages), 'Deux messages : interne + confirmation');
same(array('responsable.assurances@hellolead.fr'), $messages['internal']['to'], 'Notification : destinataire = responsable');
same(array('chef.departement.assurances@hellolead.fr'), $messages['internal']['cc'], 'Notification : chef de département en copie');
same('marie.dupont@acme.fr', $messages['internal']['reply_to']['email'], 'Notification : Reply-To = prospect');
same(array('marie.dupont@acme.fr'), $messages['client']['to'], 'Confirmation : destinataire = prospect');
same('contact@hellolead.fr', $messages['client']['reply_to']['email'], 'Confirmation : Reply-To = contact');
ok(strpos($messages['internal']['subject'], 'ACME Assurances') !== false, 'Objet interne mentionne la société');
ok(strpos($messages['client']['text'], 'a bien été enregistrée') !== false, 'Confirmation : texte d’accusé de réception');
ok(strpos($messages['client']['text'], 's\'en charge') !== false, 'Confirmation : mention « une équipe s’en charge »');
ok(strpos($messages['client']['html'], 'Bonjour Marie') !== false, 'Confirmation : personnalisée avec le prénom');
ok(strpos($messages['client']['text'], '24 h') === false, 'Confirmation : aucun délai promis');

/* =====================================================================
 * 6. Structure MIME
 * ================================================================== */

section('6. Structure MIME');
$mime = hl_lf_build_mime($messages['internal']);
$headerBlock = implode("\r\n", $mime['headers']);
ok(strpos($headerBlock, 'From: HelloLead <no-reply@hellolead.fr>') !== false, 'En-tête From présent');
ok(strpos($headerBlock, 'To: responsable.assurances@hellolead.fr') !== false, 'En-tête To présent');
ok(strpos($headerBlock, 'Cc: chef.departement.assurances@hellolead.fr') !== false, 'En-tête Cc présent');
ok(strpos($headerBlock, 'Content-Type: multipart/alternative') !== false, 'Multipart alternative');
ok(strpos($headerBlock, "MIME-Version: 1.0") !== false, 'MIME-Version présent');
ok(strpos($mime['body'], 'Content-Transfer-Encoding: base64') !== false, 'Corps encodé en base64');
$plain = mime_part($mime['body'], 'text/plain');
$html = mime_part($mime['body'], 'text/html');
ok(strpos($plain, 'ACME Assurances') !== false, 'Partie texte décodée contient la société');
ok(strpos($html, '<table') !== false, 'Partie HTML décodée contient le tableau récapitulatif');
ok(strpos($html, 'https://www.hellodevis.fr/lead-assurances/') !== false, 'Partie HTML contient la page d’origine');

$accented = $messages['client'];
$accented['subject'] = 'Votre demande — énergies professionnelles';
$mimeAccent = hl_lf_build_mime($accented);
$subjectLine = '';
foreach ($mimeAccent['headers'] as $line) {
    if (strpos($line, 'Subject:') === 0) {
        $subjectLine = $line;
    }
}
ok(strpos($subjectLine, '=?UTF-8?B?') !== false, 'Objet accentué encodé en RFC 2047');

// Décodage RFC 2047 : les encoded-words adjacents se recollent et l'espace
// qui les sépare est ignoré — il doit donc être porté par les mots eux-mêmes.
$decodeHeader = function ($value) {
    $value = preg_replace("/\r\n[ \t]+/", '', $value);
    $value = preg_replace_callback('/=\?UTF-8\?B\?([^?]+)\?=/i', function ($m) {
        return base64_decode($m[1]);
    }, $value);
    return trim($value);
};
ok($decodeHeader(substr($subjectLine, strlen('Subject: '))) === 'Votre demande — énergies professionnelles', 'Objet décodé à l’identique', $decodeHeader(substr($subjectLine, strlen('Subject: '))));

$long = 'Nouvelle demande de lead pour la société Boulangerie Lefèvre & Fils — énergies';
$encodedLong = hl_lf_mime_header($long);
ok($decodeHeader($encodedLong) === $long, 'En-tête long (>40 octets) décodé à l’identique');
$maxLine = 0;
foreach (explode("\r\n", 'Subject: ' . $encodedLong) as $line) {
    $maxLine = max($maxLine, strlen($line));
}
ok($maxLine <= 78, 'En-tête replié sous 78 colonnes', $maxLine . ' colonnes');
ok(substr_count($encodedLong, '=?UTF-8?B?') <= 3, 'Encoded-words regroupés (pas un par mot)', substr_count($encodedLong, '=?UTF-8?B?') . ' mots');

// La confirmation est un message automatique : RFC 3834.
$clientHeaderBlock = implode("\r\n", hl_lf_build_mime($messages['client'])['headers']);
ok(strpos($clientHeaderBlock, 'Auto-Submitted: auto-generated') !== false, 'Confirmation marquée Auto-Submitted (RFC 3834)');
ok(strpos($clientHeaderBlock, 'X-Auto-Response-Suppress: All') !== false, 'Confirmation : suppression des réponses auto Outlook');
ok(strpos($headerBlock, 'Auto-Submitted') === false, 'Notification interne : pas de marquage automatique (réponse humaine)');

/* =====================================================================
 * 7. SMTP (faux serveur scripté)
 * ================================================================== */

section('7. SMTP (conversation scriptée)');
$transcript = array();
$responses = array(
    "220 smtp.test ESMTP\r\n",
    "250-smtp.test\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n",
    "250-smtp.test\r\n250 AUTH LOGIN\r\n",
    "334 VXNlcm5hbWU6\r\n",
    "334 UGFzc3dvcmQ6\r\n",
    "235 2.7.0 Authentication successful\r\n",
    "250 2.1.0 Ok\r\n",
    "250 2.1.5 Ok\r\n",
    "250 2.1.5 Ok\r\n",
    "354 End data with <CR><LF>.<CR><LF>\r\n",
    "250 2.0.0 Ok: queued\r\n",
    "221 bye\r\n",
);
$fakeConn = array(
    'host'       => 'smtp.test',
    'encryption' => 'tls',
    'write'      => function ($data) use (&$transcript) {
        $transcript[] = $data;
        return true;
    },
    'read'       => function () use (&$responses) {
        $line = array_shift($responses);
        $code = $line === null ? 0 : (int) substr($line, 0, 3);
        return array('code' => $code, 'text' => trim((string) $line));
    },
    'close'      => function () {
        return true;
    },
);
$smtpResult = hl_lf_smtp_conversation($fakeConn, array(
    'from'       => 'no-reply@hellolead.fr',
    'recipients' => array('responsable@hellolead.fr', 'chef@hellolead.fr'),
    'data'       => "Subject: test\r\n\r\n.<ligne commençant par un point>",
    'username'   => 'smtp-user',
    'password'   => 'secret',
));
ok($smtpResult['ok'], 'Conversation SMTP complète réussie (' . $smtpResult['error'] . ')');
$sent = implode('', $transcript);
ok(strpos($sent, 'MAIL FROM:<no-reply@hellolead.fr>') !== false, 'MAIL FROM envoyé');
ok(strpos($sent, 'RCPT TO:<responsable@hellolead.fr>') !== false, 'RCPT TO responsable envoyé');
ok(strpos($sent, 'RCPT TO:<chef@hellolead.fr>') !== false, 'RCPT TO chef envoyé (copie)');
ok(strpos($sent, 'AUTH LOGIN') !== false, 'Authentification demandée');
ok(strpos($sent, "\r\n..<ligne commençant par un point>") !== false, 'Point en début de ligne échappé');

$failingConn = array(
    'host'       => 'smtp.test',
    'encryption' => 'none',
    'write'      => function () {
        return true;
    },
    'read'       => (function () {
        $responses = array("220 ok\r\n", "250 ok\r\n", "535 5.7.8 Authentication failed\r\n");
        return function () use (&$responses) {
            $line = array_shift($responses);
            return array('code' => $line === null ? 0 : (int) substr($line, 0, 3), 'text' => trim((string) $line));
        };
    })(),
);
$authFail = hl_lf_smtp_conversation($failingConn, array(
    'from'       => 'no-reply@hellolead.fr',
    'recipients' => array('a@hellolead.fr'),
    'data'       => 'x',
    'username'   => 'user',
    'password'   => 'bad',
));
ok(!$authFail['ok'], 'Échec d’authentification SMTP remonté');
ok(strpos($authFail['error'], '535') !== false, 'Message d’erreur SMTP explicite');

/* =====================================================================
 * 8. Endpoint — refus et cas limites
 * ================================================================== */

section('8. Endpoint : refus et cas limites');

hl_test_boot(array('enabled' => false));
$config = hl_lf_config(true);
$res = hl_lf_handle(server(), payload(), $config);
same(503, $res['status'], 'Formulaire désactivé : 503');
same('not_configured', $res['body']['code'], 'Code « not_configured » pour le front');

hl_test_boot();
$config = hl_lf_config(true);

$res = hl_lf_handle(server(), payload(array('website' => 'http://spam.example')), $config);
same(200, $res['status'], 'Honeypot : réponse neutre 200');
ok(!empty($res['body']['spam']), 'Honeypot identifié comme spam');

$res = hl_lf_handle(server(), payload(array('ts' => sprintf('%.0f', microtime(true) * 1000))), $config);
same(422, $res['status'], 'Formulaire rempli trop vite : 422');

$res = hl_lf_handle(server(), payload(array('email' => 'invalide', 'nom' => '')), $config);
same(400, $res['status'], 'Données invalides : 400');
ok(isset($res['body']['errors']['email']), 'Erreurs renvoyées champ par champ');

$before = count(eml_files());
$res = hl_lf_handle(server(), payload(), $config);
same(200, $res['status'], 'Soumission valide : 200');
after_sleep();
$files = eml_files();
same($before + 2, count($files), 'Deux e-mails générés (responsable + prospect)');

/* =====================================================================
 * 9. Contenu des e-mails réellement produits
 * ================================================================== */

section('9. Contenu des e-mails produits');

$internalFile = null;
$clientFile = null;
foreach ($files as $file) {
    if (strpos($file, '-internal-') !== false) {
        $internalFile = $file;
    }
    if (strpos($file, '-client-') !== false) {
        $clientFile = $file;
    }
}

ok($internalFile !== null, 'E-mail interne écrit');
ok($clientFile !== null, 'E-mail de confirmation écrit');

$internalRaw = (string) file_get_contents((string) $internalFile);
$clientRaw = (string) file_get_contents((string) $clientFile);

ok(strpos($internalRaw, 'To: responsable.assurances@hellolead.fr') !== false, 'Interne : To = responsable');
ok(strpos($internalRaw, 'Cc: chef.departement.assurances@hellolead.fr') !== false, 'Interne : Cc = chef de département');
ok(strpos($internalRaw, 'Reply-To: Marie Dupont <marie.dupont@acme.fr>') !== false, 'Interne : Reply-To = prospect');
ok(strpos(mime_part($internalRaw, 'text/html'), '<table') !== false, 'Interne : version HTML présente');

ok(strpos($clientRaw, 'To: marie.dupont@acme.fr') !== false, 'Confirmation : To = prospect');
$clientHtml = mime_part($clientRaw, 'text/html');
$clientText = mime_part($clientRaw, 'text/plain');
ok(strpos($clientHtml, 'bien reçu votre demande') !== false, 'Confirmation : phrase d’accusé de réception');
ok(strpos($clientText, 'Récapitulatif de votre demande') !== false, 'Confirmation : récapitulatif en texte');
ok(strpos($clientHtml, '100 à 500 leads / mois') !== false, 'Confirmation : volume repris');
ok(strpos($clientHtml, 'Assurances professionnelles I.A.R.D') !== false, 'Confirmation : besoins repris');
ok(strpos($clientHtml, 'Référence') === false, 'Confirmation : pas de métadonnées internes (référence)');
ok(strpos(mime_part($internalRaw, 'text/html'), 'Référence') !== false, 'Notification interne : référence présente');

/* =====================================================================
 * 10. Anti-doublon & limitation de débit
 * ================================================================== */

section('10. Anti-doublon & limitation de débit');

$duplicate = payload(array('lead_id' => 'hl-duplicate-0001'));
$first = hl_lf_handle(server(), $duplicate, $config);
$second = hl_lf_handle(server(), $duplicate, $config);
same(200, $first['status'], 'Premier envoi accepté');
ok(!empty($second['body']['duplicate']), 'Deuxième envoi du même lead_id ignoré');

$limited = 0;
$sameIp = array('REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '198.51.100.77');
for ($i = 0; $i < 8; $i++) {
    $res = hl_lf_handle($sameIp, payload(), $config);
    if ($res['status'] === 429) {
        $limited++;
    }
}
ok($limited > 0, 'Limitation de débit déclenchée après plusieurs envois');
$res = hl_lf_handle($sameIp, payload(), $config);
same(429, $res['status'], 'IP bloquée : 429');
ok(isset($res['headers']['Retry-After']), 'En-tête Retry-After fourni');

/* =====================================================================
 * 11. Journalisation
 * ================================================================== */

section('11. Journalisation');
$logFile = $tmp . '/logs/leads-' . gmdate('Y-m') . '.jsonl';
ok(is_file($logFile), 'Fichier de log créé');
$logLines = array_values(array_filter(explode("\n", (string) file_get_contents($logFile))));
$last = json_decode((string) end($logLines), true);
ok(is_array($last), 'Ligne de log en JSON valide');
ok(is_file($tmp . '/logs/.htaccess'), 'Dossier de logs protégé (.htaccess)');

/* =====================================================================
 * 12. En-têtes HTTP de l'endpoint (mode requête)
 * ================================================================== */

section('12. Robustesse de l’endpoint');
$res = hl_lf_handle(server(), array(), $config);
same(400, $res['status'], 'Requête vide : 400 (aucun crash)');
$res = hl_lf_handle(server(), payload(array('besoins' => 'Gestion de Patrimoine|Assurances professionnelles I.A.R.D')), $config);
same(200, $res['status'], 'Besoins sous forme de chaîne acceptés');
$res = hl_lf_handle(server(), payload(array('formulaire' => 'lead-energies')), $config);
ok(in_array($res['status'], array(200, 400), true), 'Autre formulaire traité sans erreur fatale');

/* =====================================================================
 * 13. Nettoyage
 * ================================================================== */

section('13. Nettoyage des données sensibles');
$cleaned = hl_lf_clean_text("Marie <script>alert(1)</script>Dupont", 80);
ok(strpos($cleaned, '<script') === false, 'Balises retirées des champs');
$headerInjection = hl_lf_mime_header("Test\r\nBcc: pirate@example.com");
ok(strpos($headerInjection, "\r\nBcc:") === false, 'Pas d’injection via l’objet');


section('14. Purge RGPD et diagnostic');

$purgeDir = $tmp . '/purge';
@mkdir($purgeDir . '/rate', 0777, true);
@mkdir($purgeDir . '/mail', 0777, true);

$oldMonth = gmdate('Y-m', strtotime('-8 months'));
$recentMonth = gmdate('Y-m');
file_put_contents($purgeDir . '/leads-' . $oldMonth . '.jsonl', "{\"event\":\"ancien\"}\n");
file_put_contents($purgeDir . '/leads-' . $recentMonth . '.jsonl', "{\"event\":\"récent\"}\n");
file_put_contents($purgeDir . '/rate/' . sha1('1.2.3.4') . '.json', '[1]');
touch($purgeDir . '/rate/' . sha1('1.2.3.4') . '.json', time() - (400 * 86400));
file_put_contents($purgeDir . '/mail/vieux.eml', 'old');
touch($purgeDir . '/mail/vieux.eml', time() - (400 * 86400));

$purgeConfig = array('log_dir' => $purgeDir);
$purgeReport = hl_lf_purge($purgeConfig, 180);
ok($purgeReport['removed'] >= 3, 'Purge : journaux et compteurs expirés supprimés', $purgeReport['removed']);
ok(!is_file($purgeDir . '/leads-' . $oldMonth . '.jsonl'), 'Purge : mois de plus de 6 mois supprimé');
ok(is_file($purgeDir . '/leads-' . $recentMonth . '.jsonl'), 'Purge : mois courant conservé');
ok(!is_file($purgeDir . '/rate/' . sha1('1.2.3.4') . '.json'), 'Purge : compteur de débit périmé supprimé');
ok(!is_file($purgeDir . '/mail/vieux.eml'), 'Purge : copie .eml périmée supprimée');

$purgeEmpty = hl_lf_purge(array('log_dir' => $tmp . '/inconnu'), 30);
ok($purgeEmpty['removed'] === 0 && $purgeEmpty['kept'] === 0, 'Purge : dossier absent traité sans erreur');

$doctor = hl_lf_doctor(hl_test_boot());
$levels = array();
foreach ($doctor as $line) {
    $levels[] = $line['level'];
}
ok(in_array('ERREUR', $levels, true) === false, 'Diagnostic : configuration de test sans erreur bloquante', implode(' / ', $levels));
ok(in_array('ATTENTION', $levels, true) === true, 'Diagnostic : adresses d’exemple signalées');

$doctorOff = hl_lf_doctor(hl_test_boot(array('enabled' => false)));
$flat = '';
foreach ($doctorOff as $line) {
    $flat .= $line['message'] . "\n";
}
ok(strpos($flat, 'désactivé') !== false, 'Diagnostic : formulaire désactivé signalé');

$doctorNoChef = hl_lf_doctor(hl_test_boot(array(
    'forms' => array('lead-nouveau' => array(
        'label'       => 'Nouveau formulaire',
        'responsable' => array('equipe@hellodevis.fr'),
    )),
)));
$flatNoChef = '';
foreach ($doctorNoChef as $line) {
    $flatNoChef .= $line['level'] . ' ' . $line['message'] . "\n";
}
ok(strpos($flatNoChef, 'lead-nouveau') !== false, 'Diagnostic : formulaire sans chef listé', str_replace("\n", ' | ', $flatNoChef));
ok(strpos($flatNoChef, "pas de chef de département") !== false, 'Diagnostic : absence de chef de département signalée', str_replace("\n", ' | ', $flatNoChef));

hl_test_boot();

/* =====================================================================
 * Résumé
 * ================================================================== */

/**
 * Petite pause pour laisser le temps aux écritures disque.
 *
 * @return void
 */
function after_sleep()
{
    usleep(50000);
}

$summary = $GLOBALS['hl_tests'];
echo "\n" . str_repeat('=', 52) . "\n";
echo sprintf("Résultat : %d réussite(s), %d échec(s)\n", $summary['pass'], $summary['fail']);
if ($summary['fail'] > 0) {
    echo "Échecs :\n - " . implode("\n - ", $summary['failures']) . "\n";
    echo str_repeat('=', 52) . "\n";
    exit(1);
}
echo str_repeat('=', 52) . "\n";
exit(0);
