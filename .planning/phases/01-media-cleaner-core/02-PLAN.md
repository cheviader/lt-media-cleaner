---
description: "Implementation plan for Media Cleaner ETL architecture"
depends_on: []
files_modified:
  - lt-media-cleaner.php
  - includes/class-lt-media-cleaner-db.php
  - includes/class-lt-media-cleaner-cli.php
  - includes/class-lt-media-cleaner-processor.php
  - includes/class-lt-media-cleaner-importer.php
  - includes/class-lt-media-cleaner-audit.php
autonomous: false
---

# PLAN D'IMPLÉMENTATION GSD : LT Media Cleaner v1.1.0 (Architecture ETL)

## Wave 1: Foundation (Database & Init)

### Task 1.1: Create Database Schema Class
<read_first>
- lt-media-cleaner.php
</read_first>
<action>
Create `includes/class-lt-media-cleaner-db.php`. 
Define a static method `install()` using WordPress native `dbDelta()`.
Create SQL schema for `wp_lt_inventory_edito`:
- `id` bigint(20) NOT NULL AUTO_INCREMENT
- `post_id` bigint(20) NOT NULL
- `image_url` varchar(255) NOT NULL
- `is_featured` tinyint(1) NOT NULL DEFAULT 0
- `html_tag_raw` text NOT NULL
- `status` varchar(50) NOT NULL DEFAULT 'pending'
- PRIMARY KEY (`id`), KEY `post_id` (`post_id`)

Create SQL schema for `wp_lt_inventory_files`:
- `id` bigint(20) NOT NULL AUTO_INCREMENT
- `file_path` varchar(255) NOT NULL
- `is_original` tinyint(1) NOT NULL DEFAULT 0
- `status` varchar(50) NOT NULL DEFAULT 'pending'
- PRIMARY KEY (`id`), KEY `file_path` (`file_path`)
</action>
<acceptance_criteria>
- File `includes/class-lt-media-cleaner-db.php` exists and contains valid PHP.
- `dbDelta()` is called with correct SQL syntax (including `require_once ABSPATH . 'wp-admin/includes/upgrade.php';`).
</acceptance_criteria>

### Task 1.2: Register Activation Hook and Update Plugin Header
<read_first>
- lt-media-cleaner.php
- includes/class-lt-media-cleaner-db.php
</read_first>
<action>
In `lt-media-cleaner.php`:
- Update `LT_MC_VERSION` constant to `'1.1.0'`.
- Add `require_once LT_MC_DIR_PATH . 'includes/class-lt-media-cleaner-db.php';` in the `plugins_loaded` hook or globally.
- Register activation hook: `register_activation_hook( __FILE__, [ 'LT_Media_Cleaner_DB', 'install' ] );` to trigger table creation upon activation.
</action>
<acceptance_criteria>
- `lt-media-cleaner.php` contains `define( 'LT_MC_VERSION', '1.1.0' );`.
- `lt-media-cleaner.php` contains `register_activation_hook( __FILE__, [ 'LT_Media_Cleaner_DB', 'install' ] );`.
</acceptance_criteria>


## Wave 2: Command Line Interface (CLI)

### Task 2.1: Implement Inventory Commands (Edito & Files)
<read_first>
- includes/class-lt-media-cleaner-cli.php
</read_first>
<action>
In `includes/class-lt-media-cleaner-cli.php`:
- Remove old `process` command.
- Register new commands:
  - `WP_CLI::add_command( 'media-cleaner inventory-edito', [ __CLASS__, 'inventory_edito' ] );`
  - `WP_CLI::add_command( 'media-cleaner inventory-files', [ __CLASS__, 'inventory_files' ] );`
- Implement `inventory_edito($args, $assoc_args)`:
  - Query `wp_posts` using `$wpdb` for `post_type = 'post'` and `post_status = 'publish'`. Support `--limit`.
  - Delegate DOM parsing to `LT_Media_Cleaner_Audit::extract_images($post_content)`.
  - Insert results into `wp_lt_inventory_edito` via `$wpdb->insert`.
- Implement `inventory_files($args, $assoc_args)`:
  - Accept `--year` and `--month` arguments.
  - Use PHP `RecursiveDirectoryIterator` on `wp-content/uploads/{year}/{month}`.
  - Insert all files into `wp_lt_inventory_files` via `$wpdb->insert`.
</action>
<acceptance_criteria>
- `class-lt-media-cleaner-cli.php` exposes `inventory-edito` and `inventory-files` methods.
- Methods use `$wpdb->insert` properly with prepared formats.
</acceptance_criteria>

### Task 2.2: Implement Orchestration Commands (Sideload, Backup, Simulate, Process)
<read_first>
- includes/class-lt-media-cleaner-cli.php
</read_first>
<action>
In `includes/class-lt-media-cleaner-cli.php`:
- Register commands: `sideload`, `backup-vault`, `simulate`, `process`.
- Implement `sideload`: Query `wp_lt_inventory_edito` for remote URLs. Loop and enqueue Action Scheduler tasks: `as_enqueue_async_action('lt_mc_sideload_image', ['inventory_id' => $id]);`.
- Implement `backup-vault`: Accept `--year`. Use `ZipArchive` to ZIP the `wp-content/uploads/{year}` folder locally. Output success message.
- Implement `simulate`: Load `wp_lt_inventory_edito`, run regex dry-run, output report.
- Implement `process`: Accept `--year` and `--month`. Query `wp_lt_inventory_edito` and enqueue Action Scheduler tasks for each image: `as_enqueue_async_action('lt_mc_process_image', ['inventory_id' => $id]);`.
</action>
<acceptance_criteria>
- `sideload` enqueues `lt_mc_sideload_image`.
- `process` enqueues `lt_mc_process_image`.
- `backup-vault` uses `ZipArchive`.
</acceptance_criteria>


## Wave 3: Core Processors & Action Scheduler

### Task 3.1: DOM Extraction Logic
<read_first>
- includes/class-lt-media-cleaner-audit.php
</read_first>
<action>
In `LT_Media_Cleaner_Audit`:
- Create static method `extract_images($post_content)`.
- Use `DOMDocument` to load HTML (suppressing libxml errors with `libxml_use_internal_errors(true)`).
- Extract all `<img>` tags and their `src` attributes.
- Return an array of array `['url' => '...', 'html_tag_raw' => '...']`.
</action>
<acceptance_criteria>
- Method `extract_images` uses `DOMDocument` and returns an array of found images.
</acceptance_criteria>

### Task 3.2: Sideload & Process Hooks (with Cache Purge)
<read_first>
- includes/class-lt-media-cleaner-importer.php
- includes/class-lt-media-cleaner-processor.php
</read_first>
<action>
In `LT_Media_Cleaner_Importer`:
- Create `sideload_single($inventory_id)`.
- Query `wp_lt_inventory_edito` for URL.
- Use `download_url()` and `media_handle_sideload()`.
- Update the table and replace URL in `wp_posts.post_content`.
- **CRITICAL**: Call `clean_post_cache($post_id);`

In `LT_Media_Cleaner_Processor`:
- Create `process_single($inventory_id)`.
- Process local image (WebP, scale, junk purge, S3 offload).
- Update URL in `wp_posts.post_content`.
- **CRITICAL**: Call `clean_post_cache($post_id);`
</action>
<acceptance_criteria>
- `sideload_single` handles individual downloads.
- `process_single` handles WebP/S3 transformation.
- `clean_post_cache()` is called in both methods immediately after `$wpdb->update` on `wp_posts`.
</acceptance_criteria>
