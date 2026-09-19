# WP SSH — Remote Management Bridge

A private, self-hosted WordPress plugin that lets you (or a trusted AI assistant like Claude) manage a site over an authenticated HTTPS endpoint instead of raw SSH / WP-CLI access.

**This is not a public/multi-tenant tool.** It is meant to be installed manually on sites *you personally manage*. Each install generates its own unique secret key and detects its own site automatically — nothing is shared across installs.

> ⚠️ **Read this before installing.** With write actions enabled, this plugin can write PHP files inside `wp-content`, run arbitrary SQL, and activate/deactivate plugins. Anyone who obtains your secret key has meaningful control over the site. Keep the key private, use HTTPS only, and turn on **Read-only mode** or an **IP allowlist** when you don't need full write access.

---

## Why this exists

Managing WordPress sites normally means either giving out full SSH/cPanel credentials, or doing everything by hand through wp-admin. This plugin exposes a small, whitelisted set of actions over a single REST endpoint, so a script (or an AI assistant with an HTTP tool) can:

- Read diagnostics, options, posts, media, plugins, themes, orders
- Make scoped changes (update a post, set a product attribute, clear cache, edit a file inside `wp-content`) without needing shell access
- Do it all with logging, rate-limiting, confirm-tokens on destructive actions, and automatic backups

---

## Installation

1. Download `wp-ssh.zip` (or clone this repo into `wp-content/plugins/wp-ssh/`).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, upload the zip, **Activate**.
3. Go to **Tools → WP SSH**.
4. Copy the auto-generated **Secret key**.
5. Check **Enable bridge**, save settings.

That's it — the site is now reachable at:

```
POST https://yoursite.com/wp-json/wp-ssh/v1/execute
```

---

## Authentication

Every request must include the secret key in a custom header:

```
X-WPSSH-Key: <your-secret-key>
Content-Type: application/json
```

Request body:

```json
{
  "action": "site_info",
  "params": {}
}
```

Response:

```json
{ "success": true, "data": { ... } }
```

or, on failure:

```json
{ "code": "wp_ssh_bad_key", "message": "Invalid or missing secret key.", "data": { "status": 401 } }
```

### Unauthenticated ping

`GET /wp-json/wp-ssh/v1/ping` returns `{ plugin, version, site, label }` — useful to confirm the plugin is installed and to auto-detect which site you're talking to, without needing the key.

### Security features

| Feature | What it does |
|---|---|
| Secret key | 64-char random key per install, compared with `hash_equals()`. |
| IP allowlist | Optional — restrict `/execute` to specific IPs. |
| Lockout | 5 failed key attempts from an IP → 15 minute lockout. |
| Read-only mode | One toggle blocks every write action; safe for pure browsing/diagnostics. |
| Confirm tokens | Permanent delete, restore, and non-`SELECT` SQL require `"confirm": true` in params. |
| Auto-backup | `file_write` keeps up to 3 rotating `.wpssh-bak-N` copies before overwriting; `file_restore` brings one back. |
| Activity log | Last 100 actions (time, IP, action, summary) shown in Tools → WP SSH. |

---

## Example: curl

```bash
curl -X POST https://yoursite.com/wp-json/wp-ssh/v1/execute \
  -H "X-WPSSH-Key: YOUR_SECRET_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action": "diagnostics", "params": {}}'
```

---

## Available actions

### Site & diagnostics
| Action | Params | Notes |
|---|---|---|
| `site_info` | – | Name, label, URL, WP/theme/WooCommerce version. |
| `diagnostics` | – | PHP/memory config, autoload options size, DB size + largest tables, transients/revisions counts, active caching plugins. |
| `health_check_status` | – | Last hourly self-check result (if enabled). |
| `health_check_run` | – | Run the health check now (DB connection, PHP fatals in `debug.log`, low disk space, bridge disabled). |
| `cron_list` | – | All scheduled WP-Cron events, soonest first. |
| `cron_run` | `hook`, `args?` | Manually fire a cron hook now. |

### Options
| Action | Params |
|---|---|
| `option_get` | `name` |
| `option_update` | `name`, `value` |

### Posts & content
| Action | Params |
|---|---|
| `post_list` | `post_type?`, `post_status?`, `limit?`, `search?` |
| `post_get` | `id` |
| `post_create` | `title`, `content?`, `excerpt?`, `post_type?`, `post_status?`, `slug?`, `meta?` |
| `post_update` | `id`, `title?`, `content?`, `excerpt?`, `status?`, `slug?`, `meta?` |
| `post_delete` | `id`, `force?`, `confirm?` (required if `force`) |
| `term_list` | `taxonomy?` |
| `term_create` | `name`, `taxonomy?`, `slug?` |
| `menu_list` | – |
| `menu_item_add` | `menu`, `title`, `object_id?`, `object_type?`, `url?`, `position?`, `parent_id?` |
| `widget_add` | `sidebar_id`, `id_base`, `instance` |

### Media
| Action | Params |
|---|---|
| `media_list` | `limit?` |
| `media_upload` | `filename`, `content_base64`, `title?` — jpg/jpeg/png/gif/webp/svg only |

### Plugins & themes
| Action | Params |
|---|---|
| `plugin_list` | – |
| `plugin_activate` | `file` |
| `plugin_deactivate` | `file` |
| `theme_list` | – |

### Files (scoped to `wp-content/`)
| Action | Params | Notes |
|---|---|---|
| `file_list` | `path?` | Directory listing. |
| `file_read` | `path` | Path is relative to `wp-content`. |
| `file_write` | `path`, `content`, `skip_backup?` | Auto-backs up the existing file first. |
| `file_restore` | `path`, `backup?` (1-3, default 1), `confirm` | Restores from a `.wpssh-bak-N` copy. |

All file paths are resolved with `realpath()` and rejected if they escape `wp-content` — no `../` traversal outside it.

### Database
| Action | Params | Notes |
|---|---|---|
| `db_query` | `sql`, `confirm?` | `SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN` run freely. Anything else needs **Allow write queries** enabled in settings *and* `"confirm": true`. |
| `search_replace` | `search`, `replace`, `tables?`, `dry_run?` | Serialization-safe find/replace across the DB (won't corrupt PHP-serialized values), mirrors `wp search-replace`. |
| `cache_clear` | `post_id?`, `all_elementor?`, `transients?` | Clears Elementor render/CSS cache, theme compiled-CSS options, object cache, and pings LiteSpeed/WP Super Cache/W3TC/WP Rocket if present. |

### WooCommerce
| Action | Params | Notes |
|---|---|---|
| `order_list` | `limit?`, `status?`, `search?` | Requires WooCommerce active. |
| `order_get` | `id` | Full order detail incl. line items, address. |
| `order_update_status` | `id`, `status`, `note?` | |
| `product_attribute_set` | `product_id`, `taxonomy`, `term_ids[]`, `is_variation?` | Sets a product attribute + terms in one call. |

### Elementor
| Action | Params | Notes |
|---|---|---|
| `elementor_data_get` | `post_id` | Raw `_elementor_data` JSON. |
| `elementor_data_set` | `post_id`, `data` | Validates JSON, writes it, and automatically clears Elementor's CSS/element cache. |
| `template_export` | `post_id` | Exports a page's Elementor data + page settings/template — for copying a design to another site. |
| `template_import` | `title`, `elementor_data`, `post_id?`, `post_type?`, `post_status?`, `elementor_page_settings?`, `page_template?` | Creates/updates a post with imported Elementor data. The plugin never talks to other sites directly — you move the exported JSON from one site's response into another site's `template_import` call yourself. |

---

## Settings reference (Tools → WP SSH)

- **Site label** — friendly name returned in `ping`/`site_info`, handy when you manage several sites.
- **Enable bridge** — master on/off switch.
- **Read-only mode** — blocks every write action regardless of other settings.
- **Allow write queries** — required (in addition to `confirm: true`) for non-`SELECT` SQL via `db_query`.
- **IP allowlist** — one IP per line; empty = any IP (still requires the key).
- **Health check emails** — hourly self-check with an alert email if something looks wrong.
- **Regenerate secret key** — invalidates the old key immediately.

---

## License

GPLv2 or later.
