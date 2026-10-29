# Formulaire de lead — fonctionnement 100 % JavaScript

Le formulaire des pages `/lead-assurances/`, `/lead-assurances-rcs/`,
`/lead-energies/` et `/lead-energies-rcs/` fonctionne **sans serveur, sans PHP
et sans back-end**. Toute la logique est dans le JavaScript de la page.

```
Visiteur ──► formulaire (2 étapes, validation, accessibilité)
                 │
                 ▼
        window.HLLeadConfig.provider
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

En mode `local` (valeur par défaut) :

1. valider le formulaire affiche l'écran de succès habituel ;
2. la demande est enregistrée dans le **stockage du navigateur**
   (`localStorage`, clé `hl.leads.v1`) ;
3. elle apparaît immédiatement dans `/boite-de-reception.html`, avec **les
   deux e-mails réellement générés** : la notification destinée au
   responsable (chef de département en copie) et la confirmation du prospect ;
4. aucun octet ne part sur le réseau, aucun e-mail n'est envoyé.

C'est le mode à utiliser pour les démonstrations, les tests d'intégration et
la validation client : le rendu des e-mails est celui de la production.

---

## 2. Les deux e-mails générés

| | Destinataires | Contenu |
|---|---|---|
| Notification | `responsable` (À) · `chef` (Copie) · `Répondre à` = le prospect | coordonnées, volume, besoins, formulaire, page d'origine, UTM, date, référence, encart « Action attendue » |
| Confirmation | le prospect (À) · `Répondre à` = `contactEmail` | « une équipe s'en charge », récapitulatif (sans métadonnées internes), lien politique de confidentialité |

Ils sont produits par `buildEmails()` dans le script du formulaire, à partir
des mêmes gabarits que la production (HTML + version texte).

---

## 3. Changer de mode d'envoi

Tout se règle dans **un seul objet**, en haut du `<script>` de chaque page :

```js
window.HL_LEAD_CONFIG = {
  provider: 'local',
  contactEmail: 'contact@hellodevis.fr',
  siteUrl: 'https://www.hellodevis.fr',
  inboxUrl: '/boite-de-reception.html',
  endpoint: '',
  formsubmit: { email: '' },
  web3forms: { accessKey: '' },
  form: {
    key: 'lead-assurances',
    label: 'Assurances pro',
    responsable: ['responsable.assurances@hellodevis.fr'],
    chef: ['chef.departement.assurances@hellodevis.fr']
  }
};
```

### `local` — par défaut

Aucun réglage. Les demandes restent dans le navigateur. Idéal pour la recette ;
à ne pas laisser tel quel en production (les demandes ne sont pas transmises).

### `formsubmit` — vrai e-mail sans serveur

[FormSubmit](https://formsubmit.co) relaie le formulaire vers une boîte mail.

```js
provider: 'formsubmit',
formsubmit: { email: 'responsable.assurances@hellodevis.fr' }
```

La copie au chef de département est envoyée via le champ `_cc`. La première
soumission déclenche un e-mail d'activation à confirmer une seule fois.

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

Un formulaire sans serveur ne peut pas limiter le débit par IP : si le mode
`formsubmit`/`web3forms` est utilisé en production, ces services appliquent
leurs propres filtres, et le piège reste la première barrière.

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
| `lead-*/index.html` | formulaire + moteur JS (config en tête de script) | oui |
| `boite-de-reception.html` | boîte de réception locale des demandes | **non** |
| `production-php/` | relais e-mail facultatif pour un serveur PHP (§ 8) | seulement si utilisé |
| `FORMULAIRE-LEAD.md` | ce document | non |

Le dossier `production-php/` est **optionnel** : rien dans les pages n'en dépend
aujourd'hui (`provider: 'local'`). Il est conservé pour le jour où un envoi
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

- [ ] `provider` différent de `local` (sinon les demandes restent dans le navigateur).
- [ ] Adresses `responsable` / `chef` réelles dans les 4 pages.
- [ ] `contactEmail` et `siteUrl` corrects.
- [ ] `boite-de-reception.html` **non publiée**.
- [ ] Un test réel produit bien 2 e-mails, et « Répondre » écrit au prospect.
- [ ] Politique de confidentialité à jour (finalité, durée, sous-traitant du relais).
- [ ] `robots.txt` / `noindex` conservés sur la boîte locale.
