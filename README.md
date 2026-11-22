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
- **Credentials inside options** — `get_option` hides password, token, secret and key fields inside a setting's value; `update_option` cannot change or remove them, and refuses mail, payment, security and backup plugins' settings outright
- **Raw code** — `inject_js` needs `unfiltered_html` and `inject_css` needs `edit_css`, on top of `manage_options`; posts, pages, templates, template parts and navigation keep script only for an account with `unfiltered_html` (as WordPress's own editor does), and global styles keep custom CSS only with `edit_css`, and neither while raw code is locked; the answer says when something was removed
- **MIME verification** on uploads — file type is checked after download, not from HTTP headers
- **Privilege escalation prevention** — user tools enforce role hierarchy and ask about the account itself (`edit_user`, `promote_user`, `delete_user`, so WooCommerce's and multisite's rules apply); non-admins cannot create or modify accounts that hold `manage_options` or give a role with more permissions than their own; term tools check each taxonomy's own capabilities
- **Errors without server paths** — when a tool throws, the details go to the PHP error log and the client gets a fixed line; a tool's own error messages are passed on as they are
- **Package checks** — AI Nexus's own update, its extensions' updates and the extension installer accept packages only from the VGC update channel, only with VGC's signature on the channel entry (checked with a key built into the plugin; an unsigned entry is refused since 2.44.0), and only when the download matches the entry's SHA-256; an update refused for its channel entry is named in a notice on the Plugins and Updates screens
- **Clean uninstall** — deleting the plugin (never deactivating) revokes the Application Passwords the Connect page made and removes its data on every site of a network, keeping what a still-installed legacy connector needs and the audit trail of what an AI changed
- **Disabled tools return errors** — if a tool group is disabled, the AI receives a clear message explaining how to re-enable it rather than a silent failure
- **Other plugins' AI tools** — read-only mode also covers the AI tools other plugins add (WooCommerce, AIOSEO and others), on every MCP server and on WordPress's Abilities REST route; **AI Nexus → Other AI tools** lists every other MCP server, AI tool and MCP-like address on the site and lets an administrator turn each one off, or keep new ones off until they are switched on

- **Licence key** — a key for AI Nexus extensions, under **AI Nexus → Licence**: an AI cannot read or change it, and a site without one contacts nothing

See [docs/security.md](docs/security.md) for a full breakdown.

---

## Changelog

### 2.50.0

- **A Custom HTML widget keeps its markup.** `add_widget` and `update_widget` ran every text setting through `sanitize_textarea_field()`, which strips all HTML, so a Custom HTML, Text or Block widget lost its script, links and even its paragraphs, and the answer said nothing. They now follow WordPress's own widgets, setting by setting: a `custom_html` widget's `content`, a `text` widget's `text` and a `block` widget's `content` are stored as sent when the account has `unfiltered_html` and raw code is not locked (as Appearance → Widgets stores them), and otherwise go through `wp_kses_post()`, with a `note` when a tag, attribute or comment was taken out. Every other setting is stored as before: titles and other text plain (tags removed), numbers and true/false as sent; the answer now has a `note` when tags were removed from a plain-text setting. Read-only mode still refuses both tools, and the lock (`MCP_ABILITIES_DISABLE_CUSTOM_CODE` or the `mcp_abilities_custom_code_enabled` filter) filters everyone. Another plugin's widget can name its HTML settings with the `mcp_abilities_widget_html_fields` filter.
- **Backslashes in post meta are kept.** The `meta` of `create_post` and `update_post` was handed to WordPress unslashed, and WordPress unslashes the meta it saves: every backslash was lost. JSON kept in meta (page-builder data, for one) lost the `\` of every `\"` and `\u003c`, and `C:\Temp` became `C:Temp`. Text, lists and objects at any depth keep them now.
- **Backslashes in media fields are kept.** The title, caption, description and alt text of `update_media`, of `upload_image` (and upload links), and the title of `upload_file`.
- The `settings` descriptions of `add_widget` and `update_widget` say which settings keep HTML.

### 2.49.0

- **An administrator's page keeps its own CSS, script and form fields.** `create_page`, `update_page`, `create_post` and `update_post` ran every account's content through `wp_kses_post()`, so a Custom HTML block with a `<style>`, a `<script>` (a calculator, say) or `<input>` fields lost all three, and the answer said nothing. Content is now stored as sent when the account has `unfiltered_html` (as WordPress's own editor stores it) and raw code is not locked, the rule templates, template parts and navigation follow since 2.41.0. An account without `unfiltered_html`, and everyone while raw code is locked (`MCP_ABILITIES_DISABLE_CUSTOM_CODE` or the `mcp_abilities_custom_code_enabled` filter), gets the content filtered as before, and now the answer has a `note` when a tag, attribute or comment was taken out. Read-only mode still refuses these tools.
- **Backslashes are kept.** Post and page content, templates, template parts, navigation and global styles were handed to WordPress unslashed, and WordPress unslashes what it saves: every backslash was lost, for every account. A script's `/\d+/` became `/d+/`, a block attribute's `\u003c` (an escaped `<`) became the text `u003c`, `C:\Temp` became `C:Temp`, and a global-styles value with a quote in it (a font family such as `"Inter", sans-serif`) left the stored styles unreadable. A backslash in a post or page title is kept too.
- **No note for rewrites that lose nothing.** Where content is filtered, `&` written as `&amp;` or `<br/>` as `<br />` no longer brings the "removed" note (templates, template parts and navigation too); a removed script, attribute or form field still does.
- The `content` descriptions of the four tools say how content is stored.

### 2.48.1

- **An extension can show a tag after its name.** An extension that registers a `badge` (for example `'badge' => 'Beta'`) gets it as a bubble right after its name on AI Nexus → Extensions, in the style of the tags under the description: on its card, on its own page, and on its card under "Available to install" when the catalog entry has one. Plain text, one line, at most 20 characters. VGC AI Nexus Batch 1.2.6 uses it for "Beta". Without a badge nothing changes.

### 2.48.0

Small list answers without the AI having to know how to ask for them. An AI connected through a claude.ai connector can hold a tool's description from long ago (the connector keeps the first one it saw), so advice in descriptions may never reach it; these changes work from the answer itself.

- **Meta keys alone now give small items. This changes the answer.** `list_posts` and `list_pages` with `fields` naming only meta keys (`"fields": ["_thumbnail_id"]`) give each item as `id`, `title` and `meta` with just those keys: the same answer as `["id", "title", "_thumbnail_id"]`. Until now they gave whole items plus `meta`, about 5 times larger: for 100 posts 6,991 characters instead of 36,967. An AI that also wants other item fields (`status`, `excerpt`, `permalink`, …) names them, as with 2.47.0. `"meta"` alone is left out and said in `fields_note` (a meta key named "meta" is `"meta.meta"`). A call without `fields`, or naming item fields, gets the same answer as in 2.47.0.
- **Not inside another tool call.** A list call made by a tool that runs other tools (a step of VGC AI Nexus Batch) keeps 2.47.0's whole items for meta keys alone. A batch's conditions run on the server and may read any item field (`{"status": {"ne": "publish"}}`); Batch up to 1.2.2 reads a field the item lacks as empty, so cut-down items would let such a condition pass every item. There the AI does not read the list itself, so whole items cost it little. (`Request_Context::nested()` tells whether a call runs inside another one.)
- **A big list says how to get a small one.** A list of whole items (no `fields`) of 10,000 characters or more now has one line in `note` with its size and the size of the same list with `"fields": ["id", "title"]` (`["id", "name"]` for terms), worked out from the items themselves. For 100 posts: "these 100 whole items are 33,969 characters … `"fields": ["id", "title"]` gives the same list in 3,993 characters", plus an example with a meta key. `list_posts`, `list_pages`, `list_media` and `list_terms`; the items themselves are unchanged, and smaller lists get no note. Where the answer already has a `note` (a list limited to what the account may see), this line follows it.
- Extensions can add the same line to their own lists with `Query_Support::with_size_note()`; VGC AI Nexus for WooCommerce 1.12.0 does for products.

### 2.47.0

- **Smaller list answers.** `list_posts` and `list_pages` read `fields` the way `get_post` does: naming an item field (`"fields": ["id", "title", "_thumbnail_id"]`) gives each item only `id` and the fields named, with the meta keys named under `meta`. Before, every name was taken as a meta key, so such a call got whole items plus empty meta entries. For 100 posts that is about 7,000 characters instead of 38,000. `list_posts` can also name `featured_image`, `categories` and `tags` (as `get_post` has them), `list_pages` `featured_image`; these are worked out only when named. `"meta.<key>"` is always a meta key.
- **`fields` on `list_terms` and `list_media`** (item fields only): 100 terms with `["id", "name"]` are about 3,300 characters instead of 9,600, 100 images with `["id", "url"]` about 8,800 instead of 25,000.
- **A note when a name gives nothing.** When a meta key named in `fields` has no value on any listed item (often a guess, such as `featured_media`), or a name is no field of those items, the answer says so in `fields_note` and lists the fields that can be named.
- Excerpts, permalinks, page templates and attachment details are worked out only for the fields kept.
- **`create_term`, `add_menu_item` and `reply_to_comment` also return `id`**, the new item's ID (`term_id`, `item_id` and `reply_id` stay).
- Unchanged: a call without `fields`, or with meta keys only, gets the same answer as before (2.48.0 changes the second: see above).

### 2.46.0

- **Extensions can add a short rule to what an assistant reads when it connects.** The few lines AI Nexus sends the moment an AI assistant connects are the only guidance that is surely in its context before it picks a tool (many assistants load the tools' own descriptions only when they look for one). An extension can now add one line there (filter `mcp_abilities_orientation_rules`; at most three lines from all extensions, 400 characters each). VGC AI Nexus Batch uses it to say when to send several calls as one batch and when to call tools one by one. Nothing else changes.

### 2.45.1

- **Extension pages have a title.** Each extension's own page (AI Nexus → Extensions → an extension, such as Tracker or Batch) now has its name in the browser tab ("Tracker – VGC AI Nexus"), and on PHP 8.1 and later it no longer writes a "strip_tags(): Passing null to parameter #1" deprecation notice from `wp-admin/admin-header.php` to the error log on every visit. The pages, their addresses and who can open them (administrators) are unchanged.

### 2.45.0

- **A tool that runs other tools is logged step by step.** When a tool call is made while another one is running (the new VGC AI Nexus Batch extension does this: one call runs a list of tool calls), each inner call now has its own activity-log row, its own revision label and "last edited by", and counts as a read or a write on its own. Before, it was folded into the outer call: one row named after the first step, and a reading first step could make later changes count as a read, so their revisions were not labelled. The outer call gets its own row, written after its steps. Nothing else changes.

### 2.44.0

Security review of 2026-11-10, finding C4, second step: only signed updates. Nothing that was refused before is allowed now.

- **Unsigned updates are refused.** An AI Nexus or extension update whose entry in the VGC update channel carries no VGC signature is no longer offered, linked or downloaded, and the Extensions page will not install it. Every current entry is signed, so nothing changes for a site that updates normally. `MCP_ABILITIES_REQUIRE_SIGNED_UPDATES` is no longer needed; setting it to `false` does not turn this off.
- **Said on screen.** When an update is refused because of its channel entry (not signed, a signature that does not check out, no SHA-256 checksum), a notice on the Plugins and Updates screens and on AI Nexus's pages names the plugin, the version and the reason, and says that nothing was installed. Only people who can update plugins see it. It goes away by itself once VGC publishes the update correctly or that version is installed. Before, only the PHP error log said why an update did not appear.
- Administrators who must switch the checks off (for example for a private mirror) still can, only with the `mcp_abilities_verify_packages` filter.

### 2.43.0

Security review of 2026-11-10, finding C4: updates are signed. Nothing that was refused before is allowed now.

- **Signed updates.** Every AI Nexus and extension entry in the VGC update channel now carries VGC's signature, made with a key that is not kept in the update repository. AI Nexus checks it with a public key built into the plugin. An update whose signature does not check out is not shown on the Updates screen, has no download link under "View details", and is refused if WordPress tries to download it; the Extensions page refuses to install it. So someone who gets write access to the update repository can no longer push a package to your site.
- **Extensions too.** Extension updates go through the same check when AI Nexus is active.
- **A checksum is required.** An update or extension without a SHA-256 checksum in the channel is refused (it used to install unchecked).
- **Fails safe.** If the check itself breaks, the update is refused with a message instead of being installed unchecked.
- **Transition.** Updates without a signature are still accepted in this version and noted in the PHP error log. To refuse them already, add `define( 'MCP_ABILITIES_REQUIRE_SIGNED_UPDATES', true );` to `wp-config.php`. The next version (2.44.0) refuses them for everyone.
- Administrators who must switch the checks off (for example for a private mirror) still can with the `mcp_abilities_verify_packages` filter; it now turns off the signature check too.

### 2.42.0

Security review of 2026-11-10, low findings in the core plugin. Everything here tightens; nothing that was refused before is allowed now.

- **Internal content stays internal.** The general post tools no longer read, list, search, create, change or delete content types that have no admin screen: revisions, menu items, privacy requests, oEmbed cache, block templates, global styles, fonts, WooCommerce variations, refunds and order placeholders. An Editor could read privacy-request e-mail addresses and change or delete a WooCommerce order's placeholder post, and an Author could plant a revision under someone else's post. Menus, templates and WooCommerce keep their own tools.
- **Comments.** Approving, holding, marking as spam, trashing, deleting or replying to a comment needs permission to edit that comment, as on WordPress's Comments screen. WooCommerce order notes need permission to edit orders. The comment list shows only comments the account may see.
- **Registered custom fields** follow the permission rule the plugin that registered them gave.
- **Other plugins' tools** built from an ability that was never registered are treated as that plugin's tools, so the Other AI tools switches and read-only mode apply.
- **Users and CSS.** The last administrator can no longer be given another role, nobody can take away their own permission to manage users, deleting a user checks the account that gets the content, and clearing the custom CSS needs the same permission as writing it.
- **Encoded line breaks.** An address ending in `%0A` no longer gets past read-only mode and the Other AI tools switches when another plugin's older MCP adapter is loaded.
- **Sign-in.** A return address that WordPress would store differently from the one checked is refused; an unexpected stored value for which apps may connect counts as Claude only; adding or removing a user's role ends their claude.ai connections; ending a connection resets its admin approval.
- **Application Passwords.** New setting on AI Nexus → Connections, off by default: also switch off Application Passwords not made on AI Nexus after 14 days without use. New ones are marked "New" in the list. A password past its 14 unused days is refused and deleted when it is next used, even when the site's scheduled tasks do not run. Deleting another user's Application Password needs permission to edit that user (multisite).
- **Licence answers** must belong to the request that asked for them; oversized answers are ignored.
- **Floods.** Many unknown sign-in tokens from one address get "too many requests"; a notice explains what to set when the site is behind Cloudflare or another proxy; a flood of app registrations can no longer cancel a connection someone is approving.

### 2.41.0

Security review of 2026-11-10, medium findings in the core plugin.

- **Options that move mail, money or access are protected.** `update_option` now refuses the settings of SMTP and mailer plugins (WP Mail SMTP, FluentSMTP, Post SMTP, Easy WP SMTP, VGC Mailer and core's post-by-email account), security, login, two-factor and backup plugins, Google Site Kit, WooCommerce's payment, shipping and e-mail settings and the bank account shown for bank transfers, stored recovery-mode keys, and the VGC plugins whose settings are a protection or a credential. Anything an AI may not read, it may not change either. Ordinary settings (site title, reading settings, the shop's currency) work as before.
- **Credentials inside a setting are hidden.** Many plugins keep an API password, token or key as one field of a settings array, which a rule on the option's name cannot see. `get_option` now shows such fields as "*** hidden by AI Nexus ***" at any depth, in arrays, objects and JSON, and lists which fields it hid. `update_option` keeps them: an AI can change the other fields of the same setting, but cannot change, remove or overwrite a stored credential.
- **Locking raw code locks it everywhere.** When an administrator locks raw code (`MCP_ABILITIES_DISABLE_CUSTOM_CODE` or the `mcp_abilities_custom_code_enabled` filter), templates, template parts and navigation are saved without script, and custom CSS is left out of global styles. The same happens for an account without `unfiltered_html` (markup) or `edit_css` (CSS). The tool's answer says what was removed and why.
- **Refresh tokens: one chain only.** When an app sends the same old refresh token twice within the 120-second grace period (two runs at once, a lost answer), it now gets the same new refresh token back, not a second one. If the old token comes back after its successor was already used, the connection ends, as it does after the grace period.

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
