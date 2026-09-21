=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-=                                       =-=
=-=           mySeat README               =-=
=-=                                       =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-= Version: 0.2189                        =-=
=-= Date:    21.09.2026                   =-=
=-= Time:    18:30 GMT                    =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=


mySeat - Restaurant Reservation software.

Beautifully simple restaurant reservations.
Collaborate effortlessly on reservations.
mySeat will help you keep track of your reservations with ease.


News
====

 * Current fork (PHP 8 port, new booking form and backend theme) - http://github.com/amadeushi/myseat
 * Runs on PHP 8.x with mysqli (tested on PHP 8.5, MariaDB 12.3); no database changes since v0.2160
 * New Repo - http://github.com/apmuthu/myseat
 * Get the latest tarball at: https://nodeload.github.com/apmuthu/myseat/tar.gz/master
 * Add Property Vulnerability Workaround - rename and disable web/properties.php when not needed

 
CHANGELOG
=========

Versions 0.2161 - 0.2189 are maintained in http://github.com/amadeushi/myseat.
No manual database update is needed for any of them (the table plan (v0.2171, v0.2172) creates its own
tp_* tables on first use). Optional new settings for
config/config.general.php (defaults apply when missing):
  $settings['lastBookingMinutes'] = 60;   (v0.2165)  last online booking, minutes before closing
  $settings['brandName'] = 'Amadeus';     (v0.2166)  name shown in the backend header and login

2026-09-22 == mySeat v0.2189 == amadeushi - http://github.com/amadeushi/myseat

 * Backend login (PLC/index.php) rebuilt as a responsive page in the dark/gold look: a centred card
   that fits phones (16px inputs, no zoom on iOS, safe-area aware) up to desktop, proper labels,
   autocomplete hints for password managers, visible focus, DE/EN switch (?lang=de|en, default = browser
   language, German otherwise)
 * New welcome text ("Willkommen zurück - Melde dich an, um deine Reservierungen zu verwalten." /
   "Welcome back - Sign in to manage your reservations."), all messages (wrong login with the attempts
   left, blocked, password changed) are German or English instead of English only
 * Fix: the entered user name was written into the form unescaped (XSS); it is escaped now

2026-09-22 == mySeat v0.2188 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation lists (dashboard, day view): the guest type (Hausgast / Passant / Walk-in) is no longer
   shown, and a missing salutation prints nothing instead of "--"
 * The table column no longer breaks "Tisch 128" into two lines: it is as wide as its content (and
   never narrower than 150px), the fixed 20% / 30% widths of the name and note columns are gone so the
   table fits the page and every entry stays on one line

2026-09-21 == mySeat v0.2187 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: a floor that is deep rather than wide is shown zoomed onto its tables (bounding box
   of the tables plus margin, at most 1.2 screens tall and 1.4x) instead of the fixed 1200x700
   canvas, so the tables get bigger on screen. Editing and closed floors still show the whole canvas
 * Table labels no longer wrap: the name stays on one line and name, seats and the occupancy line
   scale with the size of the table (CSS container units), so small tables with a booking stay legible

2026-09-21 == mySeat v0.2186 == amadeushi - http://github.com/amadeushi/myseat

 * Booking mails rewritten (web/classes/booking_mail.class.php, used by the active plugin
   local_email_send): friendly "du" text in German and English (English when the guest used the
   English form), subject with weekday, date and time, a clear reservation box (date, time, guests,
   booking number, the guest's note), one-click cancel link, contact line, a real sign-off. No images
   (no logo, dividers or background images) and no attachments (the old code tried to attach
   /data/*.pdf menus that do not exist). The notification mail for the restaurant has a useful
   subject ("Neue Reservierung: name, guests, date, time") and readable lines
 * Legal footer of the mail from config/config.general.php: $settings['mailLegal'] (imprint lines),
   ['imprintUrl'], ['privacyUrl'], ['mailPhone']; empty = property data of the system. Filled for
   Amadeus from https://www.amadeus-hildesheim.de/impressum.html
 * Technical: the text part contained HTML (<br />, &auml;) - now proper plain text; subject and
   sender name are RFC 2047 encoded (umlauts in the subject were sent raw), both parts are UTF-8
   base64; values of the forms are cleaned of SQL/HTML escaping (backslashes, \n, entities); a mail
   error can no longer break a booking; the salutation by title ("Sehr geehrter Herr ...") is gone
   in the mail

2026-09-21 == mySeat v0.2185 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form: the title ("Anrede") is no longer asked. The confirmation mails (both
   mail plugins) greet with "Guten Tag <name>" / "Hello <name>" when there is no title; before,
   every guest without a title got "Sehr geehrter Herr ...". Existing titles still work
 * Online booking form: the arrow icon behind the consent text is gone. The consent text links to
   the terms / privacy page of the restaurant when $settings['termsLink'] is set in
   config/config.general.php (full https address, opens in a new tab); without it the text has no
   link. Before, the link was hard-coded to the terms of the original mySeat project
   (myseat.us/terms.htm)

2026-09-21 == mySeat v0.2184 == amadeushi - http://github.com/amadeushi/myseat

 * New reservation form (backend) redesigned and simplified: date, time, guests, name, phone,
   email, note and a table picker on one page; everything else sits under "Details" (advertising
   consent, staff member - prefilled with the logged in user -, recurring booking). The title
   ("Anrede") is no longer asked. Email: the confirmation mail is an opt-in checkbox ("Bestätigung
   per E-Mail senden", off by default, only active with a valid address); there is no choice of
   language any more, the mail goes out in the local language (German). The address is checked
   in the browser and on the server. Guest type (house guest / passer-by / walk-in) is no longer asked (stored
   as PASS, like the online form). The old fields address, postcode/city, discount ("GdH"),
   parking, paid and paid by are gone from the new and the edit form; their data in old
   reservations is kept and shown in the detail view only when a reservation has values there
 * Phone: "Telefon/Zimmer" is now "Telefon" and is checked (digits, + ( ) - / . and spaces,
   6 - 15 digits) in the browser and again on the server; empty is allowed
 * Table picker: chips of the tables of the table plan for the chosen day and time, with
   seats, area filter, "available / all", closed areas and taken tables marked; several tables
   per reservation; tables that fit the group are outlined, the automatic suggestion is dashed;
   the message under the chips warns about too few seats or a taken table (a manual choice may
   overrule it, like in the table plan). Without a choice a new reservation is placed
   automatically. The edit form has the same picker with the current tables preselected;
   removing all tables there clears the assignment. For a recurring booking only the first day
   gets the chosen tables, the others are placed automatically
 * Responsive: the form is a grid that goes to one column on phones with a fixed save bar, and
   the backend top bar and page container follow the window width below 940px
 * Security: ajax/process_reservation.php only writes known reservation columns (form field
   names were used as column names before); tp.php lets the "new reservation" right read the
   table list (action free_tables)

2026-09-21 == mySeat v0.2183 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan, linking tables: the "Verbinden" mode of the editor now works as a chain - click
   the tables in the order they stand (131, 132, 133, 134, 135): every click links the table to
   the one before and continues from there (before, the first table stayed the starting point,
   so this created a star around it, not a chain). Linking again removes the link, a click on the
   current starting table ends the chain
 * Automatic assignment: groups of up to 6 linked tables (was 4, so 5 chained tables of 4 could
   not seat 20 guests). The search lists every connected group exactly once (it repeated the
   same combinations before and could stop at its limit with larger groups); a chain counts as
   connected in any direction. Least waste first, then fewest tables

2026-09-21 == mySeat v0.2182 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation status dropdown (dashboard and day view): each status has a vector icon and its
   own colour, in the closed field and in the list, with a tick at the current status:
   Bestätigt (calendar, blue), Angekommen (pin, green), Platziert (seated guest, purple),
   An der Bar (glass, amber), Fertig (check, grey), No-Show (dashed guest, slate). The texts
   were "NYA / Angekommen / Platziert / an Bar / Gegangen / No Show" (German) and are changed in
   the language files (de and en); the stored values (NYA, ARR, STD, PKD, DEP, NSW) and the
   change handler are untouched. Browsers with customizable selects (Chrome 135+) show the
   icons and colours in the list, other browsers show the coloured texts in their native list

2026-09-21 == mySeat v0.2181 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: occupied tables are highlighted for the whole day. Gold fill = one reservation,
   gold with a double ring and "2x" badge = several reservations one after another, red with a
   warning badge = overlap (double booking within the stay). Every table with reservations shows
   a strip over the opening hours with one bar per reservation (red where they overlap; times
   outside the opening hours stay visible at the edge), the tooltip lists all reservations of
   the table. Small tables show one compact line (the times when there are several). A legend
   sits above the plan. The selected-reservation view (fits / too small / taken) works together
   with the strips. The day data of tp.php now carries the opening hours

2026-09-21 == mySeat v0.2180 == amadeushi - http://github.com/amadeushi/myseat

 * Shift limits: noon shift 12:00 - 16:00 (sun), from 16:00 evening shift (moon); config values
   $daylight_noon = '12:00' and $daylight_evening = '16:00' in config/config.general.php (were
   14:00 / 18:00 - please adjust the file of your installation). Times after midnight of an
   outlet that closes after midnight count as evening (a 00:00 reservation was counted as
   noon before), exactly 18:00 was counted differently in the week view and in the list.
   The week view sums are computed with one rule (daytimeKind() / daytimeSums())
 * The colour marker at the start of a reservation row is distinguishable now: noon bright
   gold, evening dark bronze (another rule overrode both with the same colour)
 * All remaining pixel icons of the backend are vector icons (uiIcon() in
   web/classes/business.class.php): table, edit, delete, recurring, allow, notices (info,
   warning, error, success, special event), logout, user, dashboard view switch, mail
   (advertise yes/no), outlet help "i", plugins play/pause, user enable/disable, list arrows.
   They follow the theme colours (gold on hover, red for delete) and are sharp at any size.
   Not changed: the login-adjacent pages confirm.php and register/success.inc.php

2026-09-21 == mySeat v0.2179 == amadeushi - http://github.com/amadeushi/myseat

 * Dashboard week view: the sun / moon symbols in front of the noon and evening numbers were
   10px raster images (blurry, white, out of proportion); now crisp vector icons in the gold of
   the theme, aligned with the numbers (daytimeIcon() in web/classes/business.class.php)
 * Reservation list: the clock symbol next to the guest name (booking older than "old days")
   is a vector icon too, muted grey with the tooltip kept

2026-09-21 == mySeat v0.2178 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form in English: the texts that were hard-coded in German are translated
   (subtitle, opening hours block with weekday names, "Closed", step titles, Next/Back, "please
   select a time", the notices for closed days / online block / large groups / no tables left,
   the whole confirmation, waiting-list and error page). They come from bt() in
   api/business.class.php (German and English; other languages fall back to English, like
   cancel.php). Emails and the cancel page were already bilingual

2026-09-21 == mySeat v0.2177 == amadeushi - http://github.com/amadeushi/myseat

 * Backend: the "Änderungen" history dropdown in the reservation detail was a white box (legacy
   .option / .option_xl widgets = white background image with an invisible select on top, black
   text); now a dark field with a gold arrow
 * Backend: dropdown lists are drawn by the page in browsers with customizable selects
   (appearance: base-select, e.g. Chrome 135+): dark list, gold highlight for the current
   choice, gold arrow - the native macOS list stayed white despite color-scheme: dark. Other
   browsers keep the native list

2026-09-21 == mySeat v0.2176 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation lists (day view, dashboard, short view): the "table" column now shows the tables
   assigned in the table plan (e.g. "Tisch 4 + Tisch 5", link to the plan of the day). It used
   to show only the old free text field, so plan assignments were invisible there. Without an
   assignment the free text stays as before and can still be edited inline

2026-09-21 == mySeat v0.2175 == amadeushi - http://github.com/amadeushi/myseat

 * Datepicker (online form on mobile and backend): the cell of today and the hovered cell had a
   light grey background from the old jQuery UI styles - white frame / light on light text;
   now transparent like the other days
 * Backend: the open list of a dropdown (time, title, type ... in the edit form) was drawn in
   the browser's light style; the dark theme now declares color-scheme: dark

2026-09-21 == mySeat v0.2174 == amadeushi - http://github.com/amadeushi/myseat

 * Online availability by table plan (switch in the plan editor, box "Einstellung": "Online-
   Verfügbarkeit: Nach Zählung (bisher) / Nach Tischplan"; default stays the old counter logic).
   With "Nach Tischplan" a time slot is bookable when the party finds free tables: the smallest
   free table that is big enough, otherwise up to four tables marked as linkable, outside closed
   areas, respecting the average stay. Reservations without a table are placed on tables in
   memory first so they block their tables. Full slots are greyed out in the form; a booking
   for a full slot goes to the waiting list, as before. An explicit passer-by limit of the day
   still applies; the seat/table limits of the outlet do not. Falls back to the counter logic
   when there are no tables or on any error. The backend day view keeps its own counters
 * "Online-Vorschau (Tischplan)" in the day view shows, for a party size, which slots the table
   plan would offer (or why the day is not bookable: closed weekday, day off, online block),
   so the switch can be tried in parallel before it is turned on
 * Times after midnight (e.g. open 14:30 - 00:00) are treated as belonging to the same evening
   when checking overlaps at a table

2026-09-21 == mySeat v0.2173 == amadeushi - http://github.com/amadeushi/myseat

 * Block online bookings for a single day (closed party, sold out): button "Online sperren" in
   the dashboard header for the selected day, and "online sperren" / "freigeben" per day in the
   week view, with an optional internal reason. The public form shows "online reservations are
   not possible on this day, contact us", greys the day out in the datepicker, and the booking
   is also refused server-side. Staff can still enter reservations in the backend - unlike the
   existing "day off" of the daily settings, which closes the day completely. A notice is shown
   in the dashboard and day view. New table online_blocks (created automatically)
 * Security: ajax/modify_dayoff.php (day off checkbox) now requires a logged in user with the
   Daily-Outlet-Edit right; it could be called without login before

2026-09-21 == mySeat v0.2172 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: day view with the reservations of a date (date navigation, "occupancy at time"
   filter). Assign a reservation to one or more tables by clicking tables or dragging the
   reservation card onto a table; a table can be given to several reservations at different
   times. Conflicts (capacity too small, table taken within the average stay of the outlet)
   are shown and can be overridden after a confirmation; closed areas can not be used.
   Cancelled, waiting-list, departed and no-show reservations are ignored
 * Automatic assignment (best fit: smallest free table that is big enough, otherwise tables
   that were marked as linkable, up to four): button for the whole day, per reservation, and
   for every new booking (online form and backend form; setting "automatically assign new
   reservations" in the plan editor, on by default). The hook can never break a booking.
   Parties that fit nowhere stay unassigned and are marked in the list
 * Area closures ("Sperrzeiten"): an area can be closed for a date range, open ended, and
   optionally repeating every year (e.g. terrace 30.09. - 01.03.) without deleting it. Closed
   areas are marked on the tab and skipped by the automatic assignment
 * New table tp_area_closures (created automatically). Online availability still uses the
   old counter logic; switching it to table capacity is planned for a later version

2026-09-21 == mySeat v0.2171 == amadeushi - http://github.com/amadeushi/myseat

 * New backend page "Tischplan" (main_page.php?p=7), first step towards table based capacity:
   administrators (Page-System) draw the floor plan of the outlet - add tables, drag them on a
   10px grid, resize, rotate (0/45/90/135 degrees), round or rectangular, seats per table, and mark
   which tables may be pushed together for larger parties. Reservation staff can view it
 * An outlet has several areas (floors, terrace, ...), each with its own plan shown as a tab; areas
   can be added, renamed, reordered and deleted (only when empty), tables can be moved between
   areas, and tables can only be linked inside one area
 * Storage in own tables tp_areas, tp_tables, tp_table_links, tp_reservation_tables, tp_settings (InnoDB,
   created automatically); JSON endpoint web/ajax/tp.php with session, CSRF token and role checks
   and prepared statements. Nothing changes for bookings yet - the existing counter based
   availability stays active (assignment of reservations to tables follows in later versions)

2026-09-21 == mySeat v0.2170 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form: the datepicker showed "&laquo;" / "&raquo;" as text (jQuery UI 1.13 no
   longer renders HTML in the arrow labels) and was transparent - its theme variables were only
   defined inside .booking-shell, but the calendar is appended to <body>; they are now defined on
   the calendar itself, which also lifts it above the time slots

2026-09-21 == mySeat v0.2169 == amadeushi - http://github.com/amadeushi/myseat

 * Backend (web/) runs on jQuery 3.7.1, jQuery UI 1.13.3 and jquery-validate 1.19.5 (was
   jQuery 1.4.4 / UI 1.8.10 / validate 1.7). The new scripts live in web/js/v3/; footer.html.php
   loads them by default. The old stack is still in web/js/ and can be loaded per browser tab
   with ?jq3=0 (?jq3=1 switches back) - it will be removed in a later version
 * plugins.js for jQuery 3: jQuery.browser and jQuery.support.opacity are re-created (needed by
   the bundled WYSIWYG editor and Fancybox 1.3), .live() -> delegated .on(), .unload() and
   .size() replaced; custom.js uses .prop() for checkboxes
 * Datepicker arrows use the real characters (jQuery UI 1.13 no longer renders HTML there);
   autocomplete/menu styling follows the new jQuery UI markup
 * Fixed: the day view crashed with a PHP 8 fatal error (getAvailability, no average duration)
   whenever the outlet data was not loaded yet, e.g. right after a fresh login
 * Note: the WYSIWYG description editor of the outlet form could not be inspected automatically
   during the upgrade - please check it once in the browser (outlet edit page)

2026-09-21 == mySeat v0.2168 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form (api/reserve.php) runs on jQuery 3.7.1 and jQuery UI 1.13.3 (was
   jQuery 1.4.4 / UI 1.7.3, both with known vulnerabilities); event handlers use .on(),
   form validation submits the form natively, the easing plugin is replaced by the one
   built into jQuery UI. The old api/js libraries were removed.

2026-09-21 == mySeat v0.2167 == amadeushi - http://github.com/amadeushi/myseat

 * Backend: editing a reservation works again - the detail page was cut off by a PHP 8
   fatal error (unquoted $_SESSION key in reservation_form.inc.php)
 * Backend: fixed a regression of the dark theme - preloadCssImages() threw a SecurityError
   because of the Google Fonts stylesheet and stopped all page scripts after it (edit button,
   datepicker, realtime updates); the call is now guarded
 * Backend: "+ Neu" tab highlighted as the primary action of the reservation view; the
   "Amadeus" brand link opens the reservation view
 * Backend: table row hover via CSS class (search results no longer turn white), dark
   autocomplete highlight, invalid fields get a red edge instead of a pink background,
   readable guest card (h5/h6), dashboard rows with larger type and a slim time-of-day marker

2026-09-20 == mySeat v0.2166 == amadeushi - http://github.com/amadeushi/myseat

 * One-click cancel link: api/cancel.php?nr=<booking number>&email=<address>
   opens a confirmation page and cancels only after an explicit click; works without a
   session, German/English, themed. Link added to the confirmation page and the emails
   (local_email_send and email_send plugins); manual lookup form as fallback
 * Cancel history entry is written with the correct reservation id, debug output removed
 * Security: processBooking() no longer takes column names from POST (field whitelist,
   plain INSERT - reservation_id can no longer overwrite other bookings); server-side
   checks for name, email, party size (max_menu) and time format; selectedDate, pax and
   outlet id validated at the public entry points; referer escaped in the form
 * Backend (web/): dark/gold theme in web/css/theme-dark.css (screen only, print stays
   light), brand name instead of the logo image, larger centred occupancy bar that scales
   down to a half-width / portrait window, dark modal windows (CXL list, details, confirmations)

2026-09-20 == mySeat v0.2165 == amadeushi - http://github.com/amadeushi/myseat

 * Booking confirmation page redesigned (dark/gold card) with confirmed / waitlist / error
   states; waitlist bookings were shown as an error before
 * Last-booking cutoff: $settings['lastBookingMinutes'] (default 60) hides late time slots,
   also for closing times after midnight, and is enforced server-side
 * Info boxes (.alert_info) in the booking form follow the dark/gold theme
 * style.css is cache-busted with the file time so deployments reach visitors immediately

2026-09-18 == mySeat v0.2164 == amadeushi - http://github.com/amadeushi/myseat

 * Mobile booking form: no nested cards, 3-column time grid, "Online-Reservierung" subtitle,
   centred headings
 * Party size is free text from 1 guest; groups above max_menu and fully booked or closed
   days show a "contact us" message with the property email (closed weekdays now also
   enforced server-side)
 * Date field shows "Today"/"Heute" for the current day; EN/DE picker instead of text links;
   correct active language; fonts from amadeus-hildesheim.de (Cormorant Garamond, Raleway)
 * Wizard keeps its step and all entered data when the language is switched
 * property id is set when entering with ?outletID=

2026-09-18 == mySeat v0.2163 == amadeushi - http://github.com/amadeushi/myseat

 * Public reservation form (api/reserve.php) redesigned in a dark/gold two-column layout
   with sidebar (back link, weekly opening hours, address, contact)
 * Three-step wizard: date/time/guests, notes, contact details with live summary
 * Guest count updates the time slots via AJAX (api/ajax_timeslots.php) without reload;
   time-slot fragment shared in api/timeslot_fragment.inc.php
 * Time slots as clickable pills in a scrollable grid; the last slot of the day (e.g. 00:00)
   is no longer dropped

2026-09-18 == mySeat v0.2162 == amadeushi - http://github.com/amadeushi/myseat

 * Day-specific opening hours work when only the opening or only the closing time is set
 * Midnight (00:00) can be used as a day-specific closing time
 * Confirmation page after booking no longer stays blank (wrong PHPMailer path in
   local_email_send plugin)
 * "Entry added" message no longer reappears on every outlet page view
 * Datepicker language script no longer returns a 500 (wrong session key)

2026-09-18 == mySeat v0.2161 == amadeushi - http://github.com/amadeushi/myseat

 * PHP 8 compatibility: mysql_* functions provided on top of mysqli
   (web/classes/mysql_compat.php), each() and PHP4 constructors replaced,
   get_magic_quotes_gpc() shim, parse errors fixed, deprecations cleared
 * Strict SQL mode (MySQL 5.7+ / MariaDB 10.2+): missing NOT NULL columns added to the
   default settings insert
 * Login works again (constructor of flexibleAccess in PLC/plc.class.php)
 * Plugin hook system no longer fatals (phphooks.class.php)
 * Further PHP 8 fatals fixed in the public form, reservation detail and the translation files

2012-12-08 == mySeat v0.2160 == Ap.Muthu - http://github.com/apmuthu/myseat

 * Multi Property Enabled - when plc_user role = 1
 * Forum Fixes and features incorporated
 * Code cleanup
 * $settings['mailCharset'] in config.general.php
 * Typos corrected
 * PHP Notices fixed - missing variable checks done
 * TimeZone values updated
 * Asia/Singapore TimeZone incorporated
 * tooltip over day off in online reservation datepicker
 * property_grid zip field display
 * export_page, hooks fixed

2012-08-06 == mySeat v0.2150 == Sebastien Fanals - http://github.com/fanals/myseat

 * DB Table Prefixes enables multiple mySeat installs in one DB
 * mail fixed
 * local_mail plugin - GMail and HotMail SMTP enabled and tested working
 * $settings['emailSMTP'] in config.general.php

2012-01-18 == mySeat v0.2134 == Bernd Orttenburger - http://github.com/myseat/myseat
- Dutch language file improvements
- small bugfixes

There is a database update necessary for 0.195 version and up!


INSTALLATION
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
SEE ONLINE DOCUMENTATION FOR MORE DETAILS: http://www.myseat.us/API 
mySeat is easy to install.
Under most circumstances installing mySeat is a very simple process
and takes less than ten minutes to complete.

Before starting the automatic installer follow these instructions:
Create a database for mySeat on your web server.
Create a MySQL user who has all privileges for accessing and modifying it.
Open file WEBROOT/config/config.general.php in a text editor and fill in your database details.
Browse to your new mySeat site to the directory http://IP.OR.DOMAIN/PATH/install
This will take you to the mySeat automatic installer with small explanations.


UPDATE
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
To update mySeat to a newer version, it is not necessary to do a full install.
Just replace the old files on the web server, except the WEBROOT/config folder.
If there is a need to change or extend the database, it is clearly stated.
To update the database, point your web browser to mySeat update script at
http://IP.OR.DOMAIN/PATH/install/update.php


MULTILINGUAL
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
mySeat is actually translated into 9 languages:
English
German
Spanish
French
Dutch
Swedish
Italian
Chinese
Danish


GNU LICENSE
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
Copyright
mySeat is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or any later version.
mySeat is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with mySeat? 
If not, see <http://www.gnu.org/licenses/>.


mySeat? 
If not, see <http://www.gnu.org/licenses/>.


