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

### Since 2.39.0 (access analysis 2026-11-06: H2, M1, M2, L1, L2, H8)

**A connection ends with the account behind it.**
- Every authorization code, access token and refresh token carries a fingerprint of the user's password: the first 16 hex characters of `hash_hmac( 'sha256', user_pass, wp_salt( 'auth' ) )`. It reveals nothing about the password.
- When it no longer matches, the token is refused and deleted. This is checked on every MCP request, at refresh and at the code exchange, so it catches every way a password changes: a reset, the profile screen, WP-CLI, `wp_set_password()`, another plugin, even a direct database write. Only the user's tokens from before the change go; a connection made afterwards keeps working.
- Tokens issued before 2.39.0 get the fingerprint of the password they are first used with.
- A token is also refused, and deleted, when its user no longer exists or its app is no longer registered.
- A user's codes and tokens are deleted at once when the user is deleted (`deleted_user`), removed from a site of a network (`remove_user_from_blog`) or given another role (`set_user_role`, not for a new account). They connect again.

**The consent page asks more.**
- **A fresh sign-in.** The page needs a WordPress login less than 10 minutes old: the `login` time of the browser's session token. Otherwise it sends the person to `wp_login_url( <this page>, true )` (reauth) and back. Approve is accepted up to 10 minutes after that, as the page may have stayed open. A stolen login cookie, even the front-end `logged_in` one, therefore no longer turns into a connection. Filter `mcp_abilities_oauth_reauth_after` (seconds, default 600; 0 or less turns the rule off).
- **An account that can use the tools.** Only accounts with `edit_posts` may consent: no core tool works below it. Anyone else, a WooCommerce Customer for instance, gets a plain page (HTTP 403) saying their account cannot connect AI assistants to this site, and a posted Approve issues nothing. Filter `mcp_abilities_oauth_consent_capability`.
- **Claude's name and logo only for Claude.** "Authorize Claude" and the Claude logo appear only when the app returns to a Claude address (below). Any other app gets a neutral title with the name it registered under ("Authorize “…”"), a prominent warning that it is not a Claude address and that nobody checked the name, the full return address, and when the app registered. The error page shows no logo.

**"Claude only" (`restricted`) means Claude.** Under this policy, registration (`/register`) accepts these return addresses and nothing else:
- `https`, host exactly `claude.ai` or `claude.com` (standard port), path starting `/api/mcp/`. claude.ai's is `https://claude.ai/api/mcp/auth_callback`.
- Loopback, `http://localhost`, `http://127.0.0.1` or `http://[::1]` on any port, but only for an app that registers as public (`token_endpoint_auth_method` `none`). Such an app must then use PKCE: the consent page refuses a request without `code_challenge`, and the token endpoint refuses a code without one even with the secret.
- `https` on a host an administrator added under **AI Nexus → Connect → Other apps allowed to connect** (one per line; `*.example.com` includes sub-domains). These hosts are stored in `vgc_cc_allowed_origins` next to the old default wildcards, which no longer allow anything on their own; they stay there for an older connector that reads the same option.
- An address with a user name or password, a fragment, white space, a backslash or a dot segment (also encoded) is never accepted.
- The old wildcards (`*.claude.ai`, `*.anthropic.com`, any scheme, any path) are gone, so look-alikes such as `claude.ai.evil.com`, `evil.com/claude.ai`, `http://claude.ai/…` or another path are refused.
- A refused registration gets a message that names the setting where an address can be added, and one line in the error log.
- Apps registered before keep their exact registered addresses.

**A stored "open" becomes "restricted", once.** The old VGC MCP Claude Connector wrote `vgc_cc_dyn_reg_policy = open`, and AI Nexus inherits that option.
- At the first load of 2.39.0 a stored `open` is set to `restricted`, and AI Nexus's pages show a notice: what changed, how to add an address, and a Dismiss link (nonce, `manage_options`). Saving the policy also clears it.
- An administrator may choose "Any app" again; the step is recorded in `mcp_abilities_oauth_upgrades` and never runs twice.

**Refresh tokens (M1).**
- A client is remembered as one that keeps a secret when it registers with `client_secret_post` or `client_secret_basic`. A refresh token is marked when it was issued after the client proved its secret (at the code exchange or a refresh).
- A refresh for such a client, or with such a token, requires a valid `client_secret` (HTTP 401 `invalid_client` without it). An unmarked token that comes with a valid secret gets the mark.
- A public client's refresh token is still replaced on each use, with the 120-second grace for two runs refreshing at once. A marker of the replaced token (its hash, the client, the user, when) is kept for the refresh lifetime. If that old token is presented after the grace period, someone else has a copy: every code and token of that client and user is deleted, the app must connect again, and it is logged ("refresh token REUSED") and written to the activity log.
- Since 2.41.0 (review C5): inside the grace period the old token gets the **same** successor back, not a new one, so two copies of one token cannot each grow a chain of their own. The successor is kept with the old token, sealed (libsodium secretbox, key derived from the `auth` salt), only for the grace period. If the successor has already been used (or cannot be opened), the old token coming back means two chains have split: the connection ends the same way, logged as "after its successor had been used". A token rotated by 2.40.0 or earlier has no successor kept and works as before inside the grace period.

---

## Connections: How Long They Live, and Who Sees Them (since 2.39.0)

**Unused connections switch off after 14 days** (the owner's decision; no absolute lifetime).
- An OAuth refresh token lives 14 days, pushed forward each time it is used (30 days before). At the update, every existing refresh token that ran longer is cut to 14 days from that moment, so nobody is cut off on the spot.
- An Application Password made on AI Nexus's Connect page (its UUID is in `mcp_abilities_connections`) is deleted with `WP_Application_Passwords` after 14 days unused. "Unused" is judged by the later of WordPress's own `last_used` (which WordPress writes at most once a day) and AI Nexus's last activity for it, or by its creation date if it was never used. For one from before 2.39.0 the 14 days start at the update at the earliest (the time of the upgrade step `refresh_cap_239`), as for refresh tokens, so nothing is deleted on the first night.
- Other Application Passwords are only listed, never removed.
- A daily cron event, `mcp_abilities_idle_sweep`, does this. It also deletes expired and dead OAuth rows (gone user, gone app, changed password) and their entries in the list. Every removal is written to the activity log (`connection_idle_removed`).
- Filter `mcp_abilities_idle_days` (default 14, kept between 1 and 365).

**The list of connections that are on.** One entry per OAuth app + user and per Application Password used on MCP, kept whether approval is on or off and for administrators too. Connection approval's records exist only while approval is on and skip administrators.
- Stored in the non-autoloaded option `mcp_abilities_connection_registry`: kind, user, app or Application Password, app name, the "Who is connecting?" name, first seen, last active, last address.
- It is updated on MCP requests at most once a minute per connection, and holds at most 1,000 entries (the longest unused go first).
- The AI cannot read it (`get_option`) or change it (`update_option`).
- **AI Nexus → Connections** starts with "Connections that are on": every OAuth connection with a live refresh token, every Application Password made on the Connect page, and every other one used on MCP. Each row shows the user, the app and who said they are connecting, the sign-in kind, connected since, last active and from which address, when it switches off if unused, and the approval status when approval is on.
- Its **Disconnect** button needs the page's nonce and `manage_options`. For OAuth it deletes that app + user's codes and tokens; for an Application Password it deletes the password. Every Disconnect is written to the activity log.
- **Your AI connections**, on each user's own profile screen, lists only that user's connections, with **Revoke**. Revoke needs a nonce of its own, and the key must belong to the current user.

**"Also hold administrators' new connections"** (next to "Require admin approval", default off).
- While it is on, administrators' new connections wait for approval too. Switching it on approves the administrators' connections that work at that moment.
- The `mcp_abilities_approval_exempt_admins` filter still has the last word.

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
- **Read-only mode does not take AI Nexus's own tools off the list.** A write tool someone holds the capability for stays listed and is refused when called, so switching the mode off needs no reconnect (a group tool leaves its write actions out of its description). A connection waiting for approval still sees only the status tool. Other plugins' tools that change the site are taken off the list while the mode is on: see [Other Plugins' AI Tools](#other-plugins-ai-tools-and-mcp-servers-since-2380).
- **`mcp-adapter-discover-abilities` and `mcp-adapter-get-ability-info`** need `edit_posts` (they needed `read`). Set by the adapter's filters `mcp_adapter_discover_abilities_capability` and `mcp_adapter_get_ability_info_capability`; a site can set its own there. `mcp-adapter-execute-ability` still needs only `read`, because every ability checks its own permission, but it is listed only for someone who could run at least one ability.
- **The usage guide** needs `edit_posts`. Everyone who gets it gets what is useful for working: post type and taxonomy slugs, the page builder's name, whether WooCommerce and WPML are active, read-only mode, what each extension does. Only `manage_options` also sees the WordPress version, the theme, the AI Nexus plugins with their versions, the adapter, the security plugin and whether REST is blocked, the approval setting and how remote connections work. `guide_version` carries the plugin's version only for them.
- **`initialize`** gives an account below `edit_posts` a short note in place of the usual orientation, which would send it to tools it does not have.

Another plugin's copy of the MCP adapter older than 0.5.0 has no list filter and shows every tool. The checks on each call still apply.

---

## Other Plugins' AI Tools and MCP Servers (since 2.38.0)

Until 2.37.0, read-only mode and the tool switches bound only AI Nexus's own tools. Other plugins' abilities reached AI clients past them, and ran while read-only mode was on:

1. through the adapter's `mcp-adapter-execute-ability` tool on AI Nexus's own server, for any ability marked `meta.mcp.public` (WooCommerce's `product-create`, `product-update`, `product-delete`, `order-update-status`, `order-add-note`; AIOSEO's robots rules);
2. as tools other plugins add to AI Nexus's server (`mcp_adapter_default_server_config`);
3. on MCP servers other plugins create on the adapter (WooCommerce creates `woocommerce-mcp` when its MCP feature is on);
4. through WordPress's own Abilities REST route (`/wp-abilities/v1/abilities/<name>/run`) for abilities with `show_in_rest`.

Since 2.38.0 one gate covers all four (`includes/class-other-tools.php`):

- **Every ability that is not AI Nexus's own** gets its permission and execute callbacks wrapped as it is registered (`wp_register_ability_args`). AI Nexus's own are core's, every extension's and anything else built on `MCP_Abilities\Ability` (their callbacks close over one; they enforce read-only mode themselves), plus the adapter's three `mcp-adapter/*` abilities. Names cannot tell them apart: the WooCommerce extension registers `woocommerce/...` abilities of its own next to WooCommerce's.
- **It refuses only an AI:** an MCP request (any adapter server, a STDIO tool call) or the Abilities REST route, except for a signed-in browser (cookie and REST nonce) and WP-CLI. A person in wp-admin, cron and a plugin using its own ability are never refused.
- **It refuses** a tool an administrator turned off; a new one while "keep them off" is chosen; and, while read-only mode is on, every tool that is not a read. A read is a tool its plugin marks `meta.annotations.readonly: true` (WordPress 6.9 ability annotations; WooCommerce, AIOSEO, VGC Stats, VGC Google Product Feed and VGC Stock Manager mark theirs), or an unmarked tool the administrator ticked "Counts as a read". `false`, `null` or no annotation counts as a change: deny by default. A tool with its own handler (no ability behind it) is read by its `readOnlyHint`.
- **The AI is told why** (`mcp_abilities_read_only`: the tool changes the site and read-only mode is on; `mcp_abilities_tool_off`: a site administrator turned it off), with HTTP 403 on the Abilities REST route. Every refusal is written to the activity log, once per tool and request.
- **What the AI sees:** `tools/list`, `resources/list` and `prompts/list` on every adapter server, `mcp-adapter-discover-abilities` and `mcp-adapter-get-ability-info` leave out what would be refused (info answers with the reason instead).
- **Every tool call** on every adapter server goes through a second check (`mcp_adapter_pre_tool_call`), which also covers tools other plugins add with a handler of their own.

**AI Nexus → Other AI tools** (administrators, `manage_options`) lists:

1. the MCP servers on the site, each with an On/Off switch. AI Nexus's own server is first and always on;
2. other plugins' tools an AI can reach, by plugin: how it reaches each one, whether it reads or changes (from the plugin's annotation; missing is "Changes (not marked)"), an On/Off switch, and "Counts as a read" for a tool its plugin did not mark;
3. other MCP-like addresses (a path segment `mcp`, or `mcp-` / `-mcp` in a segment) that the adapter does not serve. AI Nexus cannot see or limit their tools; it can only switch the address off;
4. what happens when a plugin adds a new AI tool or MCP server: allow it (the default), or keep it off until switched on. "New" is anything not yet shown on this page; opening the page with "keep them off" stores what it shows for the first time as off, so seeing a tool never switches it on.

A server or an address that is off is gone: its routes are removed (`rest_endpoints`) and every request to it gets a 404 (`rest_pre_dispatch`), in any letter case, as `/wp-json/...` and as `?rest_route=`, inside `/batch/v1` too. AI Nexus's own server, `/mcp/...`, `/mcp-abilities/...` and `/vgc-cc/...` can never be turned off, whatever the option says.

The switches live in one option, `mcp_abilities_other_tools`, which the `update_option` ability refuses (prefix `mcp_abilities_`), so an AI cannot switch anything back on. The page saves through admin AJAX with a nonce and `manage_options`.

**Sign-in.** AI Nexus's sign-in (Application Passwords, its OAuth tokens) and "require admin approval" do not cover a server with a sign-in of its own: WooCommerce's MCP server uses WooCommerce API keys. Read-only mode and the switches on this page do cover the tools of every server on the adapter, whoever signed in.

**What it cannot cover:**

- an MCP endpoint a plugin serves itself, without the adapter: AI Nexus cannot see its tools, only switch its address off;
- `wp mcp-adapter serve` (STDIO, WP-CLI): its tool calls are refused like any other, but anyone who can run WP-CLI can run any PHP on the site, so it is not a boundary;
- an ability registered with an `ability_class` that overrides `execute()` and `check_permissions()` so its callbacks never run;
- another plugin's copy of the adapter older than 0.5.0 (no list or pre-call filters: its tools stay listed, the ability gate still refuses them), and resources or prompts with handlers of their own on another plugin's server (only the server switch applies).

---

## Custom CSS & JS (since 2.36.0)

`inject_js` needs `unfiltered_html` and `inject_css` needs `edit_css`, on top of `manage_options`: on multisite only super admins have them, and `DISALLOW_UNFILTERED_HTML` takes them from everyone. The dispatcher, the single tool and the ability itself all check.

This gates writes only. Code already stored keeps running, `get_custom_code` still reads it and `clear_css` still removes CSS, for anyone with `manage_options`. The other ways to the same code are closed too: `update_option` refuses `mcp_custom_*`, the Additional CSS post needs `edit_css` to edit, and WordPress's own filter removes custom CSS from `update_global_styles` for someone without `unfiltered_html`.

**Locked raw code (since 2.41.0, review C3).** An administrator can lock raw code away from AI tools with the `MCP_ABILITIES_DISABLE_CUSTOM_CODE` constant or by returning false from the `mcp_abilities_custom_code_enabled` filter. That switches the Custom CSS & JS group off, and it now also covers every other tool that stores markup or CSS for the whole site (`Custom_Code::locked()`, `raw_allowed()`, `css_allowed()`):

- `create_template`, `update_template`, `create_template_part`, `update_template_part` and `update_navigation` save the markup through `wp_kses_post()` (as WordPress does for a post by someone without `unfiltered_html`) unless the account has `unfiltered_html` and raw code is not locked. Block comments and ordinary markup are kept; `<script>` and event handlers are not.
- `update_global_styles` leaves out every `css` key (the custom CSS at the top and per block) unless the account has `edit_css` and raw code is not locked.
- The answer carries a `note` saying what was removed and why.

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

## The Licence Key (since 2.40.0)

AI Nexus → Licence holds one key for the site (`includes/class-licence.php`). **For now it only tells: no tool is turned off or slowed down because of a licence, whatever the server answers.**

- **No key, no traffic.** A site without a key never contacts the licence server. There is no daily check and no call when the page is opened.
- **What is sent** with a key, once a day and when an administrator opens the page (at most hourly): the key, the site's `home` address, `wp_get_environment_type()`, a random id for this installation, and the WordPress, PHP and AI Nexus versions. Always in the body of a POST over https; the key is never in an address or a log.
- **What is trusted.** An answer counts only if it carries a valid Ed25519 signature, made with a key pinned in the plugin (by key id, over the exact bytes of the payload), and is about this site's host. A stored answer is checked again each time it is read, so changing the option by hand changes nothing, and an answer older than the last one is refused.
- **A failure is not a decision.** A timeout, a TLS error, a 4xx or 5xx, a rate limit, or an answer that does not verify leaves the last verified answer as it was. After three weeks without an answer the page and AI Nexus's screens say so. Only a verified answer can say a key has expired or is not valid here.
- **Expiry cannot be outrun by a silent server.** A stored "valid" answer whose end date has passed counts as expired, and a clock set back does not undo it: the time used is the later of the site's clock and the time of the answer.
- **The AI is told** when a key on the site has expired or is not valid here (in the MCP handshake and in the usage guide), if an extension is active. A site without a key says nothing to the AI.
- **The key and the AI.** `update_option` refuses `mcp_abilities_licence` (prefix `mcp_abilities_`), `get_option` refuses it by name and its cache rows, and it is not autoloaded. Pages show only its last five characters. The forms need `manage_options` and a nonce.
- **Deleting the plugin** tells the licence server to let the site go, then removes the key. Deactivating keeps the key and stops the daily check until the next admin page.

The client's side of the protocol is in `docs/client-contract.md` of the licence server's repository.

---

## Options Protection

### Read protection (`get_option`)
The following categories of option keys are blocked from being read:

- Authentication keys and salts (`auth_key`, `secure_auth_key`, etc.)
- Payment gateway credentials (`woocommerce_stripe_*`, `woocommerce_paypal_*`, `woocommerce-ppcp-settings`, any key containing `ppcp`, etc.)
- Email credentials: `wp_mail_smtp`, core `mailserver_pass`, any key containing `smtp`, `sendgrid`, `mailchimp`, etc.
- `jetpack_private_options` (and any key containing `jetpack_private`), `fs_accounts` (Freemius)
- AI Nexus OAuth data: `vgc_cc_oauth_clients` and any key starting with `vgc_cc_` (clients, tokens, policy); who is connected and who approved it; the list of connections that are on (`mcp_abilities_connection_registry`) and the Connect page's Application Passwords (`mcp_abilities_connections`) (since 2.39.0)
- The Analytics extension's Google sign-in: any key containing `vgc_analytics_` (`vgc_analytics_oauth` holds the client secret and refresh token, the transients `vgc_analytics_otok_*` the access tokens) (since 2.39.0)
- This site's licence key (`mcp_abilities_licence`) (since 2.40.0)
- Any key containing `_license`, `_licence`, `_api_key`, `_secret`, `_private_key`, `_pass` or `token`
- The cache rows of every name above: `_transient_…`, `_transient_timeout_…`, `_site_transient_…` and `_site_transient_timeout_…` (since 2.39.0)
- FluentSMTP (`fluentmail…`), Post SMTP (`postman_…`) and UpdraftPlus remote storage (`updraft_…`) (since 2.41.0)

**Credentials inside a value (since 2.41.0, review C2).** Many plugins keep an API user, password, token or key as one field of a settings array, which a rule on the option's name cannot see. Every option `get_option` returns is looked into (arrays, objects and JSON strings, up to 12 levels): a field whose name means a password, passphrase, secret, token, API / private / access / auth / signing key, HMAC, licence or credentials, and which holds a value, is shown as `*** hidden by AI Nexus ***`. The answer lists the hidden fields in `masked_fields`. Public keys (a publishable key, a reCAPTCHA site key), names that only contain "pass" inside a word (`passive_mode`, `bypass_cache`) and empty fields are left as they are (`includes/class-option-secrets.php`).

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
| `mcp_abilities_` | This plugin's own settings: read-only mode, connection approval, the guide conventions every AI session reads, the tool consolidation switch, the switches for other plugins' AI tools and MCP servers |
| `_transient_`, `_site_transient_` | Caches, including the update and extension-installer package URLs |
| `auto_update_` | Which plugins and themes update themselves |
| `theme_mods_`, `widget_`, `mcp_custom_` | Customizer settings, widget content, custom code (use the dedicated tools) |
| `mcp_cpt_`, `mcp_wpml_`, `mcp_analytics_`, `mcp_tracker_`, `vgc_analytics_`, `vgc_pinterest_` | AI Nexus extensions' settings, stored definitions and Google / Pinterest sign-ins (since 2.39.0) |

And every AI Nexus extension's settings (since 2.39.0): the `option_key` each extension registers through `mcp_abilities_extensions`, plus `mcp_woo_settings`, `mcp_avada_settings`, `mcp_elementor_settings`, Tracker's `vgct_nexus` and Environment Lock's `vgc_ainx_envlock`, whether or not the extension is active. An AI cannot switch an extension's disabled tools back on.

**Mail, money and protections (since 2.41.0, review C1).** The review's example: point an SMTP plugin at another server, then ask for an administrator's password reset. Also refused:

| What | Why |
|---|---|
| Every name `get_option` refuses | An AI may not change what it may not read |
| `woocommerce_bacs_accounts` | The bank account customers pay into |
| `woocommerce_…_settings` | WooCommerce payment gateways, shipping methods and e-mails (who receives order mail) |
| `recovery_keys` | A planted key would open recovery mode |
| `wp_mail_…`, `mailserver_…`, `fluentmail…`, `postman_…`, `easy_wp_smtp…`, `swpsmtp_…`, `vgc_mailer_…` | Where the site's mail goes, and core's post-by-email account |
| `itsec…`, `wordfence…`, `aiowps_…`, `sg_security…`, `cerber…`, `wpcerber…`, `limit_login_…`, `wp_2fa_…`, `two_factor_…`, `jetpack_…`, `sucuri…`, `updraft_…` | Security, login, two-factor and backup plugins |
| `googlesitekit_…` | Which Google property Site Kit reads, and whose sign-in |
| `vgct_…`, `vgc_trash_guard_…`, `vgc_lic_…`, `vgc_cookie_…`, `vgc_ainx_…` | VGC plugins whose settings are a protection or a credential |

**Credentials inside a value.** When the stored value holds credential fields (see Read protection above), `update_option` keeps them: a field sent back as `*** hidden by AI Nexus ***`, or left out, keeps its stored value; a different value is refused, and so is replacing the whole setting with something that is not an array or object. An AI can change the other fields of the same setting. A new option has nothing stored to protect.

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
- **The error log** gets no line for a request without a sign-in: that is the normal first step of the claude.ai sign-in, and anyone can repeat it. A signed-in user who is refused is still logged. A wrong OAuth bearer token is logged too (`token unknown or expired`), but since 2.39.0 at most once per address (an IPv6 /64) every 10 minutes.

---

## Client Addresses (since 2.37.0)

The activity log, connection approval records and the OAuth rate limits all take the caller's address from one place (`includes/class-client-ip.php`): `REMOTE_ADDR`. `X-Forwarded-For` is written by the caller, so before 2.37.0 anyone could put any address in the activity log.

A site behind a proxy names it with the filter `mcp_abilities_trusted_proxies`, off by default:

```php
add_filter( 'mcp_abilities_trusted_proxies', fn() => [ '10.0.0.5', '173.245.48.0/20', '2400:cb00::/32' ] );
```

When `REMOTE_ADDR` is on that list, `X-Forwarded-For` is read from the right and the first address that is not a listed proxy is the visitor's. What the visitor sent to the left of it is ignored.

**IPv6 (since 2.39.0):** the OAuth rate limits and the bad-token log count an IPv6 caller by its /64 network, since one machine usually holds a whole /64 and can pick any address in it. IPv4 is counted by the address.

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

**"Check again" (since 2.39.0).** Core's updater skips its one-hour manifest cache for `?force-check=1` only for a user with `update_plugins`, on the Updates or Plugins screen. Before, any visitor could add it to a request that runs `admin_init` (`admin-post.php`, and `admin-ajax.php` where WordPress or a plugin reads the update list there) and make the site fetch the manifest every time. The extensions' own copies of the updater get the same change with their next releases.

---

## Uninstalling (since 2.37.0)

Deactivating removes nothing. Deleting the plugin (Plugins → Delete, `wp plugin uninstall`) removes, on every site of a network:

- the Application Passwords the Connect page recorded (only those; one someone made on their profile stays, even with the same name), so those connections stop working at once;
- the activity log, the settings (tool switches, consolidation, the switches on Other AI tools), the guide's conventions, the custom JavaScript from `inject_js`, upload links, the plugin's caches and rate-limit counters;
- the OAuth apps and tokens, who-is-connecting names, approval records and MCP sessions, **unless the VGC MCP Claude Connector is installed**, active or not: it serves the same connections under the same names;
- since 2.39.0: the list of connections that are on, the record of the one-time upgrade steps and their notice, and the daily `mcp_abilities_idle_sweep` event (deactivating removes the event too; it is scheduled again on the next load).

Kept: read-only mode and "require admin approval" (with "Also hold administrators' new connections"), so that deleting and uploading the plugin again never quietly makes a site less protected (two small options stay behind in the database; delete `mcp_abilities_read_only` and `mcp_abilities_require_approval` by hand if the plugin is gone for good); the MCP attribution meta on revisions, posts, comments, order notes and orders, which is the record of what an AI changed; custom CSS, which is WordPress's own Additional CSS; extensions' settings; `vgc_cc_connections` and the connector's other own settings.

**So never delete the plugin to update it.** Deleting and uploading it again loses all of the above: every connection has to be made again, and tool switches are back to their defaults, and JavaScript added with `inject_js` stops running for good. To install a zip by hand, upload it over the existing plugin (Plugins → Add New → Upload, "Replace current with uploaded").

---

## Disabled Tools Transparency

When a tool group is disabled, the AI agent does not receive an opaque failure — it receives a descriptive error:

```
The "Create User" tool is currently disabled. To use it, go to WordPress Admin → AI Nexus → Abilities and enable it.
```

This prevents the AI from silently failing or guessing workarounds, and allows it to advise the user on how to proceed. Since 2.36.0 this goes to a user who could otherwise use the tool; anyone else is simply refused, and does not see the tool listed.

---

## Since 2.42.0 (security review 2026-11-10, low findings)

### Content and comments
- **Internal content types (VT-31).** The general post tools work only with post types that have an admin screen (`show_ui`). WordPress and plugins keep internal records in the other types: revisions, menu items, privacy requests, oEmbed cache, block templates, global styles, fonts, WooCommerce variations, refunds and HPOS order placeholders. Their capabilities often do not say who may really touch them (an order placeholder uses the ordinary post capabilities). These items are refused for reading, listing, searching, creating, changing and deleting, administrators included. Menus, templates, navigation, global styles and WooCommerce products have their own tools with their own checks. `list_post_types` shows `has_admin_screen`.
- **Comments (VT-32).** `moderate_comments` lets an account use the comment tools at all. Each change to a comment (approve, hold, spam, trash, delete, reply) also needs `edit_comment` on that comment, as on WordPress's own screens and REST API (`includes/class-comment-access.php`). A WooCommerce order note needs the permission to edit orders (`edit_shop_orders`), with HPOS on or off. Comments on other internal items are refused. The list shows comments the account may edit, plus approved comments on items it may read, and says how many it left out.
- **Registered custom fields (VT-33).** When a plugin registers a meta key with its own permission rule (an `auth_callback`, or an `auth_post_meta_{key}` filter), that rule decides through `edit_post_meta`, for reading and writing, as in the block editor and the REST API. A key registered without a rule keeps AI Nexus's protected-key rule.

### Tools, users and requests
- **Other plugins' tools (VT-40).** A tool counts as AI Nexus's own only when what it runs is an AI Nexus ability. A tool another plugin builds from an ability it never registered is that plugin's tool: it appears under Other AI tools, can be switched off, and follows read-only mode.
- **Users and custom CSS (VT-43).** The last administrator keeps the administrator role. An account cannot give itself a role without `edit_users` and `promote_users` (a multisite super admin excepted). When a user is deleted, `reassign_to` must be another existing user. `clear_css` asks `edit_css` like `inject_css`.
- **Request addresses (VT-44).** WordPress matches REST routes with `$`, which also matches before a final line break, so `?rest_route=/woocommerce/mcp%0A` reaches that route. AI Nexus now compares routes the same way (any letter case, one trailing line break ignored: `Request_Context::normalize_route()`), so such an address is still seen as an MCP request, also when another plugin's older MCP adapter (before 0.5.0, without the tool-call filter) serves it. Read-only mode and the Other AI tools switches apply.

### Sign-in and connections
- **Redirect addresses (VT-34, VT-35).** The redirect address that is checked is the one stored: a registration whose address `esc_url_raw()` would change is refused under every policy. The registration policy is read through `Options::policy()`, which treats any unexpected stored value, also a non-string, as "Claude only".
- **Roles and approval (VT-36).** A role added or removed (`add_user_role`, `remove_user_role`) ends the user's OAuth connections like a role change. Whatever ends an OAuth connection (role, Disconnect, Revoke, a reused refresh token, removal) also removes its approval record unless it was rejected, so connecting again waits for approval while approval is on.
- **Other Application Passwords (VT-37).** Application Passwords not made on AI Nexus are only listed, by default (owner's decision, 2026-11-06). An administrator who can edit users may switch on "Also switch off other Application Passwords" (`mcp_abilities_idle_others`, kept on uninstall like "require admin approval"). The idle time then counts from the switch-on at the earliest, and every Application Password of the site's users that goes unused that long is deleted by the sweep. The list marks Application Passwords made in the last 7 days as New.
- **Disconnect (VT-38).** Disconnecting another user's Application Password needs `edit_user` on that user (it opens every site of a network). A claude.ai sign-in, which only opens this site, needs `manage_options` here.
- **Idle passwords at use (VT-39).** A password that should be switched off is refused at sign-in (`wp_authenticate_application_password_errors`) and deleted then, so the 14-day rule holds without WP-Cron.
- **Licence answers (VT-41).** Every call sends a random nonce; a signed answer that carries a nonce must carry this call's, so a recorded answer cannot be replayed as the answer to a later call. Answers without one (today's server) are judged as before. At most 1 MB of an answer is read.
- **Denial of service (VT-42).** Unknown or expired bearer tokens on MCP routes are counted per address (`Rate_Limit` bucket `bearer`, 30 a minute, filter `mcp_abilities_oauth_rate_limit`); past the limit the answer is 429 with Retry-After instead of 401, and a valid token is never refused because of it. Past any limit a counter is no longer written on each request. An unused app registration whose consent page a signed-in person who may connect opened in the last 15 minutes is not removed to make room. AI Nexus never trusts `CF-Connecting-IP`, `True-Client-IP`, `X-Real-IP`, `X-Forwarded-For` or `Forwarded` on its own; when several different callers arrive with such a header from one address the site has not named as a proxy, administrators see a notice explaining `mcp_abilities_trusted_proxies` (option `mcp_abilities_proxy_hint`).

---

## What the Plugin Cannot Protect Against

- **A compromised administrator account** — if an attacker gains admin credentials, the `manage_options` cap check passes legitimately.
- **A compromised update repository** — see [Updates and Packages](#updates-and-packages-since-2370).
- **A misconfigured allowlist** — if you enable User Management and connect an administrator account to the AI, the AI can manage users as that administrator would.
- **PHP file execution via uploads** — `upload_file` blocks `.php` MIME types, but WordPress's own upload restrictions should also be in place as a second layer.
- **An MCP endpoint another plugin serves itself**, outside the MCP adapter — AI Nexus cannot see or limit its tools; AI Nexus → Other AI tools can only switch its address off. See [Other Plugins' AI Tools](#other-plugins-ai-tools-and-mcp-servers-since-2380).
- **WP-CLI** — `wp mcp-adapter serve` (STDIO) is for people who can already run any PHP on the site.

**Recommendation:** Create a dedicated WordPress user for the AI agent with the minimum role needed (often `editor`), and only enable the tool groups required for the AI's actual tasks.
