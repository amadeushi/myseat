---
target: order.php
total_score: 29
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/order/index.php"
target_fingerprint: "sha256:2d0f0bf52cf2c3ab1e3dd0f29b6000cf730ef0d92c919c2ea705e88c8a74e9f8"
target_path: /Users/hamunhirbod/claude_code/myseat/order/index.php
timestamp: 2026-09-30T11-27-32Z
slug: order-index-php
---
Method: dual-agent (A: a70600fae5712fe80 · B: a1abb4bc5bc856fd9)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Toast, in-menu "N× im Warenkorb" tags, min-order progress, live open/closed status are excellent; but "Wählen" shows nothing until the product fetch resolves - no loading state |
| 2 | Match System / Real World | 4 | Solid — natural German throughout, logical order, no jargon |
| 3 | User Control and Freedom | 3 | Good qty steppers, re-edit ("Ändern"), double-tap-confirm clear-cart; undercut by focus loss on dialog close and no Escape-close on the cart panel |
| 4 | Consistency and Standards | 3 | Chip/badge/radius vocabulary reused consistently; undercut by the extras-chip layout breaking that same grammar under real data |
| 5 | Error Prevention | 3 | Required-group validation blocks add-to-cart; checkout disabled under minimum order; qty capped; good proactive guardrails |
| 6 | Recognition Rather Than Recall | 4 | Solid — "N× im Warenkorb" tags next to the price mean guests never need to open the cart to remember what they ordered |
| 7 | Flexibility and Efficiency | 2 | No shortcuts beyond native Tab, no repeat-order affordance, no bulk actions - a single-path flow |
| 8 | Aesthetic and Minimalist Design | 3 | Clean and on-brand at rest; pulled down by chip crowding under real menu-data stress |
| 9 | Error Recovery | 3 | Blocked-add flow (scroll + flash + focus + live-region count) is excellent; generic toast on failed product fetch gives no next step |
| 10 | Help and Documentation | 1 | Allergen text is a raw inline string with no legend; only "help" is a footer promise that staff will answer questions |
| **Total** | | **29/40** | **Good** |

## Design Specificity Verdict

**LLM assessment**: Half-authored. The visual skin is genuinely bespoke — Cormorant Garamond category headers, the single gold accent, considered copy ("Frisch für dich zubereitet"), and an unusually well-crafted German fuzzy-search (edit-distance + compound-word matching, correctly resolves "shnitzel"→"Schnitzel") all read as real craft, not template. But the information architecture — mode toggle → search → category chips → product list → slide-up cart → modal configurator — is the exact skeleton of Wolt/Lieferando/UberEats. Strip the gold and serif font and this could be any restaurant's ordering page. Category descriptions, the one structural place for real voice, are optional in the markup — a category without one is pure name+list, no warmth at all.

**Deterministic scan**: Clean in both modes — `impeccable detect` returned `[]` (exit 0) against the raw PHP file (regex mode) and again against a rendered static HTML harness with the real `shop.css`/`shop.js` (deep HTML-analysis mode, `--no-design-system`). Assessment B verified the detector pipeline itself works correctly (a deliberately-broken sanity file was correctly flagged, and a deliberately-broken copy of the real CSS was correctly caught) — so this is a genuine "no primary anti-pattern findings" result, not a tool blind spot.

**Visual overlays**: not run — `order/index.php` needs a live DB/session, so both assessments used static/PHP-backed rendering harnesses with real screenshots, DOM measurement, and live interaction instead.

## Overall Impression

The JS layer here is doing more design work than the surrounding page gives it credit for — the fuzzy search, the in-menu cart-awareness tags, and the blocked-add error-recovery flow are all better than average commerce-page engineering. But that craft sits on top of a generic ordering-app skeleton, and cracks appear exactly where real menu data gets long or complex: chip labels overlapping their neighbors, touch targets a few pixels under the floor on the very first controls a guest touches, and a form field the 16px rule missed. The biggest opportunity is closing the gap between how good the interaction logic already is and how it's currently dressed and finished under real-world content.

## What's Working

1. **The fuzzy search is unusually well-crafted** — edit-distance matching tuned for German compounds and adjacent-letter-swap forgiveness, verified live: the typo "shnitzel" correctly surfaced "Wiener Schnitzel." Most ordering pages skip this entirely.
2. **In-menu cart awareness plus a genuinely excellent error-recovery path** — "N× im Warenkorb" tags next to the price remove the need to open the cart while browsing, and the blocked-add flow (scrolls to, flashes, and focuses the first missing required group with a live-region announcement of exactly how many fields remain) is better accessibility engineering than most commerce dialogs manage.
3. **Defensive CSS discipline** — global `box-sizing: border-box` and `prefers-reduced-motion` handling on every transition in the file are the kind of detail that's easy to skip and wasn't skipped here.

## Priority Issues

**[P1] Touch targets fall under the 44px floor on the very first controls a guest touches**
Why it matters: `.mode-btn` (delivery/pickup toggle) and `#shop-cats a` (category nav) both measure 40px tall against a declared `min-height:40px`; `.shop-search-clear` measures 34×34px explicitly. This is the same bug class the prior cancel.php critique found on the language switcher, recurring here on the page's first interactive element.
Fix: bump `.mode-btn`, `#shop-cats a`, `.qty-btn`, `.cart-edit`, `.cart-remove` to `min-height: 44px`, and `.shop-search-clear` to `width/height: 44px`.
Suggested command: `/impeccable adapt`

**[P1] Optional "Extras" stepper chips overlap their neighbor under real menu data**
Why it matters: `.pd-ext .pd-chip-name { width: 100% }` (shop.css line 152) forces the label to claim the whole chip width even though the same row also needs to fit the price and the −/qty/+ stepper. Verified via DOM measurement at both 375px and 1024px: a chip's label and stepper spill into the adjacent grid chip, rendering one item's name directly on top of the next item's price/stepper — confirmed with a menu word as plain as "Ketchup." This happens on any product with a countable optional extra, not just synthetic edge-case data.
Fix: give `.pd-ext .pd-chip.has-step` a wider grid-column span (or a dedicated `minmax` floor) instead of forcing the name to `width:100%` in a row that also needs the stepper and price.
Suggested command: `/impeccable adapt`

**[P1] The kitchen-note textarea breaks the design system's own "16px Form Rule"**
Why it matters: `.pd-note textarea` uses `font: inherit`, resolving to the shop's 15px body size — every other text input on this page (the search bar) explicitly overrides to 16px, but this one field was missed. On iOS Safari this triggers unwanted auto-zoom on focus, in the single most-used free-text field in the ordering flow.
Fix: add `font-size: 16px` to `.pd-note textarea`.
Suggested command: `/impeccable harden`

**[P2] Product-dialog focus isn't restored to the triggering element on close**
Why it matters: after the dialog closes (Escape, ×, or a successful add), focus falls back to `<body>`, confirmed directly in the DOM. A keyboard/screen-reader guest who opened "Wählen" on item 6 of 20 loses their place and must re-tab from the top of the page to keep browsing — this makes the core add-to-cart loop exhausting to repeat for exactly the persona whose error-recovery path (see What's Working) is otherwise best-in-class.
Fix: capture the triggering element before `showModal()` and call `.focus()` on it in the dialog's `close` handler.
Suggested command: `/impeccable harden`

**[P2] The cart panel has no keyboard escape, inconsistent with the rest of the page**
Why it matters: pressing Escape with the cart open does nothing — confirmed live (`classList.contains('is-open')` stayed `true`). The search input has its own Escape-to-clear handler and the product dialog is a native `<dialog>` with built-in Escape support; the cart is a plain `<aside>` with only a click-driven close button, so the one panel without native dismissal semantics is also the only one a keyboard user can't back out of with the key every other overlay on the page honors.
Fix: add a `keydown` handler on the cart panel (or promote it to a native `<dialog>`) that closes it on Escape.
Suggested command: `/impeccable harden`

## Persona Red Flags

**Jordan (Confused First-Timer, ordering dinner for delivery)**: No welcome/orientation copy on the opening screen — just a mode toggle and search bar, so Jordan has to infer this is the ordering page from context alone. On a "Wählen" product, "ab 14,90 €" (from-price) versus a flat price on quick-add items is a subtle distinction Jordan may not register before tapping, and she may expect the tap to add the item rather than open a dialog.

**Casey (Distracted Mobile User, ordering one-handed)** — the most realistic persona for this surface: the floating cart-bar's minimum-order message wraps to two lines at 375px, visibly unbalancing the pill on a one-handed glance. More seriously: the in-progress product-dialog selection isn't persisted anywhere until committed — if Casey gets interrupted mid-configuration and the tab is backgrounded and killed by the OS, her half-built order is gone with no warning. The completed cart itself does survive reload via localStorage, so this gap is specifically at the configuration-in-progress stage.

**Sam (Accessibility-Dependent, keyboard/screen-reader user)**: benefits from real, verified wins — native `<dialog>` correctly traps focus and closes on Escape, and the blocked-add flow moves focus to the first missing required chip with an `aria-live` count. But focus lost to `<body>` on every dialog close (P2 above) makes repeating that otherwise-excellent flow exhausting, and required single-select choice groups are exposed only as `aria-pressed` toggle buttons rather than proper `radiogroup`/`radio` semantics — Sam hears "button, not pressed" per option with no signal the set is mutually exclusive.

## Minor Observations

1. Required single-select choice groups (e.g. "Variante") use `aria-pressed` toggle buttons instead of `role="radiogroup"`/`radio` semantics — a screen reader doesn't announce "1 of 2."
2. The accepting-orders page has no `<h1>` anywhere — the only `<h1>` in the file exists solely in the "coming soon" branch, which never renders alongside the actual ordering UI; the heading outline starts at `<h2>`.
3. Product images get hardcoded `alt=""` regardless of content — reasonable given the adjacent visible title, but there's no path to supply meaningful alt text if a product image ever needs to convey something the title doesn't (e.g. a plating detail).
4. A variant chip label in the required-choice grid can wrap mid-word without a hyphen ("mit Pommes" → "mit/Pomm/es") at narrow width — legible but rough, distinct from the P1 extras-chip overlap bug above.
5. A failed product-fetch shows a generic "Das hat nicht geklappt" toast with no retry affordance, silently stranding the guest on a transient network blip.
6. The collapsed "Extras" accordion summary truncates a guest's chosen items with ellipsis - picking 4-5 extras means seeing the selection cut off with no way to see the full list without reopening the section.

## Questions to Consider

- What if required-choice groups defaulted to a pre-selected common option instead of presenting several blank chips — turning "pick one of many" into "confirm or change"?
- What if the in-progress product-dialog selection were persisted the moment a guest starts choosing, so an interrupted configuration doesn't vanish if the tab is killed?
- What if one hero-weight image per category carried the "this food looks good" job, instead of the current inconsistent thumbnail-or-nothing treatment per product?
