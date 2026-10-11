=== AskParla AI Shopping Assistant for WooCommerce ===
Contributors: askparla
Tags: woocommerce, chatbot, ai, voice, product search
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.26.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI sales agent for WooCommerce that shoppers can call or chat with. Sells from your catalogue, tracks orders, speaks 8 languages. 14 days free.

== Description ==

Most online shops have a "Contact us" page. Very few have someone who answers. Parla is an AI sales agent that lives on your WooCommerce shop: a shopper taps Call and asks out loud, or types, and gets an answer from your own catalogue, puts the right item in the cart, and tracks their order.

**Try it before installing anything:** [a demo shop with Parla on it](https://askparla.com/demo?utm_source=wporg). **Free for 14 days, no card:** [create your account at askparla.com](https://askparla.com/account/signup?utm_source=wporg), the shop key is shown straight away.

Parla adds a chat to your store that opens on a choice: talk, or type. It sells first and handles the rest of the order lifecycle after.

* Finds products in the shopper's own words ("red sweater size M"), typos and other languages included
* Recommends one of them and says why, from your own product data - never an invented claim
* Adds the exact variant to the cart from inside the chat
* Hands-free voice calls, if you turn them on
* Tracks an order, once the shopper has proved it is theirs
* Changes a shipping address while the order can still be changed
* Starts a return inside your return window
* Hands over to your team when it cannot help, with a real ticket

It speaks eight languages, follows the shopper's light or dark setting, and wears your brand colour.

Nothing is invented to make a sale: scarcity is only ever mentioned when your stock says so, and the assistant never offers a discount, because it has no way to make one. It says it is an AI, in the chat and at the start of every call, and when it does not know, it says so and hands over to your team with a ticket number.

Three plans from 19 EUR a month, no commitment, with the extra conversations capped by default at the price of your plan. Full details on [the pricing page](https://askparla.com/pricing?utm_source=wporg).

== Installation ==

1. Install and activate the plugin.
2. Create your account at [askparla.com](https://askparla.com/account/signup?utm_source=wporg): your shop key is shown straight away. The first 14 days are free, with no card. The plugin opens its own page once after activation, with the same link.
3. In WordPress, go to WooCommerce -> Parla, paste the key under Connection, then press "Connect store". Each shop has its own key.

The Status tab tells you what your storefront is actually using, which is the fastest way to see whether a setting reached the server.

== Frequently Asked Questions ==

= Does this send my customer data anywhere? =

The assistant reads an order only after a shopper gives the matching order number and the email used for it. Addresses are masked before the AI ever sees them, and order tools refuse any order that has not been verified in that same conversation.

= What does "Completed" mean in my shop? =

WooCommerce has no "delivered" status. Tell Parla in the settings whether Completed means delivered (so returns may start) or shipped.

= What happens on a voice call? =

The shopper's browser does the speech, not us: it turns their voice into text and reads the reply back. In Chrome that means the audio goes to the browser's own speech service, which is why calls are off until you switch them on, and why the chat says so the first time. Firefox has no recognizer, so the call button never appears there.

= Can I make it match my site's dark mode? =

Appearance -> Light or dark. Auto follows whatever the shopper has their own device set to, which suits most shops; pin it if your storefront is only ever one or the other.

== External services ==

This plugin is the shop's end of Parla, a hosted AI sales assistant. The assistant itself runs on Parla's servers, not in your WordPress: without a Parla account and a shop key, the plugin does nothing. Installing it therefore means your shop talks to a third-party service, and this section sets out exactly what leaves your site and when.

Service: Parla, https://askparla.com
Terms of service: https://askparla.com/en/terms
Privacy policy: https://askparla.com/en/privacy

What is sent, and when:

* **On every page where the chat is enabled.** The chat script is loaded from Parla's servers, so the visitor's IP address and browser user agent reach Parla as part of that request, as they would with any externally hosted script. The script tag also carries your shop id, your shop's name, the type of page (product, category, cart, checkout, order), the product id and page title on a product page, and, only if you switch on "Greet customers by name", the first name of a signed-in customer.
* **When a shopper opens the chat and writes or speaks.** What they type or say, a random conversation id, the page they are on, and their browser's language. Nothing is sent if a visitor loads the page and never uses the chat, beyond the script request above.
* **When a shopper asks about an order.** The order number and the email address they type, so the order can be confirmed as theirs before anything is shown. Addresses are masked before the AI model sees them.
* **When a shopper leaves an email to be told about a restock, or about something you do not sell.** Their address passes through Parla and is written straight back into your own site, where you can read the list and remove anyone from it (WooCommerce &rarr; Parla &rarr; Reports). Parla keeps only the product or the phrase they are waiting on, never the address. The email, when the moment comes, is sent by your site from your own address.
* **When you open WooCommerce &rarr; Parla in your admin.** The page asks Parla for this shop's figures and its saved settings, and the live preview loads the chat script so you can see your changes; that preview counts as one conversation against your plan, and it is seeded with your own first name from your WordPress profile so the greeting looks right. On the Voice tab, if your plan includes premium voice, your browser also asks Parla for the list of voices you may choose from and plays a sample clip from Parla's servers when you press play on one.
* **When you press "Connect store".** Your site address and your Parla settings, and the plugin creates a WooCommerce REST API key so Parla can read your catalogue and, when a shopper has proved an order is theirs, that order. Deleting the plugin removes that key.

Parla passes conversation text to Anthropic (Claude) to write the answers. When premium voice is switched on, the audio of a call also goes to Deepgram (speech recognition) and Cartesia (the voice). The privacy policy above lists every subprocessor and where each one processes data.

== Privacy ==

To count the sales it helps with, the chat sets two first-party cookies on your site when a shopper uses it: parla_assist (a random conversation id, 7 days) and parla_added (the ids of products it put in the cart, 7 days). They hold nothing personal and are only read by this plugin, on your site, when an order is placed. Depending on your country and your consent setup, you may want to list them in your cookie policy.

== Screenshots ==

1. A shopper asks in their own words and gets the real product, with the variant already chosen and a button to add it to the cart.
2. What shoppers looked for and did not find, what your pages could not answer, and the answers they marked unhelpful. The part worth reading every Monday.
3. Everything shoppers see, with a live preview that updates as you type: name, manner, colours, opening questions.
4. Hands-free calls, the voices to choose from, and how many premium minutes are left this month.

== Changelog ==

= 0.26.0 =
* After activation, the plugin opens its page once and says where to get an account: a button to create it, free for 14 days, and a link to a demo shop
* The Connection tab says the same for a shop that has no key yet
* The terms and privacy links follow the language of your WordPress

= 0.25.0 =
* The settings are in French when your site is: every screen, message and email the plugin writes
* Opening hours are read in your own language: "Lun-Ven 9h-18h", "Mo-Fr 9-18", "Seg-Sex 9h-18h" and the like all work now, where only "Mon-Fri 09:00-18:00" did
* Hours that cannot be read are flagged under the field, and the setup list only ticks them off once they can be
* The sizing field is now "Sizes and measurements", and only for shops whose products come in sizes or dimensions: the setup list no longer asks for it

= 0.24.1 =
* The directory page reads as written, without lines broken in the middle of a sentence

= 0.24.0 =
* The plugin is now called AskParla AI Shopping Assistant for WooCommerce
* Brand colours and the settings preview are loaded the WordPress way, with no inline tags
* The people waiting on a restock, or on something you do not sell, are now listed on the Reports tab, with their email addresses
* Those addresses live on your site instead of on Parla, so you can read the list and remove anyone from it
* Each address is still used for one message and deleted when it goes out

= 0.23.0 =
* Overview shows three counts; the full lists moved to a Reports tab
* The unhelpful-answers list is now complete rather than a sample of recent feedback

= 0.22.0 =
* The settings page's stylesheet and script are proper files now, loaded only on that page

= 0.21.0 =
* Settings are grouped by what they do: Widget for anything shoppers see, Knowledge for what the assistant knows
* Everything a shopper sees is now on the tab with the live preview, and updates as you type
* Choose the questions the chat offers, and turn the "Track an order" button off if you sell services
* Pick the standard voice by clicking it instead of typing its name
* No more collapsed sections: every setting is visible where it belongs

= 0.20.0 =
* Give the assistant a name and a manner: it introduces itself by that name in the chat and on calls
* Four manners to choose from, plus a short note in your own words about how it should sound
* It still tells shoppers it is an AI, whatever you call it

= 0.19.0 =
* Shops on premium voice can choose which voice speaks on calls, with a sample to play for each one
* The standard voice settings now say plainly that they only apply once the month's premium minutes run out

= 0.18.0 =
* The chat says it is an AI assistant, in the header and out loud at the start of a call, as the EU AI Act requires
* A page with its own language switcher can now tell the chat which language to speak
* Connects to Parla automatically, with no server address to configure

= 0.17.0 =
* Live preview on a phone: colours, name, greeting, light or dark, popular products and calls show as you type, before you save
* Switch the preview between the chat button and the open chat
* Colour presets, and a text colour that stays readable when you change the main one
* A "Finish setting up" checklist on the Overview, which goes away once everything is done
* Premium voice minutes are part of every plan

= 0.16.1 =
* The greeting bubble is on by default, and says a standard hello when you leave its text empty
* A shopper who closes the greeting sees it again after a week, not never

= 0.16.0 =
* No server address to fill in any more: paste your shop key and connect
* Each shop now has its own key from Parla

= 0.15.1 =
* The new Parla logo on the settings screen

= 0.15.0 =
* AgentAssist is now called Parla. Your settings, connection and key carry over by themselves: nothing to redo

= 0.14.0 =
* Premium voice is now part of your Parla plan: nothing to set up in the plugin any more
* Connect with the personal key from your Parla account (starts with parla_live_)

= 0.13.0 =
* Redesigned settings screen: an Overview with sales and statistics first, then Assistant, Appearance, Voice and Connection, with switches, choice cards and a save bar that tells you when something is unsaved
* Greet signed-in customers by their first name (can be switched off under Appearance)
* A storefront check that shows what the chat on your shop is running right now

= 0.12.0 =
* Status tab: the sales the assistant helped with over the last 30 days, the items it put in the cart itself, and average order with and without it. Counted from your own orders, nothing is sent anywhere
* Shoppers can ask the assistant to add a product to their cart, in the chat or on a call
* Declares compatibility with WooCommerce High-Performance Order Storage

= 0.11.0 =
* Premium voice: each shop gets its own private key for Vapi, shown in the Voice tab with the address to paste into your assistant

= 0.8.0 to 0.10.0 =
* Premium voice (Vapi) as an option beside the free browser voice. Products and links still show on screen during the call
* Waiting list: when a shopper asks for something you do not sell, the assistant can take their email and you see what people keep asking for
* Popular products on the home screen can be turned off or limited to the home screen

= 0.7.0 =
* Light / dark / auto setting, and a rebuilt dark palette
* The admin preview now shows the chat on a storefront of the matching shade

= 0.6.0 =
* Redesigned admin: five tabs, one Save, a live preview and a Status panel
* Voice picker, so calls do not land on a novelty system voice

= 0.5.0 =
* Hands-free voice calls
* Merchant insights: what shoppers looked for and did not find

= 0.4.0 =
* Photo upload, dictation, thumbs up/down, greeting bubble, right-to-left languages

= 0.1.0 =
* First release: chat widget, store connection, product search, order tracking, address changes and returns.
