# dmg-read-more

A WordPress plugin that provides a Gutenberg block and WP-CLI command for inserting a styled "Read More" link to any published post.

## Requirements

- WordPress 6.3+
- PHP 8.1+

## Installation

### Via Composer

This package is not published on Packagist. Add the GitHub repository as a VCS source in your project's `composer.json` first, then require the package as normal.

> If you fork this repository, update the `url` below to point at your fork.

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/willhowat/dmg-read-more"
        }
    ]
}
```

```bash
composer require dmg/read-more
```

### Manual

Download or clone this repository into `wp-content/plugins/dmg-read-more/`.

## Usage

### Block

Search for **DMG Read More** in the block inserter. Use the sidebar panel to search for and select any published post. The block renders a `<p class="dmg-read-more">` element containing an anchor.

#### Styling

The block comes with minimal styling to ensure a working out of the box experience. This is intentionally scoped with 0 specificity to allow for easy theme overriding as required.

#### Block supports

The block exposes the following supports, controllable via the editor's block settings panel or `theme.json`:

| Support    | Options                          |
| ---------- | -------------------------------- |
| Colour     | Text, background, link           |
| Typography | Font size                        |
| Spacing    | Padding, margin                  |
| Alignment  | Left, centre, right, wide, full  |

Theme authors can disable individual supports via `theme.json` to match their design system:

```json
{
    "version": 3,
    "settings": {
        "blocks": {
            "dmg/read-more": {
                "color": {
                    "background": false
                }
            }
        }
    }
}
```

### WP-CLI

The WP-CLI commands use a dedicated index table for O(matching posts) performance at scale. The table must be created and seeded before `search` and `audit` can be used.

On WordPress VIP environments (`WPCOM_IS_VIP_ENV`), `search` automatically uses VIP Search (Elasticsearch via `WP_Query`) instead of the index table. The `migrate`, `backfill`, and `sync` commands are not required on VIP and exit cleanly with an informational message.

#### First-time setup

```bash
wp dmg-read-more migrate   # create the index table
wp dmg-read-more backfill  # seed from existing posts
```

Once the index is in place it is maintained automatically as posts are saved or deleted.

#### Search

Find published posts containing the block within a date range:

```bash
# Last 30 days (default)
wp dmg-read-more search

# Specific date range (ISO 8601)
wp dmg-read-more search --date-after=2024-01-01 --date-before=2024-06-01

# Restrict to specific post types
wp dmg-read-more search --post-type=post,page

# Output only the count (useful for monitoring)
wp dmg-read-more search --format=count
```

Matching post IDs are written to STDOUT, one per line. `--format=count` outputs only the total as a bare number, suitable for piping.

#### Resyncing after deactivation

If the plugin is deactivated and posts are edited during that period, the index may become stale. Run `sync` to reconcile:

```bash
wp dmg-read-more sync
```

This removes entries for posts that no longer contain the block (or are no longer published) and adds entries for any posts that were missed.

#### Audit

Report how many published posts contain the block:

```bash
wp dmg-read-more audit
```

#### Deprecation commands

Remove the block entirely from all posts that contain it:

```bash
# Preview what would change
wp dmg-read-more remove --dry-run

# Remove (prompts for confirmation)
wp dmg-read-more remove
```

Replace the block with another block, preserving attributes:

```bash
# Preview what would change
wp dmg-read-more replace core/paragraph --dry-run

# Replace (prompts for confirmation)
wp dmg-read-more replace core/paragraph
```

#### Multisite

All commands accept a `--network` flag on multisite installs, which iterates over every site in the network:

```bash
wp dmg-read-more migrate  --network
wp dmg-read-more backfill --network
wp dmg-read-more sync     --network
wp dmg-read-more audit    --network   # outputs a per-site table
wp dmg-read-more search   --network   # prefixes IDs with site_id:
wp dmg-read-more remove   --network --yes
wp dmg-read-more replace core/paragraph --network --yes
```

Pass `--yes` alongside `--network` on destructive commands to skip the per-site confirmation prompt.

### Filters

**`dmg_read_more_allowed_in_post_types`** — restrict which post types the block can be inserted into. When this returns a non-empty array the block is hidden from the inserter on all other post types:

```php
add_filter( 'dmg_read_more_allowed_in_post_types', fn() => [ 'post', 'case_study' ] );
```

**`dmg_read_more_post_types`** — restrict which post types appear in the block's search results (the post being linked to). Available in PHP and JavaScript:

```php
add_filter( 'dmg_read_more_post_types', fn() => [ 'post' ] );
```

```js
wp.hooks.addFilter(
    'dmg.readMore.postTypes',
    'my-plugin/restrict-post-types',
    () => [ 'post' ]
);
```

**`dmg_read_more_prefix`** — override the "Read More:" label. Available in PHP (frontend) and JavaScript (editor preview):

```php
add_filter( 'dmg_read_more_prefix', fn() => 'Continue reading:' );
```

```js
wp.hooks.addFilter(
    'dmg_read_more_prefix',
    'my-plugin/custom-prefix',
    () => 'Continue reading:'
);
```

## Development

Node 24 and Composer are required.

```bash
npm install
npm run build       # production build → build/
npm run start       # development watch mode
npm run lint:js       # ESLint
npm run lint:js:fix   # ESLint auto-fix
npm run lint:css      # Stylelint
npm run lint:css:fix  # Stylelint auto-fix
npm run type-check    # TypeScript
npm run format        # Prettier
npm run make-pot      # Generate .pot translation file

composer install
composer lint       # PHPCS (WordPress Coding Standards)
composer lint:fix   # PHPCBF auto-fix
composer test       # PHPUnit
```

## Build output

The `build/` directory is committed to this repository so the plugin works when installed directly via Composer or manual download without a separate build step.

In a production distribution workflow this would instead be generated by a CI release pipeline on tag push, keeping the main branch free of generated files.

## Licence

GPL-2.0-or-later — see [LICENSE](LICENSE).
