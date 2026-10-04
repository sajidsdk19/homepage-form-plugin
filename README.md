# Moving Quote Form & Location Autocomplete

A WordPress plugin for moving companies. Visitors pick a pickup and a drop-off address (Google address autocomplete), click **Instant Quote**, and the bar turns into a short customer details form in the same place. The site owner receives one complete quote request by email, and a copy is kept in WordPress.

- Version 1.0.0 · WordPress 5.8+ · PHP 7.4+
- Works with Elementor (own widget), the block editor (own block) and any page builder (shortcode)

## 1. Install

1. In WordPress go to **Plugins → Add New → Upload Plugin**, choose `moving-quote-form.zip`, then **Install Now** and **Activate**.
2. Go to **Moving Quotes → Settings**.
3. Paste your **Google Maps API key** (see section 2) and check the **Send quote requests to** email address.
4. Save, then add the form to a page (section 3).

Until a key is added the form still works, but without suggestions: visitors type addresses by hand.

## 2. Google setup

### APIs to enable

In [Google Cloud Console](https://console.cloud.google.com/), in one project with **billing enabled**, enable:

| API | Why |
| --- | --- |
| **Maps JavaScript API** | Loads Google's script in the browser |
| **Places API (New)** | Address suggestions and the selected address's details (formatted address, place ID, coordinates) |

No other Google API is needed. The legacy "Places API" is not used.

### API key restrictions (do this before going live)

A browser API key is always visible to visitors in the page source. That is normal for Google Maps; the restrictions below are what stop anyone else from using it.

Create the key under **APIs & Services → Credentials → Create credentials → API key**, then edit it:

- **Application restrictions → Websites (HTTP referrers)**. Add your domain, for example:
  - `https://example.com/*`
  - `https://*.example.com/*`
  - plus your staging domain if you have one
- **API restrictions → Restrict key** and tick only **Maps JavaScript API** and **Places API (New)**.

Also recommended: set a daily quota cap and a billing budget alert on the project.

The key is stored in the plugin settings, never in the plugin files. To keep it out of the database, define it in `wp-config.php` instead:

```php
define( 'MQF_GOOGLE_MAPS_API_KEY', 'your-key' );
```

### Suggestion settings

- **Limit to countries** – two-letter codes, e.g. `gb` or `us, ca`. Empty means worldwide.
- **Show suggestions for** – *Addresses, streets and postcodes* (default), *Towns, cities and postcodes only*, or *Everything, including businesses*. If visitors report "no matching addresses" for things you want to accept, switch to *Everything*.

If Google rejects a restriction, the plugin retries without it so the form keeps working, and records the reason in the error log.

## 3. Add the form to a page

| Where | How |
| --- | --- |
| **Elementor** | Search the widget panel for **Moving Quote Form** and drag it in. Text, notification email and colours can be set per widget. |
| **Any builder / classic editor** | Use the shortcode `[moving_quote_form]` (in Elementor: the *Shortcode* widget). |
| **Block editor** | Add the **Moving Quote Form** block. |

To replace an existing visual-only Elementor quote bar: delete the old inputs and button and put this widget in the same container. No JavaScript has to be pasted into Elementor, and the plugin does not depend on any Elementor element ID.

Optional shortcode attributes (each one overrides the plugin setting for that form only):

```
[moving_quote_form
  pickup_placeholder="Moving from"
  dropoff_placeholder="Moving to"
  button_text="Get a quote"
  title="Tell us about your move"
  submit_text="Send request"
  notification_email="branch@example.com"
  bar_color="#06183a" bar_text_color="#ffffff"
  field_color="#ffffff" field_text_color="#16213a"
  button_color="#020b20" button_text_color="#ffffff"
  accent_color="#3b82f6"
  class="my-extra-class"]
```

Several forms can be used on one page; each keeps its own state.

## 4. Settings overview

**Moving Quotes → Settings**

- **Google address autocomplete** – API key, country limit, suggestion types, and three rules:
  - *Visitors must pick an address from the suggestions* (on by default).
  - *If Google is unavailable, let visitors type the address by hand* (on by default). When off, the form shows an error instead.
  - *Do not allow the same location in both fields* (on by default).
- **Notifications and storage** – recipient address(es), email subject (`{name}`, `{pickup}`, `{dropoff}`, `{site}`), and whether to keep a copy of each request.
- **Step 1 / Step 2** – every label, placeholder and button text.
- **Fields** – add, remove, reorder and configure the step 2 fields without touching code. Types: text, phone, email, date, number, paragraph, dropdown, radio, checkboxes. Pickup and drop-off are always carried over from step 1.
- **Messages** – all validation, success and error messages.
- **Colours** – defaults match the dark navy bar. The font is inherited from the theme.
- **Error log** – technical problems (Google script blocked, key rejected, quota exceeded, email not sent) are listed here for the administrator.

Saved requests are under **Moving Quotes → Quote Requests**, with an **Export CSV** button.

## 5. How it behaves

- Pickup and drop-off use separate autocomplete instances and separate Google billing sessions.
- For each selected address the plugin stores the formatted address, Google Place ID and latitude/longitude. The email also shows the straight-line distance between the two points.
- **Instant Quote** validates both locations. Missing, unselected or identical locations show a message next to the field and the form stays on step 1.
- Step 2 replaces the bar in the same place without a page reload. The locations are shown at the top with an **Edit locations** link.
- The browser Back button returns from step 2 to the bar. A refresh keeps the locations and the current step (stored in the visitor's browser tab only; contact details are not stored).
- Submission is sent by AJAX with a WordPress nonce, server-side validation and sanitisation, a honeypot field, a per-visitor rate limit (10 requests per 10 minutes) and duplicate-submit protection (button lock in the browser, one-time token on the server).
- If the notification email cannot be sent but the request was saved, the visitor still sees success, the request is flagged in the list and the failure is logged. If nothing could be saved or sent, the visitor sees the error message and can retry.
- Pages served from a full-page cache keep working: an expired security token is refreshed automatically.

Email delivery uses WordPress's own `wp_mail()`. On most hosts an SMTP plugin is needed for reliable delivery; keep **Save requests** on as a safety net.

## 6. Customising

CSS – everything is scoped to `.mqf-quote` (form) and `.mqf-suggest` (suggestion dropdown) and driven by CSS variables:

```css
.mqf-quote {
  --mqf-bar-bg: #06183a;
  --mqf-button-bg: #020b20;
  --mqf-radius: 14px;
  --mqf-field-height: 46px;
}
```

PHP hooks:

| Hook | Purpose |
| --- | --- |
| `mqf_fields` (filter) | Change the step 2 fields in code |
| `mqf_place_types` (filter) | Google place types used to restrict suggestions (max 5) |
| `mqf_frontend_config` (filter) | Front-end options such as `minChars`, `debounce` |
| `mqf_validate_submission` (filter) | Reject a request by returning a `WP_Error` (e.g. CAPTCHA) |
| `mqf_rate_limit` (filter) | Requests allowed per visitor per 10 minutes, `0` to disable |
| `mqf_email_recipients`, `mqf_email_subject`, `mqf_email_body`, `mqf_email_headers` (filters) | Adjust the notification |
| `mqf_quote_submitted` (action) | Runs after a request is accepted – send it to a CRM, calculate a price, etc. |

JavaScript: a `mqf:submitted` event is dispatched on the form after a successful request (useful for analytics). For forms injected after page load, call `MQFQuote.init( container )`.

## 7. Files

```
moving-quote-form.php            Plugin bootstrap
includes/class-mqf-settings.php  Settings, defaults, sanitisation
includes/class-mqf-fields.php    Configurable fields and their validation
includes/class-mqf-renderer.php  Shortcode, markup, asset loading
includes/class-mqf-submission.php  Secure AJAX endpoint
includes/class-mqf-email.php     Notification email
includes/class-mqf-entries.php   Saved requests, admin list, CSV export
includes/class-mqf-logger.php    Error log
includes/class-mqf-admin.php     Settings screen
includes/class-mqf-block.php     Block editor block
includes/class-mqf-elementor-widget.php  Elementor widget
assets/js/mqf-frontend.js        Autocomplete, step transition, validation, submission
assets/css/mqf-frontend.css      Scoped, responsive styles
```
