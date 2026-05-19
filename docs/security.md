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
| Options Management | `manage_options` |
| Theme Management | `edit_theme_options` |
| User Management | `list_users` / `edit_users` |

---

## Options Protection

### Read protection (`get_option`)
The following categories of option keys are blocked from being read:

- Authentication keys and salts (`auth_key`, `secure_auth_key`, etc.)
- Payment gateway credentials (`woocommerce_stripe_*`, `woocommerce_paypal_*`, etc.)
- Email service API keys (`smtp_*`, `sendgrid_*`, `mailchimp_*`, etc.)
- Any key containing `_license`, `_api_key`, `_secret`, or `_private_key`

### Write protection (`update_option`)
The following keys can never be written:

| Key | Why |
|---|---|
| `siteurl`, `home` | Would redirect the entire site |
| `admin_email` | Could be used for account takeover |
| `active_plugins` | Could activate arbitrary code |
| `template`, `stylesheet`, `current_theme` | Could change the active theme |
| `default_role`, `users_can_register` | Could open registration to anyone |
| `upload_path`, `upload_url_path` | Could redirect file uploads |
| All auth keys and salts | Would invalidate all sessions |
| `mcp_abilities_settings`, `mcp_woo_settings` | Prevents self-modification of plugin config |
| `cron`, `doing_cron` | Could break scheduled events |

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
