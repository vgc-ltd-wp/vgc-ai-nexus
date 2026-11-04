# Security Model — VGC AI Nexus

VGC AI Nexus is designed on a single principle: **the AI can never do anything the authenticated WordPress user couldn't do through the admin dashboard.** Every tool call goes through the same capability checks WordPress itself uses.

---

## Authentication

Tools are exposed via the MCP Adapter plugin's REST endpoint. Authentication uses **WordPress Application Passwords** (built-in since WP 5.6). The AI agent sends HTTP Basic credentials on every request — no sessions, no cookies, no tokens with elevated scope.

To create an Application Password: **Users → Your Profile → Application Passwords → Add New**.

Scope the password to an account with only the capabilities the AI actually needs. A dedicated `editor`-role account is safer than using an administrator account.

---

## OAuth Sign-In for claude.ai (hardened in 2.35.0)

claude.ai (web, mobile, Desktop connectors, scheduled routines) cannot use an Application Password, so AI Nexus runs a small OAuth server (`includes/oauth/`): the app registers itself, a signed-in WordPress user approves it on a consent page, and the app gets a bearer token that acts as that user.

- **The token is for MCP only.** It signs a request in only on the MCP server routes (`/wp-json/mcp/…`, and any other MCP server the adapter registers) and the OAuth server's own routes (`/wp-json/vgc-cc/…`). On `/wp/v2`, WooCommerce or any other REST route the request stays signed out, so read-only mode, the tool switches and connection approval, which apply to MCP, cannot be bypassed through plain REST. Developers can add a prefix with the filter `mcp_abilities_oauth_bearer_routes` (read-only mode then does not protect that route).
- **A Bearer header alone changes nothing.** WordPress's cookie nonce check is skipped only for a request that a valid token actually signed in, not merely because an `Authorization: Bearer` header is present next to a login cookie.
- **Consent page:** sent with `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'` and no-cache headers, so another site cannot frame the Approve button and no cache keeps the page. It shows the host the browser goes back to after Approve, with a warning when that is not a Claude address.
- **Registration needs no sign-in, so it is bounded:** at most 10 redirect addresses of up to 2,000 characters each, and names of up to 100 characters. A registration nobody approves expires after a day, and at most 50 unapproved ones are kept (the oldest make room). Once someone approves an app it is kept. At 200 registered apps, the oldest ones with no live token make room, and if every one is still connected, new registrations are refused until an administrator disconnects some on the Connect page. Apps registered before 2.35.0 count as approved.
- **Rate limits per address** (see [Client Addresses](#client-addresses-since-2370)): 30 registrations an hour and 60 token requests a minute. Above that the answer is HTTP 429 with `Retry-After`. Filters: `mcp_abilities_oauth_rate_limit` (`[requests, seconds]`, 0 turns a limit off) and `mcp_abilities_oauth_client_ip` (the address these limits count by, for these limits alone).
- **Logging:** registrations, approvals, token issues and errors are logged (prefix `AI Nexus OAuth:`), but never a request body, an authorization code or a token, only short hashes of codes and tokens. Per-request tracing needs `MCP_ABILITIES_OAUTH_DEBUG`.
- Codes and tokens are 256-bit random values stored only as SHA-256 hashes; PKCE (S256) and exact redirect-address matching are enforced. The registration policy on the Connect page (Claude addresses only by default, any app, or none) decides which apps may register.

---

## Capability Enforcement

Every ability defines a `required_cap` that is checked by WordPress's `current_user_can()` before the tool executes.

| Ability Group | Required Capability |
|---|---|
| Post Management | `edit_posts` |
| Page Management | `edit_pages` |
| Media Management | `upload_files` |
| Comment Management | `moderate_comments`; permanently deleting a comment also needs `edit_comment` on it |
| Taxonomy Management | `manage_categories` |
| Menu Management | `edit_theme_options` |
| Options Management | `edit_posts` to reach the tool (its `list_post_types` action declares it); every other action needs `manage_options` |
| Theme Management | `edit_theme_options` |
| User Management | `list_users` / `edit_users` / `delete_users` to reach the tool; `edit_user`, `promote_user` or `delete_user` on the account itself |
| Custom CSS & JS | `manage_options`; writing JavaScript (`inject_js`) also needs `unfiltered_html`, writing CSS (`inject_css`) also `edit_css` |

With consolidation on (the default), each group is one tool whose gate is the lowest capability among its actions; the selected action's own capability is checked again before it runs. With consolidation off, each action is its own tool with its own capability, so a low capability can reach a tool on its own (`delete_posts`, which a Contributor has, reaches `delete_media`). That gives nobody more: the object-level checks below run inside the action in both modes, so such a user can still delete only media they may delete anyway.

---

## What a User Is Told Exists (since 2.36.0)

Any signed-in user with `read` can reach the MCP endpoint. Every call has always been checked; since 2.36.0 they are also told only what concerns them (`includes/class-tool-visibility.php`).

- **`tools/list`** shows a user the tools they could call. A Subscriber or WooCommerce customer gets a valid, empty list. A group tool stays listed for anyone with its lowest capability (Options Management for `edit_posts`, because of `list_post_types`), and its other actions are refused when called. An administrator sees everything, a switched-off group included: calling that one explains how to switch it on. Anyone without the capability is simply refused it.
- **Read-only mode does not take tools off the list.** A write tool someone holds the capability for stays listed and is refused when called, so switching the mode off needs no reconnect. A connection waiting for approval still sees only the status tool.
- **`mcp-adapter-discover-abilities` and `mcp-adapter-get-ability-info`** need `edit_posts` (they needed `read`). Set by the adapter's filters `mcp_adapter_discover_abilities_capability` and `mcp_adapter_get_ability_info_capability`; a site can set its own there. `mcp-adapter-execute-ability` still needs only `read`, because every ability checks its own permission, but it is listed only for someone who could run at least one ability.
- **The usage guide** needs `edit_posts`. Everyone who gets it gets what is useful for working: post type and taxonomy slugs, the page builder's name, whether WooCommerce and WPML are active, read-only mode, what each extension does. Only `manage_options` also sees the WordPress version, the theme, the AI Nexus plugins with their versions, the adapter, the security plugin and whether REST is blocked, the approval setting and how remote connections work. `guide_version` carries the plugin's version only for them.
- **`initialize`** gives an account below `edit_posts` a short note in place of the usual orientation, which would send it to tools it does not have.

Another plugin's copy of the MCP adapter older than 0.5.0 has no list filter and shows every tool. The checks on each call still apply.

---

## Custom CSS & JS (since 2.36.0)

`inject_js` needs `unfiltered_html` and `inject_css` needs `edit_css`, on top of `manage_options`: on multisite only super admins have them, and `DISALLOW_UNFILTERED_HTML` takes them from everyone. The dispatcher, the single tool and the ability itself all check.

This gates writes only. Code already stored keeps running, `get_custom_code` still reads it and `clear_css` still removes CSS, for anyone with `manage_options`. The other ways to the same code are closed too: `update_option` refuses `mcp_custom_*`, the Additional CSS post needs `edit_css` to edit, and WordPress's own filter removes custom CSS from `update_global_styles` for someone without `unfiltered_html`.

---

## Object-Level Checks (since 2.33.0)

The capability above only says whether a tool can be used at all. Which post, which status and which meta key the caller may touch is checked inside each action, the way WordPress checks it for its own screens and REST API (`includes/class-post-access.php`).

### Reading content
`get_post`, `get_meta`, `list_posts`, `list_pages`, `list_content_seo` and `search_content`:

- The post type must be public, or the caller must be able to edit that type (`cap->edit_posts`). Coupons, orders, block templates, Additional CSS and similar internal types stay hidden from roles that cannot manage them.
- `read_post` must pass for the item. An item that is not publicly visible (draft, pending, private, scheduled, trashed, password-protected) also needs `edit_post`.
- Lists use `perm => readable`. Other authors' drafts, pending, scheduled and private items are listed only for callers with the type's `edit_others_posts` (and `read_private_posts`); anyone else gets their own items for those statuses, and the result says so in `note`. Asking for another author's unpublished items is refused.
- `search_content` applies the same rules in its SQL, so `total` and the page count match what is shown.
- Explicit IDs (`get_meta`, `list_content_seo` with `ids`) are returned only when readable; the others are reported as `forbidden` or counted in `note`.

### Meta
- Protected keys (`is_protected_meta()`, e.g. a leading underscore) are read and written only with `edit_post_meta` for that key, or with the type's `edit_others_posts` plus `edit_post` on the item (Editors and Administrators). Withheld keys are listed in `meta_hidden`; refused writes in `meta_skipped`.
- `_wp_attached_file`, `_wp_attachment_metadata` and `_wp_attachment_backup_sizes` (they decide which files deleting an attachment removes) are never written from a tool, whoever asks.

### Status
- `update_post` and `update_page`: setting `publish`, `future` or `private` (or a date change on an item that already has one of those statuses) needs `publish_post` on the item. Without it the item is saved as `pending` and the result explains why in `status_note`, as the WordPress editor does. A published or private item keeps its status when someone who may edit it saves it without changing the date. Moving to the trash needs `delete_post`.
- `create_post` checks the post type's own `cap->publish_posts` (e.g. `publish_pages` for pages) and its `cap->edit_others_posts` for another author.

### Media
- `update_media` and `delete_media` act only on attachments, and need `edit_post` / `delete_post` on that attachment. An Author can change or delete their own media, not other people's, and can no longer rewrite pages or posts through `update_media`.

---

## Options Protection

### Read protection (`get_option`)
The following categories of option keys are blocked from being read:

- Authentication keys and salts (`auth_key`, `secure_auth_key`, etc.)
- Payment gateway credentials (`woocommerce_stripe_*`, `woocommerce_paypal_*`, `woocommerce-ppcp-settings`, any key containing `ppcp`, etc.)
- Email credentials: `wp_mail_smtp`, core `mailserver_pass`, any key containing `smtp`, `sendgrid`, `mailchimp`, etc.
- `jetpack_private_options` (and any key containing `jetpack_private`), `fs_accounts` (Freemius)
- AI Nexus OAuth data: `vgc_cc_oauth_clients` and any key starting with `vgc_cc_` (clients, tokens, policy); who is connected and who approved it
- Any key containing `_license`, `_api_key`, `_secret`, `_private_key`, `_pass` or `token`

### Write protection (`update_option`)
The following keys can never be written:

| Key | Why |
|---|---|
| `siteurl`, `home` | Would redirect the entire site |
| `admin_email`, `adminhash`, `new_admin_email` | Could be used for account takeover |
| `active_plugins` | Could activate arbitrary code |
| `uninstall_plugins` | Stored callbacks that run when a plugin is deleted |
| `template`, `stylesheet`, `current_theme` | Could change the active theme |
| `default_role`, `users_can_register` | Could open registration to anyone |
| `{prefix}user_roles` | The role → capability table |
| `upload_path`, `upload_url_path` | Could redirect file uploads |
| All auth keys and salts | Would invalidate all sessions |
| `mcp_abilities_settings`, `mcp_woo_settings` | Prevents self-modification of plugin config |
| `cron`, `doing_cron` | Could break scheduled events |
| `sidebars_widgets`, `rewrite_rules`, `permalink_structure` | Widget placement and how every URL resolves |

And every key starting with:

| Prefix | Why |
|---|---|
| `vgc_cc_` | OAuth token store (a written row would be a login), the apps allowed to connect, the registration policy |
| `mcp_abilities_` | This plugin's own settings: read-only mode, connection approval, the guide conventions every AI session reads, the tool consolidation switch |
| `_transient_`, `_site_transient_` | Caches, including the update and extension-installer package URLs |
| `auto_update_` | Which plugins and themes update themselves |
| `theme_mods_`, `widget_`, `mcp_custom_` | Customizer settings, widget content, custom code (use the dedicated tools) |

---

## User Management Protections

User tools are **disabled by default** and display a security warning when enabled. When active, additional checks apply:

- **Non-admins cannot create administrators** — assigning the `administrator` role requires `manage_options`.
- **Non-admins cannot modify administrator accounts** — an account that holds `manage_options` cannot be edited or deleted by someone who does not, whatever its role is called.
- **The target is checked, not only the tool** (since 2.37.0, `includes/class-user-access.php`) — `update_user` needs `edit_user` on that account, a role change also `promote_user`, and `delete_user` needs `delete_user`, so WordPress's `map_meta_cap` rules run. A WooCommerce shop manager holds `edit_users` but may edit customers only: since 2.37.0 that applies here too. On multisite, only someone who may manage the network's users edits users, and a super admin only by a super admin; only super admins delete.
- **The last administrator cannot be deleted** — neither the last account with the `administrator` role nor the last that holds `manage_options`. This is `delete_user` only: `update_user` can still change the role of the last administrator.
- **Role escalation is blocked** (since 2.34.0, `includes/class-user-access.php`) — the role must be one WordPress lets the caller assign (`get_editable_roles()`, filter `editable_roles`); an unknown role is refused, so `update_user` can no longer leave a user with no role. `update_user` requires `promote_users` to change any role. Administrators (`manage_options`) may give any of those roles. With `promote_users` but without `manage_options`, a role that carries any capability the caller lacks is refused (so a custom role with `manage_options` cannot be handed out). Without `promote_users`, `create_user` gives only roles with nothing beyond `read` (subscriber, WooCommerce customer).

---

## Taxonomy Protections (since 2.34.0)

`create_term`, `update_term`, `delete_term` and `update_term_seo` check the taxonomy's own capabilities on top of the tool gate (`manage_categories`), as WordPress's term screens and REST API do (`includes/class-term-access.php`):

- Taxonomies without an admin screen (`show_ui` false: menus, block template areas, themes, WooCommerce product types and visibility) are refused; menus have their own tools.
- Create needs the taxonomy's `manage_terms` and `edit_terms` (e.g. WooCommerce's `manage_product_terms` / `edit_product_terms` for product categories, which Editors do not have).
- Update needs `edit_term` and delete needs `delete_term` on the term itself (the default category can never be deleted). The term must exist in the taxonomy named.

---

## Input Validation (since 2.34.0)

The bundled MCP adapter calls tools through a handler, not through `WP_Ability::execute()`, so before 2.34.0 nothing checked the input against the tool's schema on that path. Now every call is checked against the schema of the action actually run (`Ability::validate_params()`, called from the group dispatcher and the single-tool path):

- Required parameters, and the **type** of every declared parameter (also array items and object properties): `id: "abc"` or `id: [5]` for an integer, or a string for an object, is refused with a message naming the parameter.
- **Enums** of AI Nexus's own tools (statuses, order, modes). An extension's enum may be a static sample of values its code reads from the site (WooCommerce order statuses include a shop's custom ones), so for extension tools only types are checked, unless the filter `mcp_abilities_validate_enums` returns true for that ability.
- Lenient where clients commonly differ without meaning anything else: `null` (or `""` for a number or boolean) counts as not sent; a number is accepted for a string; `"5"` and `"true"` are accepted for integers and booleans and passed on as 5 and true; an enum value in other letter case (`asc`) is accepted.
- Not checked: minimum/maximum, lengths, patterns and formats; the tools clamp or clean those themselves.
- The filter `mcp_abilities_validate_input` (bool, `Ability`) turns validation off for one ability.

Some values are checked against what the site really has (since 2.37.0):

- **Page templates** (`create_page`, `update_page`, `_wp_page_template` in `meta`): `default`, or one on WordPress's own list, the one `wp_insert_post()` checks: the theme's template files, a block theme's custom templates, and what plugins add with `theme_page_templates` or `theme_{post_type}_templates` (Elementor's `elementor_canvas`). The refusal lists what there is. A plugin that registers its templates in wp-admin only is not on the list during an MCP call; it has to register them everywhere, which WordPress also needs before it saves such a page outside wp-admin.
- **Taxonomies in `list_terms`:** one with an admin screen or public archives. Internal ones (menus, block template areas, WooCommerce product types and visibility) are refused. `post_format` has no screen but its terms are on the front end, so it is listed.
- **Widgets:** `add_widget` and `update_widget` need a registered widget type, so the option name they build is a real widget's. `remove_widget` does not, so a widget whose plugin has gone can still be removed.
- **`get_menu_items`:** `id` must be a number; a slug goes in `slug`.

---

## Upload Security (SSRF Prevention)

Both `upload_image` and `upload_file` download files from URLs a caller gives, and what they download becomes a public attachment. To prevent Server-Side Request Forgery, every such download goes through one guard (since 2.36.0, `includes/class-safe-fetch.php`):

1. **URL** — `http` and `https` only, no user name or password in the URL. The host must be a normal name or a normal IP address: forms such as `2130706433`, `0x7f.0.0.1` or `127.1`, which different parsers read differently, are refused.
2. **All addresses** — both A and AAAA records are read. One address that is not public refuses the URL, and so does a host that resolves to nothing. Refused: private, loopback, link-local and multicast, `0.0.0.0/8`, `100.64.0.0/10`, documentation and reserved ranges, cloud metadata addresses, the same in IPv6, and IPv4 inside IPv6 (`::ffff:127.0.0.1`).
3. **Port** — 80, 443 and 8080, WordPress's own list (filter `http_allowed_safe_ports`).
4. **Pin** — the connection is pinned to a checked address (`CURLOPT_RESOLVE`), so a second DNS answer cannot move it, and the address cURL connected to is compared afterwards. TLS is still checked against the host name.
5. **Redirects** — never followed by the HTTP client. They are followed here, at most 3, and each hop goes through all of the above. A redirect to an internal address is refused.
6. **Size** — the transfer stops at the limit (10 MB for images, 50 MB for files). A larger file is refused, not stored cut short.

**No cURL, no download.** WordPress's other transport cannot be pinned, so without PHP's cURL extension a URL is refused rather than fetched unpinned. An outgoing proxy (`WP_PROXY_HOST`) looks up addresses itself and cannot be pinned either: such a download is refused too, unless the filter `mcp_abilities_safe_fetch_through_proxy` returns true.

**Allowing an internal host on purpose:** the filter `mcp_abilities_safe_fetch_allow_host` (`false`, `$host`, `$url`) lets one host past the address and port checks. It is off by default and code-only; no tool can change it. Redirects are judged hop by hop, so an allowed host cannot redirect to one that is not.

`data_base64` and one-time upload links fetch nothing and are unchanged. They are the way to add an image that is not on the public web.

**One-time upload links** work once, also under two requests at the same moment (since 2.37.0): only the request whose delete actually removed the link's row goes on; the other gets HTTP 410.

---

## MIME Verification on Uploads

File type is **never trusted from the URL or HTTP Content-Type header**. After download, the actual file content is inspected:

- **Images** (`upload_image`): verified via `wp_get_image_mime()` which reads the file's binary signature.
- **Documents/media** (`upload_file`): verified via PHP's `finfo` extension (or `mime_content_type()` as fallback), which reads magic bytes.

If the detected MIME type does not match the allowlist, the temp file is deleted and an error is returned.

### `upload_image` allowlist
`image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`

### `upload_file` allowlist
PDF, TXT, CSV, DOC/DOCX, XLS/XLSX, PPT/PPTX, MP3, WAV, OGG, M4A, MP4, WebM, MOV

**Explicitly blocked:** ZIP, TAR, PHP, JS, HTML, SH, EXE, and all image types (use `upload_image`).

---

## When a Tool Fails (since 2.37.0)

The text of an exception or a PHP TypeError can carry server paths (`... called in /home/site/... on line 12`), so it does not go to the client. The whole of it, class, text, file and line, goes to the PHP error log, and the client gets a fixed line that says the details are there. This holds for tools, prompts, resources, permission checks, the adapter's `execute-ability` tool, the Abilities REST route and the STDIO bridge. WordPress 7.1 turns a throw inside an ability into a `WP_Error` carrying the same text, so AI Nexus catches it first, for every plugin's abilities (`Tool_Errors::contain_exceptions()`).

A tool's own `WP_Error` or `{success:false, error}` is what the AI needs to correct itself and is passed on exactly as it is. So is WooCommerce's `WC_Data_Exception`: its text is written for people ("Invalid or duplicated SKU."), and it is how WooCommerce's data classes report a bad value. The filter `mcp_adapter_tool_exception_message` (`$message`, `$throwable`, `$tool_name`) can do the same for another exception class.

---

## Limits on What a Client Can Make the Site Store (since 2.37.0)

- **MCP sessions** keep only the protocol version and the client's name and version from `initialize`, in strings of a fixed length, not the whole request. Sessions stored by an older version still read.
- **JSON-RPC batches** of more than 100 messages are refused whole with HTTP 400 and a JSON-RPC `-32600`, before any of them runs. MCP clients send one message per request, and the 2025-06-18 protocol dropped batches. Filter `mcp_adapter_max_batch_size` (0 or less turns the limit off).
- **The error log** gets no line for a request without a sign-in: that is the normal first step of the claude.ai sign-in, and anyone can repeat it. A signed-in user who is refused is still logged. A wrong OAuth bearer token is logged too (`token unknown or expired`).

---

## Client Addresses (since 2.37.0)

The activity log, connection approval records and the OAuth rate limits all take the caller's address from one place (`includes/class-client-ip.php`): `REMOTE_ADDR`. `X-Forwarded-For` is written by the caller, so before 2.37.0 anyone could put any address in the activity log.

A site behind a proxy names it with the filter `mcp_abilities_trusted_proxies`, off by default:

```php
add_filter( 'mcp_abilities_trusted_proxies', fn() => [ '10.0.0.5', '173.245.48.0/20', '2400:cb00::/32' ] );
```

When `REMOTE_ADDR` is on that list, `X-Forwarded-For` is read from the right and the first address that is not a listed proxy is the visitor's. What the visitor sent to the left of it is ignored.

**Behind Cloudflare or a similar proxy:** unless the web server restores the visitor's address (`mod_remoteip`, nginx `real_ip`, Cloudflare's own module) or the filter lists the proxy's ranges, the activity log shows the proxy's address, and everyone behind one proxy address shares a rate limit.

---

## Updates and Packages (since 2.37.0)

WordPress verifies nothing for a plugin that does not come from wordpress.org. For AI Nexus's own update and for the one-click extension install (`includes/class-package-guard.php`):

- **The package address** must start with `https://github.com/vgc-ltd-wp/vgc-plugin-updates/releases/download/`. GitHub redirects the download to another host, so it is this first address that is checked and then the file's hash, not where the file ends up coming from. `..` and encoded slashes in the address are refused.
- **When the update manifest gives a `sha256`**, the downloaded file must match it, or it is deleted and nothing is installed, with a message that shows both hashes. An entry without a `sha256` still installs, and so does one with a malformed value.
- **A cached hash older than a re-published package** does not stop the update: on a mismatch the manifest is read again, and its current hash decides.
- Only an address off the list or a hash that does not match refuses. If the check itself fails, WordPress downloads the package itself and the error log says so.

Filters: `mcp_abilities_verify_packages` (bool, `$package`, `$slug`; false skips both checks: the way out for a site this wrongly stops) and `mcp_abilities_package_url_prefixes` (where packages may come from, for a site with a mirror).

**What this protects against:** a poisoned WordPress update cache pointing at another address, a manifest entry pointing away from the channel, and a package changed in storage or on its way.

**What it does not protect against:** a compromise of the update repository itself. The hash is in the same manifest as the address, in the same public repository, so whoever can change the zip can change the hash too. That needs a signature made with a key the repository does not hold.

**What it does not cover:** updates of the extensions and other VGC plugins, which run their own copy of the updater, and a zip someone uploads by hand or installs with `wp plugin install <url>`. Extension installs are only checked against a hash where the manifest gives one.

---

## Uninstalling (since 2.37.0)

Deactivating removes nothing. Deleting the plugin (Plugins → Delete, `wp plugin uninstall`) removes, on every site of a network:

- the Application Passwords the Connect page recorded (only those; one someone made on their profile stays, even with the same name), so those connections stop working at once;
- the activity log, all settings (tool switches, read-only mode, consolidation, approval), the guide's conventions, the custom JavaScript from `inject_js`, upload links, the plugin's caches and rate-limit counters;
- the OAuth apps and tokens, who-is-connecting names, approval records and MCP sessions, **unless the VGC MCP Claude Connector is installed**, active or not: it serves the same connections under the same names.

Kept: the MCP attribution meta on revisions, posts, comments, order notes and orders, which is the record of what an AI changed; custom CSS, which is WordPress's own Additional CSS; extensions' settings; `vgc_cc_connections` and the connector's other own settings.

**So never delete the plugin to update it.** Deleting and uploading it again loses all of the above: every connection has to be made again, read-only mode is off until someone switches it back on, and JavaScript added with `inject_js` stops running for good. To install a zip by hand, upload it over the existing plugin (Plugins → Add New → Upload, "Replace current with uploaded").

---

## Disabled Tools Transparency

When a tool group is disabled, the AI agent does not receive an opaque failure — it receives a descriptive error:

```
The "Create User" tool is currently disabled. To use it, go to WordPress Admin → AI Nexus → Abilities and enable it.
```

This prevents the AI from silently failing or guessing workarounds, and allows it to advise the user on how to proceed. Since 2.36.0 this goes to a user who could otherwise use the tool; anyone else is simply refused, and does not see the tool listed.

---

## What the Plugin Cannot Protect Against

- **A compromised administrator account** — if an attacker gains admin credentials, the `manage_options` cap check passes legitimately.
- **A compromised update repository** — see [Updates and Packages](#updates-and-packages-since-2370).
- **A misconfigured allowlist** — if you enable User Management and connect an administrator account to the AI, the AI can manage users as that administrator would.
- **PHP file execution via uploads** — `upload_file` blocks `.php` MIME types, but WordPress's own upload restrictions should also be in place as a second layer.

**Recommendation:** Create a dedicated WordPress user for the AI agent with the minimum role needed (often `editor`), and only enable the tool groups required for the AI's actual tasks.
