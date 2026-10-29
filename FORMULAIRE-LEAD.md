# Formulaire de lead — trois e-mails depuis une adresse dédiée

Le formulaire des pages `/lead-assurances/`, `/lead-assurances-rcs/`,
`/lead-energies/` et `/lead-energies-rcs/` fonctionne **sans serveur, sans PHP
et sans back-end**. Toute la logique est dans le JavaScript de la page.

Les **trois messages** exigés — notification au responsable, copie au chef de
département, confirmation au client — partent de la **même boîte dédiée** :
`ifeminformatique@gmail.com`, reliée au service **EmailJS**.

```
Visiteur ──► formulaire (2 étapes, validation, accessibilité)
                 │
                 ▼
      window.HL_LEAD_CONFIG.provider  =  'emailjs'
                 │
                 ├──► 1. Responsable   manajahelvin@gmail.com   (Répondre à = le prospect)
                 ├──► 2. Chef          ifeminformatique@gmail.com  (objet « [Copie] … »)
                 │     └──► 3. Client  le prospect              (Répondre à = ifeminformatique@gmail.com)
                 │
                 └─ si EmailJS refuse (quota, clés) ──► repli `formsubmit`, la demande n'est pas perdue
                        │
                        ▼
/boite-de-reception.html  →  copie locale des demandes et des e-mails générés
```

---

## 1. Tester en local (aucune installation)

```bash
cd /chemin/vers/le/site
python3 -m http.server 8000
```

| Page | Rôle |
|---|---|
| <http://localhost:8000/lead-assurances/> | formulaire assurances |
| <http://localhost:8000/lead-energies/> | formulaire énergies |
| <http://localhost:8000/boite-de-reception.html> | demandes reçues + e-mails générés |

À la livraison, `provider: 'emailjs'` est déjà en place mais les **clés sont
vides** : tant qu'elles ne sont pas renseignées (§ 3), chaque demande part
automatiquement par le repli **FormSubmit** vers le responsable, avec le chef en
copie. **Aucune demande n'est perdue** pendant la mise en service.

Pour une démonstration sans aucun envoi, passer `provider: 'local'` : rien ne
part sur le réseau, tout reste dans le navigateur.

---

## 2. Les trois e-mails générés

| # | Message | Destinataire | Répondre à | Contenu |
|---|---|---|---|---|
| 1 | Notification | `manajahelvin@gmail.com` | le prospect | coordonnées, volume, besoins, formulaire, page d'origine, UTM, date, référence, encart « Action attendue » |
| 2 | Copie au chef | `ifeminformatique@gmail.com` | le prospect | même message que la notification, objet préfixé `[Copie]` |
| 3 | Confirmation | le prospect | `ifeminformatique@gmail.com` | « une équipe s'en charge », récapitulatif (sans métadonnées internes), lien politique de confidentialité |

Les trois partent **de** `ifeminformatique@gmail.com` : le prospect qui répond à
sa confirmation écrit donc au chef de département, pas à une adresse technique.

Les gabarits HTML + texte sont produits par `buildEmails()` dans le script du
formulaire ; la boîte de réception locale en affiche le rendu exact.

> Le chef reçoit **un second message distinct** (et non une simple copie) : c'est
> ce qui garantit qu'il reçoit l'e-mail même si le responsable filtre ou
> redirige le sien. Mettre `emailjs.ccChef: true` ajoute en plus une copie sur
> le message du responsable — inutile dans le cas nominal, donc `false`.

---

## 3. Mise en service d'EmailJS (une seule fois, ~10 minutes)

EmailJS envoie de vrais e-mails depuis une boîte existante, appelé en
JavaScript : aucun serveur, aucune clé SMTP dans la page.

### 3.1 Créer le compte et relier la boîte dédiée

1. Créer un compte gratuit sur <https://dashboard.emailjs.com> (utiliser la
   boîte `ifeminformatique@gmail.com`, c'est elle qui expédiera).
2. **Email Services → Add New Service → Gmail**.
3. Autoriser la connexion au compte `ifeminformatique@gmail.com` (OAuth
   Google). Relever l'**identifiant du service**, de la forme `service_xxxxxxx`.

> Le service doit être ce compte Gmail précis : c'est lui qui devient
> l'expéditeur des trois messages. Un autre service (Gmail personnel, SMTP
> générique) expédierait depuis une autre adresse.

Les messages sortent par l'infrastructure Google : SPF et DKIM sont donc déjà
corrects, sans configuration DNS supplémentaire.

### 3.2 Créer un gabarit unique

**Email Templates → New Template**, puis dans l'onglet *Settings* :

| Champ EmailJS | Valeur |
|---|---|
| To Email | `{{to_email}}` |
| CC | `{{cc}}` |
| BCC | `{{bcc}}` — facultatif, peut rester vide |
| Subject | `{{subject}}` |
| Reply To | `{{reply_to}}` |
| From Name | `{{from_name}}` |

Dans l'onglet *Content*, coller **exactement** ceci (triple accolade : le corps
est du HTML déjà mis en forme par le formulaire) :

```html
{{{message}}}
```

L'onglet *Plain text* n'est pas nécessaire : `message_text` est transmis en
parallèle pour les clients qui refusent le HTML (variable `{{message_text}}`
disponible si vous préférez l'utiliser).

Relever l'**identifiant du gabarit**, de la forme `template_xxxxxxx`.

> Un seul gabarit suffit pour les trois messages : le destinataire, l'objet et
> le corps sont des variables. C'est aussi ce qui tient dans l'offre gratuite
> (2 gabarits maximum).

### 3.3 Renseigner les trois clés dans les 4 pages

Dans le `<script>` en tête de chaque page, l'objet `emailjs` :

```js
emailjs: {
  publicKey: 'AbCdEfGhIjKlMnOp',   // Account → General → Public Key
  serviceId: 'service_xxxxxxx',    // Email Services
  templateId: 'template_xxxxxxx',  // Email Templates
  ccChef: false
},
```

La **clé publique** n'est pas un secret : elle est visible dans le code de la
page, c'est son principe. Ce qui protège la boîte, c'est le quota et la
possibilité de restreindre les origines autorisées côté tableau de bord.

Renseigner **les 4 pages** (`lead-assurances`, `lead-assurances-rcs`,
`lead-energies`, `lead-energies-rcs`) — même service, même gabarit.

### 3.4 Quota et surveillance

| Offre | Envois | Gabarits | Remarque |
|---|---|---|---|
| Gratuite | **200 / mois** | 2 | ~**66 demandes** (3 messages chacune) |
| Payante (~9 $/mois) | au-delà | illimités | whitelist d'origines, white-label |

- Dépassement = requêtes **rejetées** (jamais de surcoût silencieux).
- EmailJS prévient par e-mail à 80 %, 95 % et 100 % du quota.
- **Quota épuisé ou clés invalides** : le formulaire bascule automatiquement
  sur le repli `formsubmit` — la demande arrive quand même au responsable, sans
  la confirmation au client. Le journal local indique alors le fournisseur
  réellement utilisé (`formsubmit`).
- Le suivi mensuel se lit dans *Account → Usage*.

### 3.5 Vérifier en une demande

1. Envoyer une demande test depuis `/lead-assurances/`.
2. Trois e-mails doivent arriver : responsable, chef (`[Copie] …`), et le
   prospect utilisé dans le test.
3. Dans Gmail, l'expéditeur affiché est `ifeminformatique@gmail.com` ; un
   « Répondre » sur la confirmation écrit à cette même adresse.
4. `/boite-de-reception.html` affiche la demande avec la mention
   « Envoi automatique actif » et **plus de bouton** « Envoyer la confirmation
   au client » (le message est déjà parti).

---

## 4. Changer de mode d'envoi

Tout se règle dans **un seul objet**, en haut du `<script>` de chaque page :

```js
window.HL_LEAD_CONFIG = {
  provider: 'emailjs',
  senderName: 'HelloLead',
  contactEmail: 'ifeminformatique@gmail.com',   // affiché au prospect / Reply-To
  siteUrl: 'https://www.hellodevis.fr',
  inboxUrl: '/boite-de-reception.html',
  endpoint: '',
  emailjs: { publicKey: '', serviceId: '', templateId: '', ccChef: false },
  fallbackProvider: 'formsubmit',
  formsubmit: { alias: '', email: 'manajahelvin@gmail.com' },
  web3forms: { accessKey: '' },
  form: {
    key: 'lead-assurances',
    label: 'Assurances pro',
    responsable: ['manajahelvin@gmail.com'],   // message 1
    chef: ['ifeminformatique@gmail.com']       // message 2
  }
};
```

### `emailjs` — mode livré (trois e-mails, expéditeur unique)

Voir § 3. `fallbackProvider` désigne le mode utilisé si les clés sont vides ou
refusées, ou si le quota mensuel est atteint : la demande n'est jamais perdue.

### `formsubmit` — repli (vrai e-mail, sans serveur)

La demande part vers le `responsable`, le `chef` en copie, et `_replyto` porte
l'adresse du prospect. **Première soumission = e-mail d'activation à
confirmer** dans la boîte du responsable, sinon rien n'arrive.

Limite structurelle : l'API AJAX de FormSubmit n'envoie **pas** de confirmation
au prospect. C'est pourquoi ce mode est un repli, pas le mode principal.

Astuce : après activation, FormSubmit fournit un **alias** (chaîne aléatoire).
Le renseigner dans `formsubmit.alias` évite d'afficher l'adresse en clair dans
le code source.

### `local` — pour les démonstrations

Rien ne part. Les demandes restent dans le navigateur et sont consultables dans
`/boite-de-reception.html`.

### `web3forms` — autre relais sans serveur (clé API)

```js
provider: 'web3forms',
web3forms: { accessKey: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' }
```

Clé gratuite sur <https://web3forms.com>. Un seul destinataire par envoi :
prévoir un transfert vers la boîte métier pour les copies.

### `endpoint` — votre propre récepteur

```js
provider: 'endpoint',
endpoint: 'https://mon-api.exemple.fr/lead'
```

POST JSON (même contrat que le relais PHP livré en option, § 8). Utile pour un
CRM, une fonction serverless (Cloudflare Workers, Netlify, Vercel) ou un webhook
interne. C'est ce mode qu'un relais maîtrisé utiliserait pour expédier depuis
`ifeminformatique@gmail.com` via SMTP Google.

> **Seul `local` ne dépend d'aucun service tiers.** Les trois autres ajoutent une
> dépendance externe : à choisir en connaissance de cause, et à documenter dans
> la politique de confidentialité.

---

## 5. Anti-spam, RGPD et limites

### Anti-spam (côté navigateur)

| Mécanisme | Effet |
|---|---|
| Piège `website` (champ caché) | rempli → succès simulé, **rien n'est enregistré** |
| Validation stricte | nom (nom + prénom), e-mail, téléphone FR/international, code postal, société, volume |
| Honeypot invisible | `aria-hidden`, `tabindex="-1"`, hors écran |
| Nettoyage | `.trim()`, minuscules sur l'e-mail, chiffres forcés sur le code postal et le téléphone |

Un formulaire sans serveur ne peut pas limiter le débit par IP : EmailJS applique
son propre quota, le piège reste la première barrière.

### Point RGPD

Avec `emailjs`, les données du prospect transitent par EmailJS puis par Google
(Gmail) avant d'arriver dans la boîte du responsable : deux sous-traitants à
mentionner dans la politique de confidentialité, avec la finalité (réponse à une
demande commerciale) et la durée de conservation. La copie locale (§ 7) reste
dans le navigateur du poste et n'est jamais transmise.

Pour supprimer tout tiers : `provider: 'endpoint'` avec un relais maîtrisé
(PHP ou serverless), qui expédie lui-même les messages.

### Limite assumée

- Le formulaire ne peut pas savoir si un message a été **lu**, seulement s'il a
  été **accepté** par le fournisseur (HTTP 200).
- Un envoi incertain (panne réseau, erreur 5xx) n'est **jamais rejoué** sur le
  repli : mieux vaut une demande à ressaisir qu'un doublon commercial.
- Un refus explicite (quota, clés) déclenche, lui, le repli sans risque.

---

## 6. Accessibilité et états

- Select personnalisé complet (clavier : flèches, `Entrée`, `Échap`, `Home`/`End`).
- Erreurs reliées aux champs (`aria-invalid`, `aria-describedby`), focus déplacé
  vers le premier champ invalide.
- Bouton en état `aria-busy` + libellé « Envoi en cours… » pendant l'envoi.
- Écran de succès focalisé (`role="status"`), libellé d'étape « Demande envoyée ».
- Le succès ne promet la confirmation que si elle est réellement partie :
  « Une confirmation vient d'être envoyée à … » n'apparaît que dans ce cas.
- Messages d'échec explicites : validation, indisponibilité, quota, panne réseau,
  avec repli `mailto:` sur l'adresse de contact.
- `dataLayer.push('hl_lead_form_submit' | 'hl_lead_form_error')` pour GTM, avec
  `provider`, `fallback`, `partial` dans le premier.

---

## 7. Boîte de réception locale (`boite-de-reception.html`)

| Fonction | Détail |
|---|---|
| Liste | demande la plus récente en tête, compteurs (total, mois, dernière réception) |
| Détail | coordonnées, volume, besoins, formulaires, page d'origine, référence |
| E-mails | les 2 gabarits rendus dans des `iframe` (hauteur ajustée au contenu) |
| Actions | supprimer une demande, tout effacer, exporter en JSON, exporter en CSV |
| Confirmation | bouton « Envoyer la confirmation au client » **seulement** si le message n'est pas déjà parti |
| Mention | adaptative : mode EmailJS, relais, serveur ou journal local |
| Synchronisation | la page se met à jour si une demande arrive depuis un autre onglet |

Les données ne quittent jamais le navigateur. `noindex, nofollow` est appliqué,
mais **cette page ne doit pas être publiée** sur le site en production.

---

## 8. Fichiers

| Fichier | Rôle | Publier ? |
|---|---|---|
| `lead-*/index.html` | formulaire + moteur JS, `provider: 'emailjs'` (config en tête de script) | oui |
| `boite-de-reception.html` | boîte de réception locale des demandes | **non** |
| `production-php/` | relais e-mail facultatif pour un serveur PHP (§ 9) | seulement si utilisé |
| `FORMULAIRE-LEAD.md` | ce document | non |

Le dossier `production-php/` est **optionnel** : rien dans les pages n'en dépend
aujourd'hui.

---

## 9. Option : relais PHP (si un jour un envoi serveur est requis)

`production-php/lead-form-mail.php` + `lead-form-config.php` envoient réellement
les deux e-mails internes (fonction `mail()`, SMTP, `wp_mail()` ou `log`), avec
journalisation JSONL, purge RGPD (`--purge=180`) et diagnostic (`--doctor`).
L'expéditeur y est déjà `ifeminformatique@gmail.com` ; pour passer par Gmail en
SMTP, utiliser `smtp.gmail.com:587` et un **mot de passe d'application** Google
(compte → Sécurité → Validation en deux étapes).

Activation :

1. copier les deux fichiers à la racine du site (PHP 7.4+) ;
2. vérifier les adresses dans `lead-form-config.php`, puis `enabled => true` ;
3. dans la page, passer `provider: 'endpoint'` et
   `endpoint: '/lead-form-mail.php'`.

Sans serveur PHP, **ne pas l'utiliser** : c'est précisément ce qu'EmailJS
remplace.

---

## 10. Mise en production — liste de contrôle

- [x] `provider: 'emailjs'`, `fallbackProvider: 'formsubmit'` dans les 4 pages.
- [x] `responsable` = manajahelvin@gmail.com, `chef` = ifeminformatique@gmail.com,
      `contactEmail` = ifeminformatique@gmail.com.
- [ ] Compte EmailJS créé et **service Gmail relié à ifeminformatique@gmail.com**.
- [ ] Gabarit unique créé (`{{to_email}}`, `{{cc}}`, `{{subject}}`, `{{reply_to}}`,
      `{{{message}}}`).
- [ ] `publicKey`, `serviceId`, `templateId` renseignés **dans les 4 pages**.
- [ ] Une demande test produit bien **3 e-mails**, tous expédiés par
      ifeminformatique@gmail.com, et « Répondre » écrit au prospect.
- [ ] Repli FormSubmit activé une fois (lien d'activation reçu par e-mail).
- [ ] Alerte de quota surveillée (80 % / 95 % / 100 % des 200 envois mensuels).
- [ ] `boite-de-reception.html` **non publiée**.
- [ ] Politique de confidentialité à jour (EmailJS + Google, finalité, durée).
- [ ] `robots.txt` / `noindex` conservés sur la boîte locale.
