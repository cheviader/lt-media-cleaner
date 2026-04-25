# Phase 1: Media Cleaner Core - Context

**Gathered:** 2026-04-25
**Status:** Ready for planning
**Source:** PRD from Chat

<domain>
## Phase Boundary

Création du plugin WordPress "LT Media Cleaner" avec les fonctionnalités suivantes :
- Commande WP-CLI `wp media-cleaner process --period=[YYYY-MM | YYYY]`.
- Sauvegarde locale automatique (ZIP) des dossiers mensuels (`wp-content/uploads/lt-media-backups/`).
- Traitement d'images via Action Scheduler (WooCommerce).
- Nettoyage des miniatures, redimensionnement de l'original à 800px (natif WordPress), conversion en WebP (natif WordPress), et écrasement de l'original.
- Offloading vers S3 via l'API de Advanced Media Offloader.
- Remplacement des URLs locales par les URLs S3 dans les posts et la Media Library.
- Audit de validation post-traitement.
</domain>

<decisions>
## Implementation Decisions

### CLI & Orchestration
- Utilisation de WP-CLI pour lancer le processus.
- Utilisation systématique de `Action Scheduler` (via la dépendance WooCommerce activée).
- Pas d'orchestrateur externe : traitement par lots en file d'attente.

### Backup (Safety First)
- Création d'un ZIP du dossier `/uploads/YYYY/MM` avant tout traitement.
- Stockage du ZIP dans `wp-content/uploads/lt-media-backups/`.
- Le traitement ne démarre que si le ZIP est validé.

### Traitement d'Images
- Utilisation de `WP_Image_Editor` natif de WordPress pour le redimensionnement et la conversion WebP.
- Remplacement strict du fichier original par le WebP 800px, mise à jour des métadonnées attachment.
- Suppression physique des anciennes miniatures générées (thumbnails).

### S3 Offloading
- Utilisation de `advanced-media-offloader` (récemment activé) pour transférer le fichier WebP vers S3.
- Mise à jour en base de données pour pointer vers S3.
- Remplacement des URLs dans `wp_posts` (post_content) pour les posts de la période traitée.

### Validation
- Audit systématique en fin de lot pour s'assurer que les URLs dans le contenu pointent vers S3 (HTTP 200).
</decisions>

<canonical_refs>
## Canonical References

No external specs — requirements fully captured in decisions above.
</canonical_refs>

<specifics>
## Specific Ideas
- Le redimensionnement est proportionnel, base: largeur 800px.
- La période passée en argument peut être une année (planifie 12 tâches) ou un mois (planifie le traitement immédiat du mois).
</specifics>

<deferred>
## Deferred Ideas
None — PRD covers phase scope.
</deferred>

---

*Phase: 01-media-cleaner-core*
*Context gathered: 2026-04-25 via PRD Express Path*
