<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Plunk icon"></p>

<h1 align="center">Plunk for Craft CMS</h1>

This plugin provides a [Plunk](https://www.useplunk.com/) mail transport adapter for [Craft CMS](https://craftcms.com/).

## Requirements

This plugin requires Craft CMS 4.0.0+ or 5.0.0+.

## Installation

Install the plugin with Composer:

```bash
composer require rareform/craft-plunk
php craft plugin/install plunk
```

## Setup

Once Plunk is installed:

1. Go to **Settings** -> **Email** in the Craft control panel.
2. Change **Transport Type** to **Plunk**.
3. Enter your **Secret API Key**. Plunk's `/v1/send` endpoint requires a secret key (`sk_*`).
4. Leave **API Base URL** blank to use hosted Plunk, or set it to your self-hosted Plunk API origin.
5. Confirm your Craft system email address uses a domain that is verified in Plunk.
6. Click **Save** and send Craft's test email.

## Environment Variables

Both settings support Craft environment aliases and environment variables.

```dotenv
PLUNK_SECRET_KEY=sk_your_secret_key
PLUNK_API_BASE_URL=https://next-api.useplunk.com
```

Use `$PLUNK_SECRET_KEY` for the **Secret API Key** setting and `$PLUNK_API_BASE_URL` for the **API Base URL** setting.

For self-hosted Plunk installs, set **API Base URL** to the API root, without `/v1/send`. The transport appends `/v1/send` automatically.

## Notes

This adapter is intentionally focused on Craft mail delivery. It sends the subject, HTML/text body, sender, recipients, reply-to address, custom headers, and attachments from Craft through Plunk's transactional send API. Plunk templates, contact data, subscription state, workflows, and event tracking are not exposed as plugin settings in this release.

Plunk's transactional API does not provide separate CC or BCC fields. Messages with CC or BCC recipients are rejected by the transport so those recipients are not silently dropped.
