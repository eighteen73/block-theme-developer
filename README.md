# Block Theme Developer

A developer-focused plugin for developing block themes for WordPress. This plugin provides essential tools for professional WordPress block theme developers to streamline pattern management and template synchronization.

## Features

### Part 1: Pattern Management

- **Custom Post Type**: `btd_pattern` for managing block patterns
- **React Sidebar**: Rich metadata editor in the block editor sidebar
- **Dual Mode Operation**:
  - **File Mode**: Saves patterns as `.php` files in your active theme's `/patterns` directory
  - **API Mode**: Provides REST API endpoints for pattern data retrieval
- **Rich Metadata Support**:
  - Description
  - Categories (WordPress core pattern categories)
  - Keywords
  - Viewport Width
  - Block Types
  - Post Types
  - Template Types
  - Inserter Visibility

### Part 2: Template Export (Coming Soon)

Template and template part export functionality will be implemented in a future update.

## Installation

1. Clone this repository into your WordPress plugins directory
2. Run `npm install` to install dependencies
3. Run `npm run build` to build the JavaScript assets
4. Activate the plugin in your WordPress admin

## Configuration

### BLOCK_THEME_DEVELOPER_MODE Constant

The plugin behavior is controlled by the `BLOCK_THEME_DEVELOPER_MODE` constant:

- **file**: Saves patterns as PHP files in your active theme
- **api**: Provides REST API access to pattern data

#### Setting the Mode

**Option 1: wp-config.php (Recommended)**
```php
define( 'BLOCK_THEME_DEVELOPER_MODE', 'file' );
```

**Option 2: Automatic Detection**
If not defined, the plugin will automatically set:
- `file` mode for `development` or `local` environments
- `api` mode for all other environments

## Usage

### Creating Patterns

1. Go to **Patterns** in your WordPress admin
2. Click **Add New Pattern**
3. Create your pattern content using the block editor
4. Fill in the pattern metadata in the **Pattern Metadata** panel in the sidebar:
   - Description
   - Categories
   - Keywords
   - Block Types
   - Post Types
   - Template Types
   - Viewport Width
   - Inserter visibility

### Importing Existing Theme Patterns

When working with existing themes that have pattern files, you can import them into the database for editing:

1. Go to **Patterns > Import Patterns** in your admin menu
2. Select which pattern files you want to import
3. Click **Import Selected Patterns**
4. Edit the imported patterns using the rich interface
5. Your changes will automatically update the theme files when you save

**Auto-Import**: In development environments, the plugin automatically imports any existing theme patterns when first activated.

## Development Workflow

This plugin supports a hybrid development workflow that's perfect for professional theme development:

### **Typical Workflow**

1. **Development Environment**:
   - Install plugin as dev dependency
   - Import existing theme patterns (auto-imports on activation)
   - Edit patterns with rich UI and metadata
   - Export to theme files automatically on save

2. **Production Environment**:
   - Deploy theme files (no plugin needed)
   - Patterns work natively in WordPress
   - No database records or plugin dependencies

3. **Pull from Production**:
   - Download production site
   - Activate plugin locally
   - Auto-import existing theme patterns for editing
   - Continue development with full editing capability

### **Benefits**

- **Development**: Rich editing interface with metadata management
- **Production**: Clean, lightweight theme files only
- **Version Control**: Pattern files are easily tracked in Git
- **Collaboration**: Team members can edit patterns visually
- **Deployment**: No plugin dependencies in production

### File Mode

When in file mode, patterns are automatically saved to your active theme's `/patterns` directory as PHP files with proper WordPress pattern headers.

Example generated file:
```php
<?php
/**
 * Title: My Awesome Pattern
 * Description: A simple paragraph pattern for greetings
 * Categories: hero, text
 * Keywords: greeting, hello
 * Viewport Width: 1200
 * Block Types: core/paragraph
 * Post Types: post, page
 * Template Types: author, 404
 * Inserter: yes
 */
?>
<p>Hello world.</p>
```

### API Mode

When in API mode, access pattern data via REST API with WordPress Application Passwords authentication:

**Get all patterns:**
```
GET /wp-json/btd/v1/patterns
```

**Get authentication information:**
```
GET /wp-json/btd/v1/auth-info
```

#### Setting up Application Passwords

1. Go to **Users > Profile** in your WordPress admin
2. Scroll down to the **"Application Passwords"** section
3. Enter a name for your application (e.g., "Client Site")
4. Click **"Add New Application Password"**
5. Copy the generated username and password
6. Use these credentials for HTTP Basic Authentication

**Important:** The user must have the `btd_api_access` capability to access the API. When in API mode, the plugin automatically:
- Creates the `btd_api_access` capability
- Creates an `API User` role with this capability
- Assigns the capability to administrators

You can assign this capability to other users or roles using a plugin like User Role Editor.

#### Example Usage

**cURL with Basic Auth:**
```bash
curl -u "username:password" \
  https://yoursite.com/wp-json/btd/v1/patterns
```

**JavaScript with fetch:**
```javascript
const response = await fetch('/wp-json/btd/v1/patterns', {
  headers: {
    'Authorization': 'Basic ' + btoa('username:password')
  }
});
const patterns = await response.json();
```

**Response format:**
```json
{
  "id": 123,
  "title": "My Awesome Pattern",
  "content": "<p>Hello world.</p>",
  "description": "A simple paragraph pattern for greetings",
  "categories": ["hero", "text"],
  "keywords": ["greeting", "hello"],
  "viewportWidth": 1200,
  "blockTypes": ["core/paragraph"],
  "postTypes": ["post", "page"],
  "templateTypes": ["author", "404"],
  "inserter": true
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

Run PHP CodeSniffer:
```bash
composer test
```

## Requirements

- WordPress 6.2+
- PHP 8.3+
- Node.js 22+ (for development)

## License

GPL-2.0-or-later
