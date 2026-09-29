---
target: web/content/dashboard.page.php
total_score: 21
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/web/content/dashboard.page.php"
target_fingerprint: "sha256:961ef8cd04b2621c98cee0d18faad739f5c10bf769104a547aa10aff6752edea"
target_path: /Users/hamunhirbod/claude_code/myseat/web/content/dashboard.page.php
timestamp: 2026-09-29T23-10-09Z
slug: web-content-dashboard-page-php
---
Method: dual-agent (A: general-purpose subagent, design review · B: general-purpose subagent, detector/browser evidence), synthesis + independent spot-verification of every load-bearing claim by the parent session.

All checked core claims confirmed in code: the Statistics tab (`web/content/dashboard.page.php:70-74`) is wrapped `<li class='disabled'>` but contains a fully focusable, working `<a href>` with no `pointer-events:none`/`aria-disabled`/`tabindex` - found independently by both agents, confirmed by direct code read. Week/Month/Statistics tabs never receive `class='active'` despite the CSS rule already existing (`theme-dark.css:232-238`) and the same pattern already used in the topbar (`topbar2.part.php:11`). `.button_dark` has no base rule for `<a>` elements (only `input.button_dark` and the compound `a.button_dark.ob-toggle.is-blocked` exist) - both links on this page fall back to plain `.second_level_tab li a` styling. Eight hardcoded, non-translated strings confirmed despite an established `$de_lang`/constant mechanism used elsewhere on the same page, including icon alt text ("Statistics"/"Week"/"Month") that never switches to German.

One correction to Assessment B: B found failing contrast ratios (~3.1-4.0:1) in `screen.css`'s `.alert_*` classes. Checking CSS load order (`header.html.php:28,33`) shows `theme-dark.css` loads after `screen.css` at equal selector specificity and redefines `.alert_error`/`.alert_info`/`.alert_success` (lines 482-495) - the light-theme failing values are overridden in the cascade and unreachable in production. The active (dark) values pass at 5.9-14.2:1 per B.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Tab strip never shows which view is active (CSS exists, PHP condition missing) |
| 2 | Match System / Real World | 3 | Restaurant-specific terms/logic (dayparts, online block), not perfect |
| 3 | User Control and Freedom | 2 | `window.prompt()`/`alert()` instead of a guided, cancelable form |
| 4 | Consistency and Standards | 2 | "Disabled" tab is clickable; `.button_dark` has no effect; one workflow uses native dialogs, rest uses the page's own alert system |
| 5 | Error Prevention | 2 | Consequential action (block online bookings) looks like a routine link until executed |
| 6 | Recognition Rather Than Recall | 2 | Icon tabs with no visible label/tooltip (`uiIcon()` never gets `title`, only `alt`) |
| 7 | Flexibility and Efficiency | 2 | No keyboard shortcuts, no persistent default view |
| 8 | Aesthetic and Minimalist Design | 2 | Three equally-weighted cards (nav, week table, full day list) with no priority framing |
| 9 | Error Recovery | 2 | Online-block failure path uses raw `alert()` instead of the page's own `.alert_error` system |
| 10 | Help and Documentation | 2 | Sparkline numbers have no explanation of what they mean (tables vs. seats silently switches) |
| **Total** | | **21/40** | **Acceptable** |

## Design Specificity Verdict

The data is bespoke, the presentation is template-grade. The occupancy sparkline (`dash_sparkline.inc.php:43-114`) computes real open/break-time-aware timeslots per slot - not a generic date widget. The online-block feature with CSRF token, reason field, and its own operational workflow is specific to this one restaurant. But the shell around it - three stacked `.onecolumn` cards with no visual priority, a tab strip with no status indication, an uncommented table - reads like three concatenated generic admin widgets, not an authored "what do I need to know this morning" screen. The deterministic scan (`impeccable detect`, exit 0, `[]`) found no mechanical violations - expected, since the detector checks structural/visual slop patterns, not IA prioritization.

Browser evidence: unavailable - the page is login-walled and neither agent had credentials. Every claim above is source-code-based, not rendered verification.

## Overall Impression

The domain depth is real (capacity math, online-block workflow, live reservation polling), but the first screen after login treats its most valuable widget (the statistics sparkline) as if it were broken, never shows which view is active, and drops into native browser dialogs for a consequential action even though the same page already has its own consistent alert system right next to it.

## What's Working

1. **Domain-accurate occupancy math** (`dash_sparkline.inc.php:43-114`): real open/break-time-aware timeslot generation - no template would have built this.
2. **Clean, bounded traffic-light set for the capacity timeline**: exactly 4 hues (`full`/`high`/`low`/`free`), matches DESIGN.md's "small, named set" rule.
3. **Solid icon accessibility at the component level**: `uiIcon()` supports both `title` and `aria-label` correctly (`business.class.php:778-779`) - only the dashboard's own calls use it incompletely (see P2 below).

## Priority Issues

**[P1] The most informative tab looks disabled but isn't.**
`dashboard.page.php:70-74` wraps the Statistics tab in `<li class='disabled'>` with a fully working `<a href>`; `theme-dark.css:239-241` only dims it visually (`opacity: 0.45`). Why it matters: this is the view with the richest per-timeslot data, and it's dressed to look unavailable - sabotaging the one real "state of the day" moment on the page. Fix: either actually gate it server-side (drop the href, add `aria-disabled`) or drop `class='disabled'` if the tab is meant to work. Suggested command: `/impeccable clarify`

**[P1] No active-state indicator on the tab strip.**
Week/Month/Statistics tabs (`dashboard.page.php:68-85`) never get `class='active'`, even though `theme-dark.css:232-238` already defines the rule and the topbar (`topbar2.part.php:11`) already uses this exact pattern. Fix: add the missing PHP condition (`$q` comparison) - no new CSS needed. Suggested command: `/impeccable clarify`

**[P2] A consequential action looks like a routine link.**
"Online sperren" (`dashboard.page.php:30`, class `button_dark ob-toggle`) has no base rule for `<a>` - falls back to the neutral tab-link look until it's already active (`is-blocked`, `theme-dark.css:1222`). Why it matters: this stops online bookings for the day; it should look different from a normal link before being clicked, not only after. Fix: distinct warning treatment before the click, not only after. Suggested command: `/impeccable clarify`

**[P2] The block-toggle uses native browser dialogs instead of the page's own alert system.**
`window.prompt()` (line 145), `window.alert()` (lines 153, 155) - even though this same page has its own styled `.alert_error`/`.alert_success` system via `messagebox.inc.php`. Why it matters: breaks the "terse/precise" backend voice right at the one moment that deserves the most care. Fix: small inline form instead of `prompt()`, `.alert_error` markup instead of `alert()`. Suggested command: `/impeccable clarify`

**[P2] Icon tabs and sparkline numbers lack visible context.**
`uiIcon()` is called here only with `alt` (lines 72, 77, 82), never `title` - no visible tooltip, only a screen-reader label, and that label stays hardcoded English regardless of `$de_lang`. Sparkline cells (`dash_sparkline.inc.php:96-109`) show bare numbers with no time label; the metric silently switches between "tables free" and "seats free" (line 84-91) without ever showing which. Fix: add translated `title` to the `uiIcon()` calls, add a per-cell label/legend to the sparkline. Suggested command: `/impeccable clarify`

## Persona Red Flags

**Alex (power user, daily glance)**: the missing active-tab state and the fake-disabled tab are exactly the kind of friction that grates by the 50th look. No way to set a preferred default view - always lands on `$q=1` (week).

**Sam (keyboard/screen-reader)**: gets a sequence of bare numbers on the sparkline with no context - no time label, no indication whether "5" means tables or seats. On the positive side, date navigation and most icon buttons are properly labeled - not a systemic failure, just inconsistently applied.

**Jordan (new hire)**: icon-only tab strip with no tooltip and no active state, on the very first screen after login - the worst possible place for a "what do I click" moment.

## Minor Observations

- `.today-btn` is correctly rendered only conditionally (`dashboard.page.php:17-19`) - no idle button.
- `.ob-cell` in the week view correctly truncates the block reason with a `title` attribute holding the full text (`dash_week.inc.php:53-54`) - no fix needed.
- The `ajax/online_block.php` endpoint itself is well-secured (auth, CSRF token, property-ownership check, parameterized queries) - the UI critique above is about presentation, not security.
- No new, ill-fitting breakpoint value was found on this page - reuses existing values.

## Questions to Consider

1. Was the "disabled" Statistics tab an intentional half-finished permission gate, or a forgotten leftover - and if it was meant to be gated, why does the href still work?
2. The page has no place for "here's what matters today" - the Maitre day-comment exists but is explicitly limited to the day view (`page==2`) and suppressed on the dashboard. Should the first screen after login be more of a synthesis, with the full reservation list one click away instead of always visible?
