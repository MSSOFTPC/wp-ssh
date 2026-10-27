# WP SSH: Self-Hosted AI Bridge for WordPress

![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-blue)
![Self-hosted](https://img.shields.io/badge/self--hosted-yes-brightgreen)
![No SaaS](https://img.shields.io/badge/third--party%20server-none-brightgreen)

**Let Claude, ChatGPT or any AI agent manage your WordPress site, without giving out SSH access.**

WP SSH is a **privacy-first, self-hosted WordPress plugin** that exposes a small, whitelisted set of actions over one authenticated HTTPS endpoint. Posts, WooCommerce orders, Elementor pages, files, diagnostics and more can be read or changed by your AI assistant or script, with logging, backups and confirm-tokens on anything destructive.

- **Self-hosted:** the plugin runs entirely on your own WordPress. No accounts, no cloud relay, no SaaS in the middle.
- **No SSH / cPanel credentials:** your AI gets scoped actions, not a shell.
- **Safe by default:** read-only mode, IP allowlist, lockout, rotating file backups, activity log.
- **Built for real sites:** WooCommerce, Elementor, serialization-safe search-replace, health checks.

> **Privacy note:** the plugin itself never sends your data to any third party. What your AI assistant sees is whatever you let it request through the bridge. Use Read-only mode and the IP allowlist to control that. Remember that responses go to the AI provider you connect (Anthropic, OpenAI, etc.) under *their* privacy terms.

---

## Table of contents

- [What can your AI do with it?](#what-can-your-ai-do-with-it)
- [How it compares](#how-it-compares)
- [Quick start (2 minutes)](#quick-start-2-minutes)
- [Connect your AI](#connect-your-ai)
- [Authentication](#authentication)
- [Security](#security)
- [Available actions](#available-actions)
- [Settings reference](#settings-reference-tools--wp-ssh)
- [FAQ](#faq)
- [Roadmap](#roadmap)
- [License](#license)

---

## What can your AI do with it?

Example prompts you can give an assistant that has the bridge as an HTTP tool:

- "Run diagnostics on my site and tell me what is slowing it down."
- "List all draft posts and publish the ones tagged `ready`."
- "Set the `Color` attribute on product 482 to Red, Blue and Green."
- "Copy the Elementor design of page 120 to my second site."
- "Do a dry-run search-replace from `http://old.com` to `https://new.com` and show me what would change."
- "Check my WooCommerce orders from today and mark the paid ones as `processing`."
- "Clear the Elementor cache after the last edit."

---

## How it compares

| | WP SSH | Raw SSH / WP-CLI | Managed dashboards (SaaS) |
|---|---|---|---|
| Needs shell credentials | **No** | Yes | Sometimes |
| Third-party server in the middle | **No** | No | Yes |
| Scoped, whitelisted actions | **Yes** | No (full shell) | Varies |
| Read-only mode | **Yes** | No | Varies |
| Automatic file backups | **Yes** | No | Varies |
| Designed for AI agents | **Yes** | No | Rarely |
| Elementor + WooCommerce actions | **Yes** | Via extra plugins | Varies |

---

## Quick start (2 minutes)

1. Download `wp-ssh.zip` (or clone this repo into `wp-content/plugins/wp-ssh/`).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, upload the zip, **Activate**.
3. Go to **Tools → WP SSH**.
4. Copy the auto-generated **Secret key**.
5. Check **Enable bridge**, save settings.

Your site is now reachable at:

```
POST https://yoursite.com/wp-json/wp-ssh/v1/execute
```

**Recommended first settings:** turn on **Read-only mode** while you test, and add your own IP to the **IP allowlist**.

---

## Connect your AI

Any model or tool that can make an HTTPS request can use the bridge: Claude, ChatGPT (custom actions / tools), Gemini, local LLMs, n8n, Make, Zapier or a plain script.

Give the assistant:

1. The endpoint: `https://yoursite.com/wp-json/wp-ssh/v1/execute`
2. The header: `X-WPSSH-Key: <your-secret-key>`
3. The request shape: `{ "action": "...", "params": { ... } }`
4. The list of actions from [Available actions](#available-actions) below.

Suggested instruction for your agent:

```
You can manage my WordPress site through one HTTP endpoint.
POST JSON {"action": "<name>", "params": {...}} with header X-WPSSH-Key.
Always start with "site_info" or "diagnostics". Prefer read actions first.
For any destructive action (force delete, restore, non-SELECT SQL), explain
what you will do and wait for my approval before sending "confirm": true.
Use "dry_run": true for search_replace before running it for real.
```

> MCP (Model Context Protocol) wrapper and OpenAPI schema are on the [roadmap](#roadmap).

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

### Example: curl

```bash
curl -X POST https://yoursite.com/wp-json/wp-ssh/v1/execute \
  -H "X-WPSSH-Key: YOUR_SECRET_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action": "diagnostics", "params": {}}'
```

### Unauthenticated ping

`GET /wp-json/wp-ssh/v1/ping` returns `{ plugin, version, site, label }`. Use it to confirm the plugin is installed and to auto-detect which site you are talking to, without needing the key.

---

## Security

> ⚠️ **Read this before installing.** With write actions enabled, this plugin can write PHP files inside `wp-content`, run arbitrary SQL, and activate/deactivate plugins. Anyone who obtains your secret key has meaningful control over the site. Keep the key private, use HTTPS only, and turn on **Read-only mode** or an **IP allowlist** when you do not need full write access.

| Feature | What it does |
|---|---|
| Secret key | 64-char random key per install, compared with `hash_equals()`. |
| IP allowlist | Optional. Restricts `/execute` to specific IPs. |
| Lockout | 5 failed key attempts from an IP → 15 minute lockout. |
| Read-only mode | One toggle blocks every write action; safe for pure browsing and diagnostics. |
| Confirm tokens | Permanent delete, restore, and non-`SELECT` SQL require `"confirm": true` in params. |
| Auto-backup | `file_write` keeps up to 3 rotating `.wpssh-bak-N` copies before overwriting; `file_restore` brings one back. |
| Path scoping | File paths are resolved with `realpath()` and rejected if they escape `wp-content`. |
| Activity log | Last 100 actions (time, IP, action, summary) shown in Tools → WP SSH. |

### Recommended hardening checklist

- [ ] Serve the site over **HTTPS only**.
- [ ] Keep **Read-only mode** on unless you are actively making changes.
- [ ] Set an **IP allowlist** if your AI tool calls from a fixed IP.
- [ ] Leave **Allow write queries** off unless you need it.
- [ ] Never paste the secret key into public chats, screenshots or repos.
- [ ] **Regenerate the secret key** after sharing it with a tool you stop using.
- [ ] Review the **Activity log** regularly.

To report a vulnerability, please open a private security advisory on GitHub instead of a public issue.

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
| `media_upload` | `filename`, `content_base64`, `title?` (jpg/jpeg/png/gif/webp/svg only) |

### Plugins & themes
| Action | Params |
|---|---|
| `plugin_list` | – |
| `plugin_activate` | `file` |
| `plugin_deactivate` | `file` |
| `plugin_install` | `content_base64`, `confirm`, `activate?`, `overwrite?` | Installs from a base64-encoded `.zip` via WordPress's own `Plugin_Upgrader` (same path as wp-admin's "Upload Plugin"). No arbitrary URL fetching. |
| `theme_list` | – |
| `theme_install` | `content_base64`, `confirm`, `activate?`, `overwrite?` | Same as `plugin_install`, via `Theme_Upgrader`. |

### Files (scoped to `wp-content/` by default)
| Action | Params | Notes |
|---|---|---|
| `file_list` | `path?`, `root?` | Directory listing. |
| `file_read` | `path`, `root?` | Path is relative to `wp-content` (or to the site root if `root: true`). |
| `file_write` | `path`, `content`, `skip_backup?`, `root?`, `confirm?` | Auto-backs up the existing file first. `confirm: true` required when `root: true`. |
| `file_restore` | `path`, `backup?` (1-3, default 1), `confirm`, `root?` | Restores from a `.wpssh-bak-N` copy. |

All file paths are resolved with `realpath()` and rejected if they escape the allowed base directory — no `../` traversal outside it.

**Root-level access** (`"root": true`) reaches outside `wp-content` — `wp-config.php`, `.htaccess`, `.user.ini`, anything in the WordPress install root. It only works when **both** the request passes `root: true` **and** the "Allow root-level file access" setting is turned on in Tools → WP SSH (**off by default**). This is the escape hatch for things WordPress itself can't control, like `upload_max_filesize`/`post_max_size`. Leave it off unless you specifically need it.

### Database
| Action | Params | Notes |
|---|---|---|
| `db_query` | `sql`, `confirm?` | `SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN` run freely. Anything else needs **Allow write queries** enabled in settings *and* `"confirm": true`. |
| `search_replace` | `search`, `replace`, `tables?`, `dry_run?` | Serialization-safe find/replace across the DB (will not corrupt PHP-serialized values), mirrors `wp search-replace`. |
| `cache_clear` | `post_id?`, `all_elementor?`, `transients?` | Clears Elementor render/CSS cache, theme compiled-CSS options, object cache, and pings LiteSpeed / WP Super Cache / W3TC / WP Rocket if present. |

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
| `template_export` | `post_id` | Exports a page's Elementor data + page settings/template, for copying a design to another site. |
| `template_import` | `title`, `elementor_data`, `post_id?`, `post_type?`, `post_status?`, `elementor_page_settings?`, `page_template?` | Creates/updates a post with imported Elementor data. The plugin never talks to other sites directly: you move the exported JSON from one site's response into another site's `template_import` call yourself. |

---

## Settings reference (Tools → WP SSH)

- **Site label:** friendly name returned in `ping` / `site_info`, handy when you manage several sites.
- **Enable bridge:** master on/off switch.
- **Read-only mode:** blocks every write action regardless of other settings.
- **Allow write queries:** required (in addition to `confirm: true`) for non-`SELECT` SQL via `db_query`.
- **Allow root-level file access:** lets `file_*` actions reach outside `wp-content` when a request passes `"root": true`. Off by default; see the Files section above.
- **IP allowlist:** one IP per line; empty = any IP (still requires the key).
- **Health check emails:** hourly self-check with an alert email if something looks wrong.
- **Regenerate secret key:** invalidates the old key immediately.

---

## FAQ

**Is this a public or multi-tenant service?**
No. It is a plugin you install on sites you manage yourself. Each install has its own secret key and nothing is shared across installs.

**Does the plugin send my data anywhere?**
No. It only answers requests that arrive at your own endpoint with a valid key. If you connect an AI provider, the responses you pass to it are handled under that provider's terms.

**Is it safe to give an AI write access?**
Only as safe as the key and settings you choose. Start in Read-only mode, use the IP allowlist, keep write SQL disabled, and require your approval for destructive actions.

**Does it replace WP-CLI?**
For common site-management tasks, yes, over HTTPS and without shell access. It is not a full shell and deliberately cannot run arbitrary commands.

**Does it work without WooCommerce or Elementor?**
Yes. Those action groups are only used when the plugins are active.

**Can I manage multiple sites?**
Yes. Install it on each site and give each a **Site label**. Each site has its own key.

---

## Roadmap

- [ ] MCP server wrapper (`wp-ssh-mcp`) for Claude Desktop, Cursor, Claude Code and other MCP clients
- [ ] OpenAPI schema (`openapi.json`) for ChatGPT custom actions and other agents
- [ ] Multiple keys with per-action scopes (e.g. read-only key, content-only key)
- [ ] Request signing (HMAC + timestamp) to prevent replay
- [ ] Write actions (`file_write`, write SQL) off by default
- [ ] `SECURITY.md` and published threat model

Ideas and pull requests are welcome. Please open an issue first for larger changes.

---

## License

GPLv2 or later.
