# Security Model — VGC AI Nexus

VGC AI Nexus is designed on a single principle: **the AI can never do anything the authenticated WordPress user couldn't do through the admin dashboard.** Every tool call goes through the same capability checks WordPress itself uses.

---

## Authentication

Tools are exposed via the MCP Adapter plugin's REST endpoint. Authentication uses **WordPress Application Passwords** (built-in since WP 5.6). The AI agent sends HTTP Basic credentials on every request — no sessions, no cookies, no tokens with elevated scope.

To create an Application Password: **Users → Your Profile → Application Passwords → Add New**.

Scope the password to an account with only the capabilities the AI actually needs. A dedicated `editor`-role account is safer than using an administrator account.

---

## Capability Enforcement

Every ability defines a `required_cap` that is checked by WordPress's `current_user_can()` before the tool executes.

| Ability Group | Required Capability |
|---|---|
| Post Management | `edit_posts` |
| Page Management | `edit_pages` |
| Media Management | `upload_files` |
| Comment Management | `moderate_comments` |
| Taxonomy Management | `manage_categories` |
| Menu Management | `edit_theme_options` |
| Options Management | `edit_posts` to reach the tool (its `list_post_types` action declares it); every other action needs `manage_options` |
| Theme Management | `edit_theme_options` |
| User Management | `list_users` / `edit_users` |

With consolidation on (the default), each group is one tool whose gate is the lowest capability among its actions; the selected action's own capability is checked again before it runs. With consolidation off, each action is its own tool with its own capability.

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
- **Non-admins cannot modify administrator accounts** — editing or deleting an account with a higher role is blocked.
- **The last administrator cannot be deleted** — prevents accidental lockout.
- **Role escalation is blocked** — `update_user` requires `promote_users` to change any role; `manage_options` to assign `administrator`.

---

## Upload Security (SSRF Prevention)

Both `upload_image` and `upload_file` download files from external URLs. To prevent Server-Side Request Forgery:

1. **Scheme check** — only `http` and `https` are accepted.
2. **DNS resolution** — the hostname is resolved to an IP address before the request.
3. **IP range check** — the resolved IP is validated against `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. Requests to `10.x.x.x`, `192.168.x.x`, `172.16–31.x.x`, `127.x.x.x`, `169.254.x.x`, and reserved ranges are rejected.

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

## Disabled Tools Transparency

When a tool group is disabled, the AI agent does not receive an opaque failure — it receives a descriptive error:

```
The "Create User" tool is currently disabled. To use it, go to WordPress Admin → AI Nexus → Abilities and enable it.
```

This prevents the AI from silently failing or guessing workarounds, and allows it to advise the user on how to proceed.

---

## What the Plugin Cannot Protect Against

- **A compromised administrator account** — if an attacker gains admin credentials, the `manage_options` cap check passes legitimately.
- **A misconfigured allowlist** — if you enable User Management and connect an administrator account to the AI, the AI can manage users as that administrator would.
- **PHP file execution via uploads** — `upload_file` blocks `.php` MIME types, but WordPress's own upload restrictions should also be in place as a second layer.

**Recommendation:** Create a dedicated WordPress user for the AI agent with the minimum role needed (often `editor`), and only enable the tool groups required for the AI's actual tasks.
