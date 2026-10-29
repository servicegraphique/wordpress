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
 *     ]
 *   }
 *
 * Réponse (toujours HTTP 200, JSON) :
 *   { "ok": true,  "sent": { "responsable": true, "chef": true, "client": true } }
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

  // Sans le message au responsable, la demande serait perdue côté équipe :
  // la page doit le savoir pour proposer une solution de repli.
  return reply_({ ok: sent.responsable !== false, sent: sent });
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

/* --------------------------------------------------------------------------
   3. Utilitaires
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
