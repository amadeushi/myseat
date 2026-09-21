=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-=                                       =-=
=-=           mySeat README               =-=
=-=                                       =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-= Version: 0.2178                        =-=
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

Versions 0.2161 - 0.2178 are maintained in http://github.com/amadeushi/myseat.
No manual database update is needed for any of them (the table plan (v0.2171, v0.2172) creates its own
tp_* tables on first use). Optional new settings for
config/config.general.php (defaults apply when missing):
  $settings['lastBookingMinutes'] = 60;   (v0.2165)  last online booking, minutes before closing
  $settings['brandName'] = 'Amadeus';     (v0.2166)  name shown in the backend header and login

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


