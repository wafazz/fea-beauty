# New modules plan (items 1–7)

**Status:** item 4 (Cross-sell suggestions) is **done** (`080_cross_sell.sql`). Item 6 (Shipping rules) is **done** (`081_shipping_rules.sql`). Item 1 (Storefront content) is **done** (`082_cms.sql`). Items 2, 3, 5 and 7 are not started.

Covers the seven modules from the "what else to add" list:
CMS, customer segments, marketing flows, cross-sell, multiple warehouses, shipping rules, wholesale accounts.

Every module is a paid add-on, follows the same pattern as the existing modules, and is **off by default**. A
superadmin sets its price on `/superadmin/modules`, the admin buys it under "Buy Add-On Module", and the superadmin
approves the invoice. Until it is bought and switched on, the admin sees nothing new and the storefront is unchanged.

Migrations continue from **080** (the last one is `079_member_presence.sql`). The numbering in
`new-features-plan.md` (“from 068”) is out of date: those features are already built.

Every module touches the public site, customer data, money or stock, so every one is **Tier T2**: the full review
and test gates apply.

## Overview

| # | Module | Switch key | Size | Tier | New tables | Main risk |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Storefront content (CMS) | cms_pages | M | T2 | cms_pages, cms_banners | unsafe HTML in content |
| 2 | Customer segments | customer_segments | M | T2 | customer_segments, customer_tags | customer data exposed to the wrong staff |
| 3 | Marketing flows | marketing_flows | L | T2 | marketing_flows, marketing_sends | duplicate or unwanted emails |
| 4 | Cross-sell suggestions | cross_sell | S | T2 | product_related | slow product pages |
| 5 | Multiple warehouses | multi_warehouse | XL | T2 | warehouses, warehouse_stock | overselling and double stock deduction |
| 6 | Shipping rules | shipping_rules | M | T2 | pickup_points | postage wrong once a rule applies |
| 7 | Wholesale accounts | wholesale_accounts | L | T2 | b2b_accounts | credit given to buyers who never pay |

## Suggested build order

Low-risk and reusable pieces come first. Modules that change money or stock come after the ones they depend on.

1. **Cross-sell suggestions (4). Done.** Small, read-only on the storefront. It proves the paid-module wiring on a low-risk feature.
2. **Shipping rules (6).** Small change to postage. Built before any new payment method, so its numbers can be checked against the existing postage tests.
3. **Storefront content (1).** No money involved. Gives admins control of banners and policy pages.
4. **Customer segments (2).** Needed by marketing flows and wholesale accounts.
5. **Marketing flows (3).** Depends on segments. One flow (welcome email) first, others after.
6. **Wholesale accounts (7).** Depends on segments and member price groups. Changes payment rules, so it needs the full money tests.
7. **Multiple warehouses (5).** Largest and riskiest: it changes how stock is counted and deducted. Start with read-only per-location counts, and only later allocate orders to locations.

## 1. Storefront content (CMS) — DONE

**Built:** homepage banners (`cms_banners`: image, link, start and end times, order, active) shown at the top of the
homepage; content pages (`cms_pages`: title, address, body, draft or published, SEO title and description) served at
`/pages/<address>`. Bodies are cleaned with `RichText` on save and on display. Addresses that clash with the shop are
refused. Admin: `/admin/content`. Tests: `tests/CmsDbTest.php` (16 checks, passing on the `_test` database).

**Not in this build:** a second banner position (middle of the page); a footer menu of pages; Malay-language pages
as a separate language. The open questions below are still open.

**What it does.** Admins manage homepage banners and content pages (for example FAQ, shipping policy, returns policy)
without a developer.

**Scope.**
- Banners: image, link, position on the homepage, start and end dates, sort order.
- Pages: title, URL slug, body, published or draft, optional SEO title and description.
- Only published pages and banners in their dates show on the storefront.

**Changes.** `cms_pages` (slug unique, title, body, status, seo_title, seo_description, published_at),
`cms_banners` (image path, link, position, starts_at, ends_at, sort_order). Routes `/pages/{slug}` on the storefront,
admin pages under `/admin/content`. Images go through the existing upload service.

**Acceptance.**
- A draft page returns 404 to shoppers.
- A banner outside its dates does not show.
- Body HTML is cleaned with the existing `RichText` service, so scripts and unsafe attributes are removed.

**Open questions.** Should banners be multiple per position (rotating)? Should pages support Malay text separately?

## 2. Customer segments

**What it does.** Groups customers by how they buy: lifetime spend, number of orders, last order date, and tags an admin sets.

**Scope.**
- Built-in measures from existing order data: orders count, total spent, last order date (cancelled orders excluded).
- Manual tags on a customer (for example "VIP", "wholesale").
- Saved segments, for example "spent over RM500 in the last 90 days" or "no order for 60 days".
- Export a segment to CSV, for admins with the permission only, and recorded in the audit log.

**Changes.** `customer_segments` (name, rules as JSON, created_by), `customer_tags` (user_id, tag). Queries read
`orders` and `users`; nothing is copied.

**Acceptance.**
- A segment count equals the number of customers the rules match, checked against a hand count on the test database.
- Only staff with the permission see customer contact details.
- Every CSV export writes an audit row.

**Privacy.** Customers can be told what is stored about them under the PDPA. This module makes that easier, but it
does not replace a request process. See the Personal data section in the README.

## 3. Marketing flows

**What it does.** Automatic emails based on a customer's action or a segment: welcome email, review request after
delivery, and win-back email for customers in a "lapsed" segment.

**Scope.**
- Each flow has a trigger, a delay, a template, and an on or off switch in the admin.
- Each customer gets each flow at most once per trigger (for example one welcome email, one win-back per 90 days).
- Sends go through the existing queue and respect the unsubscribe list (`email_unsubscribes`).

**Changes.** `marketing_flows` (key, name, trigger, delay_hours, template, enabled), `marketing_sends`
(flow_id, user_id, ref, sent_at, unique on flow, user, ref). Triggers reuse existing events (verified account,
order placed) and the segment rules.

**Dependency.** The review request after delivery needs delivery status from the courier. NinjaVan and J&T tracking
are not wired yet, so that flow waits for courier tracking. Welcome and win-back flows do not.

**Acceptance.**
- Running the same trigger twice sends one email.
- An unsubscribed customer receives nothing.
- A flow that is switched off sends nothing, even when its queue has items (checked when the queue runs).

**Open questions.** Email only, or WhatsApp as well (the WhatsApp queue already exists)? Who approves the templates?

## 4. Cross-sell suggestions — DONE

**Built:** `product_related` (pinned, in order, up to 12 per product), `product_copurchase` (bought together, from
`bin/copurchase.php`, daily, 04:00). Product pages show pinned products first, then bought together, replacing the
same-category list only when there is something to show. The cart shows "Goes well with your cart" for the items in
it. Admins pin products from the product's edit page ("Related products (pin)"). Sold-out and inactive products are
hidden, not removed. Tests: `tests/CrossSellDbTest.php` (13 checks, passing on the `_test` database).

**Not in this build:** suggestions on the checkout page; the open questions below are still open.

**What it does.** "Frequently bought together" on product pages and in the cart.

**Scope.**
- Admins can pin related products manually (`product_related`), in order.
- If a product has no pinned items, the page shows products most often bought in the same order, from the last 180 days.
- The automatic list is calculated by a job and stored, not calculated on each page view.

**Changes.** `product_related` (product_id, related_id, sort_order). The automatic list is written to a
`product_copurchase` table by a new cron job (daily), and that job is added to the README cron list.

**Acceptance.**
- A product never suggests itself or an unpublished or out-of-stock product (out-of-stock is hidden, not removed).
- A product page makes no more queries than before, because the list is read from a table.

**Open questions.** Should the cart suggestions be hidden on checkout? Should pinned items show even when out of stock?

## 5. Multiple warehouses

**What it does.** Stock is kept per location. Checkout takes stock from the location nearest to the customer, and
admins see stock per location.

**Scope (phased).**
- **Phase A (read-only).** Locations and per-location stock counts. Existing stock moves to one default location. No
  change to checkout.
- **Phase B (allocation).** Checkout chooses the location, the order records it, and stock is taken from that location
  only. Transfers between locations are recorded in the stock movement log.

**Changes.** `warehouses` (name, address, postcode, state, active, is_default), `warehouse_stock` (warehouse_id,
product_id or variant_id, qty). A migration copies the current `products.stock` and `product_variants.stock` into the
default warehouse. Existing stock movement and count sheets gain a location column.

**Acceptance.**
- The sum of all locations equals the old total for every product and variant, before and after the migration.
- An order never takes more from a location than it holds. Checked with concurrent orders on the test database.
- An order cancelled or returned puts stock back in the location it came from.

**Risk.** Highest in this list. The migration must be run on a copy of the production database first, with the
backup taken as in the deploy checklist.

**Open questions.** How many locations at first? If the nearest one is empty, should the order fall back to another
location or be refused? Is the location shown to the customer?

## 6. Shipping rules — DONE

**Built:** a free-shipping threshold per zone (at or above, after discounts; the COD surcharge stays), and store pickup
points (`pickup_points`). Checkout offers "Pick up at a store" when points exist; a pickup order uses the point's
address, pays no postage, and records the point and its name. Admin: thresholds on `/admin/shipping-rates`, points on
`/admin/pickup-points`. Tests: `tests/ShippingRulesDbTest.php` (15 checks, passing on the `_test` database).

**Not in this build:** pickup times; the open questions below are still open.


**What it does.** Two extra options on top of the weight-based postage:
- **Free shipping** above a set order value (per zone).
- **Store pickup**: the customer collects from a pickup point, with no postage.

**Scope.**
- Free-shipping threshold per zone, in the admin's shipping rates page. The threshold is compared with the items'
  total after discounts, the same as the COD rule.
- Pickup points: name, address, opening hours, active flag. At checkout, "Pick up" replaces the delivery address and
  postage is zero.
- A pickup order is marked on the order so staff know not to ship it.

**Changes.** `ShippingRates::quote()` gains the threshold check and the pickup case. `pickup_points` table. Checkout
and the order detail page show the pickup option and point. Checkout totals stay equal to the invoice totals.

**Acceptance.**
- A subtotal exactly at the threshold gets free shipping (the rule is "at or above").
- COD surcharge is still calculated on the subtotal, as now, and is not removed by free shipping.
- Existing postage tests still pass with the module off.

**Open questions.** Is the threshold before or after the voucher discount? (Default: after, as the COD rule.) How many
pickup points, and does a pickup order need a pickup time?

## 7. Wholesale accounts (B2B)

**What it does.** Approved business buyers can order on credit terms (pay within N days) and get an invoice to pay
later, instead of paying at checkout.

**Scope.**
- Admin approves a buyer's account (company name, registration or tax number, credit limit, payment terms in days).
- A new payment method "Credit terms" is offered only to approved accounts, and only up to the credit limit.
- Statement and ageing report (what is owed, and how overdue) by customer.
- Member price groups (existing) give the wholesale prices.

**Changes.** `b2b_accounts` (user_id, company, reg_no, credit_limit, terms_days, status: pending, approved,
suspended). Orders get a `due_date`. Payments module gets the "Credit terms" method. Ageing report reads `orders`.

**Acceptance.**
- An order that would take the buyer over the credit limit is refused at checkout, with a clear message.
- A suspended account cannot place new credit orders, but its existing invoices stay on the report.
- Payments recorded against credit orders reduce the balance; the ageing report matches the sum of unpaid orders.

**Risk.** Money owed to you. Tier T2 with the full review. Consider a default credit limit of zero so nobody gets credit
until an admin sets one.

**Open questions.** Who may approve accounts? Are the invoices SST-registered (uses the SST module)? Default terms?

## Shared notes

- Each module has a superadmin switch, is off by default, has a price set on `/superadmin/modules`, and gets a line
  in the README.
- Routes for each module are guarded with `module:<key>`, and menu items are hidden when the module is off.
- Each module that changes money or stock has database tests, run on a `_test` database before it ships.
- Nothing is committed until its checks pass, as before.
- Before the first build, take a database backup (deploy checklist, step 3).

## Decisions needed before building

1. Which modules to build first (the order above is a suggestion).
2. Module prices, set on the superadmin page. No prices are in this plan.
3. The open questions in each section.
4. Whether marketing flows should include WhatsApp.
