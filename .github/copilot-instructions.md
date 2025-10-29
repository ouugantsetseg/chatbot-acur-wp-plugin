# ACUR Chatbot Plugin — Copilot Instructions

## Overview
This WordPress plugin provides a floating chatbot widget that matches user questions to a local FAQ knowledge base using PHP-based text similarity algorithms.

## Architecture
- **Entry Point:** `chatbot-acur.php` — Loads all major classes and registers plugin hooks.
- **FAQ Management:** `includes/class-acur-admin.php` — Handles CRUD for FAQs in the WordPress admin, stores tags as JSON.
- **REST API:** `includes/class-acur-rest.php` — Exposes endpoints for matching, feedback, and escalation. Used by the frontend widget.
- **Settings:** `includes/class-acur-settings.php` — Renders settings/info page. Matching is always local; no config needed.
- **Frontend Widget:** `assets/js/widget.js` and `assets/css/widget.css` — Implements the floating chatbot UI, interacts with REST API, manages session ID in localStorage.
- **Data Files:** `includes/faqs.csv`, `includes/queries.csv` — Used for bulk FAQ import/evaluation (not required for normal operation).

## Developer Workflows
- **Activate plugin** via WordPress admin. FAQ table is auto-created.
- **Add FAQs** via the Chatbot KB admin page. Tags are comma-separated and stored as JSON.
- **REST API endpoints:**
  - `GET /wp-json/acur-chatbot/v1/match?q=...` — Returns best FAQ match.
  - `POST /wp-json/acur-chatbot/v1/feedback` — Records feedback.
  - `POST /wp-json/acur-chatbot/v1/escalate` — Handles escalation.
- **No build step required.** All code is PHP/JS/CSS, ready to run.

## Project-Specific Patterns
- **FAQ tags** are stored as JSON arrays in the DB, parsed for matching.
- **Matcher** combines multiple similarity metrics for robust results.
- **No external dependencies** — all matching and logic is local.
- **Widget** uses a persistent session ID in localStorage for conversation tracking.
- **Shortcode:** `[wp_chatbot]` — Renders the widget on any page.

## Key Files
- `chatbot-acur.php` — Plugin bootstrap
- `includes/class-acur-matcher.php` — Matching logic
- `includes/class-acur-admin.php` — FAQ admin
- `includes/class-acur-rest.php` — REST API
- `assets/js/widget.js` — Frontend widget

## Example: Adding a FAQ
- Go to Chatbot KB in WP admin
- Enter question, answer, and tags (comma-separated)
- Tags are stored as JSON and used for matching

## Example: REST API Usage
```bash
curl 'https://your-site/wp-json/acur-chatbot/v1/match?q=How do I reset my password?'
```

---
For questions about matching logic, see `class-acur-matcher.php`. For UI changes, edit `widget.js` and `widget.css`.
