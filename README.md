# SOZO llms.txt readiness rules

The rules behind the **AI Readiness Report** in SOZO's Magento module `Sozo_LlmsGenerator`
(Stores › Configuration › SOZO Design › llms.txt). Every site with the module downloads them once a day from
<https://sozo-design.github.io/llms-readiness-rules/rules.json>, so the report can follow changes in AI crawlers
and best practice without a module release.

This repository is public on purpose: it holds only the rules (no client data), and sites need to read them
without credentials.

## How sites use them

- The module ships with a copy of these rules and uses it until it downloads newer ones.
- A download is used only when **its Ed25519 signature is valid** (`rules.json.sig`, made by the publish workflow
  with SOZO's secret key, checked against the public key built into the module) and its `revision` is not lower
  than the rules the site already has. A file with a newer revision bundled in a module release wins over an older
  download.
- Rules are data, never code: each check names a **check type** built into the module and gives its settings,
  wording and weight. A rule with a type the site's module version doesn't know shows as "needs a module update"
  and isn't scored.

## Changing the rules

1. Branch, edit `rules.json`, and **raise `revision` by one** (sites ignore an equal or lower revision). Set
   `published` to today's date.
2. Run `php scripts/validate.php rules.json` (PHP 8.1+). The pull request runs the same check against the
   published revision.
3. Merge to `main`. **Publish rules** validates, signs and deploys to GitHub Pages; sites pick it up within a day
   (or at once with `bin/magento sozo:llms:check --update-rules`).

A new check *type* needs a module release first; add it to `KNOWN_TYPES` in `scripts/validate.php` only once that
release is out.

## The format

```json
{
    "schema": 1,
    "revision": 2,
    "published": "2026-11-20",
    "groups": [{"id": "find", "label": "Find", "description": "..."}],
    "checks": [
        {
            "id": "robots_ai_search",
            "group": "find",
            "type": "robots_user_agents",
            "title": "AI search crawlers allowed",
            "weight": 2,
            "scored": true,
            "params": {"user_agents": ["OAI-SearchBot", "Claude-SearchBot", "PerplexityBot"], "paths": ["/", "/llms.txt"]},
            "description": "Why it matters (plain text, up to 1,000 characters).",
            "fix": "How to fix it (plain text, up to 1,500 characters).",
            "learn_more": "https://... (optional, https only)"
        }
    ]
}
```

- `id`, `group` and `type`: lower-case letters, digits and underscores. `weight`: 0 to 10. `scored: false` shows a
  check for information only.
- The score is the weighted share of scored checks that passed (a partial pass counts half), overall and per group.
  Grades: A 90+, B 75+, C 60+, D 40+, F below.

### Check types (module v400.0.0)

| Type | What it checks | Settings |
|---|---|---|
| `homepage_loads` | The home page answers 200 with HTML | |
| `robots_txt` | robots.txt loads, as text, with a Sitemap: line | |
| `robots_user_agents` | robots.txt lets the crawlers read the paths (RFC 9309) | `user_agents`, `paths` |
| `sitemap` | An XML sitemap loads (from robots.txt, else the paths) | `paths` |
| `llms_txt_exists` | /llms.txt is text, starts with "# ", has content | `min_characters` |
| `llms_txt_length` | llms.txt is a short index with Markdown links | `target`, `max` |
| `llms_txt_links` | A sample of llms.txt's links on the shop load | `sample` |
| `llms_txt_section` | llms.txt has a "## " section whose heading has a phrase | `phrases`, `min_characters` |
| `content_without_js` | The home page HTML has text and an h1 | `min_characters`, `require_h1` |
| `html_metadata` | canonical, lang, and meta names/properties | `items` |
| `json_ld_types` | JSON-LD with one of the schema.org types | `types` |
| `json_ld_properties` | The business's JSON-LD has the property groups | `types`, `groups` (`label`, `any`) |
| `trust_pages` | Pages linked from the home page with real content | `pages` (`label`, `keywords`, `fallback`), `min_characters` |
| `bot_access` | The home page loads with each User-Agent | `user_agents` (`name`, `value`) |
| `not_found` | A missing page answers 404 or 410 | |

## Signing key

- The secret key is the Actions secret `RULES_SIGNING_KEY` (base64, 64 bytes). It is never committed.
- `public-key.txt` holds the public half; the module has the same key in `Model/Readiness/RulesSignature.php`.
- To change the key: add the new public key to the module, release it and wait for sites to update, then change
  the secret and `public-key.txt`. The publish workflow refuses a secret that doesn't match `public-key.txt`.

## One-time setup (done once by an admin)

1. Settings › Pages › Source: **GitHub Actions**.
2. Settings › Secrets and variables › Actions: add `RULES_SIGNING_KEY`.
3. Run **Publish rules** once (Actions tab), then open
   <https://sozo-design.github.io/llms-readiness-rules/rules.json>.
