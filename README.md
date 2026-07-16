# Block Theme Developer

A developer-focused plugin for developing block themes for WordPress. Essential tools for pattern authoring (via the native Site Editor) and template export.

## Features

### Part 1: Pattern Management

- **Native Patterns UI**: Create and edit patterns in **Appearance → Editor → Patterns** (`wp_block`)
- **Pattern Details sidebar**: Description, keywords, block/post/template types, viewport, inserter, content-only lock, synced toggle
- **Dual Mode Operation**:
  - **File Mode** (local/dev): Every published pattern save writes `patterns/{slug}.php` in the active stylesheet theme
  - **API Mode** (pattern library): Patterns stay as `wp_block` posts; exposed via `btd/v1` for other sites
- **Synced patterns (Orbit)**: With **Synced theme pattern** on, the file includes `Synced: true`, the `wp_block` is kept, and Orbit syncs layout updates to every instance
- **Unsynced theme patterns**: Synced off → file written without `Synced` (DB pattern is kept while editing so the Site Editor does not break)

### Part 2: Template Export

- **Automatic Template Export in File Mode**:
  - Exports `wp_template` changes to `/templates/{slug}.html`
  - Exports `wp_template_part` changes to `/parts/{slug}.html`
- Successful exports remove DB overrides so files remain the source of truth

## Installation

1. Clone this repository into your WordPress plugins directory
2. Run `npm install` to install dependencies
3. Run `npm run build` to build the JavaScript assets
4. Activate the plugin in your WordPress admin

**Requires:** WordPress 7.0+, PHP 8.3+

## Configuration

### BLOCK_THEME_DEVELOPER_MODE Constant

- **file**: Export patterns/templates to the active theme (local development)
- **api**: Serve pattern data from `wp_block` via REST (pattern library sites)

#### Setting the Mode

**Option 1: wp-config.php (Recommended)**
```php
define( 'BLOCK_THEME_DEVELOPER_MODE', 'file' );
```

**Option 2: Automatic Detection**
If not defined:
- `file` mode for `development` or `local` environments
- `api` mode for all other environments

## Usage

### File mode — theme patterns

1. Open **Appearance → Editor → Patterns**
2. Create or edit a pattern
3. Use **Pattern Categories** (core) and **Pattern Details** (BTD) in the sidebar
4. Save (after the pattern has real block content — empty “Add pattern” creates are ignored):
   - **Synced** (Add Pattern modal or Pattern Details): writes `patterns/{slug}.php` with `Synced: true`, keeps the `wp_block` for Orbit
   - **Unsynced**: writes the theme file without `Synced`, keeps the `wp_block` open in the editor

BTD is not used on production in file mode. Published pattern saves with content export to the theme.

### API mode — pattern library

1. Create patterns in the Site Editor (they remain as `wp_block` posts)
2. Fill **Pattern Details** meta as needed
3. Other sites consume them via the REST API (see below)

No theme files are written in API mode.

### Exporting Templates and Template Parts

In **file mode**, saving templates from the Site Editor exports to the active stylesheet theme:

- Templates: `/templates/{slug}.html`
- Template parts: `/parts/{slug}.html`

When export succeeds, the plugin removes the database override.

Autosaves, revisions, and draft-like states are skipped. Export requires `edit_theme_options`.

## Development Workflow

### Typical file-mode workflow

1. Install BTD as a local/dev dependency (`file` mode)
2. Author patterns and templates in the Site Editor
3. Theme PHP/HTML files update on save — commit them to Git
4. Production runs the theme (and Orbit for synced patterns) without BTD

### Typical API-mode workflow

1. Run a pattern library site with BTD in `api` mode
2. Author patterns in admin as `wp_block` posts
3. Client sites pull `GET /wp-json/btd/v1/patterns` with Application Passwords

### File Mode example

```php
<?php
/**
 * Title: My Awesome Pattern
 * Slug: my-theme/my-awesome-pattern
 * Description: A simple paragraph pattern for greetings
 * Categories: hero, text
 * Keywords: greeting, hello
 * Viewport Width: 1200
 * Block Types: core/paragraph
 * Post Types: post, page
 * Template Types: author, 404
 * Inserter: true
 * Synced: true
 *
 * @package MyTheme
 */
?>
<p>Hello world.</p>
```

### API Mode

**Get all patterns:**
```
GET /wp-json/btd/v1/patterns
```

**Get authentication information:**
```
GET /wp-json/btd/v1/auth-info
```

Patterns are loaded from published `wp_block` posts (not a custom post type). Categories prefer the `wp_pattern_category` taxonomy, with `_btd_categories` as fallback.

#### Setting up Application Passwords

1. Go to **Users > Profile**
2. Scroll to **Application Passwords**
3. Create a password for your client application
4. Use HTTP Basic Auth; the user needs the `btd_api_access` capability

In API mode the plugin creates the `btd_api_access` capability, an `API User` role, and grants the capability to administrators.

**cURL:**
```bash
curl -u "username:password" \
  https://yoursite.com/wp-json/btd/v1/patterns
```

**Response format:**
```json
{
  "id": 123,
  "name": "my-awesome-pattern",
  "title": "My Awesome Pattern",
  "content": "<!-- wp:paragraph --><p>Hello world.</p><!-- /wp:paragraph -->",
  "description": "A simple paragraph pattern for greetings",
  "categories": ["hero", "text"],
  "keywords": ["greeting", "hello"],
  "viewportWidth": 1200,
  "blockTypes": ["core/paragraph"],
  "postTypes": ["post", "page"],
  "templateTypes": ["author", "404"],
  "inserter": true,
  "lastUpdated": "2026-07-16 12:00:00",
  "created": "2026-07-16 10:00:00"
}
```

## Development

### Build Process

- `npm run start` - Start development build with file watching
- `npm run build` - Build production assets
- `npm run lint:js` - Lint JavaScript files
- `npm run lint:css` - Lint CSS files
- `npm run format` - Format code with Prettier

### Coding Standards

This plugin follows the [eighteen73 WordPress Coding Standards](https://github.com/eighteen73/wordpress-coding-standards).
