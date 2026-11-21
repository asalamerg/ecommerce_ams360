# Storefront and cart

How the public store is put together, and the rules behind the cart.

## Home page

`StorefrontController::index` sends, in this order: slides, services, categories, brands, running offers (banners with a countdown), a deals row (products on sale, biggest saving first), a featured row (the product form's "Feature on homepage"), new arrivals, and one row per category section.

Category sections are the categories ticked "Show as a section on the home page", in their display order, up to 12; a main section brings its categories' products. With none ticked, the largest categories with at least 3 products are shown, up to 6. Rows load in one query, so the page's query count does not grow with the catalog (`QueryBudgetTest`).

Without admin slides, the hero shows the newest products, so it is never empty.

## Motion rules

These follow what the large stores do:

- Rows and the hero advance by themselves only on hover-capable screens at least 1024px wide, every 5.5 to 6 seconds, and only while in view. They pause on hover or keyboard focus. Using the arrows stops a row for good.
- Phones never move on their own. Rows use native scroll snapping.
- Everything animates only `transform` and `opacity`, and honours `prefers-reduced-motion`.
- The fly-to-cart animation is decorative and never delays the request.

## Cart

`CartManager` owns the cart. Signed-in carts use `user_id`, and guest carts use a token in an encrypted, 30-day cookie.

- **Prices are never stored.** They are read from the catalog on every view, so the browser cannot influence them and price changes reach existing carts.
- **Limits.** `Product::maxCartQuantity()` caps every write: available stock, up to 50.
- **Unconfirmed availability.** An estimated price with zero stock means stock has not been entered yet, so the product can be added, up to 5, and is labelled "availability confirmed on order". A confirmed price with zero stock counts as sold out and cannot be added. At checkout the customer must accept the estimate.
- **Unavailable lines.** A line whose product was hidden or sold out stays visible and flagged, but leaves the count and the total.
- **Login.** `MergeGuestCart` adds the guest cart to the account, within each product's limit, and removes the guest cart.
- **Locking.** Writes lock the cart row, so two tabs adding at once cannot lose a line.
- **Single currency.** The subtotal assumes one currency. Multi-currency needs work here first.

## Checkout

Orders are placed from the cart and finished on WhatsApp, as in the old store. `PlaceOrder` locks the cart and the products, then works everything out from the catalog in one transaction: it checks availability, deducts stock with a recorded movement, counts the coupon use, saves a snapshot of each line, and empties the cart. Submitting twice therefore cannot create two orders.

`WhatsAppOrderMessage` builds the wa.me link from the saved order, in the customer's language. The number comes from the admin settings, and `STORE_WHATSAPP` is the fallback. The browser opens the WhatsApp tab during the click, because a tab opened after a request gets blocked. The confirmation page keeps a button in case it was.

- A customer must accept an estimated price before ordering.
- Stock is deducted only for products with a real count. `stock_deducted` records how much to give back.
- `ApplyOrderStatus` runs on every staff status change. Cancelling restores stock and the coupon use once, and a cancelled order cannot be reopened.
- An order page opens only for its owner, or in the browser session that placed it. The reference alone is not enough.
- Checkout allows 5 attempts a minute.
- Payment is agreed on WhatsApp. Orders are saved as `unpaid`, and no online payment exists yet.

## Search

`products.search_name` and `search_body` hold folded text from all three languages, plus brand, SKU, and category names. `SearchText` folds spelling variants and plurals, and expands a short list of synonyms. Every word in a query must match, and matches in the name, brand, or SKU rank first.

Saving a product or translation updates the index. Brand and category renames are picked up by `store:reindex-search`, which runs daily. Run it by hand after importing data with raw SQL. Search uses `LIKE`, which suits this catalog size. A larger catalog should move to a search engine such as Meilisearch.

Do not store the `Request` in `CartManager` or any object built by a controller constructor. Laravel reuses controllers, so it would leak between visitors under Octane.

## Footer

`StorefrontFooter` builds it from the admin panel: contact details and social links from Settings, links from published Pages, and the busiest categories. Until Settings has a phone and WhatsApp number, `config('store.phone')` and `config('store.whatsapp')` are shown. It is cached for 60 seconds.

A floating WhatsApp button sits on every page; on a product page its message names the product and links to it. On phones it hides where a bar already sits at the bottom (product buy bar, comparison bar), and the product page has its own "Ask us on WhatsApp" link instead.

## Prices, discounts and offers

A product has a price, its own discount (a percentage up to 90, with an optional end day), and may be covered by offers (the admin's Offers screen: chosen products, a whole category or main section, or a whole brand; a percentage or a fixed amount; a start and an end).

`App\Services\ProductPricing` picks the lowest of these and stores it on the product (`sale_price`, `sale_ends_at`, `offer_id`), so lists sort and filter by the price paid. It runs when a product's price, discount, category or brand changes, when an offer is saved or deleted, and every minute (`store:refresh-prices` in the scheduler) to start and end offers on time. `Product::salePrice()` also checks the end time when reading, so a sale never outlives its end between two runs. The cart, checkout, order items (`original_price` keeps the price before), SEO offers and the sales report all use it.

## Customer service

`CustomerServiceController` is the customer side of the admin's support screens:

- `/{locale}/contact`: phone, WhatsApp, address, branches with opening hours and a map link, the delivery and returns texts from Settings, and a message form (into Contact messages).
- `/{locale}/repairs`: a repair request with up to 3 photos (re-encoded like every upload), into Maintenance. Signed-in customers follow the status and estimated cost in their account.
- Account page: support tickets, into Support tickets, with their status.
- `/{locale}/sim-plans`: the active SIM plans, each ordered on WhatsApp. Linked only when there are plans.

Each public form has the bot check (when Turnstile keys are set) and its own rate limit, and alerts the support staff (`StaffAlerts`).
