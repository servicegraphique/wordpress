# Formulaire de lead — envoi automatique des demandes

Documentation d'exploitation du formulaire 2 étapes des pages
`/lead-assurances/`, `/lead-assurances-rcs/`, `/lead-energies/` et
`/lead-energies-rcs/`.

> ⚠️ **Ce fichier ne doit pas être publié sur le serveur web.**
> Il décrit l'infrastructure interne (dossiers de journaux, adresses, cron).

---

## 1. Ce qui se passe quand un visiteur valide le formulaire

```
Navigateur (WordPress / Elementor)
   │  POST /lead-form-mail.php           (JSON, 1 requête, rien d'autre à charger)
   ▼
lead-form-mail.php  ──► anti-spam (piège, délai, débit, doublon) ──► validation
   │
   ├─ e-mail 1 ─► responsable du département      (To)
   │              chef de département             (Cc)
   │              Reply-To = le prospect  ← il suffit de « Répondre »
   │
   └─ e-mail 2 ─► le prospect                     (To)
                  accusé de réception + « une équipe s'en charge »
                  Reply-To = l'adresse de contact (clé `reply_to`)
```

Trois garanties :

1. **Aucune donnée perdue** : si l'envoi échoue, le visiteur ne voit pas de
   faux « merci », il obtient un message d'erreur et une adresse de repli.
2. **Aucun e-mail perdu** : chaque tentative est tracée dans un journal JSONL
   (succès, refus, échec d'envoi).
3. **Aucune régression** : les pages restent du HTML statique dans un widget
   Elementor ; le formulaire fonctionne même si le JavaScript de la page
   n'est pas rechargé (repli `mailto:` documenté au § 9).

---

## 2. Fichiers livrés

| Fichier | Rôle | À publier ? |
|---|---|---|
| `lead-form-mail.php` | Endpoint d'envoi (autonome, sans dépendance) | **oui**, à la racine du site |
| `lead-form-config.php` | Adresses, transport, anti-spam, journaux | **oui**, à côté de l'endpoint |
| `tests/lead-form-mail.test.php` | 109 assertions, sans réseau | non |
| `FORMULAIRE-LEAD.md` | Ce document | non |
| `lead-*/index.html` | Les 4 pages : champ e-mail, consentement, états de succès/échec | oui (déjà en place) |

L'endpoint est **autonome** : il ne charge pas WordPress, n'utilise aucune
librairie, et fonctionne en PHP 7.4 comme en PHP 8.4 (sans `mbstring`,
`iconv` ni `intl` — utile sur les hébergements mutualisés).

---

## 3. Installation (10 minutes)

1. **Copier** `lead-form-mail.php` et `lead-form-config.php` à la racine du
   site, à côté de `index.php` (donc accessibles en
   `https://www.hellodevis.fr/lead-form-mail.php`).
2. **Droits** : `644` pour les deux fichiers. Le dossier `logs/` sera créé
   automatiquement à côté (droits `770`).
3. **Renseigner les adresses** dans `lead-form-config.php` (§ 4).
4. **Vérifier** la configuration depuis le terminal du serveur :

   ```bash
   php lead-form-mail.php --doctor
   ```

   Chaque ligne est marquée `OK`, `ATTENTION` ou `ERREUR`. Tant qu'il reste
   une `ERREUR`, ne pas activer le formulaire.
5. **Activer** : passer `'enabled' => false` à `true`.
6. **Tester** depuis le site : une demande de test doit produire deux e-mails.
   Vérifier aussi le journal : `logs/leads-AAAA-MM.jsonl`.

> Tant que `enabled` vaut `false`, l'endpoint répond `503` et le formulaire
> affiche « Le formulaire est momentanément indisponible » avec l'adresse de
> repli. C'est le comportement voulu : aucune demande n'est acceptée avant
> que la configuration soit validée.

---

## 4. Configuration (`lead-form-config.php`)

| Clé | Rôle |
|---|---|
| `enabled` | Interrupteur général. `false` → `503`. |
| `transport` | `mail` (fonction `mail()`), `smtp` (client natif), `wp_mail` (WordPress), `log` (recette : écrit des `.eml`, n'envoie rien). |
| `from` | Expéditeur. **Doit appartenir à un domaine autorisé à envoyer** (SPF/DKIM, § 6). |
| `reply_to` | Adresse affichée dans la confirmation (« répondez-nous ici »). |
| `site_url` | Base des liens (politique de confidentialité, pied de page). |
| `forms.<clé>.responsable` | Destinataire principal (`To`). Tableau ou chaîne `a@x, b@y`. |
| `forms.<clé>.chef` | Chef de département, en copie (`Cc`). |
| `forms.<clé>.besoins` | Liste blanche des besoins acceptés. Toute valeur hors liste est écartée. |
| `smtp` | Hôte, port, chiffrement, identifiants (uniquement si `transport = 'smtp'`). |
| `anti_spam` | Voir § 7. |
| `log_dir` | Dossier des journaux (par défaut `__DIR__ . '/logs'`). |

Les quatre formulaires (`lead-assurances`, `lead-assurances-rcs`,
`lead-energies`, `lead-energies-rcs`) et le bloc `default` sont déjà
pré-remplis avec les bonnes listes de besoins. **Seules les adresses
`@hellolead.fr` sont des exemples** : elles sont signalées `⚠️ à remplacer`
et le diagnostic `--doctor` les repère.

### Choisir le transport

| Situation | Transport conseillé |
|---|---|
| Hébergement mutualisé classique, `mail()` active | `mail` |
| Site WordPress avec un plugin SMTP déjà configuré | `wp_mail` |
| Boîte professionnelle (Google Workspace, OVH, Brevo…) | `smtp` |
| Recette / démonstration | `log` |

Pour `smtp`, utiliser un **mot de passe d'application** dédié et restreint à
l'envoi. Ne jamais committer d'identifiants : `lead-form-config.php` n'est pas
public, mais il est lisible par le serveur web — préférez une variable
d'environnement si l'hébergeur le permet (`getenv('SMTP_PASS')`).

---

## 5. Contrat technique de l'endpoint

- **Méthode** : `POST` uniquement (`405` sinon).
- **Corps** : JSON (`application/json`) ou formulaire
  (`application/x-www-form-urlencoded`), 32 Ko maximum (`413` au-delà).
- **Champs** : `formulaire`, `nom`, `email`, `telephone`, `code_postal`,
  `societe`, `volume_souhaite`, `besoins[]`, `page`, `lead_id`, `ts`,
  `website` (piège), `consent`, `utm{}`.

| Réponse | Signification | Ce que fait le formulaire |
|---|---|---|
| `200 {ok:true}` | Demandes envoyées | Affiche la confirmation |
| `200 {ok:true, spam:true}` | Piège rempli | Affiche la confirmation (sans révéler le filtre) |
| `400 {errors:{champ:message}}` | Validation serveur | Messages sous les champs concernés |
| `422 {code:'spam'}` | Remplissage trop rapide | « demande n'a pas pu être vérifiée » |
| `429` + `Retry-After` | Trop de demandes pour cette IP | Message d'attente |
| `500 {code:'delivery_failed'}` | L'envoi a échoué | Erreur + adresse de repli |
| `503 {code:'not_configured'}` | Formulaire désactivé | Indisponibilité + adresse de repli |

---

## 6. Délivrabilité (SPF, DKIM, DMARC)

Un e-mail transactionnel correctement formé peut quand même finir en « spam »
si le domaine n'est pas authentifié. À faire une fois, côté DNS :

**SPF** — autoriser le serveur qui envoie :

```
hellodevis.fr.      TXT  "v=spf1 include:_spf.<votre-hebergeur>.fr ~all"
```

**DKIM** — signer les messages. La plupart des hébergeurs activent un DKIM
dans le panneau de configuration ; récupérer la clé `TXT` et la publier sur
`<sélecteur>._domainkey.hellodevis.fr`.

**DMARC** — indiquer quoi faire en cas d'échec, en mode observation d'abord :

```
_dmarc.hellodevis.fr.  TXT  "v=DMARC1; p=none; rua=mailto:dmarc@hellodevis.fr; adkim=s; aspf=s"
```

Puis, après quelques semaines sans anomalie, durcir en `p=quarantine`.

Bonnes pratiques déjà appliquées par l'endpoint :

- `From` stable (`no-reply@…`) et **`Reply-To` qui pointe vers une boîte
  réellement lue** : c'est la première cause de perte de leads.
- `Date`, `Message-ID` uniques, `MIME-Version`, encodage `base64`,
  parties texte **et** HTML (`multipart/alternative`).
- Objet encodé en RFC 2047 (accents), replié sous 78 colonnes.
- La confirmation porte `Auto-Submitted: auto-generated` et
  `X-Auto-Response-Suppress: All` : les réponses d'absence ne bouclent pas.
- Pas de pièce jointe, pas d'image distante, pas de lien raccourci.

Contrôles recommandés après mise en ligne : envoyer une demande vers une
adresse Gmail et une adresse Outlook, vérifier `Afficher l'original` →
`SPF: pass`, `DKIM: pass`, `DMARC: pass`, et tester sur
<https://www.mail-tester.com> (viser 9/10 minimum).

---

## 7. Anti-spam

| Mécanisme | Réglage | Effet |
|---|---|---|
| Piège (honeypot) | champ `website`, invisible | Rempli → succès simulé, aucun e-mail |
| Délai de remplissage | `min_fill_seconds` = 1,5 s | Validation instantanée refusée (`422`) |
| Limitation par IP | 5 envois / 15 min, 20 / jour | `429` + `Retry-After` |
| Anti-doublon | `dedupe_seconds` = 600 s | Double clic / retry réseau = un seul e-mail |
| Liste blanche | `forms.*.besoins` | Aucune valeur arbitraire dans les e-mails |
| Nettoyage | balises, CR/LF, longueurs | Pas d'injection d'en-tête possible |

Ces filtres sont silencieux côté visiteur (sauf la limitation de débit) : un
robot ne reçoit jamais de signal lui permettant d'adapter sa stratégie.

Pas de reCAPTCHA : il dégrade l'accessibilité et le taux de conversion, et le
site en charge déjà un (utilisé par le thème). Si le spam revient malgré tout,
augmenter `rate_max`/`rate_day_max` **avant** d'ajouter un captcha.

---

## 8. Données personnelles et RGPD

**Base légale** : intérêt légitime (réponse à une demande commerciale
entrante) / mesures précontractuelles. La mention sous le bouton
« En envoyant ce formulaire, vous acceptez d'être recontacté… » et le lien
vers la politique de confidentialité sont en place sur les 4 pages.

**Minimisation** : seuls les champs utiles sont collectés (nom, e-mail,
téléphone, code postal, société, volume, besoins). Pas d'adresse IP dans les
e-mails, pas de données de santé, pas de commentaire libre non borné.

**Journaux** : l'adresse IP n'est **jamais stockée en clair**. Le journal
contient une empreinte `sha256` tronquée (`ip`), suffisante pour repérer un
abus et inutilisable pour réidentifier une personne.

**Durées de conservation** (à ajuster avec le client) :

- journaux techniques (`logs/`) : **180 jours** ;
- demandes commerciales : **3 ans** à compter du dernier contact (recommandation
  CNIL pour la prospection), la boîte du responsable faisant foi ;
- fichiers `.eml` de recette : supprimés à la mise en production.

**Purge automatique** — une tâche quotidienne suffit :

```cron
# tous les jours à 4 h, purge des journaux de plus de 180 jours
0 4 * * * php /home/<compte>/www/lead-form-mail.php --purge=180 >> /home/<compte>/logs/purge.log 2>&1
```

`--purge` fonctionne uniquement en ligne de commande : l'option n'est pas
exposée en HTTP.

**Droits des personnes** : les demandes d'accès ou de suppression arrivent
sur l'adresse de contact configurée (`reply_to`) ; elles se traitent dans
cette boîte et dans le journal (`lead_id` permet de retrouver l'entrée
exacte correspondant à une demande).

**Sous-traitants** : l'hébergeur du site et le fournisseur SMTP doivent être
couverts par un contrat de sous-traitance (article 28 du RGPD).

---

## 9. Journalisation, supervision, dépannage

### Journaux

| Fichier | Contenu |
|---|---|
| `logs/leads-AAAA-MM.jsonl` | Une ligne JSON par événement : `lead`, `refusé`, `spam`, `invalide`, `échec` |
| `logs/rate/<sha1>.json` | Compteurs de débit par IP (nom de fichier haché) |
| `logs/mail/*.eml` | Copies des messages, **uniquement** en transport `log` |

Le dossier est protégé par un `.htaccess` (`Require all denied`) et un
`index.html` vide, créés automatiquement. Sur Nginx, ajouter :

```nginx
location ^~ /logs/ { deny all; return 404; }
```

### Alertes utiles

- `grep '"event":"échec"' logs/leads-*.jsonl` → un e-mail n'est pas parti.
- `grep '"event":"spam"'` → attaque ou filtre trop strict (`min_fill_seconds`).
- `grep '"event":"refusé".*"rate limit"'` → limitation atteinte, vérifier s'il
  s'agit d'un vrai pic de trafic.

### Symptômes → causes

| Symptôme | Cause probable | Solution |
|---|---|---|
| Le formulaire dit « momentanément indisponible » | `enabled` = `false`, ou aucun responsable | `php lead-form-mail.php --doctor` |
| « demande n'a pas pu être vérifiée » | Formulaire validé en moins de 1,5 s | Normal pour un robot ; si humain, baisser `min_fill_seconds` |
| « Trop de demandes envoyées récemment » | `rate_max` atteint pour l'IP (bureau partagé, NAT) | Augmenter `rate_max` ou `rate_window` |
| Erreur 500 « l'envoi n'a pas abouti » | `mail()` refusée, SMTP bloqué, quota | Vérifier `logs/` et les logs serveur ; passer en `smtp` |
| E-mails reçus en spam | SPF/DKIM/DMARC absents ou mal alignés | § 6 |
| Le responsable ne reçoit rien, le client oui | Adresse du responsable invalide | `--doctor` marque `ERREUR` |
| Le client ne reçoit rien | Adresse saisie erronée, ou filtre côté client | Vérifier `logs/` (champ `email`), demander au prospect de regarder ses indésirables |
| La mention « Une confirmation vient d'être envoyée à … » n'apparaît pas | JavaScript bloqué / ancienne page en cache | Vider le cache de page (Elementor + CDN) |

---

## 10. Tests

### Côté serveur (aucun réseau requis)

```bash
php tests/lead-form-mail.test.php
```

109 assertions couvrent : configuration, validation, fabrication MIME,
encodage RFC 2047, SMTP (faux serveur scripté), refus de l'endpoint,
contenu des e-mails produits, anti-doublon, limitation de débit,
journalisation, purge RGPD, diagnostic et robustesse (injection, JSON
malformé, champs absents).

### Côté navigateur

Le parcours a été validé en recette sur les 4 pages, en bureau et en mobile
(390 px) : succès complet, erreurs locales, `400` avec erreurs par champ,
`429`, `503`, `500`, coupure réseau, piège anti-robot et formulaire non relié
(repli `mailto:`).

---

## 11. Liste de contrôle avant mise en production

- [ ] `lead-form-mail.php` et `lead-form-config.php` sont à la racine du site.
- [ ] Les 8 adresses (`responsable` + `chef` pour assurances et énergies) sont réelles.
- [ ] `from` utilise un domaine authentifié ; SPF, DKIM et DMARC sont publiés.
- [ ] `php lead-form-mail.php --doctor` ne renvoie aucune `ERREUR`.
- [ ] `enabled` vaut `true`.
- [ ] Le cron `--purge=180` est installé.
- [ ] Un test réel produit bien 2 e-mails, et « Répondre » écrit au prospect.
- [ ] `logs/` est inaccessible depuis le navigateur (`/logs/leads-2026-10.jsonl` → 403/404).
- [ ] Le dossier `logs/mail/` (copies de recette) est vide ou supprimé.
- [ ] La politique de confidentialité mentionne la finalité et la durée de conservation.

---

## 12. Évolutions possibles

- **CRM / webhook** : ajouter un cinquième transport qui poste le lead vers
  HubSpot, Pipedrive ou Slack (`hl_lf_send()` accepte un nouveau `case`).
- **Notification SMS** du responsable pour les volumes « Plus de 1000 ».
- **Statistiques** : les journaux JSONL sont directement exploitables
  (volume, besoins, pages d'origine, taux d'échec).
- **Relance automatique** du prospect à J+3 si le responsable n'a pas répondu
  (à faire côté CRM, pas dans cet endpoint).
