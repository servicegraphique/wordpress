/**
 * HelloLead — relais e-mail Gmail (Google Apps Script)
 * ==========================================================================
 * Ce script s'installe DANS la boîte ifeminformatique@gmail.com : c'est elle
 * qui expédie les trois messages du formulaire (responsable, chef de
 * département, client). Aucun service tiers, aucune clé SMTP, aucun serveur.
 *
 * Installation : voir production-gas/README.md (5 minutes, une seule fois).
 *
 * Contrat d'appel (POST depuis la page, corps en text/plain pour éviter la
 * pré-vérification CORS qu'Apps Script ne sait pas honorer) :
 *
 *   {
 *     "token": "…",                       // doit valoir TOKEN ci-dessous
 *     "from_name": "HelloLead",
 *     "form": "Assurances pro",
 *     "lead_id": "hl-…",
 *     "messages": [
 *       { "role": "responsable", "to": "…", "cc": "", "reply_to": "…",
 *         "subject": "…", "html": "…", "text": "…" },
 *       { "role": "chef",   … },
 *       { "role": "client", … }
 *     ],
 *     "lead": {                           // données complètes du prospect
 *       "formulaire": "Assurances pro", "nom": "…", "email": "…",
 *       "telephone": "…", "code_postal": "…", "societe": "…",
 *       "volume": "100 à 500 leads / mois", "besoins": ["…"],
 *       "page": "https://…", "utm": { … }, "lead_id": "hl-…"
 *     }
 *   }
 *
 * Chaque demande est aussi inscrite dans une **feuille de calcul Google**
 * (une ligne par prospect), partagée avec SHEET_EMAIL pour le suivi.
 *
 * Réponse (toujours HTTP 200, JSON) :
 *   { "ok": true,  "sent": { "responsable": true, "chef": true, "client": true },
 *     "sheet": { "ok": true, "url": "https://docs.google.com/…" } }
 *   { "ok": false, "error": "token" | "invalid" | "quota", "sent": { … } }
 *
 * Le formulaire interprète « token » et « quota » comme un refus sûr (aucun
 * message parti) et bascule alors sur son relais de secours ; une erreur
 * inconnue n'est jamais rejouée, pour éviter tout doublon.
 */

/* --------------------------------------------------------------------------
   1. Réglages
   -------------------------------------------------------------------------- */

// Jeton partagé avec la page (CONFIG.gas.token). Long, aléatoire, unique :
// il n'a pas à être mémorisable, seulement identique des deux côtés.
// Exemple pour en générer un : ouvrir une console JavaScript et coller
//   crypto.randomUUID().replace(/-/g, '') + crypto.randomUUID().replace(/-/g, '')
var TOKEN = 'REMPLACER-PAR-UN-JETON-LONG-ET-ALEATOIRE';

// Nom affiché comme expéditeur dans la messagerie du destinataire.
var SENDER_NAME = 'HelloLead';

// Adresse affichée comme expéditeur.
// - vide : les messages partent de la boîte qui héberge ce script
//   (ifeminformatique@gmail.com), ce qui garantit SPF/DKIM sans réglage ;
// - renseignée (ex. 'pbenateau@hellolead.pro') : l'envoi utilise cet alias,
//   à condition qu'il soit déclaré et vérifié dans Gmail → Paramètres →
//   Comptes → « Envoyer des e-mails en tant que ». En cas de refus, le script
//   se rabat silencieusement sur la boîte qui l'héberge : rien n'est perdu.
var FROM_ADDRESS = '';

// Garde-fou : 3 e-mails par demande, et Gmail limite les comptes personnels
// à 100 e-mails/jour via Apps Script. 30 demandes/jour laissent une marge.
var MAX_LEADS_PER_DAY = 30;

// Taille maximale d'un message (garde-fou anti-abus), en caractères.
var MAX_MESSAGE_CHARS = 120000;

/* --------------------------------------------------------------------------
   Feuille de suivi
   -------------------------------------------------------------------------- */

// Adresse qui reçoit l'accès à la feuille (suivi individuel des prospects).
// Elle est aussi inscrite dans la colonne « Suivi par ».
var SHEET_EMAIL = 'manajahelvin@gmail.com';

// Nom donné à la feuille si le script doit la créer.
var SHEET_TITLE = 'Leads HelloLead — suivi';

// Onglet utilisé (créé s'il n'existe pas).
var SHEET_TAB = 'Leads';

// Colonnes écrites, dans cet ordre. Toute modification ici doit rester
// alignée avec buildRow_() plus bas.
var SHEET_COLUMNS = [
  'Reçue le',
  'Référence',
  'Formulaire',
  'Nom et prénom',
  'Société',
  'E-mail',
  'Téléphone',
  'Code postal',
  'Volume souhaité',
  'Besoins',
  'Page d\'origine',
  'UTM source',
  'UTM support',
  'UTM campagne',
  'gclid',
  'fbclid',
  'Suivi par',
  'E-mails'
];

/* --------------------------------------------------------------------------
   2. Points d'entrée
   -------------------------------------------------------------------------- */

/**
 * Envoi des (trois) messages. Appelé par la page du formulaire.
 */
function doPost(e) {
  var body = parseBody_(e);

  if (!body) {
    return reply_({ ok: false, error: 'invalid' });
  }

  if (!isAuthorized_(body.token)) {
    console.warn('Jeton refusé');
    return reply_({ ok: false, error: 'token' });
  }

  if (remainingQuota_() < 1) {
    console.warn('Quota Gmail épuisé');
    return reply_({ ok: false, error: 'quota' });
  }

  if (!canAcceptToday_()) {
    console.warn('Limite quotidienne du relais atteinte');
    return reply_({ ok: false, error: 'quota' });
  }

  var messages = Array.isArray(body.messages) ? body.messages.slice(0, 5) : [];
  if (!messages.length) {
    return reply_({ ok: false, error: 'invalid' });
  }

  var fromName = clean_(body.from_name) || SENDER_NAME;
  var sent = {};

  messages.forEach(function (message) {
    var role = clean_(message && message.role).slice(0, 20) || 'message';

    try {
      var to = clean_(message.to);
      var subject = clean_(message.subject);
      var html = String(message.html || '');
      var text = String(message.text || '');

      if (!isEmailList_(to) || !subject || !html || html.length > MAX_MESSAGE_CHARS) {
        throw new Error('message incomplet');
      }

      sendMail_({
        to: to,
        cc: isEmailList_(clean_(message.cc)) ? clean_(message.cc) : '',
        subject: subject,
        body: text || stripTags_(html),
        htmlBody: html,
        replyTo: isEmail_(clean_(message.reply_to)) ? clean_(message.reply_to) : '',
        name: fromName
      });

      sent[role] = true;
    } catch (error) {
      console.error('Échec ' + role + ' : ' + (error && error.message));
      sent[role] = false;
    }
  });

  if (sent.responsable !== false) {
    countToday_();
  }

  // Inscription dans la feuille de suivi. Un échec ici ne doit jamais faire
  // échouer la demande : les e-mails sont partis, la ligne est un confort.
  var sheet = appendLead_(body, sent);

  // Sans le message au responsable, la demande serait perdue côté équipe :
  // la page doit le savoir pour proposer une solution de repli.
  return reply_({ ok: sent.responsable !== false, sent: sent, sheet: sheet });
}

/**
 * Vérification de santé : ouvrir l'URL /exec dans un navigateur.
 */
function doGet() {
  return reply_({
    ok: true,
    service: 'HelloLead',
    ready: TOKEN.indexOf('REMPLACER') !== 0 && TOKEN.length >= 20,
    from: FROM_ADDRESS || account_(),
    sheet: sheetUrl_(),
    remaining: remainingQuota_()
  });
}

/**
 * Envoi de test déclenché depuis l'éditeur Apps Script (Exécuter → test).
 * À supprimer après la mise en service si le script doit rester minimal.
 */
function testEnvoi() {
  var result = doPost({
    postData: {
      contents: JSON.stringify({
        token: TOKEN,
        from_name: SENDER_NAME,
        form: 'Test',
        lead_id: 'test-' + Date.now(),
        messages: [{
          role: 'responsable',
          to: Session.getActiveUser().getEmail() || Session.getEffectiveUser().getEmail(),
          subject: '[Test] Relais HelloLead opérationnel',
          html: '<p>Ce message confirme que le relais envoie bien depuis <strong>'
            + (Session.getEffectiveUser().getEmail() || 'ce compte') + '</strong>.</p>',
          text: 'Ce message confirme que le relais HelloLead fonctionne.'
        }]
      })
    }
  });

  console.log(result.getContent());
  return result;
}

/**
 * Test de la feuille seule, sans envoyer d'e-mail (Exécuter → testFeuille).
 * Crée la feuille si besoin, puis y ajoute une ligne de démonstration.
 */
function testFeuille() {
  var result = appendLead_({
    form: 'Assurances pro',
    lead_id: 'test-feuille-' + Date.now(),
    lead: {
      formulaire: 'Assurances pro',
      nom: 'Test Feuille',
      email: 'test@exemple.fr',
      telephone: '0600000000',
      code_postal: '75001',
      societe: 'Société de test',
      volume: '100 à 500 leads / mois',
      besoins: ['Assurance I.A.R.D'],
      page: 'https://www.hellodevis.fr/lead-assurances/',
      utm: { utm_source: 'test' }
    }
  }, { responsable: true, chef: true, client: true });

  console.log(JSON.stringify(result));
  return result;
}

/* --------------------------------------------------------------------------
   3. Feuille de suivi
   -------------------------------------------------------------------------- */

/**
 * Ajoute une ligne décrivant le prospect. Idempotent : une même référence
 * (lead_id) n'est jamais inscrite deux fois, même si la page renvoie sa
 * requête. Protégé par un verrou pour éviter deux écritures simultanées.
 */
function appendLead_(body, sent) {
  var lead = body && body.lead;

  if (!lead || typeof lead !== 'object') {
    return { ok: false, reason: 'missing' };
  }

  var lock = null;
  var locked = false;

  try {
    lock = LockService.getScriptLock();
    locked = lock.tryLock(15000);
  } catch (error) {
    locked = false;
  }

  try {
    var target = sheet_();
    var reference = clean_(body.lead_id || lead.lead_id);

    if (reference && rowExists_(target.sheet, reference)) {
      return { ok: true, duplicate: true, url: target.url };
    }

    target.sheet.appendRow(buildRow_(lead, body, sent));
    formatLastRow_(target.sheet, lead);

    return { ok: true, url: target.url };
  } catch (error) {
    console.error('Feuille indisponible : ' + (error && error.message));
    return { ok: false, reason: 'sheet', message: String((error && error.message) || '').slice(0, 200) };
  } finally {
    if (locked && lock) {
      try { lock.releaseLock(); } catch (error) { /* sans conséquence */ }
    }
  }
}

/**
 * Ouvre la feuille enregistrée, ou la crée (puis la partage) à la première
 * demande. L'identifiant est conservé dans les propriétés du script : une
 * seule feuille est utilisée, quel que soit le nombre de demandes.
 */
function sheet_() {
  var props = PropertiesService.getScriptProperties();
  var spreadsheet = storedSheet_();

  if (!spreadsheet) {
    spreadsheet = SpreadsheetApp.create(SHEET_TITLE);
    props.setProperty('SHEET_ID', spreadsheet.getId());
    console.log('Feuille créée : ' + spreadsheet.getUrl());
    shareSheet_(spreadsheet.getId());
  }

  var sheet = spreadsheet.getSheetByName(SHEET_TAB);
  if (!sheet) {
    sheet = spreadsheet.getSheets()[0];
    if (!sheet) { sheet = spreadsheet.insertSheet(); }
    sheet.setName(SHEET_TAB);
  }

  prepareSheet_(spreadsheet);

  return { ss: spreadsheet, sheet: sheet, url: spreadsheet.getUrl() };
}

/** URL de la feuille, si elle a déjà été créée (sans la créer). */
function sheetUrl_() {
  var spreadsheet = storedSheet_();
  return spreadsheet ? spreadsheet.getUrl() : '';
}

function storedSheet_() {
  try {
    var id = PropertiesService.getScriptProperties().getProperty('SHEET_ID');
    return id ? SpreadsheetApp.openById(id) : null;
  } catch (error) {
    console.warn('Feuille introuvable : ' + (error && error.message));
    return null;
  }
}

/**
 * Prépare l'onglet : en-têtes figés, largeurs, formats texte pour garder les
 * zéros initiaux des téléphones et codes postaux. Idempotent.
 */
function prepareSheet_(spreadsheet) {
  var sheet = spreadsheet.getSheetByName(SHEET_TAB);
  if (!sheet) { return; }

  var first = sheet.getRange(1, 1).getValue();
  if (clean_(first)) { return; }

  sheet.getRange(1, 1, 1, SHEET_COLUMNS.length).setValues([SHEET_COLUMNS]);
  sheet.getRange(1, 1, 1, SHEET_COLUMNS.length)
    .setFontWeight('bold')
    .setBackground('#003366')
    .setFontColor('#ffffff')
    .setVerticalAlignment('middle');

  sheet.setFrozenRows(1);

  if (sheet.getMaxColumns() < SHEET_COLUMNS.length) {
    sheet.insertColumnsAfter(sheet.getMaxColumns(), SHEET_COLUMNS.length - sheet.getMaxColumns());
  }

  var widths = [130, 230, 150, 170, 170, 210, 130, 100, 190, 260, 240, 120, 120, 120, 100, 100, 200, 100];
  for (var i = 0; i < widths.length; i++) {
    sheet.setColumnWidth(i + 1, widths[i]);
  }

  // Colonnes « Téléphone » (7) et « Code postal » (8) en texte.
  sheet.getRange(2, 7, Math.max(sheet.getMaxRows() - 1, 1), 2).setNumberFormat('@');
}

/** Vrai si la référence est déjà présente dans la colonne B. */
function rowExists_(sheet, reference) {
  var lastRow = sheet.getLastRow();
  if (lastRow < 2) { return false; }

  var values = sheet.getRange(2, 2, lastRow - 1, 1).getValues();
  for (var i = 0; i < values.length; i++) {
    if (clean_(values[i][0]) === reference) { return true; }
  }
  return false;
}

/** Formate la ligne qui vient d'être ajoutée (date, texte, lien e-mail). */
function formatLastRow_(sheet, lead) {
  var row = sheet.getLastRow();
  if (row < 2) { return; }

  try {
    sheet.getRange(row, 1).setNumberFormat('dd/MM/yyyy HH:mm');

    // Téléphone et code postal : forcer le texte (zéros initiaux).
    sheet.getRange(row, 7, 1, 2).setNumberFormat('@').setHorizontalAlignment('left');

    // E-mail cliquable : le texte reste l'adresse (lisible, exportable), le
    // lien est un attribut de la cellule — aucune formule, donc rien qui
    // dépende de la locale de la feuille.
    var mail = clean_(lead && lead.email);
    if (isEmail_(mail)) {
      var cell = sheet.getRange(row, 6);
      try {
        cell.setRichTextValue(
          SpreadsheetApp.newRichTextValue()
            .setText(mail)
            .setLinkUrl('mailto:' + mail)
            .build()
        );
      } catch (error) {
        cell.setValue(mail);
      }
    }

  } catch (error) {
    console.warn('Mise en forme de la ligne ignorée : ' + (error && error.message));
  }
}

/** Ligne de valeurs, dans l'ordre de SHEET_COLUMNS. */
function buildRow_(lead, body, sent) {
  var utm = lead.utm && typeof lead.utm === 'object' ? lead.utm : {};

  return [
    new Date(),
    clean_(body.lead_id || lead.lead_id),
    clean_(lead.formulaire || body.form),
    clean_(lead.nom),
    clean_(lead.societe),
    clean_(lead.email),
    clean_(lead.telephone),
    clean_(lead.code_postal),
    clean_(lead.volume),
    besoins_(lead.besoins),
    clean_(lead.page),
    clean_(utm.utm_source),
    clean_(utm.utm_medium),
    clean_(utm.utm_campaign),
    clean_(utm.gclid),
    clean_(utm.fbclid),
    SHEET_EMAIL,
    emails_(sent)
  ];
}

function besoins_(value) {
  if (Array.isArray(value)) {
    return value.map(function (item) { return clean_(item); }).filter(Boolean).join(' · ');
  }
  return clean_(value);
}

function emails_(sent) {
  var map = sent && typeof sent === 'object' ? sent : {};
  var parts = [];
  if (map.responsable) { parts.push('responsable'); }
  if (map.chef) { parts.push('chef'); }
  if (map.client) { parts.push('client'); }
  return parts.length ? parts.length + '/3 (' + parts.join(', ') + ')' : 'aucun';
}

/** Partage la feuille avec SHEET_EMAIL (accès en modification). */
function shareSheet_(id) {
  if (!SHEET_EMAIL) { return; }
  try {
    DriveApp.getFileById(id).addEditor(SHEET_EMAIL);
    console.log('Feuille partagée avec ' + SHEET_EMAIL);
  } catch (error) {
    console.warn('Partage impossible (' + SHEET_EMAIL + ') : ' + (error && error.message));
  }
}

/**
 * Purge RGPD : supprime les lignes plus anciennes que `jours` (180 par
 * défaut). À exécuter à la main (Exécuter → purgerLeadsAnciens) ou depuis un
 * déclencheur mensuel.
 */
function purgerLeadsAnciens(jours) {
  var limite = Number(jours) || 180;
  var target = sheet_();
  var sheet = target.sheet;
  var lastRow = sheet.getLastRow();

  if (lastRow < 2) { return 0; }

  var dates = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
  var seuil = new Date().getTime() - limite * 24 * 3600 * 1000;
  var supprimees = 0;

  for (var i = dates.length - 1; i >= 0; i--) {
    var value = dates[i][0];
    var time = value instanceof Date ? value.getTime() : new Date(value).getTime();
    if (!isNaN(time) && time < seuil) {
      sheet.deleteRow(i + 2);
      supprimees++;
    }
  }

  console.log(supprimees + ' ligne(s) de plus de ' + limite + ' jours supprimée(s)');
  return supprimees;
}

/* --------------------------------------------------------------------------
   4. Utilitaires
   -------------------------------------------------------------------------- */

/**
 * Envoi d'un message. Passe par l'alias configuré s'il y en a un, et retombe
 * sur la boîte qui héberge le script si Gmail refuse l'alias (non vérifié,
 * révoqué…). Le formulaire ne voit jamais la différence : le message part.
 */
function sendMail_(message) {
  if (FROM_ADDRESS) {
    try {
      var options = {
        htmlBody: message.htmlBody,
        name: message.name,
        from: FROM_ADDRESS
      };
      if (message.cc) { options.cc = message.cc; }
      if (message.replyTo) { options.replyTo = message.replyTo; }

      GmailApp.sendEmail(message.to, message.subject, message.body, options);
      return true;
    } catch (error) {
      console.warn('Alias ' + FROM_ADDRESS + ' refusé : ' + (error && error.message));
    }
  }

  MailApp.sendEmail(message);
  return true;
}

function account_() {
  try {
    return Session.getEffectiveUser().getEmail() || '';
  } catch (error) {
    return '';
  }
}

function parseBody_(e) {
  try {
    var raw = e && e.postData && e.postData.contents;
    if (!raw) { return null; }
    var parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch (error) {
    console.error('Corps illisible : ' + (error && error.message));
    return null;
  }
}

function isAuthorized_(token) {
  var given = clean_(token);
  if (!TOKEN || TOKEN.indexOf('REMPLACER') === 0 || TOKEN.length < 20) {
    return false; // jeton non configuré : on refuse tout, sans rien envoyer
  }
  if (given.length !== TOKEN.length) { return false; }

  // Comparaison à temps constant : évite de divulguer le jeton caractère par
  // caractère par mesure de durée de réponse.
  var diff = 0;
  for (var i = 0; i < TOKEN.length; i++) {
    diff |= TOKEN.charCodeAt(i) ^ given.charCodeAt(i);
  }
  return diff === 0;
}

function reply_(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}

function clean_(value) {
  return String(value == null ? '' : value).replace(/[\r\n]+/g, ' ').trim();
}

function isEmail_(value) {
  return /^[^\s@,;<>"']+@[^\s@,;<>"']+\.[A-Za-z]{2,}$/.test(value || '');
}

function isEmailList_(value) {
  if (!value) { return false; }
  return value.split(',').every(function (part) {
    return isEmail_(clean_(part));
  });
}

function stripTags_(html) {
  return String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/* --- quotas --------------------------------------------------------------- */

function remainingQuota_() {
  try {
    return MailApp.getRemainingDailyQuota();
  } catch (error) {
    return MAX_LEADS_PER_DAY;
  }
}

function todayKey_() {
  return 'leads:' + Utilities.formatDate(new Date(), 'UTC', 'yyyy-MM-dd');
}

function canAcceptToday_() {
  try {
    var props = PropertiesService.getScriptProperties();
    return Number(props.getProperty(todayKey_()) || 0) < MAX_LEADS_PER_DAY;
  } catch (error) {
    return true;
  }
}

function countToday_() {
  try {
    var props = PropertiesService.getScriptProperties();
    var key = todayKey_();
    props.setProperty(key, String(Number(props.getProperty(key) || 0) + 1));
    pruneCounters_(props, key);
  } catch (error) {
    console.warn('Compteur indisponible : ' + (error && error.message));
  }
}

/** Supprime les compteurs des jours précédents (les propriétés sont limitées). */
function pruneCounters_(props, keepKey) {
  try {
    props.getKeys().forEach(function (key) {
      if (key.indexOf('leads:') === 0 && key !== keepKey) { props.deleteProperty(key); }
    });
  } catch (error) {
    // sans conséquence
  }
}
