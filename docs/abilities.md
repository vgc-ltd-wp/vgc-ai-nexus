# Abilities Reference — VGC AI Nexus

All tools are registered under the `woocommerce/` namespace in the MCP server. Each tool maps 1:1 to a WordPress capability gate — the AI can only do what the authenticated user is allowed to do.

---

## Post Management

### `list_posts`
List WordPress posts with optional filters.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `publish`, `draft`, `pending`, `private`, `any` (default: `publish`) |
| `per_page` | integer | No | Results per page, max 100 (default: 20) |
| `page` | integer | No | Page number (default: 1) |
| `author_id` | integer | No | Filter by author user ID |
| `category_id` | integer | No | Filter by category term ID |
| `search` | string | No | Keyword search |
| `orderby` | string | No | `date`, `title`, `ID`, `modified`, `rand` (default: `date`) |
| `order` | string | No | `ASC`, `DESC` (default: `DESC`) |
| `fields` | string[] | No | Item fields and meta keys to return. Naming an item field (`id`, `title`, `slug`, `status`, `date`, `modified`, `author_id`, `excerpt`, `permalink`, `comment_count`, or `featured_image`, `categories`, `tags`, which only come when named) cuts each item down to `id` and the fields named. Any other name is a meta key, under `meta` (`meta.<key>` is always one). Meta keys alone: `id`, `title` and `meta` (2.48.0; whole items plus `meta` before, and still inside another tool call such as a Batch step) |

**Returns:** `{ items: [...], total, total_pages, page, per_page, offset }`, plus `fields_note` when a name in `fields` gave nothing (a meta key no listed item has a value for, or `meta` alone), and `note` when the list is limited to what the account may see.

For long lists name only what you need: 100 posts with `"fields": ["id", "title", "_thumbnail_id"]` (or, since 2.48.0, `["_thumbnail_id"]` alone) are about 7,000 characters, against 37,000 for whole items with that meta key and 34,000 without `fields`. Since 2.48.0 a list of whole items of 10,000 characters or more says so in one line of `note`, with its size and the size of the same list with `"fields": ["id", "title"]` (`list_posts`, `list_pages`, `list_media`, `list_terms`).

---

### `get_post`
Retrieve full post content and metadata.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Post ID |

**Returns:** Full post object including content, excerpt, author, categories, tags, featured image, and custom fields.

---

### `create_post`
Create a new WordPress post.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `title` | string | **Yes** | Post title |
| `content` | string | No | Post body (HTML allowed) |
| `status` | string | No | `publish`, `draft`, `pending`, `private` (default: `draft`) |
| `excerpt` | string | No | Manual excerpt |
| `author_id` | integer | No | Author user ID (requires `edit_others_posts` to set another user) |
| `category_ids` | array | No | Array of category term IDs |
| `tag_ids` | array | No | Array of tag term IDs |
| `featured_image_id` | integer | No | Media attachment ID |
| `meta` | object | No | Key/value pairs of custom post meta |

**Returns:** `{ id: N, url: "..." }`

---

### `update_post`
Update an existing post.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Post ID |
| `title` | string | No | New title |
| `content` | string | No | New body content |
| `status` | string | No | `publish`, `draft`, `pending`, `private`, `trash` |
| `excerpt` | string | No | New excerpt |
| `category_ids` | array | No | Replace category assignments |
| `tag_ids` | array | No | Replace tag assignments |
| `featured_image_id` | integer | No | New featured image attachment ID |
| `meta` | object | No | Key/value pairs to update in post meta |

**Returns:** `{ id: N, url: "..." }`

> Note: `update_post` calls `wp_update_post()` which automatically saves a revision. Use `list_revisions` / `get_revision` to view revision history.

> **Content (since 2.49.0).** `content` is stored as sent, `<style>`, `<script>` and form fields included, when the account has `unfiltered_html` (an administrator or editor on a single site, only a super admin on multisite, nobody with `DISALLOW_UNFILTERED_HTML`) and raw code is not locked (`MCP_ABILITIES_DISABLE_CUSTOM_CODE` or the `mcp_abilities_custom_code_enabled` filter). Otherwise it goes through `wp_kses_post()`, as WordPress filters a post by such an account, and when that took a tag, attribute or comment out the answer has a `note` saying so. Rewrites that lose nothing (`&` written as `&amp;`, `<br/>` as `<br />`) get no note. The same holds for `create_page` and `update_page`. Backslashes are kept; before 2.49.0 every one was lost (`/\d+/` became `/d+/`, a block attribute's `<` became `u003c`).

> **Meta (since 2.50.0).** `meta` values of `create_post` and `update_post` keep their backslashes, in text, lists and objects at any depth. Before 2.50.0 every one was lost, so JSON kept in meta (page-builder data) lost the `\` of each `\"` and `<`.

---

### `delete_post`
Trash or permanently delete a post.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Post ID |
| `force` | boolean | No | `true` to permanently delete, `false` to trash (default: `false`) |

> **Trash (since 2.52.0).** Without `force` every post type goes to the trash (WordPress itself trashes only posts and pages and deleted every other type for good). An item already in the trash, a media file on a site without `MEDIA_TRASH`, and any item on a site with `EMPTY_TRASH_DAYS` 0 are refused and left alone; send `force: true` to delete them. The result has `outcome`: `trashed` or `deleted`. `delete_page` works the same way.

---

### `list_revisions`
List saved revisions for a post.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `post_id` | integer | **Yes** | Parent post ID |

**Returns:** `{ post_id: N, total: N, revisions: [{ id, date, title, author_id, author_name }] }`

---

### `get_revision`
Retrieve the full content of a specific revision.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Revision post ID |

**Returns:** `{ id, post_id, date, title, content, excerpt, author_id, author_name }`

---

## Page Management

### `list_pages`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `publish`, `draft`, `pending`, `private`, `any` (default: `publish`) |
| `per_page` | integer | No | Max 100 (default: 20) |
| `search` | string | No | Keyword search |
| `parent_id` | integer | No | Filter by parent page ID |
| `fields` | string[] | No | As for `list_posts`. Item fields: `id`, `title`, `slug`, `status`, `parent_id`, `menu_order`, `permalink`, `template`, and `featured_image` when named |

---

### `get_page`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Page ID |

---

### `create_page`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `title` | string | **Yes** | Page title |
| `content` | string | No | Page body; stored as for `create_post` (raw only with `unfiltered_html`, see the note under `update_post`) |
| `status` | string | No | `publish`, `draft`, `pending`, `private` (default: `draft`) |
| `parent_id` | integer | No | Parent page ID |
| `template` | string | No | A page template of this site, or `default`. An unknown one is refused, and the error lists what there is |
| `menu_order` | integer | No | Sort order |

---

### `update_page`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Page ID |
| `title` | string | No | New title |
| `content` | string | No | New body |
| `status` | string | No | `publish`, `draft`, `pending`, `private`, `trash` |
| `parent_id` | integer | No | New parent page ID |
| `template` | string | No | A page template of this site, or `default` (empty means `default`). An unknown one is refused |
| `menu_order` | integer | No | New sort order |

---

### `delete_page`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Page ID |
| `force` | boolean | No | Permanently delete (default: `false`) |

---

## Media Management

### `list_media`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | Max 100 (default: 20) |
| `search` | string | No | Search title/filename |
| `mime_type` | string | No | Filter by MIME type (e.g. `image/jpeg`, `application/pdf`) |
| `fields` | string[] | No | Only these fields of each item (`id` is always kept): `id`, `title`, `filename`, `url`, `mime_type`, `alt`, `date`, `width`, `height`, `filesize`. An unknown name is left out and named in `fields_note` |

---

### `get_media`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Attachment ID |

**Returns:** ID, URL, title, alt text, caption, MIME type, dimensions (for images), date.

---

### `update_media`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Attachment ID |
| `title` | string | No | New title |
| `alt` | string | No | New alt text |
| `caption` | string | No | New caption |
| `description` | string | No | New description |

Backslashes are kept in all four since 2.50.0 (and in the title, caption, description and alt text of `upload_image` and the title of `upload_file`); before, every one was lost.

---

### `delete_media`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Attachment ID |
| `force` | boolean | No | Permanently delete (default: `true`) |

---

### `upload_image`
Download an image from a URL and import it into the Media Library.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `url` | string | **Yes** | Public HTTPS URL of the image |
| `title` | string | No | Attachment title |
| `alt_text` | string | No | Alt text |
| `caption` | string | No | Caption |
| `description` | string | No | Long description |

**Allowed MIME types:** `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`  
**Max size:** 10 MB; the download stops there  
**SSRF protection:** only public addresses, checked on every redirect, with the connection pinned to the checked address. See [security.md](security.md#upload-security-ssrf-prevention).

**Returns:** `{ id: N, url: "...", mime_type: "..." }`

---

### `upload_file`
Download a document or media file from a URL and import it into the Media Library.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `url` | string | **Yes** | Public HTTPS URL of the file |
| `title` | string | No | Attachment title |
| `filename` | string | No | Override stored filename (must include correct extension) |

**Allowed types:** PDF, TXT, CSV, DOC, DOCX, XLS, XLSX, PPT, PPTX, MP3, WAV, OGG, M4A, MP4, WebM, MOV  
**Not allowed:** Images (use `upload_image`), ZIP, PHP, JS, HTML, or any executable.  
**Max size:** 50 MB; the download stops there  
**SSRF protection:** Same as `upload_image`.

**Returns:** `{ id: N, url: "...", filename: "...", mime_type: "..." }`

---

## Comment Management

### `list_comments`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `hold`, `approve`, `spam`, `trash`, `all` (default: `all`) |
| `post_id` | integer | No | Filter by post |
| `per_page` | integer | No | Max 100 (default: 20) |

---

### `reply_to_comment`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `comment_id` | integer | **Yes** | Parent comment ID |
| `content` | string | **Yes** | Reply text (must not be empty) |

Backslashes in the reply (and in the replying account's name) are kept since 2.51.0; before, every one was lost.

---

### `moderate_comment`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Comment ID |
| `moderation` | string | **Yes** | `approve`, `hold`, `spam`, `trash`, `delete` (through the group tool, `action` names the operation, so the moderation goes here; a direct call may also send it as `action`). `delete` also needs `edit_comment` on the comment |

---

## Taxonomy Management

### `list_terms`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `taxonomy` | string | **Yes** | Taxonomy slug (e.g. `category`, `post_tag`). One with an admin screen or public archives; internal ones such as menus are refused |
| `search` | string | No | Filter by name |
| `per_page` | integer | No | Max 100 (default: 50) |
| `fields` | string[] | No | Only these fields of each term (`id` is always kept): `id`, `name`, `slug`, `description`, `parent_id`, `count` (and `language` with WPML). An unknown name is left out and named in `fields_note` |

---

### `create_term`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `taxonomy` | string | **Yes** | Taxonomy slug |
| `name` | string | **Yes** | Term name |
| `slug` | string | No | URL slug (auto-generated if omitted) |
| `description` | string | No | Term description |
| `parent_id` | integer | No | Parent term ID (hierarchical taxonomies only) |

**Returns:** `{ id, term_id, term_taxonomy_id }`: `id` and `term_id` are the new term's ID (`id` since 2.47.0).

---

### `update_term`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `term_id` | integer | **Yes** | Term ID |
| `taxonomy` | string | **Yes** | Taxonomy slug |
| `name` | string | No | New name |
| `slug` | string | No | New slug |
| `description` | string | No | New description |

Backslashes in a term's name and description are kept since 2.51.0 (`create_term` and `update_term`); before, every one was lost (`C:\Temp` became `C:Temp`).

---

### `delete_term`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `term_id` | integer | **Yes** | Term ID |
| `taxonomy` | string | **Yes** | Taxonomy slug |

---

## Menu Management

### `list_menus`
No parameters. Returns all registered nav menus with their assigned locations.

---

### `get_menu_items`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | One of the two | Menu ID |
| `slug` | string | One of the two | Menu slug |

**Returns:** Flat list of menu items with ID, title, URL, type, order, and parent ID.

Backslashes in a menu's name (`create_menu`) and an item's title (`add_menu_item`, `update_menu_item`) are kept since 2.51.0; before, every one was lost. An item title equal to the linked page's or term's own title is stored empty, as in Appearance → Menus, so the item follows that title when it changes (with a backslash in the title too since 2.51.0).

`update_menu_item` changes only the fields sent (`title`, `url`, `parent_id`, `position`, `target`, `classes`) since 2.51.1: it reads the item first and hands WordPress every field, as Appearance → Menus does. Before, WordPress filled each field not sent with its default: a title-only change dropped a custom link's URL, target, classes, description, title attribute and parent and moved it to the end of the menu, turned a page or term item into a custom link with no URL, and published an item that was still a draft. A page or term item that follows its object's title keeps following it. `url` applies to custom links only; a page, post or term item keeps linking to its object.

---

## Options Management

### `get_site_info`
No parameters. Returns site name, description, URL, admin email, timezone, language and date format.

---

### `get_option`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `key` | string | **Yes** | Option name |

Protected keys (auth keys/salts, payment gateway credentials, API secrets, etc.) are blocked.

---

### `update_option`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `key` | string | **Yes** | Option name |
| `value` | any | **Yes** | New value |

Critical options (`siteurl`, `active_plugins`, `default_role`, auth keys, AI Nexus's and every extension's settings, etc.) are write-protected; secrets and the list of connections cannot be read. See [security.md](security.md#options-protection).

---

## Theme Management

### `get_theme_mods`
No parameters. Returns all Customizer (`theme_mods`) settings for the active theme as key/value pairs.

**Returns:** `{ theme: "theme-slug", mods: { key: value, ... } }`

---

### `update_theme_mod`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `key` | string | **Yes** | The `theme_mod` key (use `get_theme_mods` to discover available keys) |
| `value` | string/number/boolean/null | **Yes** | New value |

Structural keys (`nav_menu_locations`, `custom_css_post_id`, etc.) are blocked.

---

## User Management

> ⚠️ Disabled by default. Enable under **AI Nexus → Abilities → User Management**.

### `list_users`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `role` | string | No | Filter by role slug |
| `search` | string | No | Search by name or email |
| `per_page` | integer | No | Max 100 (default: 20) |
| `orderby` | string | No | `login`, `nicename`, `email`, `registered`, `display_name` |

---

### `get_user`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | No | User ID |
| `email` | string | No | User email (provide `id` or `email`) |

---

### `create_user`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `username` | string | **Yes** | Login username |
| `email` | string | **Yes** | Email address |
| `password` | string | **Yes** | Password |
| `role` | string | No | `administrator`, `editor`, `author`, `contributor`, `subscriber` (default: `subscriber`) |
| `first_name` | string | No | First name |
| `last_name` | string | No | Last name |

Assigning `administrator` requires `manage_options` capability.

---

### `update_user`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | User ID |
| `email` | string | No | New email |
| `first_name` | string | No | New first name |
| `last_name` | string | No | New last name |
| `role` | string | No | New role |
| `password` | string | No | New password |

Needs `edit_user` on that account, and `promote_user` for a role change. Cannot modify an account that holds `manage_options` unless you hold it too.

Since 2.51.0 (`create_user` and `update_user`) backslashes in the display name, first and last name and bio are kept (before, every one was lost), and a password with a quote or backslash works on the login form (before, WordPress's login form never accepted it: the form checks the password as WordPress stores it, slashed).

---

### `delete_user`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | User ID |
| `reassign_id` | integer | No | Transfer content to this user ID before deletion |

Needs `delete_user` on that account. Cannot delete an account that holds `manage_options` unless you hold it too. Cannot delete the last administrator account, nor the last account that holds `manage_options`.
