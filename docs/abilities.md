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

**Returns:** `{ posts: [...], total: N }`

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

---

### `delete_post`
Trash or permanently delete a post.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Post ID |
| `force` | boolean | No | `true` to permanently delete, `false` to trash (default: `false`) |

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
| `content` | string | No | Page body |
| `status` | string | No | `publish`, `draft`, `pending`, `private` (default: `draft`) |
| `parent_id` | integer | No | Parent page ID |
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
| `alt_text` | string | No | New alt text |
| `caption` | string | No | New caption |
| `description` | string | No | New description |

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
**Max size:** 10 MB  
**SSRF protection:** URLs resolving to private IPs are rejected.

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
**Max size:** 50 MB  
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

---

### `moderate_comment`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `comment_id` | integer | **Yes** | Comment ID |
| `action` | string | **Yes** | `approve`, `hold`, `spam`, `trash`, `delete` |

---

## Taxonomy Management

### `list_terms`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `taxonomy` | string | **Yes** | Taxonomy slug (e.g. `category`, `post_tag`) |
| `search` | string | No | Filter by name |
| `per_page` | integer | No | Max 100 (default: 50) |

---

### `create_term`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `taxonomy` | string | **Yes** | Taxonomy slug |
| `name` | string | **Yes** | Term name |
| `slug` | string | No | URL slug (auto-generated if omitted) |
| `description` | string | No | Term description |
| `parent_id` | integer | No | Parent term ID (hierarchical taxonomies only) |

---

### `update_term`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `term_id` | integer | **Yes** | Term ID |
| `taxonomy` | string | **Yes** | Taxonomy slug |
| `name` | string | No | New name |
| `slug` | string | No | New slug |
| `description` | string | No | New description |

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
| `menu_id` | integer | **Yes** | Menu ID |

**Returns:** Flat list of menu items with ID, title, URL, type, order, and parent ID.

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

Critical options (`siteurl`, `active_plugins`, `default_role`, auth keys, etc.) are write-protected.

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

Cannot modify administrator accounts unless you are an administrator.

---

### `delete_user`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | User ID |
| `reassign_id` | integer | No | Transfer content to this user ID before deletion |

Cannot delete administrator accounts unless you are an administrator. Cannot delete the last administrator account.
