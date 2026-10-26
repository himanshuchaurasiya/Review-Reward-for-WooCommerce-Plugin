=== Review Reward for WooCommerce ===
Contributors: yourwporgusername
Tags: woocommerce, reviews, coupons, rewards, email
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email review reminders and reward customers with a single-use coupon after an approved, verified product review.

== Description ==

Review Reward for WooCommerce turns buyers into reviewers.

1. A customer's order is marked Completed.
2. After a delay you choose (default 7 days) they get one reminder email linking to the products they bought.
3. When they submit a review that meets your rules and it is approved, a unique single-use coupon is created and emailed to them.

**Features**

* Review reminder email after order completion (configurable delay)
* Reward types: free order (100% off, with a maximum order value) or fixed amount off
* Optional free shipping on the coupon
* Minimum star rating and minimum review length
* Review window: review must be submitted within X days of order completion
* Coupons are single-use, restricted to the reviewer's email, and can expire
* One reward per customer per product
* Option to stop reward orders from earning further rewards
* Compatible with WooCommerce High-Performance Order Storage (HPOS)

**Notes**

Reminders are sent by a daily WP-Cron task. On low-traffic sites, use a real server cron to trigger wp-cron.php. The coupon is restricted to the email used on the review.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin (WooCommerce must be active).
3. Go to WooCommerce > Review Rewards and configure your settings.

== Frequently Asked Questions ==

= Does the customer need to have bought the product? =

Yes. A reward is only issued if the reviewer has a completed order containing that product.

= Can a customer earn several rewards for the same product? =

No. One reward per customer per product.

= Why did my customer's coupon not work? =

The coupon is locked to the email address used on the review. It must match the billing email at checkout.

== Changelog ==

= 1.1.1 =
* Fixed duplicate-reward check when the reviewer has no email.
* Fixed reminders targeting all historical orders when review window is 0.
* Fixed reminder queue getting stuck on stores with many orders.
* Added translation support and uninstall cleanup.
* Renamed internal prefix to revrew_.

= 1.1.0 =
* Added reminder emails, chain-reward protection, max order value and HPOS compatibility.

== Upgrade Notice ==

= 1.1.1 =
Bug fixes for reminders and duplicate-reward checks. Settings are stored under a new key, so re-save your settings after updating from 1.1.0.
