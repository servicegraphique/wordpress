# Formulaire de lead — fonctionnement 100 % JavaScript

Le formulaire des pages `/lead-assurances/`, `/lead-assurances-rcs/`,
`/lead-energies/` et `/lead-energies-rcs/` fonctionne **sans serveur, sans PHP
et sans back-end**. Toute la logique est dans le JavaScript de la page.

```
Visiteur ──► formulaire (2 étapes, validation, accessibilité)
                 │
                 ▼
      window.HL_LEAD_CONFIG.provider
                 │
      ┌──────────┼─────────────┬──────────────┐
      │          │             │              │
   'local'  'formsubmit'  'web3forms'    'endpoint'
  (défaut)  relais e-mail relais e-mail  POST JSON
  stockage   sans serveur  sans serveur  (facultatif)
  navigateur
      │
      ▼
/boite-de-reception.html  →  les 2 e-mails générés, consultables
```

---

## 1. Tester en local (aucune installation)

```bash
cd /chemin/vers/le/site
python3 -m http.server 8000
```

Puis ouvrir :

| Page | Rôle |
|---|---|
| <http://localhost:8000/lead-assurances/> | formulaire assurances |
| <http://localhost:8000/lead-energies/> | formulaire énergies |
| <http://localhost:8000/boite-de-reception.html> | demandes reçues + e-mails générés |

Le mode livré est **`formsubmit`** : une demande valide part réellement par
e-mail vers `manajahelvin@gmail.com`, avec `ifeminformatique@gmail.com` en
copie, sans aucun serveur.

1. la première soumission déclenche un e-mail d'activation de FormSubmit
   dans la boîte du responsable : **cliquer une fois sur le lien** ;
2. à chaque envoi, la demande part immédiatement (objet
   `[Lead <formulaire>] <société> — <volume> leads/mois`) ;
3. une **copie locale** est conservée : elle apparaît dans
   `/boite-de-reception.html` avec les deux messages générés, exportables en
   JSON/CSV — utile si un e-mail se perd ;
4. la confirmation destinée au prospect y est prête : le bouton
   « Envoyer la confirmation au client » l'ouvre dans la messagerie du poste.

Pour une démonstration sans aucun envoi, passer `provider: 'local'` : rien ne
part sur le réseau, tout reste dans le navigateur.

---

## 2. Les deux e-mails générés

| | Destinataires | Contenu |
|---|---|---|
| Notification | `manajahelvin@gmail.com` (À) · `ifeminformatique@gmail.com` (Copie) · `Répondre à` = le prospect | coordonnées, volume, besoins, formulaire, page d'origine, UTM, date, référence, encart « Action attendue » |
| Confirmation | le prospect (À) · `Répondre à` = `manajahelvin@gmail.com` | « une équipe s'en charge », récapitulatif (sans métadonnées internes), lien politique de confidentialité |

Ils sont produits par `buildEmails()` dans le script du formulaire, à partir
des mêmes gabarits que la production (HTML + version texte).

---

## 3. Changer de mode d'envoi

Tout se règle dans **un seul objet**, en haut du `<script>` de chaque page :

```js
window.HL_LEAD_CONFIG = {
  provider: 'formsubmit',
  contactEmail: 'manajahelvin@gmail.com',
  siteUrl: 'https://www.hellodevis.fr',
  inboxUrl: '/boite-de-reception.html',
  endpoint: '',
  formsubmit: { alias: '', email: 'manajahelvin@gmail.com' },
  web3forms: { accessKey: '' },
  form: {
    key: 'lead-assurances',
    label: 'Assurances pro',
    responsable: ['manajahelvin@gmail.com'],   // À
    chef: ['ifeminformatique@gmail.com']       // Copie
  }
};
```

### `formsubmit` — actif (vrai e-mail, sans serveur)

La demande part vers le `responsable`, le `chef` est en copie, et
`_replyto` porte l'adresse du prospect : répondre à l'e-mail écrit
directement au client. Première soumission = e-mail d'activation à confirmer.

Astuce : après activation, FormSubmit fournit un **alias** (chaîne aléatoire).
Le renseigner dans `formsubmit.alias` évite d'afficher l'adresse en clair dans
le code source de la page.

### `local` — pour les démonstrations

Rien ne part. Les demandes restent dans le navigateur et sont consultables
dans `/boite-de-reception.html`.

### `web3forms` — vrai e-mail sans serveur (clé API)

```js
provider: 'web3forms',
web3forms: { accessKey: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' }
```

Clé gratuite sur <https://web3forms.com>. Prévoir un transfert de la boîte
web3forms vers la boîte métier pour les copies.

### `endpoint` — votre propre récepteur

```js
provider: 'endpoint',
endpoint: 'https://mon-api.exemple.fr/lead'
```

POST JSON (même contrat que le relais PHP livré en option, § 6). Utile pour
un CRM, une fonction serverless (Cloudflare Workers, Netlify, Vercel) ou un
webhook interne.

> **Le mode `local` reste le seul qui ne dépend d'aucun service tiers.**
> Les trois autres ajoutent une dépendance externe : à choisir en connaissance
> de cause, et à documenter dans la politique de confidentialité.

---

## 4. Anti-spam (côté navigateur)

| Mécanisme | Effet |
|---|---|
| Piège `website` (champ caché) | rempli → succès simulé, **rien n'est enregistré** |
| Validation stricte | nom (nom + prénom), e-mail, téléphone FR/international, code postal, société, volume |
| Honeypot invisible | `aria-hidden`, `tabindex="-1"`, hors écran |
| Nettoyage | `.trim()`, minuscules sur l'e-mail, chiffres forcés sur le code postal et le téléphone |

Un formulaire sans serveur ne peut pas limiter le débit par IP : le relais
applique ses propres filtres, le piège reste la première barrière.

### Point RGPD

Avec `formsubmit`, les données du prospect transitent par un service tiers
(FormSubmit) avant d'arriver dans la boîte du responsable : à mentionner dans
la politique de confidentialité au titre des sous-traitants. Le mode
`endpoint` avec un relais maîtrisé (PHP ou serverless) évite ce tiers.

### Limite assumée du mode sans serveur

La **confirmation automatique au client** exige un envoi serveur : un
navigateur ne peut pas écrire au prospect depuis le domaine HelloLead. En
mode `formsubmit`, l'écran de succès reste donc honnête (« Votre demande a
bien été transmise à notre équipe. ») et la confirmation est préparée en un
clic depuis la boîte de réception locale.

Pour l'automatiser réellement : `production-php/` (PHP) ou un relais
serverless (Cloudflare Worker, Netlify/Vercel Function) qui reçoit le POST et
appelle un fournisseur d'e-mail transactionnel.

---

## 5. Accessibilité et états

- Select personnalisé complet (clavier : flèches, `Entrée`, `Échap`, `Home`/`End`).
- Erreurs reliées aux champs (`aria-invalid`, `aria-describedby`), focus déplacé
  vers le premier champ invalide.
- Bouton en état `aria-busy` + libellé « Envoi en cours… » pendant l'envoi.
- Écran de succès focalisé (`role="status"`), libellé d'étape « Demande envoyée ».
- Messages d'échec explicites : validation, indisponibilité, panne réseau, avec
  repli `mailto:` sur l'adresse de contact.
- `dataLayer.push('hl_lead_form_submit' | 'hl_lead_form_error')` pour GTM.

---

## 6. Boîte de réception locale (`boite-de-reception.html`)

| Fonction | Détail |
|---|---|
| Liste | demande la plus récente en tête, compteurs (total, mois, dernière réception) |
| Détail | coordonnées, volume, besoins, formulaires, page d'origine, référence |
| E-mails | les 2 messages rendus dans des `iframe` (hauteur ajustée au contenu) |
| Actions | supprimer une demande, tout effacer, exporter en JSON, exporter en CSV |
| Synchronisation | la page se met à jour si une demande arrive depuis un autre onglet |

Les données ne quittent jamais le navigateur. `noindex, nofollow` est appliqué,
mais **cette page ne doit pas être publiée** sur le site en production.

---

## 7. Fichiers

| Fichier | Rôle | Publier ? |
|---|---|---|
| `lead-*/index.html` | formulaire + moteur JS, `provider: 'formsubmit'` (config en tête de script) | oui |
| `boite-de-reception.html` | boîte de réception locale des demandes | **non** |
| `production-php/` | relais e-mail facultatif pour un serveur PHP (§ 8) | seulement si utilisé |
| `FORMULAIRE-LEAD.md` | ce document | non |

Le dossier `production-php/` est **optionnel** : rien dans les pages n'en dépend
aujourd'hui (`provider: 'formsubmit'`). Il est conservé pour le jour où un envoi
transactionnel côté serveur sera nécessaire.

---

## 8. Option : relais PHP (si un jour un vrai envoi serveur est requis)

`production-php/lead-form-mail.php` + `lead-form-config.php` envoient réellement
les deux e-mails (fonction `mail()`, SMTP, `wp_mail()` ou `log`), avec
journalisation JSONL, purge RGPD (`--purge=180`) et diagnostic (`--doctor`).

Activation :

1. copier les deux fichiers à la racine du site (PHP 7.4+) ;
2. renseigner les adresses dans `lead-form-config.php`, puis `enabled => true` ;
3. dans la page, passer `provider: 'endpoint'` et
   `endpoint: '/lead-form-mail.php'`.

Sans serveur PHP, **ne pas l'utiliser** : c'est précisément ce que le mode
`local` remplace.

---

## 9. Mise en production — liste de contrôle

- [x] `provider: 'formsubmit'` actif dans les 4 pages.
- [x] `responsable` = manajahelvin@gmail.com, `chef` = ifeminformatique@gmail.com.
- [ ] Formulaire **activé une fois** via le lien reçu par e-mail à la première soumission.
- [ ] `contactEmail` et `siteUrl` corrects.
- [ ] `boite-de-reception.html` **non publiée**.
- [ ] Un test réel produit bien 2 e-mails, et « Répondre » écrit au prospect.
- [ ] Politique de confidentialité à jour (finalité, durée, sous-traitant du relais).
- [ ] `robots.txt` / `noindex` conservés sur la boîte locale.
