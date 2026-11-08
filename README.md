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
- **Object-level checks** — each post, page and media item is checked against the user (`read_post`, `edit_post`, `delete_post`, `publish_post`): a Contributor reads only published content and their own drafts, cannot publish, and cannot read or write protected meta; media tools act only on attachments the user may edit
- **Input validation** — every call is checked against the tool's schema (required fields, types, and the enums of core tools) before it runs
- **Options denylist** — authentication keys, salts, `active_plugins`, `siteurl`, the roles table, caches, OAuth tokens, this plugin's and every AI Nexus extension's settings, the Analytics extension's Google sign-in and mail/payment credentials are read- and/or write-protected
- **claude.ai sign-in (OAuth)** — a token works only on the MCP routes, so read-only mode and the tool switches cannot be bypassed through the plain REST API; the consent page cannot be framed or cached, needs a sign-in under 10 minutes old and an account that can write posts, and shows the full address the browser goes to; only Claude's own addresses (and local apps with PKCE, and hosts an administrator adds) may register; a password change, a role change or deleting the user ends the connection; an unused connection switches off after 14 days; app registration is capped, rate-limited per address and expires when unused; codes, tokens and request bodies are never logged
- **Every connection in view** — AI Nexus → Connections lists every AI connection that is on and when it was last used, with Disconnect; each user sees and can revoke their own on their profile
- **SSRF protection** on upload tools — public addresses only (A and AAAA, every redirect), the connection pinned to the checked address, the size limit enforced during the download
- **Only what concerns them** — `tools/list` shows a user the tools they could call; versions, the theme and the security setup are for administrators only
- **Raw code** — `inject_js` needs `unfiltered_html` and `inject_css` needs `edit_css`, on top of `manage_options`
- **MIME verification** on uploads — file type is checked after download, not from HTTP headers
- **Privilege escalation prevention** — user tools enforce role hierarchy and ask about the account itself (`edit_user`, `promote_user`, `delete_user`, so WooCommerce's and multisite's rules apply); non-admins cannot create or modify accounts that hold `manage_options` or give a role with more permissions than their own; term tools check each taxonomy's own capabilities
- **Errors without server paths** — when a tool throws, the details go to the PHP error log and the client gets a fixed line; a tool's own error messages are passed on as they are
- **Package checks** — AI Nexus's own update and the extension installer accept packages only from the VGC update channel, and verify the manifest's SHA-256 where it gives one
- **Clean uninstall** — deleting the plugin (never deactivating) revokes the Application Passwords the Connect page made and removes its data on every site of a network, keeping what a still-installed legacy connector needs and the audit trail of what an AI changed
- **Disabled tools return errors** — if a tool group is disabled, the AI receives a clear message explaining how to re-enable it rather than a silent failure
- **Other plugins' AI tools** — read-only mode also covers the AI tools other plugins add (WooCommerce, AIOSEO and others), on every MCP server and on WordPress's Abilities REST route; **AI Nexus → Other AI tools** lists every other MCP server, AI tool and MCP-like address on the site and lets an administrator turn each one off, or keep new ones off until they are switched on

- **Licence key** — a key for AI Nexus extensions, under **AI Nexus → Licence**: an AI cannot read or change it, and a site without one contacts nothing

See [docs/security.md](docs/security.md) for a full breakdown.

---

## Changelog

### 2.40.0

- **New page: AI Nexus → Licence.** Enter a licence key for AI Nexus extensions, see whether it is active, until when, and whether this site counts as the live site or a staging copy. One key covers one live site and its staging copies, and unlocks every extension there. AI Nexus itself stays free and needs no key.
- **Nothing changes yet.** Every tool keeps working at full speed with or without a key, whatever the licence says. A site with a key is told when it has expired or stopped being valid, and so is the AI working on it.
- **A site without a key contacts nothing.** With a key, AI Nexus asks the licence server once a day and when the page is opened; it sends the key, the site's address and environment type, a random id for this installation, and the WordPress, PHP and AI Nexus versions. If the server cannot be reached, nothing changes; after three weeks without an answer the page says so.
- **The key is protected:** an AI cannot read or change it, it is sent only in the body of an https request, and only its last five characters are ever shown. Answers from the server count only if they carry a valid signature. Deleting the plugin removes the key and lets the site go.

### 2.39.0

- **An AI connection no longer outlives the account behind it.** Changing a password ends every claude.ai connection of that account, whatever way the password was changed (reset, profile, WP-CLI, another plugin). So does deleting the user, removing them from the site or changing their role; they connect again. A connection of an app that is no longer registered stops working too.
- **Connecting needs a fresh sign-in.** The approval page asks you to log in again unless you did so in the last 10 minutes, so a stolen login cookie cannot be turned into a lasting AI connection. Only accounts that can write posts may connect an AI; others see a page saying their account cannot.
- **"Claude only" now means Claude.** New apps may register only with Claude's own return addresses (https://claude.ai/api/mcp/… and https://claude.com/api/mcp/…), local apps on your computer that use PKCE, and hosts you add under **Other apps allowed to connect** on the Connect page. Apps connected before keep working. The approval page shows Claude's name and logo only for Claude; any other app gets a plain title with its name, a clear warning, its full return address and when it registered.
- **Sites that had "Any app"** (set by the old connector plugin) are switched to "Claude only" once, with a notice that says how to add an address. You can choose "Any app" again.
- **Unused connections switch off after 14 days.** A claude.ai connection that is not used for 14 days ends (it used to be 30, renewed on use); existing ones get 14 days from the update. An Application Password made on the Connect page is deleted after 14 days without use (existing ones also get 14 days from the update). Other Application Passwords are only listed. Developers can change the 14 with the `mcp_abilities_idle_days` filter.
- **New: Connections that are on.** AI Nexus → Connections now starts with every AI connection that is on — administrators included, whether or not approval is on — with who, which app, who said they are connecting, since when, when and from where it was last used, when it switches off if unused, its approval status, and a Disconnect button.
- **New: Your AI connections** on each user's profile, with Revoke for their own.
- **New setting:** "Also hold administrators' new connections", next to "Require admin approval".
- **Refresh tokens:** an app that keeps a secret must send it on every refresh, and an old refresh token used again after it was replaced ends that connection.
- **Option protection** now covers every AI Nexus extension's settings, and the AI can no longer read the Analytics extension's Google sign-in or the list of connections.
- **Fewer ways to load the site:** a wrong token is logged once per address every 10 minutes, IPv6 callers are counted by their /64 network, and only an administrator's "Check again" on the Updates screen skips the update cache.

### 2.38.0

- Read-only mode now covers other plugins' AI tools too. While it is on, an AI can use another plugin's tool only if that plugin marks it as read-only (or you count it as a read); everything else is refused and no longer listed. This holds for AI Nexus's execute tool, tools other plugins add to AI Nexus's server, other plugins' MCP servers and WordPress's Abilities REST route. A person signed in to wp-admin is not affected.
- New page **AI Nexus → Other AI tools**: every MCP server on the site, every other plugin's tool an AI can reach and how, other MCP-like addresses, each with an On/Off switch. AI Nexus's own server is always on.
- New setting: when a plugin adds a new AI tool or MCP server, allow it (default) or keep it off until you switch it on.
- Every refusal is written to the activity log. The settings cannot be changed by an AI, and they are removed when the plugin is deleted.

---

## Documentation

- [All Abilities Reference](docs/abilities.md)
- [Security Model](docs/security.md)
- [Extending with Add-ons](docs/extensions.md)

---

## License

GPL v2 or later — https://www.gnu.org/licenses/gpl-2.0.html
