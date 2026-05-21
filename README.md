# VGC AI Nexus

**The AI management layer for WordPress.**

VGC AI Nexus exposes your site's content, users, settings and menus as [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) tools so AI agents can read, create, update and delete data through a secure, permission-controlled interface.

Connect Claude, ChatGPT, or any MCP-compatible AI agent to your WordPress site and manage it through natural language.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Latest |

## Installation

1. Install and activate the **MCP Adapter** plugin first.
2. Upload the `vgc-ai-nexus` plugin folder to `/wp-content/plugins/`.
3. Activate **VGC AI Nexus** in *Plugins → Installed Plugins*.
4. Go to **AI Nexus → Abilities** to enable or disable tool groups.

### Connecting Claude

**Claude Desktop** — add to your `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "my-wordpress-site": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch"],
      "env": {
        "MCP_SERVER_URL": "https://your-site.com/wp-json/mcp/v1",
        "MCP_AUTH_TOKEN": "your-application-password"
      }
    }
  }
}
```

**Claude.ai** — go to *Settings → Integrations → Add MCP Server* and enter:
- **URL:** `https://your-site.com/wp-json/mcp/v1`
- **Auth:** WordPress Application Password (Users → Your Profile → Application Passwords)

---

## Abilities

Each group can be enabled or disabled independently from **AI Nexus → Abilities**.

### Post Management
| Tool | Description |
|---|---|
| `list_posts` | List posts with filters for status, author, category, date and search |
| `get_post` | Retrieve full post content and metadata |
| `create_post` | Create a new post of any registered post type (use `post_type` param; defaults to `post`). Supports `slug` and `parent_id` for hierarchical types. |
| `update_post` | Update title, content, status, categories or tags |
| `delete_post` | Trash or permanently delete a post |
| `list_revisions` | List all saved revisions for a post |
| `get_revision` | Retrieve the full content of a specific revision |

### Page Management
| Tool | Description |
|---|---|
| `list_pages` | List pages with status and search filters |
| `get_page` | Retrieve full page content and metadata |
| `create_page` | Create a new page |
| `update_page` | Update page content, status or parent |
| `delete_page` | Trash or permanently delete a page |

### Media Management
| Tool | Description |
|---|---|
| `list_media` | List media library items with MIME type and search filters |
| `get_media` | Retrieve media item details and URLs |
| `update_media` | Update title, alt text, caption or description |
| `delete_media` | Delete a media library item |
| `upload_image` | Import an image from a URL (JPEG, PNG, GIF, WebP, AVIF — max 10 MB) |
| `upload_file` | Import a document or media file from a URL (PDF, Office, audio, video — max 50 MB) |

### Comment Management
| Tool | Description |
|---|---|
| `list_comments` | List comments with status and post filters |
| `reply_to_comment` | Post a reply to an existing comment |
| `moderate_comment` | Approve, hold, mark as spam, trash or delete a comment |

### Taxonomy Management
| Tool | Description |
|---|---|
| `list_terms` | List terms in any taxonomy (categories, tags, custom) |
| `create_term` | Create a new term |
| `update_term` | Update term name, slug or description |
| `delete_term` | Delete a term |

### Menu Management
| Tool | Description |
|---|---|
| `list_menus` | List all registered navigation menus and their theme locations |
| `get_menu_items` | Retrieve the items in a specific menu |
| `create_menu` | Create a new navigation menu |
| `delete_menu` | Delete a navigation menu and all its items |
| `add_menu_item` | Add a custom URL, post/page, or taxonomy term item to a menu |
| `update_menu_item` | Update an existing menu item's title, URL, order or parent |
| `delete_menu_item` | Remove an item from a menu |
| `assign_menu_location` | Assign or unassign a menu to a theme location |
| `list_navigations` | List all block-based `wp_navigation` menus (FSE / block themes) |
| `get_navigation` | Retrieve the full block markup of a `wp_navigation` post |
| `update_navigation` | Write new block markup to a `wp_navigation` post |

### Widget Management
| Tool | Description |
|---|---|
| `list_widget_areas` | List all registered widget areas (sidebars) and widget counts |
| `list_widgets` | List all widgets in a specific widget area with their settings |
| `add_widget` | Add a widget to a widget area |
| `update_widget` | Update the settings of an existing widget instance |
| `remove_widget` | Remove a widget from a widget area |

### Options Management
| Tool | Description |
|---|---|
| `get_site_info` | Retrieve site name, URL, timezone and language |
| `get_option` | Read a WordPress option by key (protected keys blocked) |
| `update_option` | Update a WordPress option (protected keys blocked) |
| `list_post_types` | List all registered post types with their capabilities, feature support and REST base |
| `flush_rewrite_rules` | Regenerate WordPress permalink/rewrite rules (equivalent to saving Settings → Permalinks) |

### Custom CSS & JS
| Tool | Description |
|---|---|
| `get_custom_code` | Retrieve current custom CSS and JS snippets |
| `inject_css` | Append, replace, or find-and-replace within the site's custom CSS |
| `clear_css` | Wipe all custom CSS |
| `inject_js` | Write a JavaScript snippet to the site header or footer |

### Template Management *(block themes only)*
| Tool | Description |
|---|---|
| `list_templates` | List all FSE page templates with their IDs, source and status |
| `get_template` | Retrieve the full block markup of a page template |
| `update_template` | Write new block markup to a template (creates a DB override for theme-file templates) |
| `create_template` | Create a new custom page template |
| `delete_template` | Delete a customised template (DB override). Theme-file templates revert to their defaults. |
| `list_template_parts` | List all template parts (header, footer, sidebar, etc.) |
| `get_template_part` | Retrieve the full block markup of a template part |
| `update_template_part` | Write new block markup to a template part, with optional `area` assignment |
| `create_template_part` | Create a new custom template part |
| `delete_template_part` | Delete a customised template part (DB override only; reverts to theme default) |
| `set_template_part_area` | Change the area (header/footer/sidebar/uncategorized) of an existing template part override |
| `get_global_styles` | Retrieve the active theme's global styles settings and styles (theme defaults + user overrides) |
| `update_global_styles` | Deep-merge new settings or styles into the site's global styles (creates or updates the user override) |
| `list_block_patterns` | List all registered block patterns, with optional category and keyword filters |

### Theme Management
| Tool | Description |
|---|---|
| `get_theme_mods` | Retrieve all Customizer settings for the active theme |
| `update_theme_mod` | Update a single Customizer setting |

### User Management *(disabled by default — security risk)*
| Tool | Description |
|---|---|
| `list_users` | List user accounts with role and search filters |
| `get_user` | Retrieve a user's profile information |
| `create_user` | Create a new user account |
| `update_user` | Update user profile, email or role |
| `delete_user` | Delete a user account |

> ⚠️ **Security warning:** User tools expose all accounts and allow creating administrators, changing passwords and deleting users. Only enable if you understand the implications.

---

## Extensions

- **[VGC AI Nexus for WooCommerce](https://github.com/vgc-ltd-wp/mcp-abilities-woocommerce)** — Adds product, order, customer and coupon management tools.

---

## Security

VGC AI Nexus enforces WordPress's own permission system — every tool call is checked against the authenticated user's capabilities before execution. No tool can do anything the logged-in user couldn't do through the admin dashboard.

Key protections:

- **Capability checks** on every tool (`edit_posts`, `manage_options`, `upload_files`, etc.)
- **Options denylist** — authentication keys, salts, `active_plugins`, `siteurl` and other critical options are read- and write-protected
- **SSRF protection** on upload tools — URLs resolving to private IP ranges are rejected
- **MIME verification** on uploads — file type is checked after download, not from HTTP headers
- **Privilege escalation prevention** — user tools enforce role hierarchy; non-admins cannot create or modify administrator accounts
- **Disabled tools return errors** — if a tool group is disabled, the AI receives a clear message explaining how to re-enable it rather than a silent failure

See [docs/security.md](docs/security.md) for a full breakdown.

---

## Documentation

- [All Abilities Reference](docs/abilities.md)
- [Security Model](docs/security.md)
- [Extending with Add-ons](docs/extensions.md)

---

## License

GPL v2 or later — https://www.gnu.org/licenses/gpl-2.0.html
