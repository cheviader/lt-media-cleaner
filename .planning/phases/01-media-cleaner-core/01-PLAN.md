---
wave: 1
depends_on: []
files_modified:
  - lt-media-cleaner.php
  - includes/class-lt-media-cleaner-cli.php
  - includes/class-lt-media-cleaner-backup.php
  - includes/class-lt-media-cleaner-processor.php
  - includes/class-lt-media-cleaner-s3.php
  - includes/class-lt-media-cleaner-audit.php
autonomous: true
---

# Phase 1: Media Cleaner Core - Plan

## Tasks

<task>
<read_first>
- `lt-media-cleaner.php`
</read_first>
<action>
Créer le fichier principal `lt-media-cleaner.php`.
1. Ajouter les en-têtes de plugin WordPress: Plugin Name: LT Media Cleaner, Version: 0.1.0.
2. Ajouter la protection `defined( 'ABSPATH' ) || exit;`.
3. Définir les constantes `LT_MC_VERSION` et `LT_MC_DIR_PATH`.
4. Mettre en place la vérification des dépendances (WooCommerce pour Action Scheduler, et Advanced Media Offloader). Si manquants, afficher une `admin_notice` et stopper l'exécution du plugin.
5. Créer la structure de dossiers `includes/` et instancier les classes nécessaires via une classe principale (Singleton `LT_Media_Cleaner`).
</action>
<acceptance_criteria>
- `lt-media-cleaner.php` contains `Plugin Name: LT Media Cleaner`
- `lt-media-cleaner.php` contains `Version: 0.1.0`
- `lt-media-cleaner.php` contains `LT_MC_VERSION`
- `lt-media-cleaner.php` contains `admin_notice` hook
</acceptance_criteria>
</task>

<task>
<read_first>
- `includes/class-lt-media-cleaner-cli.php`
</read_first>
<action>
Créer la classe `LT_Media_Cleaner_CLI` pour gérer la commande `wp media-cleaner process`.
1. Déclarer la commande `WP_CLI::add_command( 'media-cleaner process', ... )`.
2. Gérer l'argument `--period`. Si `YYYY`, créer 12 actions (janvier à décembre) dans l'Action Scheduler vers le hook `lt_mc_process_month`.
3. Si `YYYY-MM`, créer une action unique dans l'Action Scheduler vers le hook `lt_mc_process_month`.
4. Utiliser `as_enqueue_async_action( 'lt_mc_process_month', [ 'year' => $year, 'month' => $month ] )`.
</action>
<acceptance_criteria>
- `includes/class-lt-media-cleaner-cli.php` contains `WP_CLI::add_command`
- `includes/class-lt-media-cleaner-cli.php` contains `as_enqueue_async_action`
- `includes/class-lt-media-cleaner-cli.php` contains `'media-cleaner process'`
</acceptance_criteria>
</task>

<task>
<read_first>
- `includes/class-lt-media-cleaner-backup.php`
</read_first>
<action>
Créer la classe `LT_Media_Cleaner_Backup`.
1. S'accrocher à l'action `lt_mc_process_month`.
2. Fonctionnalité : Prendre les variables `$year` et `$month` et trouver le dossier `wp-content/uploads/YYYY/MM`.
3. IMPORTANT : Définir `set_time_limit(0)` et `wp_raise_memory_limit('image')` pour éviter le timeout lors de la compression de gros dossiers.
4. Utiliser `ZipArchive` pour compresser ce dossier entier.
5. Sauvegarder le ZIP dans `wp-content/uploads/lt-media-backups/YYYY-MM.zip`.
6. Si succès, planifier les sous-actions individuelles de traitement d'images pour ce mois (`lt_mc_process_image` pour chaque ID d'attachement du mois) via Action Scheduler en lots.
7. Planifier à la suite des lots une tâche finale globale : `lt_mc_audit_month_posts` via Action Scheduler.
</action>
<acceptance_criteria>
- `includes/class-lt-media-cleaner-backup.php` contains `ZipArchive`
- `includes/class-lt-media-cleaner-backup.php` contains `set_time_limit(0)`
- `includes/class-lt-media-cleaner-backup.php` contains `lt_mc_audit_month_posts`
</acceptance_criteria>
</task>

<task>
<read_first>
- `includes/class-lt-media-cleaner-processor.php`
</read_first>
<action>
Créer la classe `LT_Media_Cleaner_Processor`.
1. Gérer le hook `lt_mc_process_image` (reçoit l'ID de l'image).
2. Récupérer le chemin original de l'image. Supprimer physiquement toutes les tailles intermédiaires générées (thumbnails) associées à cet ID.
3. Si l'image fait plus de 800px de large, la redimensionner à 800px max (via `wp_get_image_editor`).
4. Convertir l'image originale modifiée en WebP (via `wp_get_image_editor` en forçant l'extension et le type mime).
5. Écraser l'entrée originale dans la base de données (mettre à jour le `post_mime_type` à `image/webp` et le chemin).
6. Déclencher un hook `lt_mc_image_ready_for_s3` avec le nouvel ID (ou chemin) pour offloading.
</action>
<acceptance_criteria>
- `includes/class-lt-media-cleaner-processor.php` contains `wp_get_image_editor`
- `includes/class-lt-media-cleaner-processor.php` contains `image/webp`
- `includes/class-lt-media-cleaner-processor.php` contains `post_mime_type`
</acceptance_criteria>
</task>

<task>
<read_first>
- `includes/class-lt-media-cleaner-s3.php`
</read_first>
<action>
Créer la classe `LT_Media_Cleaner_S3`.
1. Gérer le hook `lt_mc_image_ready_for_s3`.
2. Interfacer avec le plugin `advanced-media-offloader`. S'il n'offre pas d'API simple pour un remplacement rétroactif en base, uploader le fichier sur S3 (via ses méthodes existantes ou les fonctions AWS PHP SDK s'il l'inclut).
3. Remplacer physiquement les URLs locales (`/wp-content/uploads/YYYY/MM/image.webp`) par l'URL S3 dans la bibliothèque média, ET lancer une requête SQL globale sécurisée `wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s)", ... ) )` pour remplacer l'URL locale vers l'URL S3 dans le contenu des posts.
4. Planifier une vérification individuelle `as_enqueue_async_action('lt_mc_verify_s3_url')`.
</action>
<acceptance_criteria>
- `includes/class-lt-media-cleaner-s3.php` contains `UPDATE`
- `includes/class-lt-media-cleaner-s3.php` contains `REPLACE`
- `includes/class-lt-media-cleaner-s3.php` contains `as_enqueue_async_action('lt_mc_verify_s3_url')`
</acceptance_criteria>
</task>

<task>
<read_first>
- `includes/class-lt-media-cleaner-audit.php`
</read_first>
<action>
Créer la classe `LT_Media_Cleaner_Audit`.
1. Gérer le hook `lt_mc_verify_s3_url` (audit de chaque image individuellement). Faire un test HTTP HEAD via `wp_remote_head( $s3_url )`.
2. Si la réponse est `200 OK`, l'image est valide. Si échoué, logger l'erreur.
3. Gérer le hook `lt_mc_audit_month_posts` (audit global du mois planifié après toutes les images de ce mois).
4. Cette fonction vérifie les articles (posts) du mois donné, parse leur `post_content` avec une regex, et génère une alerte s'il reste des URLs locales vers `/wp-content/uploads/YYYY/MM/`.
</action>
<acceptance_criteria>
- `includes/class-lt-media-cleaner-audit.php` contains `wp_remote_head`
- `includes/class-lt-media-cleaner-audit.php` contains `200`
- `includes/class-lt-media-cleaner-audit.php` contains `lt_mc_audit_month_posts`
</acceptance_criteria>
</task>

## Verification
- Lancer la commande `wp media-cleaner process --period=2026-04`.
- Vérifier que le ZIP est bien créé dans `wp-content/uploads/lt-media-backups/2026-04.zip`.
- Vérifier que l'Action Scheduler contient bien les hooks en file d'attente.
- Assurer que l'audit mensuel (`lt_mc_audit_month_posts`) passe sans trouver d'URLs locales orphelines.
