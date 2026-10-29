/**
 * HelloLead — relais « feuille de suivi » (Google Apps Script)
 * ==========================================================================
 * Ce script est **indépendant** du relais e-mail (production-gas/Code.gs) :
 * il ne touche ni à Gmail, ni au formulaire d'envoi. Son seul rôle est
 * d'ajouter une ligne par demande dans la feuille de calcul à laquelle il
 * est attaché — pour le suivi individuel des prospects.
 *
 * Installation (5 minutes, une seule fois) — voir README.md :
 *   1. Ouvrir SA feuille de calcul → Extensions → Apps Script
 *   2. Coller ce fichier, remplacer TOKEN
 *   3. Déployer → Application web (moi / tout le monde)
 *   4. Reporter l'URL /exec et le jeton dans les 4 pages (CONFIG.sheet)
 *
 * Contrat d'appel (POST depuis la page, corps text/plain — seul type simple
 * compatible CORS avec Apps Script) :
 *
 *   {
 *     "token": "…",
 *     "lead": {
 *       "formulaire": "Assurances pro", "nom": "…", "email": "…",
 *       "telephone": "…", "code_postal": "…", "societe": "…",
 *       "volume": "100 à 500 leads / mois", "besoins": ["…", "…"],
 *       "page": "https://…", "utm": { … }, "lead_id": "hl-…"
 *     }
 *   }
 *
 * Réponses (toujours HTTP 200, JSON) :
 *   { "ok": true,  "row": 12,  "added": ["Statut"], "columns": 18 }
 *   { "ok": true,  "duplicate": true, "row": 0 }      // déjà présente
 *   { "ok": false, "error": "token" | "invalid" | "quota" }
 */

/* --------------------------------------------------------------------------
   1. Réglages
   -------------------------------------------------------------------------- */

// Jeton partagé avec la page (CONFIG.sheet.token). Long, aléatoire.
// Génération : console du navigateur (F12) →
//   crypto.randomUUID().replace(/-/g, '') + crypto.randomUUID().replace(/-/g, '')
var TOKEN = 'REMPLACER-PAR-UN-JETON-LONG-ET-ALEATOIRE';

// Identifiant du classeur (facultatif).
// Lorsque le script est créé DEPUIS la feuille (Extensions → Apps Script), il
// retrouve le classeur tout seul : laisser vide.
// Si l'URL /exec répond « Aucun classeur », coller ici la partie de l'URL de la
// feuille comprise entre /d/ et /edit :
//   https://docs.google.com/spreadsheets/d/1AbC…xyz/edit  →  '1AbC…xyz'
var SHEET_ID = '';

// Onglet utilisé. Vide = première feuille du classeur.
var SHEET_TAB = '';

// Ligne des en-têtes (1 = première ligne).
var HEADER_ROW = 1;

// Créer les colonnes absentes à la fin de la ligne d'en-tête.
// false = seules les colonnes déjà présentes sont remplies.
var ADD_MISSING_COLUMNS = true;

// Garde-fou anti-abus : nombre de lignes écrites par jour.
var MAX_ROWS_PER_DAY = 300;

// Longueur maximale d'une valeur écrite (caractères).
var MAX_VALUE_CHARS = 2000;

/**
 * Champs reconnus, dans l'ordre d'ajout des colonnes manquantes.
 * Chaque champ accepte plusieurs intitulés (comparaison insensible à la casse,
 * aux accents, aux espaces et à la ponctuation finale).
 */
var FIELDS = [
  { key: 'received_at', label: 'Reçue le',
    aliases: ['recue le', 'date', 'date de reception', 'date reception', 'horodatage', 'timestamp', 'date/heure'] },
  { key: 'reference', label: 'Référence',
    aliases: ['reference', 'ref', 'lead id', 'id', 'identifiant'] },
  { key: 'form', label: 'Formulaire',
    aliases: ['formulaire', 'form', 'offre', 'produit'] },
  { key: 'name', label: 'Nom et prénom',
    aliases: ['nom', 'nom et prenom', 'nom prenom', 'contact', 'nom du contact', 'prenom nom'] },
  { key: 'company', label: 'Société',
    aliases: ['societe', 'entreprise', 'raison sociale', 'company', 'client'] },
  { key: 'email', label: 'E-mail',
    aliases: ['e-mail', 'email', 'mail', 'courriel', 'adresse e-mail', 'adresse email'] },
  { key: 'phone', label: 'Téléphone',
    aliases: ['telephone', 'tel', 'phone', 'mobile', 'numero de telephone', 'numero'] },
  { key: 'postal', label: 'Code postal',
    aliases: ['code postal', 'cp', 'postal', 'code postale', 'zip'] },
  { key: 'volume', label: 'Volume souhaité',
    aliases: ['volume', 'volume souhaite', 'nombre de leads', 'volume de leads'] },
  { key: 'needs', label: 'Besoins',
    aliases: ['besoins', 'besoin', 'services', 'prestations'] },
  { key: 'page', label: "Page d'origine",
    aliases: ['page', "page d'origine", 'page origine', 'url', 'landing', 'landing page'] },
  { key: 'utm_source', label: 'UTM source',
    aliases: ['utm source', 'utm_source', 'source'] },
  { key: 'utm_medium', label: 'UTM support',
    aliases: ['utm support', 'utm_medium', 'support', 'canal'] },
  { key: 'utm_campaign', label: 'UTM campagne',
    aliases: ['utm campagne', 'utm_campaign', 'campagne', 'campaign'] },
  { key: 'gclid', label: 'gclid', aliases: ['gclid', 'google click id'] },
  { key: 'fbclid', label: 'fbclid', aliases: ['fbclid'] }
];

/* --------------------------------------------------------------------------
   2. Points d'entrée
   -------------------------------------------------------------------------- */

/**
 * Ajout d'une ligne. Appelé par la page du formulaire.
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

  if (!canAcceptToday_()) {
    console.warn('Limite quotidienne de la feuille atteinte');
    return reply_({ ok: false, error: 'quota' });
  }

  var lead = body.lead;
  if (!lead || typeof lead !== 'object' || (!lead.nom && !lead.email)) {
    return reply_({ ok: false, error: 'invalid' });
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
    var sheet = target_();
    var layout = layout_(sheet);

    // Même demande renvoyée deux fois : on ne crée pas de doublon.
    var reference = clean_(lead.lead_id);
    if (reference && layout.columns.reference) {
      if (exists_(sheet, layout.columns.reference, reference)) {
        return reply_({ ok: true, duplicate: true, row: 0, columns: layout.width });
      }
    }

    var values = buildValues_(lead, layout);

    var lastRow = sheet.getLastRow();
    var writeRow = Math.max(lastRow + 1, HEADER_ROW + 1);
    sheet.getRange(writeRow, 1, 1, layout.width).setValues([values]);

    formatRow_(sheet, writeRow, layout);

    countToday_();

    return reply_({
      ok: true,
      row: writeRow,
      added: layout.added,
      columns: layout.width
    });
  } catch (error) {
    console.error('Écriture impossible : ' + (error && error.message));
    return reply_({ ok: false, error: 'sheet', message: String((error && error.message) || '').slice(0, 200) });
  } finally {
    if (locked && lock) {
      try { lock.releaseLock(); } catch (error) { /* sans conséquence */ }
    }
  }
}

/**
 * Vérification de santé : ouvrir l'URL /exec dans un navigateur.
 * N'écrit rien.
 */
function doGet() {
  try {
    var sheet = target_();
    var layout = layout_(sheet);
    return reply_({
      ok: true,
      service: 'HelloLead — feuille de suivi',
      ready: TOKEN.indexOf('REMPLACER') !== 0 && TOKEN.length >= 20,
      tab: sheet.getName(),
      rows: Math.max(sheet.getLastRow() - HEADER_ROW, 0),
      columns: layout.width,
      headers: layout.headers
    });
  } catch (error) {
    return reply_({ ok: false, error: 'sheet', message: String((error && error.message) || '').slice(0, 200) });
  }
}

/**
 * Ligne de démonstration (Exécuter → testLigne), sans passer par la page.
 * Sert à valider les autorisations et le mapping des colonnes.
 */
function testLigne() {
  var result = doPost({
    postData: {
      contents: JSON.stringify({
        token: TOKEN,
        lead: {
          formulaire: 'Test',
          nom: 'Test Ligne',
          email: 'test@exemple.fr',
          telephone: '0600000000',
          code_postal: '75001',
          societe: 'Société de test',
          volume: '100 à 500 leads / mois',
          besoins: ['Assurance I.A.R.D'],
          page: 'https://www.hellodevis.fr/lead-assurances/',
          utm: { utm_source: 'test' },
          lead_id: 'test-' + new Date().getTime()
        }
      })
    }
  });

  console.log(result.getContent());
  return result;
}

/* --------------------------------------------------------------------------
   3. Lecture de la feuille
   -------------------------------------------------------------------------- */

/** Feuille cible : l'onglet configuré, sinon la première du classeur. */
function target_() {
  var spreadsheet = null;

  // 1. Identifiant fourni explicitement.
  if (SHEET_ID) {
    spreadsheet = SpreadsheetApp.openById(SHEET_ID);
  }

  // 2. Script lié au classeur (cas normal : créé depuis la feuille).
  if (!spreadsheet) {
    try {
      spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
    } catch (error) {
      spreadsheet = null;
    }
  }

  // 3. Identifiant mémorisé lors d'un précédent appel.
  if (!spreadsheet) {
    var stored = PropertiesService.getScriptProperties().getProperty('SHEET_ID');
    if (stored) { spreadsheet = SpreadsheetApp.openById(stored); }
  }

  if (!spreadsheet) {
    throw new Error('Aucun classeur : renseigner SHEET_ID en tête du script');
  }

  try {
    PropertiesService.getScriptProperties().setProperty('SHEET_ID', spreadsheet.getId());
  } catch (error) {
    // sans conséquence
  }

  var sheet = SHEET_TAB ? spreadsheet.getSheetByName(SHEET_TAB) : spreadsheet.getSheets()[0];
  if (!sheet) {
    throw new Error('Onglet introuvable : ' + SHEET_TAB);
  }

  return sheet;
}

/**
 * Analyse la ligne d'en-tête : quelle colonne correspond à quel champ, et
 * quelles colonnes manquent. Crée les manquantes si ADD_MISSING_COLUMNS.
 */
function layout_(sheet) {
  // Une feuille vierge renvoie 0 : on part alors de zéro colonne, pour que la
  // première colonne créée soit bien la colonne A.
  var width = sheet.getLastColumn();
  var headers = width
    ? sheet.getRange(HEADER_ROW, 1, 1, width).getValues()[0].map(function (value) { return clean_(value); })
    : [];

  var columns = {};
  var byKey = {};

  FIELDS.forEach(function (field) {
    var aliases = field.aliases.map(norm_);
    aliases.push(norm_(field.label));

    for (var i = 0; i < headers.length; i++) {
      if (headers[i] && aliases.indexOf(norm_(headers[i])) !== -1 && !byKey[i]) {
        columns[field.key] = i + 1;
        byKey[i] = field.key;
        break;
      }
    }
  });

  // Colonnes absentes : ajoutées à la fin, dans l'ordre de FIELDS.
  var added = [];
  if (ADD_MISSING_COLUMNS) {
    FIELDS.forEach(function (field) {
      if (columns[field.key]) { return; }

      width += 1;
      headers.push(field.label);
      columns[field.key] = width;
      added.push(field.label);
    });

    if (added.length) {
      if (width > sheet.getMaxColumns()) {
        sheet.insertColumnsAfter(sheet.getMaxColumns(), width - sheet.getMaxColumns());
      }
      sheet.getRange(HEADER_ROW, 1, 1, width).setValues([headers]);
      styleHeader_(sheet, width);
    }
  }

  if (width < 1) {
    throw new Error('Aucune colonne : ajouter une ligne d’en-tête dans la feuille');
  }

  return { width: width, headers: headers, columns: columns, added: added };
}

/** Met en forme les en-têtes nouvellement créés (sans toucher aux anciens). */
function styleHeader_(sheet, width) {
  try {
    var range = sheet.getRange(HEADER_ROW, 1, 1, width);
    range.setFontWeight('bold');
    sheet.setFrozenRows(HEADER_ROW);
  } catch (error) {
    // l'utilisateur a peut-être protégé la ligne : sans conséquence
  }
}

/** Valeur déjà présente dans une colonne (déduplication) ? */
function exists_(sheet, column, value) {
  var lastRow = sheet.getLastRow();
  if (lastRow <= HEADER_ROW) { return false; }

  var values = sheet.getRange(HEADER_ROW + 1, column, lastRow - HEADER_ROW, 1).getValues();
  for (var i = 0; i < values.length; i++) {
    if (clean_(values[i][0]) === value) { return true; }
  }
  return false;
}

/* --------------------------------------------------------------------------
   4. Écriture
   -------------------------------------------------------------------------- */

/** Ligne complète, largeur = nombre de colonnes de la feuille. */
function buildValues_(lead, layout) {
  var utm = lead.utm && typeof lead.utm === 'object' ? lead.utm : {};

  var values = {
    received_at: new Date(),
    reference: clean_(lead.lead_id),
    form: clean_(lead.formulaire),
    name: clean_(lead.nom),
    company: clean_(lead.societe),
    email: clean_(lead.email),
    phone: clean_(lead.telephone),
    postal: clean_(lead.code_postal),
    volume: clean_(lead.volume),
    needs: list_(lead.besoins),
    page: clean_(lead.page),
    utm_source: clean_(utm.utm_source),
    utm_medium: clean_(utm.utm_medium),
    utm_campaign: clean_(utm.utm_campaign),
    gclid: clean_(utm.gclid),
    fbclid: clean_(utm.fbclid)
  };

  var row = [];
  for (var i = 1; i <= layout.width; i++) {
    row.push(columnValue_(layout, values, i));
  }

  return row;
}

/** Valeur à écrire dans la colonne `index`, ou '' si ce n'est pas un champ. */
function columnValue_(layout, values, index) {
  var keys = Object.keys(layout.columns);
  for (var i = 0; i < keys.length; i++) {
    if (layout.columns[keys[i]] === index) {
      return shorten_(values[keys[i]]);
    }
  }
  return '';
}

/** Formats par colonne : texte pour téléphone / code postal, date lisible. */
function formatRow_(sheet, row, layout) {
  try {
    if (layout.columns.received_at) {
      sheet.getRange(row, layout.columns.received_at).setNumberFormat('dd/MM/yyyy HH:mm');
    }
    if (layout.columns.phone) {
      sheet.getRange(row, layout.columns.phone).setNumberFormat('@').setHorizontalAlignment('left');
    }
    if (layout.columns.postal) {
      sheet.getRange(row, layout.columns.postal).setNumberFormat('@').setHorizontalAlignment('left');
    }
    if (layout.columns.email) {
      var cell = sheet.getRange(row, layout.columns.email);
      var mail = clean_(cell.getValue());
      if (isEmail_(mail)) {
        cell.setRichTextValue(
          SpreadsheetApp.newRichTextValue().setText(mail).setLinkUrl('mailto:' + mail).build()
        );
      }
    }
  } catch (error) {
    console.warn('Mise en forme ignorée : ' + (error && error.message));
  }
}

/* --------------------------------------------------------------------------
   5. Utilitaires
   -------------------------------------------------------------------------- */

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

function reply_(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}

function isAuthorized_(token) {
  var given = clean_(token);
  if (!TOKEN || TOKEN.indexOf('REMPLACER') === 0 || TOKEN.length < 20) {
    return false;
  }
  if (given.length !== TOKEN.length) { return false; }

  var diff = 0;
  for (var i = 0; i < TOKEN.length; i++) {
    diff |= TOKEN.charCodeAt(i) ^ given.charCodeAt(i);
  }
  return diff === 0;
}

function clean_(value) {
  return String(value == null ? '' : value).replace(/[\r\n]+/g, ' ').trim();
}

function shorten_(value) {
  if (value == null) { return ''; }
  if (typeof value !== 'string') { return value; }   // dates, nombres…
  return value.slice(0, MAX_VALUE_CHARS);
}

function list_(value) {
  if (Array.isArray(value)) {
    return value.map(function (item) { return clean_(item); }).filter(Boolean).join(' · ');
  }
  return clean_(value);
}

function isEmail_(value) {
  return /^[^\s@,;<>"']+@[^\s@,;<>"']+\.[A-Za-z]{2,}$/.test(value || '');
}

/** Minuscules, sans accents, sans ponctuation finale, espaces normalisés. */
function norm_(value) {
  var text = String(value == null ? '' : value).replace(/\s+/g, ' ').trim().toLowerCase();
  try {
    text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  } catch (error) {
    text = text.replace(/[éèêë]/g, 'e').replace(/[àâä]/g, 'a')
      .replace(/[îï]/g, 'i').replace(/[ôö]/g, 'o').replace(/[ùûü]/g, 'u')
      .replace(/[ç]/g, 'c');
  }
  return text.replace(/[.:;!?]+$/, '').trim();
}

/* --- garde-fou quotidien -------------------------------------------------- */

function todayKey_() {
  return 'rows:' + Utilities.formatDate(new Date(), 'UTC', 'yyyy-MM-dd');
}

function canAcceptToday_() {
  try {
    var value = PropertiesService.getScriptProperties().getProperty(todayKey_());
    return Number(value || 0) < MAX_ROWS_PER_DAY;
  } catch (error) {
    return true;
  }
}

function countToday_() {
  try {
    var props = PropertiesService.getScriptProperties();
    var key = todayKey_();
    props.setProperty(key, String(Number(props.getProperty(key) || 0) + 1));

    props.getKeys().forEach(function (name) {
      if (name.indexOf('rows:') === 0 && name !== key) { props.deleteProperty(name); }
    });
  } catch (error) {
    console.warn('Compteur indisponible : ' + (error && error.message));
  }
}
