---
target: order/checkout.php
total_score: 28
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 3
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/order/checkout.php"
target_fingerprint: "sha256:809d53f3fa337a76998da240d1e6f2764053b93198362373819e4b2c4a761337"
target_path: /Users/hamunhirbod/claude_code/myseat/order/checkout.php
timestamp: 2026-09-30T11-52-25Z
slug: order-checkout-php
---
Method: dual-agent (A: a88937a0524196fd9 · B: a13e3f9ac8a9f075d)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Zone check gives good live feedback, but the submit CTA's enabled/disabled state actively misrepresents readiness - it never reflects missing name/phone/time |
| 2 | Match System / Real World | 4 | Solid — idiomatic German food-order language, tip framed for the driver |
| 3 | User Control and Freedom | 3 | Coupon removable, tip resettable, mode switchable freely; no cart-quantity edit from checkout itself |
| 4 | Consistency and Standards | 3 | Reuses shared components well, but mode/tip use toggle-buttons while time/payment use native radios - two idioms for the same "pick one" task |
| 5 | Error Prevention | 1 | The submit button being enabled with no name, no phone, or no valid time selected invites a doomed submit instead of preventing it |
| 6 | Recognition Rather Than Recall | 4 | Solid — sticky order summary, "remember my details" reduces re-entry |
| 7 | Flexibility and Efficiency | 3 | Coupon prefill via URL param, remembered guest details, sensible ASAP default |
| 8 | Aesthetic and Minimalist Design | 4 | Restrained, on-brand, fieldset/legend structure keeps a long form scannable |
| 9 | Error Recovery | 1 | A create-order failure renders correctly but off-screen, ~600px from the sticky button a guest just pressed |
| 10 | Help and Documentation | 3 | Helpful inline micro-copy (payment amount hints, delivery-zone status) where it's actually needed |
| **Total** | | **28/40** | **Good** |

## Design Specificity Verdict

**LLM assessment**: Clearly authored for this restaurant, not a generic template. The serif section legends, the single gold accent disciplined to exactly the moments that matter, and copy like "Fast geschafft – nur noch 1 Angabe!" and the driver-tip framing read as a considered, warm voice rather than boilerplate checkout copy. It loses a notch only because the payment-method copy ("Sicher über Mollie") and the legal paragraph are fairly interchangeable with any German delivery checkout.

**Deterministic scan**: Clean in both modes — `impeccable detect` returned `[]` (exit 0) against the raw PHP file and again against a rendered static HTML harness. All substantive findings here come from live interaction, not the static detector.

**Visual overlays**: not run — `checkout.php` needs a live session/cart; both assessments used PHP-backed rendering harnesses with a stub `api.php` implementing the real endpoints, so the actual `checkout.js` logic ran end-to-end rather than being mocked away.

## Overall Impression

This is the highest-stakes page in the entire guest flow — a legally binding, payment-obligated commitment — and it's also where the flow's craft and its cracks are both most visible. The zone-check, coupon, and progress-bar micro-interactions are genuinely well built and give honest live feedback. But the single most important status indicator on the page, the submit button itself, doesn't tell the truth: it can look fully ready while required fields are still missing, and when a submission does fail, the explanation lands far outside the guest's field of view. At a payment-commitment moment, an interface that appears to lie about readiness is more corrosive to trust than any amount of legal fine print.

## What's Working

1. **The zone-check and coupon flows are genuinely well built** — debounced address checking, live "Adresse wird geprüft..." status, color-coded ok/bad states, and coupon apply/remove round-trips all gave immediate, legible, correct feedback in testing.
2. **Real semantic structure throughout** — five genuine `<fieldset>/<legend>` pairs (not divs pretending to be sections), every text field's `<label>` correctly wraps its input, and the time-slot picker and payment selector both use native `<input type="radio">` rather than custom ARIA reimplementations.
3. **The 16px Form Rule and 44px touch-target floor hold up on every field and control specific to this page** — all 10 checkout-specific inputs measured exactly 16px, and every interactive control measured met or exceeded 44px. The defect class found on sibling pages does not reappear here in this page's own markup.

## Priority Issues

**[P0] The submit button doesn't validate name, phone, or a selected time before enabling**
Why it matters: `reason()` — the function driving `#co-submit`'s disabled state and the `#co-why` text next to it — checks cart contents, minimum order, delivery zone, and payment method, but never checks `name`, `phone`, or whether a valid time was actually selected. Confirmed live by both assessments: with an empty name and phone, the button was fully enabled showing the live total; in a no-time-slots scenario, the button stayed enabled with no explanation, and submitting produced "Bitte wähle eine Zeit" for a control the page never showed. The separate `checklist()`/progress-bar logic already tracks these same fields correctly — two pieces of UI on the same screen disagree about whether the order is ready.
Fix: make `reason()` the single source of truth for both the button's disabled state and the progress text, reusing the same field checks `checklist()` already has.
Suggested command: `/impeccable harden`

**[P1] The custom tip amount field silently rejects the exact format its own placeholder asks for**
Why it matters: `#co-tip-custom-in` is `type="number"` with placeholder "0,00" (German comma-decimal), and `tipCents()` parses the value expecting a comma. But a native number input rejects a comma outright — typing "3,50" produces a console warning and the field's value becomes empty. A German guest following the placeholder's own formatting cue cannot enter a custom tip at all.
Fix: switch the field to `type="text" inputmode="decimal"` so comma input is accepted, keeping the existing comma-to-period parsing logic.
Suggested command: `/impeccable harden`

**[P1] A failed order submission is invisible from where the guest is actually looking**
Why it matters: on a create-order failure, `#co-error` renders the correct message, but it lives at the bottom of the scrollable form column — roughly 600px from the submit button, which sits in the separate sticky sidebar. No scroll or focus is triggered for this failure path (unlike the client-side name/phone validations, which do call `.focus()`). A guest who scrolled down to tap the button sees it silently reset with no visible explanation, and is likely to just tap it again.
Fix: scroll/focus `#co-error` into view on any `!r.ok` response, or surface a short-lived error line inside the sticky `.co-sum` sidebar near `#co-why`, where it's guaranteed to be on-screen at the moment the button was pressed.
Suggested command: `/impeccable harden`

**[P1] An empty cart still shows a fully interactive, active checkout form underneath the "cart is empty" message**
Why it matters: the async `op=state` response calls `setMode(S.mode)`, which unconditionally un-hides `#sec-address` for the default delivery mode — regardless of whether the cart is actually empty. Verified live: "Dein Warenkorb ist leer" appeared at the top while a fully interactive, empty address form and an active coupon toggle rendered directly below it. A guest with an empty or expired cart sees a half-broken page and can fill out an address for an order that can't be placed.
Fix: guard `setMode()`'s section-visibility toggles with a cart-length check, or have the empty-cart branch short-circuit before `setMode()` runs.
Suggested command: `/impeccable harden`

**[P2] A rejected delivery address is told it's "still being checked"**
Why it matters: when an address is checked and definitively rejected, `#co-zone` correctly shows "Leider liegt diese Adresse außerhalb unseres Liefergebiets" in danger color. But `reason()` only distinguishes "field empty" from "field has a value" and shows "Wir prüfen noch, ob wir zu deiner Adresse liefern" even after the check has finished and failed — two live-region messages on the same screen actively disagreeing about whether the check is pending or resolved.
Fix: give `reason()` a third branch for "zone checked and rejected" with its own copy ("Wir liefern leider nicht zu dieser Adresse").
Suggested command: `/impeccable clarify`

## Persona Red Flags

**Jordan (Confused First-Timer)**: hits the P0 issue directly — fills in the order but skips name/phone, taps the shiny gold "ready-looking" button, and only then learns a field is missing. The mode toggle itself (delivery vs. pickup, with a hint line) is clear; the binding-order text uses "Widerrufsrecht" (right of withdrawal) unglossed, which is honest but asks an anxious first-timer to trust rather than fully parse it.

**Casey (Distracted Mobile User)**: the 44px touch targets genuinely hold up under one-handed testing — a real win given the sibling pages' history here. Casey's actual failure point is the submit-error case: if the network hiccups while she's half-distracted, the button quietly resets and the explanation is off-screen, so she has no reason to scroll up and will likely just re-tap, assuming it's unresponsive. If she wants to add a custom tip in the format the field itself suggests, she can't (P1 above).

**Sam (Accessibility-Dependent)**: the time-slot picker and payment selector are fully keyboard/AT-operable via native radios with no special concerns found — a genuine strength. Tip buttons and the mode toggle are keyboard-reachable with correctly-toggling `aria-pressed`, but Sam hears "button, not pressed" rather than "radio button, 2 of 6" — weaker grouping information than a radiogroup would give. The coupon disclosure is a good pattern (focus moves to the input on open) but has no Escape-to-close, the same defect class already found on sibling pages.

## Minor Observations

1. Toggle-button groups (mode, tip) use `aria-pressed` while time and payment use native radios — two different "pick one" idioms on the same form.
2. `#co-submit` has no visually distinct disabled appearance — its color is identical enabled or disabled, relying only on the browser's default disabled rendering.
3. Tip percentage is computed on the subtotal before any coupon discount — likely intentional (tipping on food value, not a discounted total) but not obvious from the UI; worth confirming.
4. No loading skeleton for `#co-pay`/`#co-when` while their initial fetches resolve — on a slow connection these sections render visibly empty for a moment.
5. `city` defaults to a hardcoded "Hildesheim" — sensible for a single-location restaurant, but a guest at the edge of the delivery zone has to notice and overwrite prefilled text rather than starting from empty.

## Questions to Consider

- What if the submit button's disabled state and its `#co-why` text were generated from one shared validation function, rather than three separate hand-maintained logic paths that have already drifted out of sync with each other?
- What if the order-critical error region lived inside the sticky sidebar instead of the scrollable form column, guaranteeing it's on-screen exactly when and where a guest is looking when something goes wrong?
- What if, given this is explicitly the binding, payment-obligated moment in the whole product, the page briefly re-confirmed the total/time/address back to the guest right before firing the order — turning the moment of commitment into a moment of reassurance instead of a silent network round-trip?
