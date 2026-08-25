=== BuyIt Together ===
Contributors: powerloop
Tags: woocommerce, cross-sells, related products, upsell, recommendations
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows on each product page the items customers actually bought with it, deduced from your own order history. No per-product setup.

== Description ==

BuyIt Together reads your order history, counts which products end up in the
same order, and shows the strongest pairs on each product page. Nothing is
configured product by product: the associations are recalculated every week
from what your customers actually did.

It also tells you how well your existing WooCommerce cross-sells match reality,
and proposes the associations your sales support but your configuration is
missing.

= What it gives you =

* **A suggestion block on the product page**, built from real co-purchases.
* **A diagnosis** of your existing cross-sells: how many are confirmed by
  actual orders, and how many are not.
* **Recommendations** you can apply in bulk, with one-click undo.
* **Rules by tag or category**, for products that do not have enough history
  yet — for example, suggest a screwdriver kit on every part tagged "Gimbal".
* **A category editor** that lists a whole category, one product per row, with
  its suggestions, cross-sells and upsells editable in place.
* **Bulk assignment** across a category, a tag or a hand-picked selection.

= What it does not do =

It does not call any external service. Everything is computed from your own
database, on your own server. No account, no API key, no data leaves the site.

= Two kinds of suggestion, kept separate =

WooCommerce cross-sells appear in the **cart**, once the customer has chosen a
product. This plugin's own suggestions appear on the **product page**, before
they add to cart. The admin screen keeps them in separate tabs, because they
reach the customer at different moments and are stored in different places —
cross-sells in WooCommerce's own fields, shared with the rest of your site;
product page suggestions in the plugin's own storage.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/buyit-together`, or install it
   from Plugins → Add New → Upload.
2. Activate it. WooCommerce must be active.
3. Go to WooCommerce → BuyIt Together and press **Recalculate now** to build
   the first set of associations.

The calculation then runs automatically once a week.

== Frequently Asked Questions ==

= Does it modify my WooCommerce cross-sells? =

Only when you ask it to. Applying recommendations or using bulk assignment
writes to WooCommerce's own cross-sell fields, and each such operation can be
undone from the Cart tab. The product page suggestions are stored separately
and never touch WooCommerce's data.

= What happens to my data if I uninstall it? =

The plugin's own settings and computed associations are deleted. Your
WooCommerce cross-sells and upsells are left untouched — they are your site's
data, not the plugin's, and you may have entered them by hand before
installing it.

= How much history does it use? =

The last 12 months of completed and processing orders, filterable via
`bit_fenetre_jours`. A pair must appear at least twice to be stored, and at
least three times to be recommended.

= My catalogue has products that are bought with everything. =

Add them to "Products never to suggest elsewhere" in the Analysis tab. A
consumable or a free gift shows up in almost every order and would otherwise
become the companion of your whole catalogue.

= Does it work with High-Performance Order Storage? =

Yes. It reads orders from the HPOS tables directly.

== Screenshots ==

1. The Analysis tab: match rate, stat tiles and recommendations.
2. The category editor, one product per row.
3. The suggestion block as customers see it.

== Changelog ==

= 1.3.2 =
* Fixed: a real (non-square) product photo — two parts side by side, a close-up, a long thin item — inflated its card far beyond what its content needed, which is what made the window tall enough to need scrolling in the first place. Thumbnails are now a fixed compact size, not sized from the image itself.
* The window is now capped to 4 suggestions, regardless of how many are configured for the product — a fixed-size window with no internal scrolling cannot simply grow to fit an open-ended list. Filterable via `bit_nb_modal`.
* Wider window, smaller cards: more suggestions fit in a single row instead of stacking into extra rows.
* Add / View product buttons are now a guaranteed identical size in every layout, not just when the grid happened to make them match.

= 1.3.1 =
* Fixed: in the Columns layout, a real (longer) product name wrapped to three or four lines, throwing off the Add buttons' alignment from one card to the next. Names are now capped to two lines and cards keep the same height.
* The window no longer scrolls internally in normal use; only a whole-window scroll remains as a safety net for an unusually long list of suggestions.
* Smaller Add / View product buttons, matched in width regardless of their label length.
* "View cart" and "Continue shopping" are now centred.

= 1.3.0 =
* The Add to cart window has been redesigned: rounded panel, soft shadow, a checkmark badge, an entrance animation, and a proper two-layout choice (row or a small grid of cards).
* New: the window can now be customised — suggestions title, "added to cart" message, accent/background/text colours, corner rounding, and layout — from a new Settings tab, with a live preview.
* By default the window automatically matches the site's own colours (the real Add to cart button and page background), rather than an arbitrary fixed colour. A checkbox switches to fixed colours instead.
* New "Settings" tab: where suggestions appear (block, window, or both) and the window's appearance now live together, instead of being mixed into Product page suggestions.

= 1.2.0 =
* New: an optional window can open right after "Add to cart" on the product page, showing the same suggestions with their own "Add" buttons — the customer never leaves the page. Uses WooCommerce's own public cart API (Store API), so it works alongside whatever the theme's Add to cart button already does.
* New setting under Product page suggestions: show the suggestions as the existing block, as the new window, or both. Off by default — existing sites keep their current behaviour until this is turned on. Requires WooCommerce 8.3 or later; the option is hidden with an explanation on older versions.
* The window's own quick-add buttons only appear for simple products; variable products link to their page instead, since picking a variation needs its own screen.

= 1.1.0 =
* Brand mark added next to the screen title, matching the plugin directory icon.
* A confirmation now appears after every save — applying recommendations, saving exclusions, bulk assignment, undo, and recalculation all previously redirected silently.
* Stat tiles on the Analysis tab are now shortcuts to the section that explains them.
* The match-rate meter animates in on page load instead of appearing pre-filled.
* The recommendations table shows a live count of selected rows.
* The "Recalculate now" button disables itself and shows progress while a large catalogue is being processed, preventing an accidental double submission.
* Fixed: recalculating from the Analysis tab dropped back to a different tab, hiding the very numbers the calculation had just refreshed.
* The bulk-assignment panel in the category editor stays in view while scrolling a long list.

= 1.0.0 =
* First public release.
