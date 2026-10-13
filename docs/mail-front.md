# Intégration mail Hotline

Base API : `https://www.chifaa.sn/hotline/api`.
Toutes les routes exigent `Authorization: Bearer <token>` et `Accept: application/json`.

## Prompt à donner au front

Adapter la fenêtre « Nouveau message » existante, en conservant son style bleu/blanc, ses champs De, À, Cc, Cci, Objet, Message et son envoi en texte brut.

- De est en lecture seule : afficher `user.hotline.email`. Le serveur choisit lui-même l’expéditeur ; ne pas envoyer de champ from.
- Ajouter sous Message un bouton accessible avec une icône trombone et le libellé « Joindre des fichiers ». Permettre la sélection multiple et le glisser-déposer. Afficher chaque fichier dans une carte compacte : icône adaptée au type, nom, taille, bouton de suppression avec aria-label. Afficher une miniature pour les images locales, avec nettoyage des object URLs.
- Limites : 10 fichiers, 10 Mio par fichier, 20 Mio au total. Formats : jpg, jpeg, png, gif, webp, bmp, tif, tiff, pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv, odt, ods, odp, zip. Valider avant l’envoi et afficher les erreurs serveur 422 à proximité des champs. Les limites du fournisseur SMTP peuvent être inférieures.
- Découper À/Cc/Cci sur virgule ou point-virgule, nettoyer les espaces et les valeurs vides, valider les adresses. Maximum 50 destinataires au total. À, Objet et Message sont obligatoires.
- Envoyer `POST /emails/send` avec FormData : `to[]`, `cc[]`, `bcc[]`, `objet`, `contenu`, `attachments[]`. Ne pas fixer Content-Type : le navigateur ajoute la boundary multipart. Ne pas encoder les fichiers en base64. Omettre les tableaux facultatifs vides.
- Désactiver Envoyer pendant la requête, afficher un indicateur de chargement. Fermer et vider le formulaire uniquement après succès HTTP 200. Préserver le brouillon en cas d’erreur. Gérer 401 (session), 403 (hotline), 413 (taille HTTP), 422 (validation ou SMTP non configuré), 429 (limite d’envoi) et 502 (envoi non confirmé). Ne pas réessayer automatiquement un envoi 502 : il peut déjà avoir été accepté.
- Dans le détail d’un mail reçu, afficher `attachments { id name mime_type size }`, disponible via GraphQL et dans la réponse de synchronisation, ou charger `GET /emails/{emailId}/attachments` (réponse `{data: [...]}`). Ajouter des cartes avec icônes image/document/tableur/PDF et bouton Télécharger.
- Télécharger via fetch authentifié `GET /attachments/{id}/download`, vérifier response.ok, convertir en Blob puis déclencher le téléchargement avec le nom reçu dans les métadonnées. Révoquer l’object URL. Ne pas utiliser de lien public ni placer le token dans l’URL. Les fichiers reçus sont téléchargés, sans rendu HTML ni exécution dans le navigateur.
- Dans le formulaire administrateur de hotline existant, ajouter smtp_host, smtp_port (587 par défaut), smtp_encryption (tls ou ssl), smtp_username, smtp_password. Utiliser les routes admin existantes POST/PUT /hotlines. Les identifiants SMTP vides utilisent ceux de la boîte IMAP ; smtp_password vide à la modification conserve le mot de passe enregistré. smtp_host est nécessaire à l’envoi. Les mots de passe ne sont jamais renvoyés par l’API.

## Exemple d’envoi

```js
const splitAddresses = value => value.split(/[,;]/).map(s => s.trim()).filter(Boolean);
const payload = new FormData();
for (const address of splitAddresses(to)) payload.append('to[]', address);
for (const address of splitAddresses(cc)) payload.append('cc[]', address);
for (const address of splitAddresses(bcc)) payload.append('bcc[]', address);
payload.append('objet', subject);
payload.append('contenu', message);
for (const file of files) payload.append('attachments[]', file);

const response = await fetch('https://www.chifaa.sn/hotline/api/emails/send', {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  body: payload,
});
const result = await response.json();
if (!response.ok) throw result; // Mapper les erreurs aux champs dans l’interface.
```

## Réception

`POST /synchro_mails` conserve son payload actuel (aucun fichier à envoyer).
La synchronisation récupère toujours les cinq derniers messages, désormais avec leurs pièces jointes. Relancer la synchronisation pour importer les fichiers de ces messages déjà présents. Les mails plus anciens ne sont pas repris automatiquement.

```graphql
{ emails { id objet contenu attachments { id name mime_type size } } }
```

Le stockage est privé, avec contrôle de la hotline active pour les téléchargements. L’envoi SMTP n’ajoute pas de copie dans le dossier IMAP « Envoyés » et ne crée pas de mail reçu en base. Le succès indique une acceptation par SMTP, pas une garantie de livraison finale.
