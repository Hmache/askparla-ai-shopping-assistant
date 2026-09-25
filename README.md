# Parla for WooCommerce

The WordPress plugin that puts [Parla](https://askparla.com) on a WooCommerce store: an AI sales
assistant that advises shoppers from the shop's own catalogue, tracks orders, changes an address
while it can still be changed, and starts a return inside the shop's return window.

This repository holds the plugin exactly as it ships. Read it before you install it: that is why it
is here.

## What runs where

The plugin is the shop's end of a hosted service. It stores the shop's settings, loads the chat
script, and calls Parla's API. Everything the assistant does — understanding the question, searching
the catalogue, writing the answer, speaking on a call — runs on Parla's servers, which are not part
of this repository.

Without a Parla account and a shop key, the plugin does nothing. There is no licence check or
feature flag in this code: what it can do, it does for everyone.

## Requirements

- WordPress 6.5 or later
- WooCommerce 8.0 or later
- PHP 8.0 or later
- A Parla account and a shop key ([askparla.com](https://askparla.com))

## Install

**From WordPress.org** — submitted, awaiting review. Once listed, install it like any other plugin.

**From a release here** — download the zip from
[Releases](https://github.com/Hmache/parla-for-woocommerce/releases), then in WordPress go to
Plugins → Add New → Upload Plugin, choose the file, install and activate.

Then open WooCommerce → Parla, paste the shop key Parla sent you, and press Connect store. Each shop
has its own key.

## What leaves the site, and when

The short version is below. The full disclosure, with the service's terms, its privacy policy and
every subprocessor, is in [`readme.txt`](readme.txt) under `== External services ==`.

- **On every page where the chat is enabled:** the chat script is loaded from Parla's servers, so
  the visitor's IP address and user agent reach Parla as they would with any externally hosted
  script, along with the shop id, the shop's name, the type of page, and the product id and title on
  a product page.
- **When a shopper writes or speaks in the chat:** what they typed or said, a random conversation
  id, the page they are on, and their browser's language.
- **When a shopper asks about an order:** the order number and the email they type, so the order can
  be confirmed as theirs before anything is shown. Addresses are masked before the AI model sees
  them, and the assistant refuses any order that was not verified in that same conversation.
- **Nothing** if a visitor loads the page and never uses the chat, beyond the script request.

Conversation text is passed to Anthropic (Claude) to write the answers. On a premium voice call the
audio also goes to Deepgram (speech recognition) and Cartesia (the voice).

## Privacy

The chat sets two first-party cookies on the shop's own domain so the plugin can count the sales it
helped with: `parla_assist` (a random conversation id, 7 days) and `parla_added` (the ids of products
it put in the cart, 7 days). Neither holds anything personal, and they are read only by this plugin,
on the shop's own site, when an order is placed.

Parla signs a GDPR data processing agreement with each shop and lists its subprocessors.

## Reporting a security issue

Email **security@askparla.com** rather than opening a public issue, and allow a few days for a first
reply.

## Licence

GPL-2.0-or-later, the same as WordPress. See [LICENSE](LICENSE).
