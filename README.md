# PRC Related Posts

A WordPress plugin for the PRC Platform that provides editorial tools and customizable blocks for managing related content relationships. Features an intuitive editor interface for manual curation and dynamic blocks for displaying related posts across your site with flexible layout options.

## Features

- **Manual Related Posts Curation**: Editor sidebar panel for manually selecting and managing related posts
- **Related Posts Query Block**: Dynamic block that displays related posts with customizable layouts
- **Intelligent Fallback System**: Automatically finds related content based on primary taxonomy terms when manual posts aren't specified
- **Flexible Post Type Support**: Configurable support for different post types
- **Caching Integration**: Built-in caching support with VIP cache integration
- **Legacy Content Support**: Handles migration and compatibility for older content

## Requirements

- WordPress 6.7+
- PHP 8.2+
- PRC Platform Core plugin

## Installation

This plugin is part of the PRC Platform and should be installed alongside the core platform components.

## Usage

### Manual Related Posts

1. When editing a post, look for the "Related Posts" panel in the editor sidebar
2. Use the panel to search and select posts you want to feature as related content
3. Drag and drop to reorder the selected posts
4. The manually selected posts will take precedence over automatically generated ones

### Related Posts Query Block

1. Add the "Related Posts Query" block to your content
2. Configure the display options:
    - **Posts per page**: Number of related posts to show (default: 5)
    - **Orientation**: Vertical or horizontal layout
    - **Allowed blocks**: Specify which blocks can be used in the query loop

### Automatic Related Posts

When no manual related posts are specified, the plugin will automatically find related content by:

1. Looking for posts with the same primary taxonomy term (using Yoast SEO primary category)
2. Falling back to posts in the same category if primary term matching yields insufficient results
3. Prioritizing recent content and excluding the current post

### Supported Post Types

By default, the plugin works with the `post` post type. You can extend support to additional post types using the filter:

```php
add_filter( 'prc_platform__related_posts_enabled_post_types', function( $post_types ) {
    $post_types[] = 'custom-post-type';
    return $post_types;
});
```

### Programmatic Usage

You can retrieve related posts programmatically using the plugin's API:

```php
// Get related posts for a specific post
$plugin = new \PRC\Platform\Related_Posts\Plugin();
$related_posts = $plugin->process( $post_id, $args );
```

## Configuration

### Caching

The plugin includes built-in caching with the following defaults:

- **Cache key**: `relatedPosts`
- **Cache duration**: 1 hour
- **VIP cache integration**: Automatic cache purging on content updates

### Meta Fields

Related posts data is stored in the `relatedPosts` post meta field with the following schema:

```json
{
	"date": "string",
	"key": "string",
	"link": "string",
	"permalink": "string",
	"postId": "integer",
	"title": "string",
	"label": "string"
}
```

## Development

### File Structure

```
prc-related-posts/
├── prc-related-posts.php          # Main plugin file
├── includes/                      # Core PHP classes
│   ├── class-plugin.php          # Main plugin class
│   ├── class-api.php             # Related posts API
│   ├── class-loader.php          # Hook loader
│   ├── class-plugin-activator.php
│   ├── class-plugin-deactivator.php
│   ├── utils.php                 # Utility functions
│   └── inspector-sidebar-panel/   # Editor sidebar component
└── blocks/                       # Block components
    ├── class-blocks.php          # Blocks registration
    └── src/
        └── related-posts-query/   # Related Posts Query block
            ├── index.js
            ├── block.json
            └── class-related-posts-query.php
```

### Building Assets

Navigate to the blocks directory and run:

```bash
cd blocks/
npm install
npm run build
```

### Hooks and Filters

#### Actions

- `prc_platform_on_update` - Clears cache when posts are updated
- `wpcom_vip_cache_pre_execute_purges` - Handles VIP cache purging

#### Filters

- `prc_platform__related_posts_enabled_post_types` - Modify supported post types

## Legacy Content Handling

The plugin includes special handling for legacy content published before April 18, 2024. Posts older than this date will have custom related posts disabled unless explicitly enabled via the `_legacy_related_posts_fixed` meta field.

## Caching Strategy

The plugin implements a multi-level caching strategy:

1. **Object cache**: Related posts are cached using WordPress object cache
2. **VIP integration**: Automatic cache invalidation on VIP platform
3. **Preview protection**: Cache is bypassed for logged-in users and preview contexts

## Author

**Seth Rubenstein**
Pew Research Center
[webdev@pewresearch.org](mailto:webdev@pewresearch.org)

## License

GPL-2.0+

## Support

For technical support and bug reports, contact the development team at [webdev@pewresearch.org](mailto:webdev@pewresearch.org).
