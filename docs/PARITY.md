# Parity checklist: rebuild vs old system

Each rule from section 3 of the rebuild prompt and the test that proves it in the new stack. Run all with `cd app && vendor/bin/phpunit` (add MariaDB env for the parallel-order and backup tests); the display copy is tested with `cd web && npm test`.

| Rule | Test |
|---|---|
| Hours 10:00-22:00 Dhaka, boundaries 09:59 / 10:00 / 21:59 / 22:00 | `OrderingRulesTest::test_hours_boundaries`, `OrderApiTest::test_closed_hours_boundaries`, `web/src/lib/rules.test.ts` |
| Three methods; zones IN_CITY / OUTER_CITY | `OrderingRulesTest`, `OrderApiTest::test_home_delivery_cod_happy_path` |
| Outer Sylhet eligibility (Bakery whole, others only approved, uncertain never) | `OrderingRulesTest::test_outer_city_rules`, `test_uncertain_flags_never_eligible…`, `OrderApiTest::test_outer_city_names_ineligible_line` |
| Fees only on home delivery | `OrderingRulesTest::test_fee_home_delivery_only`, `OrderApiTest::test_bulk_by_quantity_allows_only_showroom_and_charges_no_fee` |
| Express pickup: 60 min lead, 30 min slots, inside hours | `OrderingRulesTest::test_pickup_*`, `OrderApiTest::test_express_pickup_lead_time_and_outlet` |
| Bulk by quantity (19/20) and by amount, showroom only | `OrderingRulesTest::test_bulk_*`, `OrderApiTest::test_bulk_by_quantity_allows_only_showroom_and_charges_no_fee`, `test_bulk_by_amount` |
| Status flows, forward only or cancel | `OrderingRulesTest::test_flow_*`, `OrderApiTest::test_status_moves_one_step_only…`, `test_showroom_flow` |
| Unpriced / hidden product cannot be ordered | `CatalogueTest::test_unpriced_or_hidden…`, `OrderApiTest::test_unpriced_and_hidden_products_are_refused` |
| Catalogue import idempotent, 59 products hidden + unpriced | `CatalogueTest::test_import_*` |
| Search Bangla and English | `CatalogueTest::test_search_in_bangla_and_english_with_aliases`; browser check in the Phase 5 run |
| Stock: atomic reserve/commit/release, no overselling under parallel orders | `InventoryTest::test_parallel_orders_cannot_oversell` (12 processes, 5 units → exactly 5 orders; MariaDB) |
| Append-only stock ledger with actor + reason | `InventoryTest::test_set_stock_writes_ledger…`, `test_stock_ledger_is_append_only` |
| Variant with orders or stock history cannot be removed | `CatalogueTest::test_variant_with_orders_or_stock_history…`, `AdminCatalogueTest::test_variant_with_stock_history…` |
| Idempotency key (double tap = one order) | `OrderApiTest::test_double_tap_makes_one_order` |
| Snapshot of names, prices, address; later edits do not change history | `OrderApiTest::test_server_ignores_browser_prices_and_snapshots_items` |
| Status events append-only | `SettingsSetupTest::test_status_events_append_only` |
| Ownership check on tracking | `OrderApiTest::test_track_needs_matching_order_and_phone` |
| Customers cannot cancel; staff cancel with a reason | `OrderApiTest::test_customers_have_no_cancel_endpoint`, `test_cancel_needs_reason…` |
| Paid + cancelled → `payment.refund_required` | `OrderApiTest::test_cancelling_a_paid_order_writes_refund_required` |
| Audit log with before/after, append-only | `StaffTest::test_audit_log_is_append_only`, `AdminCatalogueTest::test_edit_product_logs_before_and_after`, `AdminScreensTest::test_content_pages_screen_and_audit_screen` |
| Manager is read-only (also via direct calls) | `StaffTest::test_manager_is_view_only…`, `OrderApiTest::test_manager_cannot_change_orders_or_payments`, `AdminUiTest::test_manager_sees_orders_read_only…`, `AdminScreensTest::test_manager_*` |
| TOTP + recovery codes, replay rejected; lockout | `StaffTest::test_totp_*`, `test_lockout_*`, `AdminUiTest::test_admin_*` |
| Payments: bKash/Nagad need TrxID + sender; one TrxID once; COD marked PAID by staff | `OrderApiTest::test_mobile_payment_needs_trx…`, `test_same_trxid_cannot_be_used_twice…`, `test_verify_bkash_then_due_is_zero…`, `test_cod_marked_paid_by_staff_only_once` |
| Honeypot, rate limit, CORS | `OrderApiTest::test_honeypot…`, `test_order_endpoint_is_rate_limited`, `test_track_is_rate_limited…`, `test_cors_allows_only_configured_origin` |
| Money integer poisha; phone normalised | `MoneyTest`, `PhoneTest` |
| Backup restores cleanly | `BackupTest::test_backup_restores_cleanly_into_a_fresh_database` (MariaDB) |
| Go-live checks | `LaunchTest` (preflight, headers, argon2id) |

## Acceptance items that are not unit tests

| Item | Status |
|---|---|
| Real order from the site reaches the admin; staff move it; TrxID verified | Order placed from the built site against the real API + MariaDB and seen in the admin (Phase 4 run); status/payment actions covered by the tests above |
| Mobile performance (Pixel 5 class, 4x CPU, slow 4G) | Lighthouse: Performance 97-99, Accessibility 100, Best practices 100, LCP 2.0-2.3 s, CLS ≤ 0.024, TBT 0 ms (home measured with temporary test hero images). **Re-measure with real photos on the live site and a real phone.** |
| Colours and logo unchanged, light/dark | All 63 `--dw-*` tokens identical to `packages/ui/styles/dw.css` except the font stack (Tiro Bangla + Montserrat as specified); `logo.png` byte-identical; theme switch present |
| Telegram | Dropped by the owner; replaced by `OrderNotifier` (log now, WhatsApp later) |
| Storefront zone behaviour as in the old site: zone bar (In Sylhet / Outer Sylhet, delivery promise, open/closed chip, showroom link), Outer Sylhet shows only deliverable products, product page shows both zones and blocks add-to-cart with the reason, cart flags ineligible lines (switch zone or remove, never silently removed) | Browser run against the real API: zone toggle 59 → 25 products, ds-01 blocked in Outer Sylhet with reason, cart warning and fix buttons |
| Showroom order builder as in the old site (`/showroom`, `/bulk` redirects): products by category with quantities, search, threshold by quantity or amount, checkout forced to showroom, paid in advance (`bulk.prepaid` default on) | Browser run: 19 items blocked, 20 enabled, COD disabled, bKash order created (`OrderApiTest::test_showroom_orders_are_prepaid_by_default…`) |
| Items needing the owner | See `docs/DEPLOY_HOSTING.md` section 0 |
