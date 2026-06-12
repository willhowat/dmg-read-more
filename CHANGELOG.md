# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-06-12

### Added

- Environment-aware search strategy — the CLI automatically uses the dedicated index table on standard WordPress and VIP Search (via `WP_Query`) on WordPress VIP environments; detected via the `WPCOM_IS_VIP_ENV` constant
- `--network` flag on `migrate`, `backfill`, `sync`, `search`, and `audit` — iterates all sites on a multisite install using `switch_to_blog`, prefixing `search` output with `site_id:` to avoid ambiguity across the network
- `wp dmg-read-more audit` — reports the number of published posts containing the block; on `--network` outputs a per-site table
- `wp dmg-read-more remove` — removes all instances of the block from post content using `parse_blocks`/`serialize_block`; supports `--dry-run`, `--network`, and `--yes`
- `wp dmg-read-more replace <new-block>` — replaces all instances of the block with a named target block, preserving attributes; supports `--dry-run`, `--network`, and `--yes`

### Changed

- `migrate`, `backfill`, and `sync` exit cleanly with an informational message on VIP environments where the index table is not required

## [1.0.0] - 2026-05-30

### Added

- `dmg/read-more` Gutenberg block — dynamic block that renders a styled Read More link to any published post
- Sidebar inspector panel with debounced keyword search, direct post ID lookup, and paginated results
- WYSIWYG canvas preview matching frontend output; warning notice when the block links to the post it appears in
- PHP render callback outputting `<p class="dmg-read-more">` (with block-support attributes via `get_block_wrapper_attributes()`) containing an anchor with `<span class="dmg-read-more__prefix">` and `<span class="dmg-read-more__title">` children
- WP-CLI command group `wp dmg-read-more` with four subcommands:
  - `migrate` — creates the `{prefix}dmg_read_more_index` table
  - `backfill` — seeds the index from existing published posts
  - `sync` — reconciles the index after a period of plugin deactivation
  - `search` — queries the index for posts containing the block, with optional `--date-after`, `--date-before`, `--post-type`, and `--format` (`ids` or `count`) flags
- Dedicated index table for O(matching posts) search performance at scale; maintained automatically via `save_post` and `deleted_post` hooks
- `dmg_read_more_allowed_in_post_types` filter — restricts which post types the block can be inserted into
- `dmg_read_more_post_types` filter — restricts which post types appear in the block's link target search results
- `dmg_read_more_prefix` filter — overrides the "Read More:" label on the frontend and in the editor preview
- Uninstall routine that drops the index table on plugin deletion
