# Formulaire de lead — trois e-mails depuis la boîte dédiée

Le formulaire des pages `/lead-assurances/`, `/lead-assurances-rcs/`,
`/lead-energies/` et `/lead-energies-rcs/` fonctionne **sans serveur, sans PHP
et sans back-end**.

Les **trois messages** exigés — notification au responsable
(`cremy@hellolead.pro`), copie au chef de département
(`pbenateau@hellolead.pro`), confirmation au client — sont expédiés **depuis la
boîte `ifeminformatique@gmail.com`**, via un relais **Google Apps Script**
installé dans cette boîte : c'est Gmail qui expédie, sans service tiers. Le
§ 3 bis explique comment faire apparaître `pbenateau@hellolead.pro` comme
expéditeur affiché.

```
Visiteur ──► formulaire (2 étapes, validation, accessibilité)
                 │
                 ▼
      window.HL_LEAD_CONFIG.provider  =  'gas'
                 │
                 │  un seul POST text/plain
                 ▼
      Application web Apps Script (dans ifeminformatique@gmail.com)
                 │
                 ├──► 1. Responsable   cremy@hellolead.pro      (Répondre à = le prospect)
                 ├──► 2. Chef          pbenateau@hellolead.pro  (objet « [Copie] … »)
                 └──► 3. Client        le prospect              (Répondre à = pbenateau@hellolead.pro)

      si le relais refuse (jeton, quota) ──► repli `formsubmit`, la demande n'est pas perdue
                 │
                 ├──► Google Sheets « Leads HelloLead — suivi » : une ligne par prospect,
                 │    partagée avec manajahelvin@gmail.com (suivi individuel)
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

À la livraison, `provider: 'gas'` est déjà en place mais **l'URL et le jeton
sont vides** : tant qu'ils ne sont pas renseignés (§ 3), chaque demande part
automatiquement par le repli **FormSubmit** vers le responsable, avec le chef en
copie. **Aucune demande n'est perdue** pendant la mise en service.

Pour une démonstration sans aucun envoi, passer `provider: 'local'` : rien ne
part sur le réseau, tout reste dans le navigateur.

---

## 2. Les trois e-mails générés

| # | Message | Destinataire | Répondre à | Contenu |
|---|---|---|---|---|
| 1 | Notification | `cremy@hellolead.pro` | le prospect | coordonnées, volume, besoins, formulaire, page d'origine, UTM, date, référence, encart « Action attendue » |
| 2 | Copie au chef | `pbenateau@hellolead.pro` | le prospect | même message que la notification, objet préfixé `[Copie]` |
| 3 | Confirmation | le prospect | `pbenateau@hellolead.pro` | « une équipe s'en charge », récapitulatif (sans métadonnées internes), lien politique de confidentialité |

Les trois sont expédiés par la boîte `ifeminformatique@gmail.com` ; le prospect
qui répond à sa confirmation écrit au chef de département
(`pbenateau@hellolead.pro`), pas à l'adresse technique du relais.

Les gabarits HTML + texte sont produits par `buildEmails()` dans le script du
formulaire ; la boîte de réception locale en affiche le rendu exact.

> Le chef reçoit **un message distinct** (et non une simple copie) : c'est ce qui
> garantit qu'il reçoit l'e-mail même si le responsable filtre ou redirige le
> sien. `ccChef: true` ajoute en plus une copie sur le message du responsable —
> inutile dans le cas nominal, donc `false`.

### La feuille de suivi

En plus des e-mails, **chaque demande valide crée une ligne** dans une feuille
de calcul Google, partagée avec l'adresse de suivi :

| | |
|---|---|
| Nom | « Leads HelloLead — suivi », onglet **Leads** |
| Accès | `manajahelvin@gmail.com` (modification) — invitation Google à accepter une fois |
| Contenu | date, référence, formulaire, nom, société, e-mail, téléphone, code postal, volume, besoins, page d'origine, UTM, `gclid`, `fbclid`, « Suivi par », statut des e-mails |
| Une ligne par | demande (déduplication par référence) |
| Si la feuille tombe | les e-mails partent quand même, le visiteur ne voit rien |

Détail des colonnes, partage, purge RGPD (`purgerLeadsAnciens`) et dépannage :
`production-gas/README.md`, § 4. L'URL de la feuille est aussi renvoyée par
l'URL `/exec` (champ `sheet`).

> **Mise à jour du script obligatoire** : l'écriture dans Sheets ajoute des
> autorisations. Coller le nouveau `Code.gs`, lancer **Exécuter → `testFeuille`**
> et accepter la demande de Google, puis redéployer une **nouvelle version**.

---

## 3. Mise en service du relais Gmail (une seule fois, ~5 minutes)

Le script `production-gas/Code.gs` vit dans la boîte : c'est elle qui expédie.
Procédure détaillée et dépannage : **`production-gas/README.md`**. Résumé :

1. <https://script.google.com> **connecté à `ifeminformatique@gmail.com`** →
   **Nouveau projet** → coller tout `Code.gs`.
2. Générer un jeton long et aléatoire et le mettre dans la constante `TOKEN`
   (le même servira dans les pages).
3. **Déployer → Nouveau déploiement → Application web** :
   - *Exécuter en tant que* : **moi** (`ifeminformatique@gmail.com`)
   - *Qui a accès* : **Tout le monde**
4. Copier l'URL terminée par `/exec`, puis vérifier dans un navigateur :

   ```json
   {"ok":true,"service":"HelloLead","ready":true,"remaining":97}
   ```

5. Dans **les 4 pages**, renseigner :

   ```js
   provider: 'gas',
   gas: {
     url: 'https://script.google.com/macros/s/AKfy…/exec',
     token: 'le-même-jeton-que-dans-TOKEN'
   },
   ```

6. Envoyer une demande test : trois e-mails arrivent (responsable, chef,
   prospect), expédiés par la boîte qui héberge le script.

### 3 bis. Expéditeur affiché

Par défaut, l'expéditeur est **`ifeminformatique@gmail.com`** : c'est la boîte
qui héberge le relais, donc celle que Gmail authentifie (SPF/DKIM corrects sans
aucun réglage DNS). Les destinataires, eux, sont bien `cremy@hellolead.pro` et
`pbenateau@hellolead.pro`.

Pour afficher **`pbenateau@hellolead.pro`** à la place, deux possibilités :

| Méthode | Ce qu'il faut faire | Remarque |
|---|---|---|
| **Alias Gmail** (le plus simple) | Gmail → Paramètres → **Comptes** → « Envoyer des e-mails en tant que » → ajouter `pbenateau@hellolead.pro` (vérification par code, ou SMTP de `hellolead.pro`), puis dans `Code.gs` : `var FROM_ADDRESS = 'pbenateau@hellolead.pro';` et **redéployer une nouvelle version** | Le relais continue de tourner dans la boîte actuelle. Si l'alias est refusé, le script se rabat seul sur la boîte : rien n'est perdu |
| **Déplacer le relais** | Recréer le projet Apps Script **depuis `pbenateau@hellolead.pro`** (si c'est un compte Google), recoller `Code.gs`, déployer, puis reporter la nouvelle `url` et un nouveau `token` dans les 4 pages | Aucune dépendance à l'alias ; à préférer si `hellolead.pro` est un Google Workspace |

`FROM_ADDRESS` reste vide par défaut : tant qu'il l'est, le comportement est
celui qui a été validé.

### Quotas et garde-fous

| Limite | Valeur | Conséquence |
|---|---|---|
| Gmail (compte personnel) via Apps Script | **100 e-mails / jour** | 3 par demande → ~33 demandes/jour |
| `MAX_LEADS_PER_DAY` du script | **30 demandes / jour** | au-delà : refus `quota` → repli FormSubmit |
| `MAX_MESSAGE_CHARS` | 120 000 caractères | message refusé (`invalid`) |

### Sécurité

L'URL et le jeton sont visibles dans le code de la page, comme toute
configuration côté navigateur : ils empêchent un usage accidentel, pas un usage
malveillant déterminé — d'où le plafond de 30 demandes/jour. Ils figurent donc
aussi dans ce dépôt (public) une fois la configuration posée. En cas d'abus :
changer `TOKEN` dans le script, redéployer une **nouvelle version**, reporter le
nouveau jeton dans les 4 pages.

---

## 4. Changer de mode d'envoi

Tout se règle dans **un seul objet**, en haut du `<script>` de chaque page :

```js
window.HL_LEAD_CONFIG = {
  provider: 'gas',
  senderName: 'HelloLead',
  ccChef: false,                                // copie du chef sur le message au responsable
  contactEmail: 'pbenateau@hellolead.pro',      // affiché au prospect / Reply-To
  siteUrl: 'https://www.hellodevis.fr',
  inboxUrl: '/boite-de-reception.html',
  endpoint: '',

  gas: { url: '', token: '' },

  emailjs: { publicKey: '', serviceId: '', templateId: '' },   // alternative
  fallbackProvider: 'formsubmit',
  formsubmit: { alias: '', email: 'cremy@hellolead.pro' },
  web3forms: { accessKey: '' },

  form: {
    key: 'lead-assurances',
    label: 'Assurances pro',
    responsable: ['cremy@hellolead.pro'],       // message 1
    chef: ['pbenateau@hellolead.pro']           // message 2
  }
};
```

### `gas` — mode livré (trois e-mails, expéditeur = la boîte elle-même)

Voir § 3. Un seul POST porte les trois messages ; le script répond
`{ok, sent:{responsable, chef, client}}`, ce qui permet de savoir précisément
ce qui est parti.

### `emailjs` — alternative sans serveur (service tiers)

Si un service tiers est préféré : compte gratuit sur <https://dashboard.emailjs.com>,
**service Gmail relié à `ifeminformatique@gmail.com`**, un gabarit unique
(`{{to_email}}`, `{{cc}}`, `{{subject}}`, `{{reply_to}}`, corps `{{{message}}}`),
puis :

```js
provider: 'emailjs',
emailjs: { publicKey: '…', serviceId: 'service_…', templateId: 'template_…' }
```

Gratuit : 200 envois/mois (≈ 66 demandes), puis requêtes rejetées. Le
comportement est identique côté page (trois messages, même suivi).

### `formsubmit` — repli (vrai e-mail, sans serveur)

La demande part vers le `responsable`, le `chef` en copie, et `_replyto` porte
l'adresse du prospect. **Première soumission = e-mail d'activation à
confirmer** dans la boîte du responsable, sinon rien n'arrive.

Limite structurelle : l'API AJAX de FormSubmit n'envoie **pas** de confirmation
au prospect. C'est pourquoi ce mode est un repli, pas le mode principal.

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

POST JSON (même contrat que le relais PHP livré en option, § 9). Utile pour un
CRM, une fonction serverless (Cloudflare Workers, Netlify, Vercel) ou un webhook
interne.

> **Seul `local` ne dépend d'aucun service.** `gas` reste chez Google (la boîte
> elle-même), `emailjs`/`formsubmit`/`web3forms` ajoutent un sous-traitant.

---

## 5. Anti-spam, RGPD et limites

### Anti-spam (côté navigateur)

| Mécanisme | Effet |
|---|---|
| Piège `website` (champ caché) | rempli → succès simulé, **rien n'est enregistré** |
| Validation stricte | nom (nom + prénom), e-mail, téléphone FR/international, code postal, société, volume |
| Honeypot invisible | `aria-hidden`, `tabindex="-1"`, hors écran |
| Nettoyage | `.trim()`, minuscules sur l'e-mail, chiffres forcés sur le code postal et le téléphone |

Un formulaire sans serveur ne peut pas limiter le débit par IP : le relais
plafonne à 30 demandes/jour, le piège reste la première barrière.

### Point RGPD

Avec `gas`, les données passent par Gmail/Google — déjà sous-traitant de la
messagerie — et restent chez Google : la feuille de suivi est un fichier du même
compte, partagé avec l'adresse de suivi, soumis à la même politique de
conservation (purge à 180 jours). Avec `emailjs`, un
second sous-traitant s'ajoute. Dans les deux cas : finalité (réponse à une
demande commerciale) et durée de conservation à documenter dans la politique de
confidentialité. La copie locale (§ 7) reste dans le navigateur du poste et
n'est jamais transmise.

### Limite assumée

- Le formulaire ne peut pas savoir si un message a été **lu**, seulement s'il a
  été **accepté** pour expédition.
- Un envoi incertain (panne réseau, erreur 5xx, réponse ambiguë) n'est **jamais
  rejoué** sur le repli : mieux vaut une demande à ressaisir qu'un doublon
  commercial.
- Un refus explicite (URL/ jeton absents, jeton refusé, quota atteint)
  déclenche, lui, le repli sans risque, car rien n'est parti.

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
| E-mails | les gabarits rendus dans des `iframe` (hauteur ajustée au contenu) |
| Actions | supprimer une demande, tout effacer, exporter en JSON, exporter en CSV |
| Confirmation | bouton « Envoyer la confirmation au client » **seulement** si le message n'est pas déjà parti |
| Mention | adaptative : relais Gmail, EmailJS, relais FormSubmit, serveur ou journal local |
| Synchronisation | la page se met à jour si une demande arrive depuis un autre onglet |

Les données ne quittent jamais le navigateur. `noindex, nofollow` est appliqué,
mais **cette page ne doit pas être publiée** sur le site en production.

---

## 8. Fichiers

| Fichier | Rôle | Publier ? |
|---|---|---|
| `lead-*/index.html` | formulaire + moteur JS, `provider: 'gas'` (config en tête de script) | oui |
| `boite-de-reception.html` | boîte de réception locale des demandes | **non** |
| `production-gas/` | relais Gmail Apps Script + procédure d'installation | non |
| `production-php/` | relais e-mail facultatif pour un serveur PHP (§ 9) | seulement si utilisé |
| `FORMULAIRE-LEAD.md` | ce document | non |

---

## 9. Option : relais PHP (si un serveur est disponible)

`production-php/lead-form-mail.php` + `lead-form-config.php` envoient réellement
les deux e-mails internes (fonction `mail()`, SMTP, `wp_mail()` ou `log`), avec
journalisation JSONL, purge RGPD (`--purge=180`) et diagnostic (`--doctor`).
L'expéditeur y est déjà `pbenateau@hellolead.pro` ; renseigner le transport
SMTP de la boîte et vérifier SPF/DKIM du domaine avant d'activer.

Activation : copier les deux fichiers à la racine du site (PHP 7.4+), vérifier
les adresses, `enabled => true`, puis dans la page `provider: 'endpoint'` et
`endpoint: '/lead-form-mail.php'`.

Sans serveur PHP, **ne pas l'utiliser** : c'est ce que le relais Apps Script
remplace.

---

## 10. Mise en production — liste de contrôle

- [x] `provider: 'gas'`, `fallbackProvider: 'formsubmit'` dans les 4 pages.
- [x] `responsable` = cremy@hellolead.pro, `chef` = pbenateau@hellolead.pro,
      `contactEmail` = pbenateau@hellolead.pro.
- [x] Projet Apps Script créé **depuis `ifeminformatique@gmail.com`**.
- [x] `TOKEN` remplacé par un jeton long et aléatoire.
- [x] Application web déployée (*moi* / *Tout le monde*), URL `/exec` publiée.
- [x] `gas.url` et `gas.token` renseignés **dans les 4 pages** (dépôt).
- [ ] Pages **importées dans WordPress** avec ces deux valeurs (mêmes lignes).
- [ ] Une demande test produit bien **3 e-mails** vers cremy@hellolead.pro,
      pbenateau@hellolead.pro et le prospect, et « Répondre » écrit au prospect.
- [ ] `FROM_ADDRESS` laissé vide, ou renseigné avec un alias Gmail vérifié
      (§ 3 bis) — redéployer une nouvelle version dans ce second cas.
- [ ] Repli FormSubmit activé une fois (lien d'activation reçu par e-mail).
- [ ] Script mis à jour et **réautorisé** (Sheets + Drive), nouvelle version déployée.
- [ ] `testFeuille` a créé la feuille et l'invitation Google a été acceptée par
      `manajahelvin@gmail.com`.
- [ ] Une demande test apparaît bien dans la feuille (une ligne, colonnes remplies).
- [ ] Purge RGPD programmée (`purgerLeadsAnciens`, 180 jours).
- [ ] `boite-de-reception.html` **non publiée**.
- [ ] Politique de confidentialité à jour (Gmail/Google, finalité, durée).
- [ ] `robots.txt` / `noindex` conservés sur la boîte locale.
