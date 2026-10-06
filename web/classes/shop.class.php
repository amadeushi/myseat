<?php
/*
 * Delivery / pickup shop (orders for the kitchen): menu, delivery zones, opening hours, orders. Public pages live in
 * /order, the staff side is "Bestellungen" (dashboard) and the kitchen monitor in the backend (rights: Reservation-Edit
 * for the orders, Settings-General for the shop settings).
 *  - Tables tp_shop_* (utf8mb4), created on first use like the other newer classes of this app.
 *  - Money is handled in integer cents; the server always recomputes prices from the menu, the browser's cart is
 *    only a list of choices (product, variation, options, quantity).
 *  - Nothing in here throws to a caller that renders a page: problems are returned as messages.
 * Settings (table tp_shop_settings, edited in Einstellungen > Lieferservice): see shop_defaults().
 */
require_once __DIR__.'/feedback.class.php';
require_once __DIR__.'/sms.class.php'; // sms_encrypt / sms_decrypt keep the Mollie key encrypted like the SMS key

// ---- schema
function shop_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	$db = fb_db();
	mysqli_query($db, "SET NAMES utf8mb4"); // names and notes of guests may contain emoji
	$q = function ($sql) use ($db) { mysqli_query($db, $sql); };
	$opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_settings')." (`k` VARCHAR(40) NOT NULL PRIMARY KEY, `v` MEDIUMTEXT NULL, `updated_at` DATETIME NOT NULL) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_categories')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `resmio_id` INT NULL, `name` VARCHAR(120) NOT NULL, `description` VARCHAR(500) NOT NULL DEFAULT '',
		`sort` DOUBLE NOT NULL DEFAULT 0, `active` TINYINT NOT NULL DEFAULT 1, UNIQUE KEY `resmio` (`resmio_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_products')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `resmio_id` INT NULL, `category_id` INT UNSIGNED NOT NULL, `title` VARCHAR(160) NOT NULL,
		`description` VARCHAR(800) NOT NULL DEFAULT '', `image_url` VARCHAR(300) NOT NULL DEFAULT '', `price_cents` INT NOT NULL DEFAULT 0,
		`sort` DOUBLE NOT NULL DEFAULT 0, `active` TINYINT NOT NULL DEFAULT 1, `allergens` VARCHAR(400) NOT NULL DEFAULT '',
		UNIQUE KEY `resmio` (`resmio_id`), KEY `cat` (`category_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_variations')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `resmio_id` INT NULL, `product_id` INT UNSIGNED NOT NULL, `title` VARCHAR(120) NOT NULL,
		`price_cents` INT NOT NULL DEFAULT 0, `multiplier` DECIMAL(5,2) NOT NULL DEFAULT 1.00, `sort` DOUBLE NOT NULL DEFAULT 0,
		UNIQUE KEY `resmio` (`resmio_id`), KEY `prod` (`product_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_modgroups')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `resmio_id` INT NULL, `title` VARCHAR(120) NOT NULL, `min_qty` INT NOT NULL DEFAULT 0,
		`max_qty` INT NOT NULL DEFAULT 1, `sort` DOUBLE NOT NULL DEFAULT 0, UNIQUE KEY `resmio` (`resmio_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_modifiers')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `resmio_id` INT NULL, `product_id` INT UNSIGNED NOT NULL, `group_id` INT UNSIGNED NOT NULL,
		`title` VARCHAR(120) NOT NULL, `price_cents` INT NOT NULL DEFAULT 0, `max_qty` INT NOT NULL DEFAULT 1, `sort` DOUBLE NOT NULL DEFAULT 0,
		UNIQUE KEY `resmio` (`resmio_id`), KEY `prod` (`product_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_zones')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `resmio_id` INT NULL, `name` VARCHAR(80) NOT NULL, `polygon` MEDIUMTEXT NOT NULL,
		`fee_cents` INT NOT NULL DEFAULT 0, `min_order_cents` INT NOT NULL DEFAULT 0, `active` TINYINT NOT NULL DEFAULT 1, UNIQUE KEY `resmio` (`resmio_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_hours')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `kind` VARCHAR(10) NOT NULL, `weekday` TINYINT NOT NULL, `begins` TIME NOT NULL, `ends` TIME NOT NULL,
		KEY `kind` (`kind`, `weekday`)) $opts");
	// exceptions to the weekly opening times: holidays, closed days, other times for a day or a period
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_hours_ex')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `date_from` DATE NOT NULL, `date_to` DATE NOT NULL, `kind` VARCHAR(10) NOT NULL DEFAULT 'all',
		`closed` TINYINT NOT NULL DEFAULT 1, `yearly` TINYINT NOT NULL DEFAULT 0, `label` VARCHAR(80) NOT NULL DEFAULT '', `windows` VARCHAR(300) NOT NULL DEFAULT '',
		KEY `dates` (`date_from`, `date_to`)) $opts");
	// slips for the receipt printer in the kitchen (print agent on the Raspberry Pi picks them up, see shop_print_claim)
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_print_jobs')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `order_id` INT UNSIGNED NOT NULL, `is_full` TINYINT NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL,
		`claimed_at` DATETIME NULL, `printed_at` DATETIME NULL, `tries` TINYINT NOT NULL DEFAULT 0, KEY `todo` (`printed_at`, `created_at`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_geocache')." (
		`h` CHAR(40) NOT NULL PRIMARY KEY, `lat` DOUBLE NULL, `lng` DOUBLE NULL, `postcode` VARCHAR(10) NULL, `road` VARCHAR(160) NULL,
		`created_at` DATETIME NOT NULL) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_orders')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `token` CHAR(32) NOT NULL, `number` VARCHAR(12) NOT NULL, `day_no` INT NOT NULL DEFAULT 0,
		`order_date` DATE NOT NULL, `type` VARCHAR(10) NOT NULL, `status` VARCHAR(12) NOT NULL DEFAULT 'new', `scheduled_at` DATETIME NULL, `eta_at` DATETIME NULL,
		`customer_name` VARCHAR(120) NOT NULL, `phone` VARCHAR(40) NOT NULL, `email` VARCHAR(160) NOT NULL DEFAULT '',
		`street` VARCHAR(160) NOT NULL DEFAULT '', `zip` VARCHAR(10) NOT NULL DEFAULT '', `city` VARCHAR(80) NOT NULL DEFAULT '', `address_note` VARCHAR(200) NOT NULL DEFAULT '',
		`lat` DOUBLE NULL, `lng` DOUBLE NULL, `zone_id` INT UNSIGNED NULL,
		`ip_hash` CHAR(16) NOT NULL DEFAULT '',
		`subtotal_cents` INT NOT NULL DEFAULT 0, `fee_cents` INT NOT NULL DEFAULT 0, `tip_cents` INT NOT NULL DEFAULT 0, `total_cents` INT NOT NULL DEFAULT 0,
		`payment_method` VARCHAR(12) NOT NULL DEFAULT 'cash', `payment_status` VARCHAR(12) NOT NULL DEFAULT 'open', `mollie_id` VARCHAR(40) NULL,
		`note` VARCHAR(500) NOT NULL DEFAULT '', `lang` VARCHAR(2) NOT NULL DEFAULT 'de', `is_test` TINYINT NOT NULL DEFAULT 0,
		`created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, `accepted_at` DATETIME NULL, `ready_at` DATETIME NULL, `done_at` DATETIME NULL,
		UNIQUE KEY `token` (`token`), UNIQUE KEY `number` (`number`), KEY `status` (`status`, `created_at`), KEY `day` (`order_date`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_order_items')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `order_id` INT UNSIGNED NOT NULL, `product_id` INT UNSIGNED NULL, `title` VARCHAR(160) NOT NULL,
		`variation` VARCHAR(120) NOT NULL DEFAULT '', `options` TEXT NULL, `qty` INT NOT NULL DEFAULT 1, `unit_cents` INT NOT NULL DEFAULT 0, `line_cents` INT NOT NULL DEFAULT 0,
		`note` VARCHAR(200) NOT NULL DEFAULT '', KEY `ord` (`order_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_order_log')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `order_id` INT UNSIGNED NOT NULL, `event` VARCHAR(40) NOT NULL, `detail` VARCHAR(200) NOT NULL DEFAULT '',
		`at` DATETIME NOT NULL, KEY `ord` (`order_id`)) $opts");
	// reusable option groups ("Zubehörgruppen"): a group has options and is assigned to any number of dishes
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_group_items')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `group_id` INT UNSIGNED NOT NULL, `title` VARCHAR(120) NOT NULL, `price_cents` INT NOT NULL DEFAULT 0,
		`max_qty` INT NOT NULL DEFAULT 1, `sort` DOUBLE NOT NULL DEFAULT 0, KEY `grp` (`group_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_product_groups')." (
		`product_id` INT UNSIGNED NOT NULL, `group_id` INT UNSIGNED NOT NULL, `sort` DOUBLE NOT NULL DEFAULT 0, PRIMARY KEY (`product_id`, `group_id`), KEY `grp` (`group_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_coupons')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `code` VARCHAR(40) NOT NULL, `note` VARCHAR(160) NOT NULL DEFAULT '', `kind` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
		`value` INT NOT NULL DEFAULT 0, `max_discount_cents` INT NOT NULL DEFAULT 0, `min_order_cents` INT NOT NULL DEFAULT 0, `applies` ENUM('all','delivery','pickup') NOT NULL DEFAULT 'all',
		`valid_from` DATETIME NULL, `valid_until` DATETIME NULL, `max_uses` INT NOT NULL DEFAULT 0, `per_guest` TINYINT NOT NULL DEFAULT 0, `used` INT NOT NULL DEFAULT 0,
		`active` TINYINT NOT NULL DEFAULT 1, `created_at` DATETIME NOT NULL, UNIQUE KEY `code` (`code`)) $opts");
	// how often the till asked Google for street suggestions (and how many were picked), per day: the figure for the price list of Google (see shop_places_usage())
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_places_use')." (`day` DATE NOT NULL PRIMARY KEY, `suggest` INT UNSIGNED NOT NULL DEFAULT 0, `pick` INT UNSIGNED NOT NULL DEFAULT 0) $opts");
	// the order receipts of Uber Eats as the images the tablet prints (the Pi in the kitchen catches them, see tools/kitchen-pi/uber-eats-bridge): the image itself, a
	// hash of it (the same receipt printed twice is stored once), and room for what is read from it later (status, data, order_id)
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_uber_slips')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `received` DATETIME NOT NULL, `sha1` CHAR(40) NOT NULL, `w` SMALLINT UNSIGNED NOT NULL, `h` SMALLINT UNSIGNED NOT NULL,
		`png` MEDIUMBLOB NOT NULL, `status` VARCHAR(12) NOT NULL DEFAULT 'new', `data` MEDIUMTEXT NULL, `order_id` INT UNSIGNED NULL, UNIQUE KEY `sha1` (`sha1`), KEY `received` (`received`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_coupon_uses')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `coupon_id` INT UNSIGNED NOT NULL, `order_id` INT UNSIGNED NOT NULL, `guest_key` CHAR(40) NOT NULL DEFAULT '', `guest_key2` CHAR(40) NOT NULL DEFAULT '',
		`discount_cents` INT NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL, KEY `coupon` (`coupon_id`), KEY `ord` (`order_id`)) $opts");
	$icol = fb_rows("SHOW INDEX FROM ".fb_t('tp_shop_order_items')." WHERE Key_name = 'prod'");
	if (!$icol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_order_items')." ADD INDEX `prod` (`product_id`)"); }
	$ccol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'coupon_code'");
	if (!$ccol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `coupon_code` VARCHAR(40) NOT NULL DEFAULT '', ADD `discount_cents` INT NOT NULL DEFAULT 0"); }
	$dcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'driver_at'");
	if (!$dcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `driver_lat` DOUBLE NULL, ADD `driver_lng` DOUBLE NULL, ADD `driver_at` DATETIME NULL"); }
	// driver page: district (raw name from OpenStreetMap), driving distance/time from the restaurant, when it was looked up (see shop_order_geo_fill)
	$gcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'geo_at'");
	if (!$gcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `suburb` VARCHAR(80) NULL, ADD `route_m` INT NULL, ADD `route_s` INT NULL, ADD `geo_at` DATETIME NULL"); }
	// own corrections for the district shown to drivers: kind street (exact street name), zip (PLZ) or name (what OpenStreetMap calls it -> what we show)
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_suburbs')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `kind` VARCHAR(8) NOT NULL, `pattern` VARCHAR(120) NOT NULL, `suburb` VARCHAR(80) NOT NULL,
		KEY `kind` (`kind`)) $opts");
	// the way of the drivers (a point every minute or 25 m), for the dispatch map; kept track_days days
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_driver_track')." (
		`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `driver_id` INT UNSIGNED NOT NULL, `lat` DOUBLE NOT NULL, `lng` DOUBLE NOT NULL, `at` DATETIME NOT NULL,
		KEY `drv` (`driver_id`, `at`)) $opts");
	$wcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'pay_with_cents'"); // cash: "the guest pays with 50 euro" (till), for the change
	if (!$wcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `pay_with_cents` INT NULL"); }
	$scol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'surcharge_cents'"); // till: a surcharge in euro and the note of discount / surcharge with its reason
	if (!$scol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `surcharge_cents` INT NOT NULL DEFAULT 0, ADD `adjust_note` VARCHAR(160) NOT NULL DEFAULT ''"); }
	$mcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'pay_detail'"); // how the guest paid online at Mollie (creditcard, paypal ...), for the daily report
	if (!$mcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `pay_detail` VARCHAR(30) NOT NULL DEFAULT ''"); }
	$kcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_print_jobs')." LIKE 'kind'"); // print jobs that are no order slip: the daily reports
	if (!$kcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_print_jobs')." ADD `kind` VARCHAR(16) NOT NULL DEFAULT 'order', ADD `report_date` DATE NULL"); }
	// customer backend (web/content/customers.page.php): notes and marks per customer, links of two customers that are one person, the log of manual actions, a blocked account,
	// and stamps given by hand (they belong to no order: the order_id is a negative number, so the unique key of the stamps still holds)
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_customer_notes')." (
		`ckey` CHAR(40) NOT NULL PRIMARY KEY, `note` TEXT NULL, `flags` VARCHAR(80) NOT NULL DEFAULT '', `updated_at` DATETIME NOT NULL, `updated_by` VARCHAR(60) NOT NULL DEFAULT '') $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_customer_links')." (
		`a` CHAR(40) NOT NULL, `b` CHAR(40) NOT NULL, `created_at` DATETIME NOT NULL, `created_by` VARCHAR(60) NOT NULL DEFAULT '', PRIMARY KEY (`a`, `b`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_customer_log')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `ckey` CHAR(40) NOT NULL DEFAULT '', `event` VARCHAR(20) NOT NULL, `detail` VARCHAR(255) NOT NULL DEFAULT '', `by_user` VARCHAR(60) NOT NULL DEFAULT '',
		`at` DATETIME NOT NULL, KEY `ck` (`ckey`, `at`)) $opts");
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_accounts')." LIKE 'blocked'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_accounts')." ADD `blocked` TINYINT NOT NULL DEFAULT 0"); }
	$oc = fb_row("SHOW COLUMNS FROM ".fb_t('tp_shop_stamps')." LIKE 'order_id'");
	if ($oc && stripos((string)$oc['Type'], 'unsigned') !== false) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_stamps')." MODIFY `order_id` INT NOT NULL"); }
	$pcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_drivers')." LIKE 'phone'");
	if (!$pcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_drivers')." ADD `phone` VARCHAR(40) NOT NULL DEFAULT ''"); }
	$col = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'ip_hash'");
	if (!$col) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `ip_hash` CHAR(16) NOT NULL DEFAULT ''"); }
	// pizza configurator: a dish can offer the guest "build it yourself"; an option can carry the symbol it shows on the pizza
	$cfcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_products')." LIKE 'configurator'");
	if (!$cfcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_products')." ADD `configurator` TINYINT NOT NULL DEFAULT 0"); }
	$iccol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_group_items')." LIKE 'icon'");
	if (!$iccol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_group_items')." ADD `icon` VARCHAR(20) NOT NULL DEFAULT ''"); }
	$frcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'fail_reason'");
	if (!$frcol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `fail_reason` VARCHAR(255) NOT NULL DEFAULT ''"); }
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_calls')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `phone` VARCHAR(40) NOT NULL, `received_at` DATETIME NOT NULL, KEY `at` (`received_at`)) $opts");
	// orders imported from a Lieferando receipt PDF (see shop_lieferando.class.php): source tells the kitchen monitor
	// apart from own-shop orders, external_ref is the Lieferando order code and keeps a re-import from creating it twice
	$scol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'source'");
	if (!$scol) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `source` VARCHAR(12) NOT NULL DEFAULT 'shop', ADD `external_ref` VARCHAR(20) NULL, ADD UNIQUE KEY `ext_ref` (`external_ref`)");
	}
	// the postcode/road Nominatim or Google matched, so a confirmed address can hand its normalized
	// form back to the guest (auto-fill the PLZ, correct the street spelling) - see shop_geocode()
	$gcol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_geocache')." LIKE 'postcode'");
	if (!$gcol) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_geocache')." ADD `postcode` VARCHAR(10) NULL, ADD `road` VARCHAR(160) NULL");
		// every row cached before these columns existed has postcode/road left NULL forever - a cache hit
		// never re-asks the provider, so that address would silently never auto-fill. Clearing the cache
		// once here is cheap and self-healing: the next lookup for it just asks Nominatim/Google again,
		// this time keeping the postcode and road too
		mysqli_query($db, "TRUNCATE TABLE ".fb_t('tp_shop_geocache'));
	}
	// every distinct street/PLZ Nominatim offered for a query (JSON), so shop_find_zone() can tell two
	// real, differently-named streets apart instead of silently guessing between them - see shop_geocode()
	$ccol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_geocache')." LIKE 'candidates'");
	if (!$ccol) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_geocache')." ADD `candidates` TEXT NULL");
		mysqli_query($db, "TRUNCATE TABLE ".fb_t('tp_shop_geocache'));
	}
	// drivers (order/driver.php, the self-service delivery queue): identified by their phone's Traccar
	// device id, no login - the operator maps device id to a name once (Einstellungen > Lieferservice).
	// tp_shop_driver_positions holds one row per driver, the latest ping from order/driver_gps.php
	// (OsmAnd protocol), independent of whether that driver currently has an order - a driver's phone
	// keeps reporting in the background even before accepting a delivery
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_drivers')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `device_id` VARCHAR(64) NOT NULL, `name` VARCHAR(80) NOT NULL,
		`active` TINYINT NOT NULL DEFAULT 1, `created_at` DATETIME NOT NULL, UNIQUE KEY `device` (`device_id`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_driver_positions')." (
		`driver_id` INT UNSIGNED NOT NULL PRIMARY KEY, `lat` DOUBLE NOT NULL, `lng` DOUBLE NOT NULL, `updated_at` DATETIME NOT NULL) $opts");
	// shared basket ("Gemeinsam bestellen"): see shop_basket_create()
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_baskets')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `token` CHAR(24) NOT NULL, `owner` CHAR(32) NOT NULL, `status` VARCHAR(10) NOT NULL DEFAULT 'open',
		`order_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL, `expires_at` DATETIME NOT NULL, UNIQUE KEY `tok` (`token`), KEY `exp` (`expires_at`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_basket_members')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `basket_id` INT UNSIGNED NOT NULL, `member` CHAR(32) NOT NULL, `name` VARCHAR(24) NOT NULL,
		`created_at` DATETIME NOT NULL, UNIQUE KEY `mem` (`basket_id`, `member`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_basket_lines')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `basket_id` INT UNSIGNED NOT NULL, `member` CHAR(32) NOT NULL, `pid` INT UNSIGNED NOT NULL,
		`vid` INT UNSIGNED NOT NULL DEFAULT 0, `opts` TEXT NULL, `qty` INT NOT NULL DEFAULT 1, `note` VARCHAR(200) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL,
		KEY `bsk` (`basket_id`)) $opts");
	// stamp card: one row per finished order of a guest (the guest is told apart by the same keys the coupons use: phone, e-mail);
	// a full card is turned into a personal coupon (tp_shop_coupons.source = 'stamp', bound to the guest keys)
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_stamps')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `order_id` INT UNSIGNED NOT NULL, `guest_key` CHAR(40) NOT NULL DEFAULT '', `guest_key2` CHAR(40) NOT NULL DEFAULT '',
		`base_cents` INT NOT NULL DEFAULT 0, `earned_at` DATETIME NOT NULL, `expires_at` DATETIME NOT NULL, `coupon_id` INT UNSIGNED NULL,
		UNIQUE KEY `ord` (`order_id`), KEY `k1` (`guest_key`), KEY `k2` (`guest_key2`)) $opts");
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_coupons')." LIKE 'source'")) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_coupons')." ADD `source` VARCHAR(10) NOT NULL DEFAULT '', ADD `guest_key` CHAR(40) NOT NULL DEFAULT '', ADD `guest_key2` CHAR(40) NOT NULL DEFAULT '', ADD `parent_id` INT UNSIGNED NULL");
	}
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_baskets')." LIKE 'short_url'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_baskets')." ADD `short_url` VARCHAR(120) NULL"); }
	$ocol = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'driver_id'");
	if (!$ocol) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `driver_id` INT UNSIGNED NULL, ADD KEY `driver` (`driver_id`)"); }
	// guest accounts (order/konto): an account is a confirmed phone number and/or e-mail address, kept only as the same
	// hashes the stamp card and the coupons use (shop_coupon_guest_keys), so orders and stamps of that guest are found without a migration.
	// A sign-in code and a sign-in link belong to one row: whichever is used first uses up the other (see shop_account.class.php)
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_accounts')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `key_phone` CHAR(40) NULL, `key_mail` CHAR(40) NULL, `mask_phone` VARCHAR(40) NOT NULL DEFAULT '', `mask_mail` VARCHAR(80) NOT NULL DEFAULT '',
		`name` VARCHAR(80) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, `last_login_at` DATETIME NULL, UNIQUE KEY `kp` (`key_phone`), UNIQUE KEY `km` (`key_mail`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_login_codes')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `kind` VARCHAR(5) NOT NULL, `target_key` CHAR(40) NOT NULL, `purpose` VARCHAR(5) NOT NULL DEFAULT 'login', `account_id` INT UNSIGNED NULL,
		`code_hash` CHAR(64) NOT NULL, `link_hash` CHAR(64) NOT NULL, `attempts` TINYINT NOT NULL DEFAULT 0, `used_at` DATETIME NULL, `expires_at` DATETIME NOT NULL,
		`ip_hash` CHAR(16) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, UNIQUE KEY `lnk` (`link_hash`), KEY `tgt` (`target_key`, `created_at`), KEY `ip` (`ip_hash`, `created_at`), KEY `day` (`kind`, `created_at`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_sessions')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `token_hash` CHAR(64) NOT NULL, `account_id` INT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL, `last_seen` DATETIME NOT NULL,
		`expires_at` DATETIME NOT NULL, `ua` VARCHAR(80) NOT NULL DEFAULT '', UNIQUE KEY `tok` (`token_hash`), KEY `acc` (`account_id`), KEY `exp` (`expires_at`)) $opts");
	$q("CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_favorites')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `account_id` INT UNSIGNED NOT NULL, `product_id` INT UNSIGNED NOT NULL, `variation_id` INT UNSIGNED NOT NULL DEFAULT 0, `opts` TEXT NULL,
		`note` VARCHAR(200) NOT NULL DEFAULT '', `sig` CHAR(40) NOT NULL, `created_at` DATETIME NOT NULL, UNIQUE KEY `fav` (`account_id`, `sig`)) $opts");
	// reordering needs ids: the variation of an order line (the option ids sit in the options JSON since v6.9)
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_order_items')." LIKE 'variation_id'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_order_items')." ADD `variation_id` INT UNSIGNED NULL"); }
	// what the guest typed to sign in, so the order form can be filled with it (the account itself only holds hashes)
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_login_codes')." LIKE 'target_plain'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_login_codes')." ADD `target_plain` VARCHAR(160) NOT NULL DEFAULT ''"); }
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_accounts')." LIKE 'contact_phone'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_accounts')." ADD `contact_phone` VARCHAR(30) NOT NULL DEFAULT '', ADD `contact_mail` VARCHAR(160) NOT NULL DEFAULT ''"); }
	// delivery address of the account (filled in the shop and the checkout), and the account an order was placed under (only those collect stamps)
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_accounts')." LIKE 'addr_street'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_accounts')." ADD `addr_street` VARCHAR(120) NOT NULL DEFAULT '', ADD `addr_zip` VARCHAR(10) NOT NULL DEFAULT '', ADD `addr_city` VARCHAR(80) NOT NULL DEFAULT '', ADD `addr_note` VARCHAR(200) NOT NULL DEFAULT ''"); }
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'account_id'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `account_id` INT UNSIGNED NULL"); }
	// the guest keys of an order (phone, e-mail), filled when the account looks at its history (shop_account_backfill_keys)
	// (columns and keys in separate statements: one combined ALTER failed on a MariaDB, and without the columns nothing below works)
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'guest_key'")) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `guest_key` CHAR(40) NOT NULL DEFAULT '', ADD `guest_key2` CHAR(40) NOT NULL DEFAULT ''");
	}
	foreach (array('gk1' => 'guest_key', 'gk2' => 'guest_key2') as $kn => $kc) {
		if (!fb_rows("SHOW INDEX FROM ".fb_t('tp_shop_orders')." WHERE Key_name = '$kn'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD KEY `$kn` (`$kc`)"); }
	}
	$done = true;
	shop_migrate_groups();
}

// The menu came from an import in which every dish carried its own options. Once: turn that into reusable groups. Groups with
// the same title and identical options become one group shared by the dishes; a different set under the same title gets its
// own group ("Soßen zum Dippen (2)"). The old table tp_shop_modifiers stays untouched.
function shop_migrate_groups() {
	$db = fb_db();
	$flag = function () { return fb_row("SELECT v FROM ".fb_t('tp_shop_settings')." WHERE k = 'menu_v2'"); };
	if ($flag()) { return; }
	$lock = mysqli_query($db, "SELECT GET_LOCK('".fb_t('tp_shop_menu_v2')."', 20)");
	if ($flag()) { return; }
	$clean = function ($t) { return trim(preg_replace('/\s*\((?:eine |1 )?Auswahlm(?:ö|oe)glichkeit(?:en)?\)\s*$/iu', '', (string)$t)); };
	$rows = fb_rows("SELECT m.product_id, m.group_id, m.title, m.price_cents, m.max_qty, g.title AS gtitle, g.min_qty, g.max_qty AS gmax, g.sort AS gsort
		FROM ".fb_t('tp_shop_modifiers')." m JOIN ".fb_t('tp_shop_modgroups')." g ON g.id = m.group_id ORDER BY m.product_id, g.sort, g.id, m.sort, m.id");
	$per = array();
	foreach ($rows as $r) { $per[(int)$r['product_id']][(int)$r['group_id']][] = $r; }
	$sigs = array(); $reused = array(); $titleCount = array();
	foreach ($per as $pid => $groups) {
		$order = 0;
		foreach ($groups as $gid => $items) {
			$sig = $gid.'|'.md5(json_encode(array_map(function ($i) { return array($i['title'], (int)$i['price_cents'], (int)$i['max_qty']); }, $items)));
			if (!isset($sigs[$sig])) {
				$t = $clean($items[0]['gtitle']);
				if (!isset($reused[$gid])) {
					$reused[$gid] = true; $new = (int)$gid;
					fb_exec("UPDATE ".fb_t('tp_shop_modgroups')." SET title = ? WHERE id = ?", 'si', array($t, $new));
				} else {
					$titleCount[$t] = isset($titleCount[$t]) ? $titleCount[$t] + 1 : 2;
					fb_exec("INSERT INTO ".fb_t('tp_shop_modgroups')." (resmio_id, title, min_qty, max_qty, sort) VALUES (NULL, ?, ?, ?, ?)", 'siid', array($t.' ('.$titleCount[$t].')', (int)$items[0]['min_qty'], (int)$items[0]['gmax'], (float)$items[0]['gsort']));
					$new = (int)mysqli_insert_id($db);
				}
				$n = 0;
				foreach ($items as $it) {
					fb_exec("INSERT INTO ".fb_t('tp_shop_group_items')." (group_id, title, price_cents, max_qty, sort) VALUES (?, ?, ?, ?, ?)", 'isiid', array($new, $it['title'], (int)$it['price_cents'], max(1, (int)$it['max_qty']), ++$n));
				}
				$sigs[$sig] = $new;
			}
			fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_product_groups')." (product_id, group_id, sort) VALUES (?, ?, ?)", 'iid', array((int)$pid, $sigs[$sig], ++$order));
		}
	}
	fb_exec("REPLACE INTO ".fb_t('tp_shop_settings')." (k, v, updated_at) VALUES ('menu_v2', '1', NOW())");
	if ($lock) { mysqli_query($db, "SELECT RELEASE_LOCK('".fb_t('tp_shop_menu_v2')."')"); }
}

// ---- settings
function shop_defaults() {
	return array(
		'public' => '0',            // the shop pages are visible to guests
		'accepting' => '0',         // guests may place orders (otherwise browse only)
		'test_mode' => '0',         // orders are marked as tests: no mails, deletable in the backend
		'eta_delivery_min' => '45', // shown to the guest for "as soon as possible"
		'lead_pickup_min' => '30',
		'slot_min' => '15',         // step of the time choice
		'days_ahead' => '0',        // 0 = today only
		'min_order_delivery' => '20.00', 'min_order_pickup' => '0.00',
		'allow_cash' => '1', 'allow_card_door' => '1', 'allow_online' => '1',
		'tip_enabled' => '1',
		'notice' => '',             // one line of text on top of the shop (e.g. "Heute später")
		'pause_delivery' => '0', 'pause_delivery_until' => '0', // pause of the orders: switched in the dashboard Bestellungen, until = end (Unix time), 0 = until it is switched off
		'pause_pickup' => '0', 'pause_pickup_until' => '0',
		'cust_regular_n' => '3', 'cust_regular_days' => '90', // customer backend: "Stammgast" = this many orders within this many days
		'cust_sleep_days' => '60', 'cust_new_days' => '30',   // "Schlafend" = no order for this many days (with at least 2 orders), "Neu" = first order not older than this
		'feedback_on' => '1', 'feedback_since' => '', 'feedback_last_run' => '0', 'feedback_outlet_id' => '0', // feedback mail after an order (web/classes/shop_feedback.class.php); feedback_since = switch-on time, only later orders count
		'places_suggest' => '1',    // street suggestions in the till while the staff types (Google Places, through the server); see shop_places_suggest()
		'last_order_min' => '0',    // the shop takes orders until the end of the order time minus this many minutes (0 = until closing; the food may leave after closing)
		'pos_discount_pct' => '10', // the "-10 %" key of the till (Erfassung): percent of the goods and the delivery fee
		'kitchen_drive_min' => '15', // minutes a delivery needs to the guest: the kitchen monitor shows when the food has to leave
		'notify_email' => '',       // sender of the order mails and address that gets a mail for every new order
		'resmio_slug' => 'amadeus-cafe-restaurant-bar',
		'sms_orders' => '1',        // SMS to the guest when the delivery is on its way / the pickup is ready (only with SMS sending set up)
		'stamp_on' => '1',          // stamp card: every finished order is a stamp, a full card becomes a voucher (see shop_stamp_*)
		'stamp_percent' => '10', 'stamp_goal' => '5', 'stamp_months' => '12', 'voucher_days' => '90',
		'account_on' => '1',        // guest accounts: sign-in by code or link, order history, favorites (see shop_account.class.php)
		'account_sms' => '1',       // sign-in codes by SMS (otherwise only by e-mail); needs SMS sending set up
		'account_sms_daily' => '100', // at most this many sign-in SMS a day (every one costs money)
		'origin_street' => '', 'origin_zip' => '', 'origin_city' => '', // where the restaurant is (map of the order status)
		'quote_free_orders' => '4', 'quote_per_order_min' => '2', // the till's time to tell a caller: this many orders in the kitchen do not delay, every further one adds minutes
		'track_days' => '7',        // how many days the way of the drivers is kept (dispatch map), 0 = it is not stored
		'route_url' => 'https://router.project-osrm.org', // routing service for the driving way of a delivery (OSRM API), only https
	);
}
function shop_setting($k) {
	shop_ensure_schema();
	$r = fb_row("SELECT v FROM ".fb_t('tp_shop_settings')." WHERE k = ? LIMIT 1", 's', array($k));
	if ($r !== null) { return $r['v']; }
	$d = shop_defaults();
	return isset($d[$k]) ? $d[$k] : null;
}
function shop_setting_set($k, $v) {
	shop_ensure_schema();
	if ($v === null) { fb_exec("DELETE FROM ".fb_t('tp_shop_settings')." WHERE k = ?", 's', array($k)); return; }
	fb_exec("REPLACE INTO ".fb_t('tp_shop_settings')." (k, v, updated_at) VALUES (?, ?, NOW())", 'ss', array($k, (string)$v));
}
function shop_flag($k) { return shop_setting($k) === '1'; }
// ---- print agent: a Raspberry Pi in the kitchen with the receipt printer asks for slips and prints them as ESC/POS (web/ajax/print_agent.php).
// The kitchen monitor queues a slip here when the agent was seen in the last 20 seconds; otherwise it prints through the browser as before.
function shop_print_agent_key() {
	$k = (string)shop_setting('print_agent_key');
	if (strlen($k) < 32) { $k = bin2hex(random_bytes(24)); shop_setting_set('print_agent_key', $k); }
	return $k;
}
// Uber Eats receipts: the key the Pi in the kitchen sends with every image (header X-Api-Key of order/uber_import.php). Made on first use, shown in the backend
// (Einstellungen > Lieferservice) so it can be entered on the Pi; it is not in config.general.php.
function shop_uber_key() {
	$k = (string)shop_setting('uber_import_key');
	if (strlen($k) < 32) { $k = bin2hex(random_bytes(24)); shop_setting_set('uber_import_key', $k); }
	return $k;
}
define('SHOP_UBER_KEEP_DAYS', 14);
// stores one receipt image (PNG, as the Pi builds it from the print job); the same image twice is not stored again. Receipts older than SHOP_UBER_KEEP_DAYS are deleted:
// they carry the name, address and phone number of guests.
function shop_uber_store($png) {
	$info = @getimagesizefromstring($png);
	if (!$info || $info[2] !== IMAGETYPE_PNG) { return array('ok' => false, 'error' => 'Kein PNG-Bild.'); }
	if ($info[0] < 200 || $info[0] > 1200 || $info[1] < 20 || $info[1] > 8000) { return array('ok' => false, 'error' => 'Unerwartete Bildgröße ('.$info[0].' x '.$info[1].').'); }
	$sha = sha1($png);
	$old = fb_row("SELECT id FROM ".fb_t('tp_shop_uber_slips')." WHERE sha1 = ?", 's', array($sha));
	if ($old) { return array('ok' => true, 'id' => (int)$old['id'], 'duplicate' => true); }
	$st = fb_exec("INSERT INTO ".fb_t('tp_shop_uber_slips')." (received, sha1, w, h, png) VALUES (?, ?, ?, ?, ?)", 'ssiis', array(date('Y-m-d H:i:s'), $sha, (int)$info[0], (int)$info[1], $png));
	if (!$st) { return array('ok' => false, 'error' => 'Speichern fehlgeschlagen.'); }
	$id = (int)mysqli_insert_id(fb_db());
	fb_exec("DELETE FROM ".fb_t('tp_shop_uber_slips')." WHERE received < ?", 's', array(date('Y-m-d H:i:s', time() - SHOP_UBER_KEEP_DAYS * 86400)));
	return array('ok' => true, 'id' => $id, 'duplicate' => false);
}
// count and time of the last received receipt and how many are in which state (new, reading, read, check, error), for the backend page
function shop_uber_stats() {
	$r = fb_row("SELECT COUNT(*) AS n, MAX(received) AS last FROM ".fb_t('tp_shop_uber_slips'));
	$by = array('new' => 0, 'reading' => 0, 'read' => 0, 'check' => 0, 'error' => 0, 'imported' => 0);
	foreach (fb_rows("SELECT status, COUNT(*) AS n FROM ".fb_t('tp_shop_uber_slips')." GROUP BY status") as $b) { $by[$b['status']] = (int)$b['n']; }
	return array('count' => $r ? (int)$r['n'] : 0, 'last' => $r && $r['last'] ? $r['last'] : '', 'by' => $by);
}
function shop_print_agent_alive() { return time() - (int)shop_setting('print_agent_seen') <= 20; }
// The control figures every slip prints: positions (the lines of the order) and pieces (the sum of their quantities; options belong to their position and do not
// count). The slips number their positions "Pos n von N" and print these figures from the very list they print, so a slip can be held against the delivery slip.
function shop_slip_counts($items) {
	$pos = 0; $pcs = 0;
	foreach ($items as $it) { $pos++; $pcs += max(0, (int)$it['qty']); }
	return array('pos' => $pos, 'pcs' => $pcs);
}
function shop_slip_control_text($cnt) { return 'Kontrolle: '.$cnt['pos'].($cnt['pos'] === 1 ? ' Position' : ' Positionen').' / '.$cnt['pcs'].' Stück'; }
function shop_print_enqueue_report($kind, $date) {
	fb_exec("INSERT INTO ".fb_t('tp_shop_print_jobs')." (order_id, is_full, created_at, kind, report_date) VALUES (0, 0, ?, ?, ?)", 'sss', array(date('Y-m-d H:i:s'), $kind === 'online' ? 'report_online' : 'report_cash', $date));
	return (int)mysqli_insert_id(fb_db());
}
function shop_print_enqueue($orderId, $full) {
	fb_exec("INSERT INTO ".fb_t('tp_shop_print_jobs')." (order_id, is_full, created_at) VALUES (?, ?, ?)", 'iis', array((int)$orderId, $full ? 1 : 0, date('Y-m-d H:i:s')));
	return (int)mysqli_insert_id(fb_db());
}
// the oldest slip nobody has printed (not older than 15 minutes, a claim is given up after 30 seconds, at most 5 tries); array(id, order_id, data (base64 ESC/POS)) or null
function shop_print_claim() {
	require_once __DIR__.'/escpos.class.php';
	$stale = date('Y-m-d H:i:s', time() - 30);
	$j = fb_row("SELECT id, order_id, is_full, kind, report_date FROM ".fb_t('tp_shop_print_jobs')." WHERE printed_at IS NULL AND tries < 5 AND created_at > ? AND (claimed_at IS NULL OR claimed_at < ?) ORDER BY id LIMIT 1", 'ss', array(date('Y-m-d H:i:s', time() - 900), $stale));
	if (!$j) { return null; }
	$st = fb_exec("UPDATE ".fb_t('tp_shop_print_jobs')." SET claimed_at = ?, tries = tries + 1 WHERE id = ? AND printed_at IS NULL AND (claimed_at IS NULL OR claimed_at < ?)", 'sis', array(date('Y-m-d H:i:s'), (int)$j['id'], $stale));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return null; }
	if ($j['kind'] !== 'order') { // a daily report
		require_once __DIR__.'/shop_report.class.php';
		return array('id' => (int)$j['id'], 'order_id' => 0, 'data' => base64_encode(shop_report_escpos($j['kind'] === 'report_online' ? 'online' : 'cash', (string)$j['report_date'])));
	}
	$o = shop_order((int)$j['order_id']);
	if (!$o) { shop_print_done((int)$j['id']); return null; }
	return array('id' => (int)$j['id'], 'order_id' => (int)$j['order_id'], 'data' => base64_encode(shop_slip_escpos($o, shop_order_items((int)$o['id']), (bool)$j['is_full'])));
}
function shop_print_done($id) { fb_exec("UPDATE ".fb_t('tp_shop_print_jobs')." SET printed_at = ? WHERE id = ?", 'si', array(date('Y-m-d H:i:s'), (int)$id)); }

// the page token of the logged-in user: the same in every session of that user (secret: shop_preview_key())
function myseat_admin_token() {
	$who = isset($_SESSION['u_id']) ? (string)$_SESSION['u_id'] : (isset($_SESSION['u_name']) ? (string)$_SESSION['u_name'] : 'staff');
	if (!function_exists('shop_preview_key')) { return bin2hex(random_bytes(16)); }
	return substr(hash_hmac('sha256', 'shop_admin_token|'.$who, shop_preview_key()), 0, 32);
}

// ---- staff preview of the order page on the guest domain: the backend login only exists on the main domain, so a logged-in member of staff
// gets a link with a short-lived signed token (web/preview_link.php); order/preview.php checks it and opens the preview for that browser.
function shop_preview_key() {
	$k = (string)shop_setting('preview_key');
	if (strlen($k) < 32) { $k = bin2hex(random_bytes(32)); shop_setting_set('preview_key', $k); }
	return $k;
}
function shop_preview_token() {
	$p = (time() + 120).'.'.bin2hex(random_bytes(6));
	return $p.'.'.hash_hmac('sha256', $p, shop_preview_key());
}
function shop_preview_check($t) {
	$a = explode('.', (string)$t);
	if (count($a) !== 3 || !ctype_digit($a[0]) || (int)$a[0] < time() || (int)$a[0] > time() + 300) { return false; }
	return hash_equals(hash_hmac('sha256', $a[0].'.'.$a[1], shop_preview_key()), $a[2]);
}
// Sender of the mails to guests (sign-in code, stamp card): the shop's notification address, else the address of the property.
function shop_mail_from() {
	$from = trim((string)shop_setting('notify_email'));
	if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) { return $from; }
	$r = fb_row("SELECT email FROM ".fb_t('properties')." ORDER BY id LIMIT 1");
	$p = $r ? trim((string)$r['email']) : '';
	return filter_var($p, FILTER_VALIDATE_EMAIL) ? $p : '';
}

function shop_cents($eur) { return (int)round(((float)str_replace(',', '.', (string)$eur)) * 100); }
function shop_money($cents) { return number_format($cents / 100, 2, ',', '.').' €'; }

// allergen and additive codes of Resmio as the guest reads them
function shop_allergen_label($code) {
	static $map = array('CELERY' => 'Sellerie', 'CEREALS_CONTAINING_GLUTEN' => 'glutenhaltiges Getreide', 'CONTAINS_CAFFEINE' => 'koffeinhaltig', 'CRUSTACEANS' => 'Krebstiere',
		'EGGS' => 'Eier', 'FISH' => 'Fisch', 'MILK' => 'Milch', 'RYE' => 'Roggen', 'SPELT' => 'Dinkel', 'SULPHUR_DIOXIDE' => 'Schwefeldioxid und Sulfite', 'TREE_NUTS' => 'Schalenfrüchte',
		'WHEAT' => 'Weizen', 'WITH_ANTI-OXIDANT' => 'mit Antioxidationsmittel', 'WITH_FOOD_COLORING' => 'mit Farbstoff', 'WITH_PHOSPHATE' => 'mit Phosphat', 'WITH_PRESERVATIVE' => 'mit Konservierungsstoff',
		'WITH_SWEETENER' => 'mit Süßungsmittel', 'PEANUTS' => 'Erdnüsse', 'SOY' => 'Soja', 'SOYBEANS' => 'Soja', 'MUSTARD' => 'Senf', 'SESAME' => 'Sesam', 'LUPIN' => 'Lupinen', 'MOLLUSCS' => 'Weichtiere',
		'BARLEY' => 'Gerste', 'OATS' => 'Hafer', 'KAMUT' => 'Kamut', 'ALMONDS' => 'Mandeln', 'HAZELNUTS' => 'Haselnüsse', 'WALNUTS' => 'Walnüsse');
	$c = strtoupper(trim((string)$code));
	return isset($map[$c]) ? $map[$c] : ucfirst(strtolower(str_replace('_', ' ', $c)));
}

// ---- catalog for the pages
function shop_catalog() {
	shop_ensure_schema();
	$cats = fb_rows("SELECT id, name, description FROM ".fb_t('tp_shop_categories')." WHERE active = 1 ORDER BY sort, id");
	$prods = fb_rows("SELECT id, category_id, title, description, image_url, price_cents, allergens FROM ".fb_t('tp_shop_products')." WHERE active = 1 ORDER BY sort, id");
	$vars = fb_rows("SELECT id, product_id, title, price_cents, multiplier FROM ".fb_t('tp_shop_variations')." ORDER BY sort, id");
	$mods = fb_rows("SELECT gi.id, pg.product_id, g.id AS group_id, gi.title, gi.price_cents, gi.max_qty, g.title AS group_title, g.min_qty, g.max_qty AS group_max
		FROM ".fb_t('tp_shop_product_groups')." pg JOIN ".fb_t('tp_shop_modgroups')." g ON g.id = pg.group_id JOIN ".fb_t('tp_shop_group_items')." gi ON gi.group_id = g.id
		ORDER BY pg.sort, g.id, gi.sort, gi.id");
	$byProduct = array();
	foreach ($prods as $p) { $p['variations'] = array(); $p['groups'] = array(); $byProduct[(int)$p['id']] = $p; }
	foreach ($vars as $v) { if (isset($byProduct[(int)$v['product_id']])) { $byProduct[(int)$v['product_id']]['variations'][] = array('id' => (int)$v['id'], 'title' => $v['title'], 'price' => (int)$v['price_cents'], 'mult' => (float)$v['multiplier']); } }
	foreach ($mods as $m) {
		$pid = (int)$m['product_id']; $gid = (int)$m['group_id'];
		if (!isset($byProduct[$pid])) { continue; }
		if (!isset($byProduct[$pid]['groups'][$gid])) { $byProduct[$pid]['groups'][$gid] = array('id' => $gid, 'title' => $m['group_title'], 'min' => (int)$m['min_qty'], 'max' => (int)$m['group_max'], 'items' => array()); }
		$byProduct[$pid]['groups'][$gid]['items'][] = array('id' => (int)$m['id'], 'title' => $m['title'], 'price' => (int)$m['price_cents'], 'max' => (int)$m['max_qty']);
	}
	foreach ($byProduct as &$p) { $p['groups'] = array_values($p['groups']); } unset($p);
	return array('categories' => $cats, 'products' => array_values($byProduct));
}

// ---- one product with everything the guest can choose (loaded when the guest opens it)
function shop_product_detail($id) {
	shop_ensure_schema();
	$all = shop_catalog_product((int)$id);
	return $all;
}
/*
 * The symbol an option shows on the guest's pizza in the configurator ('' = it is not a topping, e.g. a dip). An explicit
 * choice in the menu editor wins, 'none' switches the symbol off, an empty value follows from the name (the menu came from
 * Resmio, so names like "Peperoni (mild)" or "Sambal Hollandaise auf Pizza" decide; the more specific rule comes first).
 */
// The rules of the automatic symbol, in the order they are tried (the first that matches wins, so a more specific word stands before a general
// one). Each rule: regular expression on the lower-case name, symbol key, the words as people write them (for the manual page).
function shop_item_icon_rules() {
	return array(
		array('/zum dippen/', 'dip', '"zum Dippen"'),
		array('/korean/', 'sauce_korean', 'Korean'), array('/sambal/', 'sauce_sambal', 'Sambal'), array('/hollandaise/', 'sauce_hollandaise', 'Hollandaise'),
		array('/creme fra|crème fra/u', 'sauce_creme', 'Creme fraiche, Crème fraîche'), array('/barbecue|bbq/', 'sauce_bbq', 'Barbecue, BBQ'),
		array('/knoblauch\s*so/u', 'sauce_garlic', 'Knoblauchsoße'), array('/curry\s*so/u', 'sauce_curry', 'Currysoße'), array('/so(ß|ss)e/u', 'sauce_other', 'Soße, Sosse'),
		array('/salami/', 'salami', 'Salami'), array('/sucuk/', 'sucuk', 'Sucuk'), array('/schinken/', 'ham', 'Schinken'),
		array('/hähnchen|haehnchen|chicken/u', 'chicken', 'Hähnchen, Chicken'), array('/thunfisch/', 'tuna', 'Thunfisch'),
		array('/gamba|garnele|shrimp/', 'shrimp', 'Gamba, Garnele, Shrimp'), array('/nugget/', 'nugget', 'Nugget'), array('/patty|beyond/', 'patty', 'Patty, Beyond'),
		array('/mozzarella/', 'mozzarella', 'Mozzarella'), array('/parmigiano|parmesan/', 'parmesan', 'Parmigiano, Parmesan'), array('/gorgonzola/', 'gorgonzola', 'Gorgonzola'),
		array('/schafsk|feta/u', 'feta', 'Schafskäse, Feta'), array('/k(ä|ae)se|schmelz/u', 'melt', 'Käse, Schmelz'),
		array('/spinat/', 'spinach', 'Spinat'), array('/getr(\.|ocknet).*tomat/', 'sundried', 'getr. / getrocknete Tomaten'), array('/tomate/', 'tomato', 'Tomate'),
		array('/zwiebel/', 'onion', 'Zwiebel'), array('/olive/', 'olive', 'Olive'), array('/peperoni/', 'pepperoni', 'Peperoni'), array('/paprika/', 'pepper', 'Paprika'),
		array('/mais/', 'corn', 'Mais'), array('/brokkoli|broccoli/', 'broccoli', 'Brokkoli, Broccoli'), array('/artischock/', 'artichoke', 'Artischocke'),
		array('/ananas/', 'pineapple', 'Ananas'), array('/rucola/', 'arugula', 'Rucola'), array('/kapern/', 'caper', 'Kapern'), array('/champignon|pilz/', 'mushroom', 'Champignon, Pilz'),
	);
}
// the names of the symbols (the same list the menu editor offers in its drop-down, web/js/menu_editor.js ICONS)
function shop_item_icon_labels() {
	return array('tomato' => 'Tomate', 'spinach' => 'Spinat', 'onion' => 'Zwiebel', 'olive' => 'Olive', 'pepperoni' => 'Peperoni', 'pepper' => 'Paprika', 'corn' => 'Mais', 'broccoli' => 'Brokkoli',
		'artichoke' => 'Artischocke', 'pineapple' => 'Ananas', 'sundried' => 'Getrocknete Tomate', 'arugula' => 'Rucola', 'caper' => 'Kapern', 'mushroom' => 'Champignon', 'melt' => 'Geschmolzener Käse',
		'parmesan' => 'Parmesan', 'gorgonzola' => 'Gorgonzola', 'mozzarella' => 'Mozzarella', 'feta' => 'Schafskäse', 'ham' => 'Schinken', 'salami' => 'Salami', 'sucuk' => 'Sucuk', 'chicken' => 'Hähnchen',
		'tuna' => 'Thunfisch', 'shrimp' => 'Garnele', 'nugget' => 'Nugget', 'patty' => 'Patty', 'sauce_hollandaise' => 'Soße Hollandaise', 'sauce_sambal' => 'Soße Sambal', 'sauce_creme' => 'Soße Crème fraîche',
		'sauce_bbq' => 'Soße BBQ', 'sauce_korean' => 'Soße Korean BBQ', 'sauce_garlic' => 'Soße Knoblauch', 'sauce_curry' => 'Soße Curry', 'sauce_other' => 'Soße (andere)', 'dip' => 'Dip (Beilage)');
}
function shop_item_icon($title, $icon = '') {
	$icon = (string)$icon;
	if ($icon === 'none') { return ''; }
	if ($icon !== '') { return preg_match('/^[a-z_]{2,20}$/', $icon) ? $icon : ''; }
	$t = mb_strtolower((string)$title, 'UTF-8');
	foreach (shop_item_icon_rules() as $r) { if (preg_match($r[0], $t)) { return $r[1]; } }
	return '';
}

function shop_catalog_product($id) {
	$p = fb_row("SELECT id, category_id, title, description, image_url, price_cents, allergens, configurator FROM ".fb_t('tp_shop_products')." WHERE id = ? AND active = 1", 'i', array((int)$id));
	if (!$p) { return null; }
	$p['configurator'] = (int)$p['configurator'];
	$p['variations'] = array();
	foreach (fb_rows("SELECT id, title, price_cents, multiplier FROM ".fb_t('tp_shop_variations')." WHERE product_id = ? ORDER BY sort, id", 'i', array((int)$id)) as $v) {
		$p['variations'][] = array('id' => (int)$v['id'], 'title' => $v['title'], 'price' => (int)$v['price_cents'], 'mult' => (float)$v['multiplier']);
	}
	$groups = array();
	foreach (fb_rows("SELECT gi.id, g.id AS group_id, gi.title, gi.price_cents, gi.max_qty, gi.icon, g.title AS gtitle, g.min_qty, g.max_qty AS gmax
		FROM ".fb_t('tp_shop_product_groups')." pg JOIN ".fb_t('tp_shop_modgroups')." g ON g.id = pg.group_id JOIN ".fb_t('tp_shop_group_items')." gi ON gi.group_id = g.id
		WHERE pg.product_id = ? ORDER BY pg.sort, g.id, gi.sort, gi.id", 'i', array((int)$id)) as $m) {
		$g = (int)$m['group_id'];
		if (!isset($groups[$g])) { $groups[$g] = array('id' => $g, 'title' => $m['gtitle'], 'min' => (int)$m['min_qty'], 'max' => (int)$m['gmax'], 'items' => array()); }
		$groups[$g]['items'][] = array('id' => (int)$m['id'], 'title' => $m['title'], 'price' => (int)$m['price_cents'], 'max' => (int)$m['max_qty'], 'icon' => shop_item_icon($m['title'], $m['icon']));
	}
	$p['groups'] = array_values($groups);
	$p['price'] = (int)$p['price_cents']; unset($p['price_cents']);
	return $p;
}

// ---- price of one cart line, always from the menu. $l: pid, vid (0 = none), opts (modifier id => quantity), qty, note
function shop_price_line($l) {
	$pid = (int)(isset($l['pid']) ? $l['pid'] : 0);
	$qty = max(1, min(50, (int)(isset($l['qty']) ? $l['qty'] : 1)));
	$p = shop_catalog_product($pid);
	if (!$p) { return array('ok' => false, 'error' => 'Ein Gericht im Warenkorb ist nicht mehr verfügbar.'); }
	$base = $p['price']; $mult = 1.0; $vtitle = '';
	if ($p['variations']) {
		$vid = (int)(isset($l['vid']) ? $l['vid'] : 0); $found = null;
		foreach ($p['variations'] as $v) { if ($v['id'] === $vid) { $found = $v; } }
		if (!$found) { return array('ok' => false, 'error' => 'Bitte eine Größe oder Variante für "'.$p['title'].'" wählen.'); }
		$base = $found['price']; $mult = $found['mult']; $vtitle = $found['title'];
	}
	$opts = (isset($l['opts']) && is_array($l['opts'])) ? $l['opts'] : array();
	$extras = 0; $chosen = array();
	foreach ($p['groups'] as $g) {
		$sum = 0;
		foreach ($g['items'] as $it) {
			$q = (int)(isset($opts[$it['id']]) ? $opts[$it['id']] : 0);
			if ($q <= 0) { continue; }
			if ($q > $it['max']) { return array('ok' => false, 'error' => '"'.$it['title'].'" kann bei "'.$p['title'].'" höchstens '.$it['max'].' mal gewählt werden.'); }
			$sum += $q; $extras += $it['price'] * $q;
			$chosen[] = array('id' => $it['id'], 'group' => $g['title'], 'title' => $it['title'], 'qty' => $q, 'price' => $it['price']);
		}
		if ($sum < $g['min']) { return array('ok' => false, 'error' => 'Bitte bei "'.$p['title'].'" mindestens '.$g['min'].' aus "'.$g['title'].'" wählen.'); }
		if ($g['max'] > 0 && $sum > $g['max']) { return array('ok' => false, 'error' => 'Bei "'.$p['title'].'" sind höchstens '.$g['max'].' aus "'.$g['title'].'" möglich.'); }
	}
	$unit = $base + (int)round($extras * $mult);
	return array('ok' => true, 'line' => array('product_id' => $pid, 'vid' => $vtitle !== '' ? (int)$found['id'] : 0, 'title' => $p['title'], 'variation' => $vtitle, 'options' => $chosen, 'qty' => $qty, 'unit_cents' => $unit,
		'line_cents' => $unit * $qty, 'note' => mb_substr(trim((string)(isset($l['note']) ? $l['note'] : '')), 0, 200)));
}

// ---- opening times (weekday 0 = Monday like Resmio). kind: 'delivery' | 'pickup'
function shop_windows($kind, $ts) {
	$wd = (int)date('N', $ts) - 1; $day = date('Y-m-d', $ts); $out = array();
	$ex = shop_ex_for($kind, $ts); // an exception for this day replaces the weekly times
	if ($ex) {
		if ($ex['closed']) { return array(); }
		foreach ((array)json_decode($ex['windows'], true) as $w) { if (isset($w[0], $w[1])) { $out[] = array(strtotime($day.' '.$w[0]), strtotime($day.' '.$w[1])); } }
		return $out;
	}
	foreach (fb_rows("SELECT begins, ends FROM ".fb_t('tp_shop_hours')." WHERE kind = ? AND weekday = ? ORDER BY begins", 'si', array($kind, $wd)) as $h) {
		$out[] = array(strtotime($day.' '.$h['begins']), strtotime($day.' '.$h['ends']));
	}
	return $out;
}

// ---- exceptions: array(id, date_from, date_to, kind all|delivery|pickup, closed, yearly, label, windows (JSON list of ["H:i","H:i"]))
function shop_ex_rows($reset = false) {
	static $rows = null;
	if ($reset) { $rows = null; return array(); }
	if ($rows === null) { $rows = fb_rows("SELECT id, date_from, date_to, kind, closed, yearly, label, windows FROM ".fb_t('tp_shop_hours_ex')." ORDER BY id"); }
	return $rows;
}
// the exception that applies to this kind on the day of $ts: one for exactly this kind beats one for both, then the shorter period, then the newer entry
function shop_ex_for($kind, $ts) {
	$day = date('Y-m-d', $ts); $md = date('m-d', $ts); $best = null; $bs = null;
	foreach (shop_ex_rows() as $e) {
		if ($e['kind'] !== 'all' && $e['kind'] !== $kind) { continue; }
		$hit = $e['yearly'] ? ($md >= substr($e['date_from'], 5) && $md <= substr($e['date_to'], 5)) : ($day >= $e['date_from'] && $day <= $e['date_to']);
		if (!$hit) { continue; }
		$score = array($e['kind'] === 'all' ? 0 : 1, -(int)round((strtotime($e['date_to']) - strtotime($e['date_from'])) / 86400), (int)$e['id']);
		if ($bs === null || $score > $bs) { $best = $e; $bs = $score; }
	}
	return $best;
}
// a list of array(begins, ends) from a form: array(ok, windows | error)
function shop_hours_clean($list, $where) {
	$time = '/^([01]\d|2[0-3]):[0-5]\d$/'; $win = array();
	if (!is_array($list)) { $list = array(); }
	if (count($list) > 6) { return array('ok' => false, 'error' => $where.'Es sind höchstens 6 Zeitfenster möglich.'); }
	foreach ($list as $pair) {
		$b = isset($pair[0]) ? trim((string)$pair[0]) : ''; $e = isset($pair[1]) ? trim((string)$pair[1]) : '';
		if ($b === '' && $e === '') { continue; }
		if (!preg_match($time, $b) || !preg_match($time, $e)) { return array('ok' => false, 'error' => $where.'Bitte Beginn und Ende als Uhrzeit angeben.'); }
		if ($e <= $b) { return array('ok' => false, 'error' => $where.'Das Ende ('.$e.') muss nach dem Beginn ('.$b.') liegen. Über Mitternacht bitte in zwei Zeilen: bis 23:59 und am Folgetag ab 00:00.'); }
		$win[] = array($b, $e);
	}
	sort($win);
	for ($i = 1; $i < count($win); $i++) {
		if ($win[$i][0] < $win[$i - 1][1]) { return array('ok' => false, 'error' => $where.'Die Zeiten '.$win[$i - 1][0].' bis '.$win[$i - 1][1].' und '.$win[$i][0].' bis '.$win[$i][1].' überschneiden sich.'); }
	}
	return array('ok' => true, 'windows' => $win);
}
function shop_ex_save($d) {
	shop_ensure_schema();
	$id = (int)(isset($d['id']) ? $d['id'] : 0);
	$ok = function ($v) { $t = DateTime::createFromFormat('Y-m-d', (string)$v); return ($t && $t->format('Y-m-d') === $v) ? $v : ''; };
	$from = $ok(isset($d['date_from']) ? trim((string)$d['date_from']) : '');
	$to = $ok(isset($d['date_to']) ? trim((string)$d['date_to']) : '');
	if ($from === '') { return array('ok' => false, 'error' => 'Bitte gib das Datum an (Von).'); }
	if ($to === '') { $to = $from; }
	if ($to < $from) { return array('ok' => false, 'error' => 'Das Ende des Zeitraums liegt vor dem Beginn.'); }
	if ((strtotime($to) - strtotime($from)) / 86400 > 366) { return array('ok' => false, 'error' => 'Ein Zeitraum darf höchstens ein Jahr lang sein.'); }
	$yearly = empty($d['yearly']) ? 0 : 1;
	if ($yearly && substr($from, 0, 4) !== substr($to, 0, 4)) { return array('ok' => false, 'error' => 'Bei "jedes Jahr" muss der Zeitraum innerhalb eines Jahres liegen.'); }
	$kind = (isset($d['kind']) && in_array($d['kind'], array('delivery', 'pickup'), true)) ? $d['kind'] : 'all';
	$closed = empty($d['closed']) ? 0 : 1;
	$label = mb_substr(trim((string)(isset($d['label']) ? $d['label'] : '')), 0, 80);
	$json = '';
	if (!$closed) {
		$c = shop_hours_clean(isset($d['windows']) ? $d['windows'] : array(), '');
		if (!$c['ok']) { return $c; }
		if (!$c['windows']) { return array('ok' => false, 'error' => 'Bitte trage mindestens ein Zeitfenster ein oder wähle "Geschlossen".'); }
		$json = json_encode($c['windows']);
	}
	if ($id) {
		if (!fb_row("SELECT id FROM ".fb_t('tp_shop_hours_ex')." WHERE id = ?", 'i', array($id))) { return array('ok' => false, 'error' => 'Diese Ausnahme gibt es nicht mehr.'); }
		$r = fb_exec("UPDATE ".fb_t('tp_shop_hours_ex')." SET date_from = ?, date_to = ?, kind = ?, closed = ?, yearly = ?, label = ?, windows = ? WHERE id = ?", 'sssiissi', array($from, $to, $kind, $closed, $yearly, $label, $json, $id));
	} else {
		$r = fb_exec("INSERT INTO ".fb_t('tp_shop_hours_ex')." (date_from, date_to, kind, closed, yearly, label, windows) VALUES (?, ?, ?, ?, ?, ?, ?)", 'sssiiss', array($from, $to, $kind, $closed, $yearly, $label, $json));
	}
	shop_ex_rows(true);
	return $r === false ? array('ok' => false, 'error' => 'Die Ausnahme konnte nicht gespeichert werden.') : array('ok' => true);
}
function shop_ex_delete($id) {
	fb_exec("DELETE FROM ".fb_t('tp_shop_hours_ex')." WHERE id = ?", 'i', array((int)$id));
	shop_ex_rows(true);
	return array('ok' => true);
}
// the public holidays of Lower Saxony (Easter by the Gauss formula); array(array(Y-m-d, name))
function shop_ex_holidays($y) {
	$a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
	$h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
	$easter = mktime(12, 0, 0, intdiv($h + $l - 7 * $m + 114, 31), (($h + $l - 7 * $m + 114) % 31) + 1, $y);
	$off = function ($n) use ($easter) { return date('Y-m-d', $easter + $n * 86400); };
	return array(array($y.'-01-01', 'Neujahr'), array($off(-2), 'Karfreitag'), array($off(1), 'Ostermontag'), array($y.'-05-01', 'Tag der Arbeit'), array($off(39), 'Christi Himmelfahrt'),
		array($off(50), 'Pfingstmontag'), array($y.'-10-03', 'Tag der Deutschen Einheit'), array($y.'-10-31', 'Reformationstag'), array($y.'-12-25', '1. Weihnachtstag'), array($y.'-12-26', '2. Weihnachtstag'));
}
// enter them as "closed" (every one can be changed or deleted afterwards); what is there already (same day and name) is skipped. Returns how many were added.
function shop_ex_add_holidays($y) {
	shop_ensure_schema();
	$y = (int)$y; if ($y < 2020 || $y > 2100) { return -1; }
	$n = 0;
	foreach (shop_ex_holidays($y) as $h) {
		if (fb_row("SELECT id FROM ".fb_t('tp_shop_hours_ex')." WHERE date_from = ? AND label = ?", 'ss', array($h[0], $h[1]))) { continue; }
		fb_exec("INSERT INTO ".fb_t('tp_shop_hours_ex')." (date_from, date_to, kind, closed, yearly, label, windows) VALUES (?, ?, 'all', 1, 0, ?, '')", 'sss', array($h[0], $h[0], $h[1])); $n++;
	}
	shop_ex_rows(true);
	return $n;
}

/*
 * Opening times from the backend: $data[kind][weekday 0..6] = list of array('H:i' begins, 'H:i' ends); replaces every row of
 * tp_shop_hours. A window lies within one day (over midnight: two windows, "bis 23:59" and the next day "ab 00:00"), windows of a
 * day must not overlap. Returns array(ok, error | count).
 */
function shop_hours_save($data) {
	shop_ensure_schema();
	$names = array('Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag');
	$labels = array('delivery' => 'Lieferung', 'pickup' => 'Abholung');
	$rows = array();
	foreach ($labels as $kind => $label) {
		for ($d = 0; $d < 7; $d++) {
			$c = shop_hours_clean(isset($data[$kind][$d]) ? $data[$kind][$d] : array(), $label.', '.$names[$d].': ');
			if (!$c['ok']) { return $c; }
			foreach ($c['windows'] as $w) { $rows[] = array($kind, $d, $w[0].':00', $w[1].':00'); }
		}
	}
	$db = fb_db();
	mysqli_begin_transaction($db);
	$ok = fb_exec("DELETE FROM ".fb_t('tp_shop_hours')) !== false;
	foreach ($rows as $r) {
		if (!$ok) { break; }
		$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_hours')." (kind, weekday, begins, ends) VALUES (?, ?, ?, ?)", 'siss', $r) !== false;
	}
	if (!$ok) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Die Zeiten konnten nicht gespeichert werden.'); }
	mysqli_commit($db);
	return array('ok' => true, 'count' => count($rows));
}

/*
 * State of delivery or pickup right now: array(open, until (ts), next (ts or 0), eta_min). "open" means an order can still be placed now (until the end
 * of the order time, see last_order_min); the food of the last orders may leave after closing.
 */
// Delivery or pickup paused by the staff (rush, no driver, kitchen full): no new orders of that kind, not even for a later time.
// A pause with an end ends by itself.
function shop_paused($kind) {
	$kind = ($kind === 'pickup') ? 'pickup' : 'delivery';
	if (!shop_flag('pause_'.$kind)) { return array('paused' => false, 'until' => 0); }
	$u = (int)shop_setting('pause_'.$kind.'_until');
	if ($u > 0 && $u <= time()) { return array('paused' => false, 'until' => 0); }
	return array('paused' => true, 'until' => $u);
}
function shop_pause_set($kind, $on, $minutes = 0) {
	$kind = ($kind === 'pickup') ? 'pickup' : 'delivery';
	$minutes = max(0, min(720, (int)$minutes));
	shop_setting_set('pause_'.$kind, $on ? '1' : '0');
	shop_setting_set('pause_'.$kind.'_until', ($on && $minutes > 0) ? (string)(time() + $minutes * 60) : '0');
}
function shop_pause_state() {
	$out = array();
	foreach (array('delivery', 'pickup') as $k) { $p = shop_paused($k); $out[$k] = array('paused' => $p['paused'], 'until' => $p['until'] ? date('H:i', $p['until']) : '', 'until_ts' => $p['until']); }
	return $out;
}
function shop_state($kind, $now = null) {
	$now = $now ?: time();
	$lead = ($kind === 'delivery') ? (int)shop_setting('eta_delivery_min') : (int)shop_setting('lead_pickup_min');
	$pz = shop_paused($kind);
	if ($pz['paused']) { return array('open' => false, 'until' => 0, 'next' => 0, 'lead' => $lead, 'paused' => true, 'paused_until' => $pz['until']); }
	// orders are taken until the end of the order time (minus "last_order_min"); the delivery or pickup itself may then fall after closing
	$cut = max(0, min(240, (int)shop_setting('last_order_min'))) * 60;
	foreach (shop_windows($kind, $now) as $w) {
		if ($now >= $w[0] && $now <= $w[1] - $cut) { return array('open' => true, 'until' => $w[1] - $cut, 'next' => 0, 'lead' => $lead); }
	}
	$ex = shop_ex_for($kind, $now); $note = ($ex && $ex['closed']) ? (string)$ex['label'] : ''; // "Heiligabend": why it is closed today
	$next = 0;
	for ($d = 0; $d <= 7 && !$next; $d++) {
		$t = $now + $d * 86400;
		foreach (shop_windows($kind, $t) as $w) { if ($w[0] > $now) { $next = $w[0]; break; } }
	}
	return array('open' => false, 'until' => 0, 'next' => $next, 'lead' => $lead, 'note' => $note);
}

// selectable times for a day (Y-m-d): array of 'H:i'. Today starts after the lead time.
function shop_slots($kind, $date) {
	$ts = strtotime($date.' 12:00:00');
	if (!$ts) { return array(); }
	$pz = shop_paused($kind);
	if ($pz['paused']) { return array(); }
	$step = max(5, (int)shop_setting('slot_min')) * 60;
	$lead = (($kind === 'delivery') ? (int)shop_setting('eta_delivery_min') : (int)shop_setting('lead_pickup_min')) * 60;
	$earliest = ($date === date('Y-m-d')) ? time() + $lead : 0;
	$slots = array();
	foreach (shop_windows($kind, $ts) as $w) {
		$t = max($w[0], $earliest);
		$t = (int)(ceil($t / $step) * $step);
		for (; $t <= $w[1] - ($kind === 'delivery' ? 0 : 0); $t += $step) { $slots[$t] = date('H:i', $t); }
	}
	return array_values($slots);
}

// ---- delivery zones: address -> coordinates (OpenStreetMap Nominatim, cached, Google as a paid fallback for
// addresses Nominatim's data misses) -> zone (point in polygon)
// returns array(lat, lng, postcode, road, candidates) or null - postcode/road are the provider's own
// normalized values (may be null even on a hit, e.g. a cache row from before those columns existed),
// used to hand a confirmed address back to the guest (auto-fill an empty or wrong PLZ; the street
// itself is never rewritten). candidates is every distinct match (lat, lng, postcode, road) Nominatim
// offered for this query, including the primary one - shop_find_zone() uses it to tell a guest apart
// two real, differently-named streets instead of silently guessing between them.
function shop_geocode($street, $zip, $city) {
	shop_ensure_schema();
	$key = sha1(mb_strtolower(trim($street).'|'.trim($zip).'|'.trim($city)));
	$hit = fb_row("SELECT lat, lng, postcode, road, candidates, created_at FROM ".fb_t('tp_shop_geocache')." WHERE h = ?", 's', array($key));
	if ($hit && ($hit['lat'] !== null || strtotime($hit['created_at']) > time() - 86400)) {
		if ($hit['lat'] === null) { return null; }
		$cands = $hit['candidates'] ? json_decode($hit['candidates'], true) : array(array('lat' => (float)$hit['lat'], 'lng' => (float)$hit['lng'], 'postcode' => $hit['postcode'], 'road' => $hit['road']));
		return array((float)$hit['lat'], (float)$hit['lng'], $hit['postcode'], $hit['road'], $cands);
	}
	$url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&addressdetails=1&countrycodes=de&street='.rawurlencode(trim($street)).'&postalcode='.rawurlencode(trim($zip)).'&city='.rawurlencode(trim($city));
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
		CURLOPT_USERAGENT => 'mySeat-Lieferservice/1.0 (Amadeus Hildesheim; hamun@amds.at)'));
	$raw = curl_exec($ch);
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if ($http !== 200) { return null; } // a problem of the service is not cached
	$j = json_decode((string)$raw, true);
	$cands = array();
	if (is_array($j)) {
		foreach ($j as $row) {
			if (empty($row['lat']) || empty($row['lon'])) { continue; }
			$addr = isset($row['address']) ? $row['address'] : array();
			if (empty($addr['road'])) { continue; } // not a street-level match (a district, a postcode area, ...)
			$road = trim($addr['road'].' '.(isset($addr['house_number']) ? $addr['house_number'] : shop_street_number($street)));
			$postcode = !empty($addr['postcode']) ? (string)$addr['postcode'] : null;
			$k = mb_strtolower($road.'|'.$postcode);
			if (isset($cands[$k])) { continue; } // Nominatim can list the same street/PLZ combo more than once
			$cands[$k] = array('lat' => (float)$row['lat'], 'lng' => (float)$row['lon'], 'postcode' => $postcode, 'road' => $road);
		}
	}
	$cands = array_values($cands);
	$lat = $lng = $postcode = $road = null;
	if ($cands) { $lat = $cands[0]['lat']; $lng = $cands[0]['lng']; $postcode = $cands[0]['postcode']; $road = $cands[0]['road']; }
	// Nominatim's German address data occasionally misses real addresses (new builds, rural roads)
	// - Google Geocoding is a paid fallback only for exactly that gap, never the default path, so
	// a normal lookup that Nominatim already answers never costs anything
	if ($lat === null) {
		$g = shop_geocode_google($street, $zip, $city);
		if ($g) { list($lat, $lng, $postcode, $road) = $g; $cands = array(array('lat' => $lat, 'lng' => $lng, 'postcode' => $postcode, 'road' => $road)); }
	}
	fb_exec("REPLACE INTO ".fb_t('tp_shop_geocache')." (h, lat, lng, postcode, road, candidates, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
		'sddsss', array($key, $lat, $lng, $postcode, $road, count($cands) > 1 ? json_encode($cands) : null));
	return $lat !== null ? array($lat, $lng, $postcode, $road, $cands) : null;
}
// the house number the guest typed, when a provider's own match does not carry one separately
function shop_street_number($street) {
	return preg_match('/(\d[\d\s\/a-zA-Z-]*)$/u', trim((string)$street), $m) ? trim($m[1]) : '';
}

// returns array(lat, lng, postcode, road) or null, same contract as shop_geocode()
function shop_geocode_google($street, $zip, $city) {
	$key = shop_google_key();
	if ($key === '') { return null; }
	$addr = trim($street.', '.$zip.' '.$city, ' ,');
	if ($addr === '') { return null; }
	$url = 'https://maps.googleapis.com/maps/api/geocode/json?address='.rawurlencode($addr).'&region=de&key='.rawurlencode($key);
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8));
	$raw = curl_exec($ch);
	if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) { return null; }
	$j = json_decode((string)$raw, true);
	if (!is_array($j) || !isset($j['status']) || $j['status'] !== 'OK' || empty($j['results'][0]['geometry']['location'])) { return null; }
	$loc = $j['results'][0]['geometry']['location'];
	if (!isset($loc['lat'], $loc['lng'])) { return null; }
	$postcode = null; $route = null; $number = null;
	foreach ((isset($j['results'][0]['address_components']) ? $j['results'][0]['address_components'] : array()) as $c) {
		if (!empty($c['types']) && in_array('postal_code', $c['types'], true)) { $postcode = $c['long_name']; }
		if (!empty($c['types']) && in_array('route', $c['types'], true)) { $route = $c['long_name']; }
		if (!empty($c['types']) && in_array('street_number', $c['types'], true)) { $number = $c['long_name']; }
	}
	$road = $route !== null ? trim($route.' '.($number !== null ? $number : shop_street_number($street))) : null;
	return array((float)$loc['lat'], (float)$loc['lng'], $postcode, $road);
}

// what3words: an escape hatch for locations with no real street address (a field, a park entrance,
// an event site). Only reached from the guest UI after both Nominatim and Google fail to find a
// typed address - never the default path. Cached the same way as a normal address, keyed by the
// words string instead of street/zip/city.
function shop_geocode_w3w($words) {
	$apiKey = shop_w3w_key();
	$words = trim((string)$words, "/ \t\n\r\0\x0B");
	if ($words === '' || $apiKey === '') { return null; }
	shop_ensure_schema();
	$key = sha1('w3w:'.mb_strtolower($words));
	$hit = fb_row("SELECT lat, lng, created_at FROM ".fb_t('tp_shop_geocache')." WHERE h = ?", 's', array($key));
	if ($hit && ($hit['lat'] !== null || strtotime($hit['created_at']) > time() - 86400)) {
		return $hit['lat'] !== null ? array((float)$hit['lat'], (float)$hit['lng']) : null;
	}
	$url = 'https://api.what3words.com/v3/convert-to-coordinates?words='.rawurlencode($words).'&key='.rawurlencode($apiKey);
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8));
	$raw = curl_exec($ch);
	if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) { return null; } // a bad code or a service problem, neither cached
	$j = json_decode((string)$raw, true);
	$lat = (is_array($j) && !empty($j['coordinates']['lat'])) ? (float)$j['coordinates']['lat'] : null;
	$lng = (is_array($j) && !empty($j['coordinates']['lng'])) ? (float)$j['coordinates']['lng'] : null;
	fb_exec("REPLACE INTO ".fb_t('tp_shop_geocache')." (h, lat, lng, created_at) VALUES (?, ?, ?, NOW())", 'sdd', array($key, $lat, $lng));
	return $lat !== null ? array($lat, $lng) : null;
}

// same idea as shop_w3w_test() below, for the Google key: resolves a known-good address and
// surfaces Google's own status/error_message (Google Geocoding returns HTTP 200 even on failure,
// the real result is the "status" field - REQUEST_DENIED, OVER_QUERY_LIMIT, etc.)
function shop_google_test() {
	$key = shop_google_key();
	if ($key === '') { return array('ok' => false, 'error' => 'Kein Schlüssel hinterlegt.'); }
	$url = 'https://maps.googleapis.com/maps/api/geocode/json?address='.rawurlencode('Hildesheim, Germany').'&key='.rawurlencode($key);
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10));
	$raw = curl_exec($ch);
	$errno = curl_errno($ch); $err = $errno ? curl_error($ch) : null;
	if ($errno) { return array('ok' => false, 'error' => 'Google nicht erreichbar ('.$err.').'); }
	$j = json_decode((string)$raw, true);
	if (is_array($j) && isset($j['status']) && $j['status'] === 'OK' && !empty($j['results'][0]['geometry']['location'])) {
		return array('ok' => true, 'message' => 'Verbindung in Ordnung. '.shop_places_test_text());
	}
	$status = (is_array($j) && isset($j['status'])) ? $j['status'] : 'unbekannter Fehler';
	$msg = (is_array($j) && !empty($j['error_message'])) ? $j['error_message'] : '';
	return array('ok' => false, 'error' => 'Google meldet: '.$status.($msg !== '' ? ' ('.$msg.')' : '').'.');
}

// ---- Street suggestions while the staff types an address in the till (Google Places API (New): Autocomplete, then Place Details for the one that is picked).
// The key stays on the server: the till asks ajax/shop_pos.php, which asks Google. A session token ties the typing and the pick together, so Google counts them as one
// use. The setting places_suggest switches it off; without a key or when Google says no, the till simply shows no suggestions and the fields work as before.
function shop_places_count($kind) {
	$col = $kind === 'pick' ? 'pick' : 'suggest';
	fb_exec("INSERT INTO ".fb_t('tp_shop_places_use')." (`day`, `suggest`, `pick`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `$col` = `$col` + 1", 'sii', array(date('Y-m-d'), $col === 'suggest' ? 1 : 0, $col === 'pick' ? 1 : 0));
}
// requests to Google so far: today, this month, last month (suggestions asked, suggestions picked)
function shop_places_usage() {
	shop_ensure_schema();
	$sum = function ($from, $to) { $r = fb_row("SELECT COALESCE(SUM(suggest), 0) AS s, COALESCE(SUM(pick), 0) AS p FROM ".fb_t('tp_shop_places_use')." WHERE `day` BETWEEN ? AND ?", 'ss', array($from, $to)); return array('suggest' => (int)$r['s'], 'pick' => (int)$r['p']); };
	$m0 = date('Y-m-01'); $l0 = date('Y-m-01', strtotime($m0.' -1 month')); $l1 = date('Y-m-t', strtotime($l0));
	return array('today' => $sum(date('Y-m-d'), date('Y-m-d')), 'month' => $sum($m0, date('Y-m-d')), 'last' => $sum($l0, $l1));
}
function shop_places_on() { return shop_setting('places_suggest') !== '0' && shop_google_key() !== ''; }
function shop_places_call($method, $url, $payload, $mask) {
	$h = array('X-Goog-Api-Key: '.shop_google_key(), 'Content-Type: application/json');
	if ($mask !== '') { $h[] = 'X-Goog-FieldMask: '.$mask; }
	$opt = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => $h);
	if ($method === 'POST') { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($payload); }
	$ch = curl_init($url); curl_setopt_array($ch, $opt);
	$raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); curl_close($ch);
	if ($errno) { return array('ok' => false, 'error' => 'Google nicht erreichbar.'); }
	$j = json_decode((string)$raw, true);
	if ($code !== 200 || !is_array($j)) {
		$st = (is_array($j) && !empty($j['error']['status'])) ? $j['error']['status'].': ' : '';
		return array('ok' => false, 'error' => $st.((is_array($j) && !empty($j['error']['message'])) ? $j['error']['message'] : 'HTTP '.$code));
	}
	return array('ok' => true, 'json' => $j);
}
// up to six suggestions for what has been typed so far: array('ok', 'items' => [id, text, main, sec])
function shop_places_suggest($q, $session) {
	$q = trim(mb_substr((string)$q, 0, 100));
	if (mb_strlen($q) < 3) { return array('ok' => true, 'items' => array()); }
	$body = array('input' => $q, 'languageCode' => 'de', 'includedRegionCodes' => array('de'), 'includedPrimaryTypes' => array('street_address', 'route', 'premise', 'subpremise'));
	if (preg_match('/^[A-Za-z0-9_-]{8,36}$/', (string)$session)) { $body['sessionToken'] = (string)$session; }
	static $o = false; if ($o === false) { $o = shop_origin(); }
	if ($o) { $body['locationBias'] = array('circle' => array('center' => array('latitude' => (float)$o[0], 'longitude' => (float)$o[1]), 'radius' => 30000.0)); } // prefers the surroundings of the restaurant, does not exclude the rest
	$r = shop_places_call('POST', 'https://places.googleapis.com/v1/places:autocomplete', $body, '');
	if (!$r['ok']) { error_log('places autocomplete: '.$r['error']); return $r; }
	shop_places_count('suggest');
	$items = array();
	foreach ((isset($r['json']['suggestions']) && is_array($r['json']['suggestions'])) ? $r['json']['suggestions'] : array() as $s) {
		if (empty($s['placePrediction']['placeId'])) { continue; }
		$p = $s['placePrediction'];
		$items[] = array('id' => (string)$p['placeId'], 'text' => isset($p['text']['text']) ? (string)$p['text']['text'] : '',
			'main' => isset($p['structuredFormat']['mainText']['text']) ? (string)$p['structuredFormat']['mainText']['text'] : (isset($p['text']['text']) ? (string)$p['text']['text'] : ''),
			'sec' => isset($p['structuredFormat']['secondaryText']['text']) ? (string)$p['structuredFormat']['secondaryText']['text'] : '');
		if (count($items) >= 6) { break; }
	}
	// the restaurant's own town first (the bias above only prefers it), the order Google gave stays within each group
	$home = mb_strtolower(trim((string)shop_setting('origin_city')));
	if ($home !== '') {
		$near = array(); $rest = array();
		foreach ($items as $it) { if (mb_strpos(mb_strtolower($it['sec']), $home) === 0) { $near[] = $it; } else { $rest[] = $it; } }
		$items = array_merge($near, $rest);
	}
	return array('ok' => true, 'items' => $items);
}
// the picked suggestion as the three fields of the till (+ the point, so the zone can be found without another lookup)
function shop_places_pick($placeId, $session) {
	if (!preg_match('/^[A-Za-z0-9_-]{10,300}$/', (string)$placeId)) { return array('ok' => false, 'error' => 'Unbekannter Vorschlag.'); }
	$url = 'https://places.googleapis.com/v1/places/'.rawurlencode((string)$placeId).'?languageCode=de&regionCode=DE'.(preg_match('/^[A-Za-z0-9_-]{8,36}$/', (string)$session) ? '&sessionToken='.rawurlencode((string)$session) : '');
	$r = shop_places_call('GET', $url, null, 'addressComponents,location');
	if (!$r['ok']) { error_log('places details: '.$r['error']); return $r; }
	shop_places_count('pick');
	$route = ''; $no = ''; $zip = ''; $city = '';
	foreach ((isset($r['json']['addressComponents']) && is_array($r['json']['addressComponents'])) ? $r['json']['addressComponents'] : array() as $c) {
		$t = isset($c['types']) && is_array($c['types']) ? $c['types'] : array(); $n = isset($c['longText']) ? (string)$c['longText'] : '';
		if (in_array('route', $t, true)) { $route = $n; }
		elseif (in_array('street_number', $t, true)) { $no = $n; }
		elseif (in_array('postal_code', $t, true)) { $zip = $n; }
		elseif (in_array('locality', $t, true)) { $city = $n; }
		elseif ($city === '' && in_array('postal_town', $t, true)) { $city = $n; }
	}
	if ($route === '') { return array('ok' => false, 'error' => 'Der Vorschlag enthält keine Straße.'); }
	$loc = isset($r['json']['location']) ? $r['json']['location'] : array();
	return array('ok' => true, 'street' => $route, 'number' => $no, 'zip' => $zip, 'city' => $city, 'lat' => isset($loc['latitude']) ? (float)$loc['latitude'] : null, 'lng' => isset($loc['longitude']) ? (float)$loc['longitude'] : null);
}
// what the backend button "Verbindung prüfen" adds to its answer
function shop_places_test_text() {
	$r = shop_places_suggest('Goslarsche Landstr', '');
	if ($r['ok'] && $r['items']) { return 'Adressvorschläge für die Kasse: in Ordnung ('.count($r['items']).' Vorschläge für "Goslarsche Landstr").'; }
	if ($r['ok']) { return 'Adressvorschläge für die Kasse: Google antwortet, fand aber nichts für "Goslarsche Landstr".'; }
	return 'Adressvorschläge für die Kasse: nicht verfügbar ('.$r['error'].'). In der Google-Cloud-Konsole die "Places API (New)" für diesen Schlüssel freigeben.';
}

// diagnostic for the backend "Verbindung prüfen" button: resolves a known-good address
// (what3words' own documented example, ///filled.count.soap) and surfaces the API's own error
// message - shop_geocode_w3w() itself only ever returns null on failure, which cannot distinguish
// a bad/inactive key from a genuine what3words outage or a guest's typo
function shop_w3w_test() {
	$apiKey = shop_w3w_key();
	if ($apiKey === '') { return array('ok' => false, 'error' => 'Kein Schlüssel hinterlegt.'); }
	$url = 'https://api.what3words.com/v3/convert-to-coordinates?words=filled.count.soap&key='.rawurlencode($apiKey);
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10));
	$raw = curl_exec($ch);
	$errno = curl_errno($ch); $err = $errno ? curl_error($ch) : null;
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if ($errno) { return array('ok' => false, 'error' => 'what3words nicht erreichbar ('.$err.').'); }
	$j = json_decode((string)$raw, true);
	if ($http === 200 && is_array($j) && !empty($j['coordinates']['lat'])) { return array('ok' => true, 'message' => 'Verbindung in Ordnung.'); }
	$msg = (is_array($j) && !empty($j['error']['message'])) ? $j['error']['message'] : ('HTTP '.$http);
	return array('ok' => false, 'error' => 'what3words meldet: '.$msg);
}

function shop_point_in_polygon($lat, $lng, $poly) {
	$in = false; $n = count($poly);
	for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
		$yi = $poly[$i][0]; $xi = $poly[$i][1]; $yj = $poly[$j][0]; $xj = $poly[$j][1];
		if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) { $in = !$in; }
	}
	return $in;
}

// the zone a coordinate falls in, or null - shared by an address lookup and a what3words lookup
function shop_zone_for_point($lat, $lng) {
	foreach (fb_rows("SELECT id, name, polygon, fee_cents, min_order_cents FROM ".fb_t('tp_shop_zones')." WHERE active = 1 ORDER BY fee_cents, id") as $z) {
		$poly = json_decode($z['polygon'], true);
		if (is_array($poly) && shop_point_in_polygon($lat, $lng, $poly)) {
			return array('id' => (int)$z['id'], 'name' => $z['name'], 'fee_cents' => (int)$z['fee_cents'], 'min_order_cents' => (int)$z['min_order_cents']);
		}
	}
	return null;
}

// ---- delivery zone editor (backend page p=11, web/content/shop_zones.page.php): draw, edit and delete
// the polygons themselves. shop_admin.php's 'zone' op still covers name/fee/min/active for the settings
// page; these are the shape-aware operations only that page needs.
function shop_zones_list() {
	shop_ensure_schema();
	$out = array();
	foreach (fb_rows("SELECT id, name, polygon, fee_cents, min_order_cents, active FROM ".fb_t('tp_shop_zones')." ORDER BY fee_cents, id") as $z) {
		$poly = json_decode($z['polygon'], true);
		$out[] = array('id' => (int)$z['id'], 'name' => $z['name'], 'polygon' => is_array($poly) ? $poly : array(),
			'fee_cents' => (int)$z['fee_cents'], 'min_order_cents' => (int)$z['min_order_cents'], 'active' => (int)$z['active']);
	}
	return $out;
}
// a usable polygon: at least 3 points, each a [lat, lng] pair of real numbers
function shop_zone_polygon_ok($poly) {
	if (!is_array($poly) || count($poly) < 3) { return false; }
	foreach ($poly as $p) { if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) { return false; } }
	return true;
}
function shop_zone_create($name, $feeCents, $minCents, $active, $poly) {
	if (!shop_zone_polygon_ok($poly)) { return array('ok' => false, 'error' => 'Das Gebiet braucht mindestens 3 Eckpunkte.'); }
	$name = mb_substr(trim((string)$name), 0, 80);
	if ($name === '') { return array('ok' => false, 'error' => 'Bitte gib dem Gebiet einen Namen.'); }
	$clean = array_map(function ($p) { return array((float)$p[0], (float)$p[1]); }, $poly);
	fb_exec("INSERT INTO ".fb_t('tp_shop_zones')." (name, polygon, fee_cents, min_order_cents, active) VALUES (?, ?, ?, ?, ?)",
		'ssiii', array($name, json_encode($clean), max(0, (int)$feeCents), max(0, (int)$minCents), $active ? 1 : 0));
	return array('ok' => true, 'id' => (int)mysqli_insert_id(fb_db()));
}
function shop_zone_save_shape($id, $poly) {
	if (!shop_zone_polygon_ok($poly)) { return array('ok' => false, 'error' => 'Das Gebiet braucht mindestens 3 Eckpunkte.'); }
	$clean = array_map(function ($p) { return array((float)$p[0], (float)$p[1]); }, $poly);
	fb_exec("UPDATE ".fb_t('tp_shop_zones')." SET polygon = ? WHERE id = ?", 'si', array(json_encode($clean), (int)$id));
	return array('ok' => true);
}
// name/fee/min/active only - shared by the settings page (web/ajax/shop_admin.php's 'zone' op) and this
// editor's own 'save_info' op, which use two different admin tokens and so can't call one another directly
function shop_zone_save_info($id, $name, $feeCents, $minCents, $active) {
	$name = mb_substr(trim((string)$name), 0, 80);
	if ($id <= 0 || $name === '') { return array('ok' => false, 'error' => 'Name fehlt.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_zones')." SET name = ?, fee_cents = ?, min_order_cents = ?, active = ? WHERE id = ?",
		'siiii', array($name, max(0, (int)$feeCents), max(0, (int)$minCents), $active ? 1 : 0, (int)$id));
	return array('ok' => true);
}
function shop_zone_delete($id) {
	fb_exec("DELETE FROM ".fb_t('tp_shop_zones')." WHERE id = ?", 'i', array((int)$id));
	return array('ok' => true);
}
// advisory only: two active zones whose areas overlap, so an operator sees it and knows which one
// shop_zone_for_point() would actually pick there (ORDER BY fee_cents, id - the cheaper zone wins,
// with no separate priority setting). A vertex of one zone found inside the other is good enough
// evidence of an overlap for this purpose; it will not catch two polygons crossing without either
// one containing a vertex of the other, which is a rare shape for a real delivery-area map
function shop_zones_overlaps($zones) {
	$active = array_values(array_filter($zones, function ($z) { return $z['active'] && shop_zone_polygon_ok($z['polygon']); }));
	$out = array();
	for ($i = 0; $i < count($active); $i++) {
		for ($j = $i + 1; $j < count($active); $j++) {
			$a = $active[$i]; $b = $active[$j]; $hit = false;
			foreach ($a['polygon'] as $p) { if (shop_point_in_polygon($p[0], $p[1], $b['polygon'])) { $hit = true; break; } }
			if (!$hit) { foreach ($b['polygon'] as $p) { if (shop_point_in_polygon($p[0], $p[1], $a['polygon'])) { $hit = true; break; } } }
			if ($hit) {
				// same ordering shop_zone_for_point() itself uses
				$winner = ($a['fee_cents'] !== $b['fee_cents']) ? ($a['fee_cents'] < $b['fee_cents'] ? $a['id'] : $b['id']) : min($a['id'], $b['id']);
				$out[] = array('a' => $a['id'], 'b' => $b['id'], 'winner' => $winner);
			}
		}
	}
	return $out;
}

// the zone of an address: array(ok, zone (id, name, fee_cents, min_order_cents), lat, lng, error, reason).
// reason is 'not_found' (geocoding itself failed - the UI may then offer a what3words code instead) or
// 'outside_zone' (a real, found address that is simply not served).
function shop_find_zone($street, $zip, $city) {
	// PLZ is not required here: Nominatim/Google can often resolve street + Ort alone, and a
	// successful match hands its own postcode back (see 'postcode' below) rather than asking the
	// guest to type it first - the PLZ is still required to actually place the order (shop_create_order)
	if (trim($street) === '' || trim($city) === '') { return array('ok' => false, 'reason' => 'not_found', 'error' => 'Bitte Straße mit Hausnummer und Ort angeben.'); }
	$pos = shop_geocode($street, $zip, $city);
	if (!$pos) { return array('ok' => false, 'reason' => 'not_found', 'error' => 'Wir konnten diese Adresse nicht finden. Bitte prüfe Straße, Hausnummer und PLZ, oder wähle Abholung.'); }
	list($lat, $lng, $postcode, $road, $cands) = $pos;
	// several real, differently-named streets can match the same typed text ("Goschentor" is also the
	// start of "Goschenstraße") - only the ones actually in a delivery zone matter, and only among
	// those does an actual choice exist; a single deliverable match is accepted exactly as before
	$deliverable = array();
	foreach ($cands as $c) {
		$z = shop_zone_for_point($c['lat'], $c['lng']);
		if ($z) { $c['zone'] = $z; $deliverable[] = $c; }
	}
	if (!$deliverable) { return array('ok' => false, 'reason' => 'outside_zone', 'error' => 'Diese Adresse liegt leider außerhalb unseres Liefergebiets. Du kannst gern bei uns abholen.', 'lat' => $lat, 'lng' => $lng); }
	if (count($deliverable) === 1) {
		$c = $deliverable[0];
		return array('ok' => true, 'zone' => $c['zone'], 'lat' => $c['lat'], 'lng' => $c['lng'], 'postcode' => $c['postcode'], 'road' => $c['road']);
	}
	$out = array();
	foreach ($deliverable as $c) {
		$min = $c['zone']['min_order_cents'] > 0 ? $c['zone']['min_order_cents'] : shop_cents(shop_setting('min_order_delivery'));
		$out[] = array('road' => $c['road'], 'postcode' => $c['postcode'], 'city' => trim($city), 'lat' => $c['lat'], 'lng' => $c['lng'],
			'zone' => array('id' => $c['zone']['id'], 'name' => $c['zone']['name'], 'fee' => $c['zone']['fee_cents'], 'min' => $min));
	}
	return array('ok' => false, 'reason' => 'ambiguous', 'error' => 'Mehrere passende Adressen gefunden. Bitte wähle die richtige aus.', 'candidates' => $out);
}

// same contract as shop_find_zone(), but for a what3words code instead of street/zip/city
function shop_find_zone_w3w($words) {
	if (trim((string)$words, "/ \t\n\r\0\x0B") === '') { return array('ok' => false, 'reason' => 'not_found', 'error' => 'Bitte einen what3words-Code eingeben.'); }
	$pos = shop_geocode_w3w($words);
	if (!$pos) { return array('ok' => false, 'reason' => 'not_found', 'error' => 'Dieser what3words-Code konnte nicht gefunden werden. Bitte prüfe die Schreibweise.'); }
	$zone = shop_zone_for_point($pos[0], $pos[1]);
	if (!$zone) { return array('ok' => false, 'reason' => 'outside_zone', 'error' => 'Diese Adresse liegt leider außerhalb unseres Liefergebiets. Du kannst gern bei uns abholen.', 'lat' => $pos[0], 'lng' => $pos[1]); }
	return array('ok' => true, 'zone' => $zone, 'lat' => $pos[0], 'lng' => $pos[1], 'words' => trim((string)$words, "/ \t\n\r\0\x0B"));
}

// ---- light menu for the list page: products per category with a flag for "has choices" and the lowest price
function shop_menu() {
	shop_ensure_schema();
	$cats = fb_rows("SELECT id, name, description FROM ".fb_t('tp_shop_categories')." WHERE active = 1 ORDER BY sort, id");
	$prods = fb_rows("SELECT p.id, p.category_id, p.title, p.description, p.image_url, p.price_cents, p.configurator,
			(SELECT COUNT(*) FROM ".fb_t('tp_shop_variations')." v WHERE v.product_id = p.id) AS nvar,
			(SELECT MIN(v.price_cents) FROM ".fb_t('tp_shop_variations')." v WHERE v.product_id = p.id) AS vmin,
			(SELECT COUNT(*) FROM ".fb_t('tp_shop_product_groups')." pg WHERE pg.product_id = p.id) AS nmod
		FROM ".fb_t('tp_shop_products')." p WHERE p.active = 1 ORDER BY p.sort, p.id");
	$by = array();
	foreach ($prods as $p) { $by[(int)$p['category_id']][] = $p; }
	$out = array();
	foreach ($cats as $c) { if (!empty($by[(int)$c['id']])) { $c['products'] = $by[(int)$c['id']]; $out[] = $c; } }
	return $out;
}

// the full menu with every product's variations/modifier groups embedded (web/content/orders_pos.page.php
// needs the whole picker up front, not a round trip per tapped product like the guest page does)
function shop_pos_catalog() {
	$menu = shop_menu();
	foreach ($menu as &$cat) {
		foreach ($cat['products'] as &$p) {
			$full = shop_catalog_product((int)$p['id']);
			$p['variations'] = $full ? $full['variations'] : array();
			$p['groups'] = $full ? $full['groups'] : array();
			$p['price'] = $full ? $full['price'] : (int)$p['price_cents'];
		}
		unset($p);
	}
	unset($cat);
	return $menu;
}

// ---- orders
const SHOP_STATUS_LABEL = array('pending' => 'wartet auf Zahlung', 'new' => 'neu', 'accepted' => 'angenommen', 'preparing' => 'in Zubereitung', 'ready' => 'fertig',
	'delivering' => 'unterwegs', 'done' => 'erledigt', 'cancelled' => 'storniert', 'failed' => 'fehlgeschlagen');

// the number of the day of an order is MAX + 1: two requests at the same moment would both read the same MAX, so the reading and the INSERT happen under one lock
function shop_dayno_lock() { mysqli_query(fb_db(), "SELECT GET_LOCK('".fb_t('tp_shop_dayno')."', 10)"); }
function shop_dayno_unlock() { mysqli_query(fb_db(), "SELECT RELEASE_LOCK('".fb_t('tp_shop_dayno')."')"); }
function shop_order_number() {
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	for ($try = 0; $try < 10; $try++) {
		$n = '';
		for ($i = 0; $i < 6; $i++) { $n .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
		if (!fb_row("SELECT 1 AS x FROM ".fb_t('tp_shop_orders')." WHERE number = ?", 's', array($n))) { return $n; }
	}
	return strtoupper(bin2hex(random_bytes(3)));
}

function shop_phone_ok($p) { $d = preg_replace('/\D/', '', (string)$p); return preg_match('/^[+0-9 ()\/.\-]+$/', (string)$p) && strlen($d) >= 6 && strlen($d) <= 16; }

/*
 * Check everything and store the order. $in: type, lines, name, phone, email, street, zip, city (or words, a
 * what3words code, instead of street/zip/city), address_note, when ('asap' or 'Y-m-d H:i'),
 * payment ('mollie' | 'cash' | 'card_door'), tip (cents), note, ip. Returns array(ok, error) or array(ok, order (row), items).
 * A guest who pays online gets status 'pending' until the payment arrived; the kitchen sees an order from 'new' on.
 */
function shop_create_order($in) {
	shop_ensure_schema();
	if (!shop_flag('accepting')) { return array('ok' => false, 'error' => 'Wir nehmen gerade keine Bestellungen an.'); }
	$type = (isset($in['type']) && $in['type'] === 'pickup') ? 'pickup' : 'delivery';
	$pz = shop_paused($type);
	if ($pz['paused']) { return array('ok' => false, 'error' => ($type === 'pickup' ? 'Die Abholung' : 'Die Lieferung').' ist gerade pausiert'.($pz['until'] ? ' (bis etwa '.date('H:i', $pz['until']).' Uhr)' : '').'. Bitte versuche es später noch einmal'.($type === 'pickup' ? ' oder bestelle zur Lieferung.' : ' oder bestelle zur Abholung.')); }
	$lines = (isset($in['lines']) && is_array($in['lines'])) ? array_slice($in['lines'], 0, 60) : array();
	if (!$lines) { return array('ok' => false, 'error' => 'Dein Warenkorb ist leer.'); }
	$items = array(); $sub = 0;
	foreach ($lines as $l) {
		$r = shop_price_line(is_array($l) ? $l : array());
		if (!$r['ok']) { return array('ok' => false, 'error' => $r['error']); }
		$items[] = $r['line']; $sub += $r['line']['line_cents'];
	}
	// contact
	$name = mb_substr(trim((string)(isset($in['name']) ? $in['name'] : '')), 0, 120);
	$phone = mb_substr(trim((string)(isset($in['phone']) ? $in['phone'] : '')), 0, 40);
	$email = trim((string)(isset($in['email']) ? $in['email'] : ''));
	if (mb_strlen($name) < 2) { return array('ok' => false, 'error' => 'Bitte gib deinen Namen an.'); }
	if (!shop_phone_ok($phone)) { return array('ok' => false, 'error' => 'Bitte gib eine Telefonnummer an, unter der wir dich erreichen.'); }
	if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Die E-Mail-Adresse sieht nicht richtig aus.'); }
	// address and fee
	$fee = 0; $zoneId = null; $lat = null; $lng = null; $street = $zip = $city = ''; $addrNote = '';
	$min = ($type === 'delivery') ? shop_cents(shop_setting('min_order_delivery')) : shop_cents(shop_setting('min_order_pickup'));
	if ($type === 'delivery') {
		$addrNote = mb_substr(trim((string)(isset($in['address_note']) ? $in['address_note'] : '')), 0, 200);
		$words = mb_substr(trim((string)(isset($in['words']) ? $in['words'] : '')), 0, 40);
		if ($words !== '') {
			// no real street: store the what3words code where street/zip/city are normally shown
			// (bon, driver view, admin lists already just print street/zip/city, unchanged here)
			// and keep zone_for_point already found a driver-navigable lat/lng
			$z = shop_find_zone_w3w($words);
			if (!$z['ok']) { return array('ok' => false, 'error' => $z['error']); }
			$street = 'what3words: '.$z['words']; $zip = ''; $city = '';
		} else {
			$street = mb_substr(trim((string)(isset($in['street']) ? $in['street'] : '')), 0, 160);
			$zip = mb_substr(trim((string)(isset($in['zip']) ? $in['zip'] : '')), 0, 10);
			$city = mb_substr(trim((string)(isset($in['city']) ? $in['city'] : '')), 0, 80);
			$z = shop_find_zone($street, $zip, $city);
			if (!$z['ok']) { return array('ok' => false, 'error' => $z['error']); }
			// the browser already auto-fills the PLZ from the same lookup once an address is found
			// (see order/shop.js, checkout.js) - this only catches a guest who reached the endpoint
			// without that step, by falling back to what the geocoder itself matched
			if ($zip === '') { $zip = !empty($z['postcode']) ? $z['postcode'] : ''; }
			if ($zip === '') { return array('ok' => false, 'error' => 'Bitte gib deine Postleitzahl an.'); }
		}
		$fee = $z['zone']['fee_cents']; $zoneId = $z['zone']['id']; $lat = $z['lat']; $lng = $z['lng'];
		if ($z['zone']['min_order_cents'] > 0) { $min = $z['zone']['min_order_cents']; }
	}
	if ($sub < $min) { return array('ok' => false, 'error' => 'Der Mindestbestellwert ist '.shop_money($min).'.'); }
	// time
	$when = isset($in['when']) ? (string)$in['when'] : 'asap';
	$scheduled = null; $eta = null;
	if ($when === 'asap') {
		$st = shop_state($type);
		if (!$st['open']) { return array('ok' => false, 'error' => 'Zurzeit ist keine Bestellung für sofort möglich. Bitte wähle eine Zeit.'); }
		$eta = date('Y-m-d H:i:s', time() + $st['lead'] * 60);
	} else {
		if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $when, $m)) { return array('ok' => false, 'error' => 'Bitte wähle eine Zeit.'); }
		if (!in_array($m[2], shop_slots($type, $m[1]), true)) { return array('ok' => false, 'error' => 'Diese Zeit ist leider nicht mehr frei. Bitte wähle eine andere.'); }
		$scheduled = $when.':00'; $eta = $scheduled;
	}
	// payment and tip
	$pay = isset($in['payment']) ? (string)$in['payment'] : '';
	$allowed = array('mollie' => shop_flag('allow_online'), 'cash' => shop_flag('allow_cash'), 'card_door' => shop_flag('allow_card_door'));
	if (empty($allowed[$pay])) { return array('ok' => false, 'error' => 'Diese Zahlungsart ist nicht möglich.'); }
	$tip = shop_flag('tip_enabled') ? max(0, min(5000, (int)(isset($in['tip']) ? $in['tip'] : 0))) : 0;
	// coupon: checked again here (the guest's view was only a preview), the discount is never taken from the browser
	$coupon = null; $discount = 0;
	$codeIn = shop_coupon_normalize(isset($in['coupon']) ? $in['coupon'] : '');
	// a voucher of the stamp card is used up automatically, unless the guest entered a code of his own
	$auto = false;
	// signed in: the keys of the account count, not what was typed into the form (set by api.php from the session, never from the browser)
	$gkeys = (!empty($in['acc_id']) && !empty($in['acc_keys']) && is_array($in['acc_keys'])) ? $in['acc_keys'] : shop_coupon_guest_keys($phone, $email);
	if ($codeIn === '') { $av = shop_stamp_voucher($gkeys); if ($av) { $codeIn = $av['code']; $auto = true; } }
	if ($codeIn !== '') {
		$cr = shop_coupon_check($codeIn, $type, $sub, $gkeys);
		if (!$cr['ok'] && !$auto) { return array('ok' => false, 'error' => $cr['error']); }
		if ($cr['ok']) { $coupon = $cr['coupon']; $discount = $cr['discount']; }
	}
	$total = $sub - $discount + $fee + $tip;
	// a visitor may not flood the kitchen
	$ip = substr(hash('sha256', (isset($in['ip']) ? $in['ip'] : '').'|myseat-shop'), 0, 16);
	$recent = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_orders')." WHERE ip_hash = ? AND created_at > ?", 'ss', array($ip, date('Y-m-d H:i:s', time() - 600)));
	if ($recent && (int)$recent['n'] >= 4) { return array('ok' => false, 'error' => 'Es sind kurz hintereinander mehrere Bestellungen eingegangen. Bitte ruf uns an.'); }

	$db = fb_db();
	$test = shop_flag('test_mode') ? 1 : 0;
	// a real order uses up one redemption before the order exists (two guests cannot take the last one together); test orders do not
	if ($coupon && !$test && !shop_coupon_reserve($coupon['id'])) { return array('ok' => false, 'error' => 'Dieser Gutschein wurde gerade eingelöst und ist jetzt aufgebraucht.'); }
	$token = bin2hex(random_bytes(16));
	$number = shop_order_number();
	$today = date('Y-m-d');
	shop_dayno_lock(); // two orders arriving together (n8n imports, a phone order) must not get the same number of the day
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$status = ($pay === 'mollie') ? 'pending' : 'new';
	$now = date('Y-m-d H:i:s');
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, scheduled_at, eta_at, customer_name, phone, email, street, zip, city, address_note, lat, lng, zone_id,
		 ip_hash, subtotal_cents, fee_cents, tip_cents, total_cents, payment_method, payment_status, note, lang, is_test, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, 'de', ?, ?, ?)",
		'ssisssssssssss'.'sddisiiiississ', array($token, $number, $dayNo, $today, $type, $status, $scheduled, $eta, $name, $phone, $email, $street, $zip, $city, $addrNote,
			$lat === null ? 0 : $lat, $lng === null ? 0 : $lng, $zoneId === null ? 0 : $zoneId, $ip, $sub, $fee, $tip, $total, $pay,
			mb_substr(trim((string)(isset($in['note']) ? $in['note'] : '')), 0, 500), $test, $now, $now));
	if (!$ok) {
		if ($coupon && !$test) { shop_coupon_unreserve($coupon['id']); }
		return array('ok' => false, 'error' => 'Die Bestellung konnte nicht gespeichert werden. Bitte versuche es noch einmal.');
	}
	$id = (int)mysqli_insert_id($db); shop_dayno_unlock();
	if (!empty($in['acc_id'])) { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET account_id = ? WHERE id = ?", 'ii', array((int)$in['acc_id'], $id)); }
	if ($coupon) {
		fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET coupon_code = ?, discount_cents = ? WHERE id = ?", 'sii', array($coupon['code'], $discount, $id));
		if (!$test) {
			$gk = $gkeys;
			fb_exec("INSERT INTO ".fb_t('tp_shop_coupon_uses')." (coupon_id, order_id, guest_key, guest_key2, discount_cents, created_at) VALUES (?, ?, ?, ?, ?, NOW())", 'iissi', array((int)$coupon['id'], $id, $gk[0], $gk[1], $discount));
		}
	}
	foreach ($items as $it) {
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, variation_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			'iiisssiiis', array($id, $it['product_id'], $it['vid'] > 0 ? $it['vid'] : null, $it['title'], $it['variation'], json_encode($it['options'], JSON_UNESCAPED_UNICODE), $it['qty'], $it['unit_cents'], $it['line_cents'], $it['note']));
	}
	shop_log($id, 'created', $pay);
	return array('ok' => true, 'order' => shop_order($id), 'items' => shop_order_items($id));
}

// ---- till (web/content/orders_pos.page.php): what the caller can be told, who the caller is, the last orders of the till
// how long an order takes now: the base from the settings (delivery or pickup time) plus a few minutes for every order in the kitchen above the free number
// (settings quote_free_orders / quote_per_order_min), rounded up to 5 minutes; whether the shop takes orders now; times to pick from. The till can always
// take an order - the state is information for the person on the phone, not a lock.
function shop_pos_quote($type) {
	shop_ensure_schema();
	$type = $type === 'pickup' ? 'pickup' : 'delivery'; $now = time();
	$base = max(1, ($type === 'delivery') ? (int)shop_setting('eta_delivery_min') : (int)shop_setting('lead_pickup_min'));
	$free = max(0, (int)shop_setting('quote_free_orders')); $per = max(0, (int)shop_setting('quote_per_order_min'));
	$row = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ? AND is_test = 0 AND status IN ('new', 'accepted', 'preparing')", 's', array(date('Y-m-d')));
	$backlog = (int)$row['n']; $extra = min(40, max(0, $backlog - $free) * $per);
	$min = (int)(ceil(($base + $extra) / 5) * 5);
	$st = shop_state($type, $now);
	$windows = array(); foreach (shop_windows($type, $now) as $w) { $windows[] = array(date('H:i', $w[0]), date('H:i', $w[1])); }
	$step = max(5, (int)shop_setting('slot_min')) * 60; $t = (int)(ceil(($now + $min * 60) / $step) * $step); $slots = array();
	for ($i = 0; $i < 8; $i++, $t += $step) { $slots[] = date('Y-m-d H:i', $t); }
	return array('type' => $type, 'min' => $min, 'base' => $base, 'extra' => $extra, 'backlog' => $backlog, 'free' => $free, 'eta' => date('H:i', $now + $min * 60),
		'open' => !empty($st['open']), 'paused' => !empty($st['paused']), 'paused_until' => !empty($st['paused_until']) ? date('H:i', $st['paused_until']) : '',
		'until' => !empty($st['until']) ? date('H:i', $st['until']) : '', 'next' => !empty($st['next']) ? date('d.m. H:i', $st['next']) : '', 'note' => isset($st['note']) ? (string)$st['note'] : '',
		'windows' => $windows, 'slots' => $slots, 'min_order_cents' => $type === 'delivery' ? shop_cents(shop_setting('min_order_delivery')) : shop_cents(shop_setting('min_order_pickup')));
}
// who is calling: the orders of this number (name, address, what was ordered), how often, the favourite dishes and an order that is open right now
function shop_pos_customer($phone) {
	$phone = trim((string)$phone);
	if ($phone === '' || !shop_phone_ok($phone)) { return array('n' => 0, 'orders' => array(), 'open' => array(), 'fav' => array()); }
	$orders = shop_guest_history($phone, 5);
	$n = (int)fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_orders')." WHERE phone = ? AND is_test = 0 AND status <> 'cancelled'", 's', array($phone))['n'];
	$open = array_map(function ($r) { return array('id' => (int)$r['id'], 'day_no' => (int)$r['day_no'], 'status' => $r['status'], 'type' => $r['type'], 'time' => substr($r['created_at'], 11, 5)); },
		fb_rows("SELECT id, day_no, status, type, created_at FROM ".fb_t('tp_shop_orders')." WHERE phone = ? AND is_test = 0 AND order_date = ? AND status IN ('new', 'accepted', 'preparing', 'ready', 'delivering') ORDER BY id DESC LIMIT 3", 'ss', array($phone, date('Y-m-d'))));
	$fav = array_map(function ($r) { return (int)$r['product_id']; }, fb_rows("SELECT i.product_id, SUM(i.qty) AS q FROM ".fb_t('tp_shop_order_items')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id
		WHERE o.phone = ? AND o.is_test = 0 AND o.status <> 'cancelled' AND i.product_id IS NOT NULL GROUP BY i.product_id ORDER BY q DESC LIMIT 8", 's', array($phone)));
	// what the customer backend knows about the caller: the note and marks (they must be seen before saying yes), the stamp card, a voucher
	$cust = null;
	if (function_exists('shop_cust_by_phone') || is_file(__DIR__.'/shop_customers.class.php')) {
		require_once __DIR__.'/shop_customers.class.php';
		$c = shop_cust_by_phone($phone);
		if ($c) { $labels = shop_cust_flag_labels(); $cust = array('id' => $c['id'], 'note' => $c['note'], 'flags' => array_map(function ($f) use ($labels) { return isset($labels[$f]) ? $labels[$f] : $f; }, $c['flags']), 'warn' => (bool)array_intersect($c['flags'], array('vorsicht', 'allergie', 'passend')) || $c['blocked'],
			'stamps' => $c['stamps'], 'goal' => shop_stamp_cfg()['goal'], 'account' => (bool)$c['accounts'], 'voucher' => $c['voucher_value'], 'blocked' => $c['blocked']); }
	}
	return array('n' => $n, 'orders' => $orders, 'open' => $open, 'fav' => $fav, 'cust' => $cust);
}
// the dishes ordered most in the last 30 days (product ids), for the first tab of the till
function shop_pos_popular($limit = 12) {
	return array_map(function ($r) { return (int)$r['product_id']; }, fb_rows("SELECT i.product_id, SUM(i.qty) AS q FROM ".fb_t('tp_shop_order_items')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id
		WHERE o.is_test = 0 AND o.status <> 'cancelled' AND o.created_at > ? AND i.product_id IS NOT NULL GROUP BY i.product_id ORDER BY q DESC LIMIT ".(int)$limit, 's', array(date('Y-m-d H:i:s', time() - 30 * 86400))));
}
// the last orders taken at the till today; one that nobody has accepted yet (status new) and is at most 10 minutes old can still be taken back
function shop_pos_recent($limit = 6) {
	$rows = fb_rows("SELECT id, day_no, number, type, status, customer_name, total_cents, adjust_note, scheduled_at, eta_at, created_at FROM ".fb_t('tp_shop_orders')." WHERE source = 'phone' AND is_test = 0 AND order_date = ? ORDER BY id DESC LIMIT ".(int)$limit, 's', array(date('Y-m-d')));
	return array_map(function ($r) {
		$due = $r['scheduled_at'] ?: $r['eta_at'];
		return array('id' => (int)$r['id'], 'day_no' => (int)$r['day_no'], 'number' => $r['number'], 'type' => $r['type'], 'status' => $r['status'], 'name' => $r['customer_name'], 'total' => (int)$r['total_cents'], 'adjust' => (string)$r['adjust_note'],
			'time' => substr($r['created_at'], 11, 5), 'due' => $due ? substr($due, 11, 5) : '', 'scheduled' => !empty($r['scheduled_at']), 'can_cancel' => $r['status'] === 'new' && time() - strtotime($r['created_at']) <= 600);
	}, $rows);
}
function shop_pos_cancel($id, $by) {
	$o = fb_row("SELECT id, source, status, created_at FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$id));
	if (!$o || $o['source'] !== 'phone') { return array('ok' => false, 'error' => 'Diese Bestellung wurde nicht an der Kasse erfasst.'); }
	if ($o['status'] !== 'new' || time() - strtotime($o['created_at']) > 600) { return array('ok' => false, 'error' => 'Die Bestellung ist schon angenommen oder zu alt. Bitte in der Disposition stornieren.'); }
	return shop_set_status((int)$id, 'cancelled', $by.': an der Kasse zurückgenommen') ? array('ok' => true) : array('ok' => false, 'error' => 'Das hat nicht geklappt.');
}
// district and driving way of a confirmed address (for the line under the address in the till); '' / null when unknown
function shop_pos_zone_info($lat, $lng, $street, $zip) {
	$origin = shop_origin(); $km = null; $min = null;
	$osm = shop_suburb_osm((float)$lat, (float)$lng);
	if ($origin) { $rt = shop_route_osrm($origin[0], $origin[1], (float)$lat, (float)$lng); if ($rt) { $km = round($rt[0] / 1000, 1); $min = max(1, (int)round($rt[1] / 60)); } }
	return array('suburb' => shop_suburb_label($street, $zip, $osm === null ? '' : $osm), 'km' => $km, 'min' => $min);
}

/*
 * A real order the staff types in on the guest's behalf (phone call, or an order from a delivery portal that
 * isn't technically connected) - web/content/orders_pos.page.php. Reuses the same pricing (shop_price_line())
 * and zone lookup (shop_find_zone()) as the guest checkout, but skips every guest-only guard: the shop may be
 * "closed" for self-service and this can still be typed in, there is no minimum order value, no time-slot
 * picking (always "as soon as possible"), no coupon, no tip, no online payment, and no per-IP rate limit (there
 * is no guest IP - it's the till). $in: type, lines, name, phone, email, street, zip, city, address_note,
 * payment ('cash'|'card_door'), note. Starts as 'accepted' (accepted_at now), source 'phone', is_test 0: the person at the till has already told the caller the time (or the wish time was chosen), so nobody has to
 * accept it again on the dispatch screen; it is on the kitchen monitor and in the dispatch work column at once.
 */
// the "-10 %" and "+ Aufschlag" keys of the till: the browser only says on/off, the amount of the surcharge and a reason from a fixed list; the amounts are worked out here.
// The discount is a percentage of the goods plus the delivery fee, rounded to the cent and never more than that; the surcharge is added on top (euro, up to 50 EUR).
function shop_pos_reasons($kind) {
	return $kind === 'surcharge' ? array('Verpackung', 'Sonderfahrt', 'Nachtzuschlag', 'Sonstiges') : array('Stammgast', 'Reklamation', 'Mitarbeiter', 'Sonstiges');
}
function shop_pos_adjust($sub, $fee, $in) {
	$pct = max(1, min(50, (int)shop_setting('pos_discount_pct')));
	$discount = 0; $surcharge = 0; $notes = array();
	if (!empty($in['discount'])) {
		$discount = min($sub + $fee, (int)round(($sub + $fee) * $pct / 100));
		$why = isset($in['discount_reason']) ? (string)$in['discount_reason'] : '';
		$notes[] = 'Rabatt '.$pct.' % -'.shop_money($discount).(in_array($why, shop_pos_reasons('discount'), true) ? ' ('.$why.')' : ' (ohne Angabe)');
	}
	if (!empty($in['surcharge'])) {
		$surcharge = (int)$in['surcharge'];
		if ($surcharge < 1 || $surcharge > 5000) { return array('ok' => false, 'error' => 'Der Aufschlag kann zwischen 0,01 und 50,00 Euro liegen.'); }
		$why = isset($in['surcharge_reason']) ? (string)$in['surcharge_reason'] : '';
		$notes[] = 'Aufschlag +'.shop_money($surcharge).(in_array($why, shop_pos_reasons('surcharge'), true) ? ' ('.$why.')' : ' (ohne Angabe)');
	}
	return array('ok' => true, 'discount' => $discount, 'surcharge' => $surcharge, 'note' => implode('; ', $notes));
}
function shop_create_manual_order($in) {
	shop_ensure_schema();
	$type = (isset($in['type']) && $in['type'] === 'pickup') ? 'pickup' : 'delivery';
	$lines = (isset($in['lines']) && is_array($in['lines'])) ? $in['lines'] : array();
	if (!$lines) { return array('ok' => false, 'error' => 'Der Warenkorb ist leer.'); }
	// a silent array_slice() here used to drop item 61+ while still returning "angelegt" - staff must split the
	// order instead of unknowingly serving an order the guest thinks is complete
	if (count($lines) > 60) { return array('ok' => false, 'error' => 'Zu viele Positionen in einer Bestellung (maximal 60). Bitte in zwei Bestellungen aufteilen.'); }
	$items = array(); $sub = 0;
	foreach ($lines as $l) {
		$r = shop_price_line(is_array($l) ? $l : array());
		if (!$r['ok']) { return array('ok' => false, 'error' => $r['error']); }
		$items[] = $r['line']; $sub += $r['line']['line_cents'];
	}
	$name = mb_substr(trim((string)(isset($in['name']) ? $in['name'] : '')), 0, 120);
	$phone = mb_substr(trim((string)(isset($in['phone']) ? $in['phone'] : '')), 0, 40);
	$email = trim((string)(isset($in['email']) ? $in['email'] : ''));
	// a delivery always needs a number (the driver may have to call); a guest picking the order up in person
	// right now can skip it, and the name too, if the till says so (the order is then called "Abholer")
	$noPhone = ($type === 'pickup' && !empty($in['no_phone']));
	if (mb_strlen($name) < 2) {
		if (!$noPhone) { return array('ok' => false, 'error' => 'Bitte einen Namen angeben.'); }
		$name = 'Abholer';
	}
	if (!$noPhone && !shop_phone_ok($phone)) { return array('ok' => false, 'error' => 'Bitte eine Telefonnummer angeben.'); }
	if ($noPhone) { $phone = ''; }
	if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Die E-Mail-Adresse sieht nicht richtig aus.'); }
	$fee = 0; $zoneId = null; $lat = null; $lng = null; $street = $zip = $city = ''; $addrNote = '';
	if ($type === 'delivery') {
		$addrNote = mb_substr(trim((string)(isset($in['address_note']) ? $in['address_note'] : '')), 0, 200);
		$street = mb_substr(trim((string)(isset($in['street']) ? $in['street'] : '')), 0, 160);
		$zip = mb_substr(trim((string)(isset($in['zip']) ? $in['zip'] : '')), 0, 10);
		$city = mb_substr(trim((string)(isset($in['city']) ? $in['city'] : '')), 0, 80);
		$z = shop_find_zone($street, $zip, $city);
		if (!$z['ok']) { return $z; } // 'ambiguous' comes with candidates - the POS shows the same pick list as checkout.js
		if ($zip === '') { $zip = !empty($z['postcode']) ? $z['postcode'] : ''; }
		$fee = $z['zone']['fee_cents']; $zoneId = $z['zone']['id']; $lat = $z['lat']; $lng = $z['lng'];
	}
	$pay = isset($in['payment']) ? (string)$in['payment'] : '';
	if (!in_array($pay, array('cash', 'card_door'), true)) { return array('ok' => false, 'error' => 'Bitte eine Zahlart wählen.'); }
	$adj = shop_pos_adjust($sub, $fee, $in);
	if (!$adj['ok']) { return $adj; }
	$total = $sub + $fee + $adj['surcharge'] - $adj['discount'];
	// a wish time (Y-m-d H:i) stands as the caller chose it; "as soon as possible" is the time the till told the caller (shop_pos_quote)
	$scheduled = null; $eta = date('Y-m-d H:i:s', time() + shop_pos_quote($type)['min'] * 60);
	if (!empty($in['when'])) {
		$w = strtotime((string)$in['when']);
		if ($w === false || $w < time() + 120 || $w > time() + 3 * 86400) { return array('ok' => false, 'error' => 'Die Wunschzeit liegt in der Vergangenheit oder zu weit voraus.'); }
		$scheduled = date('Y-m-d H:i:00', $w); $eta = $scheduled;
	}
	$payWith = ($pay === 'cash' && !empty($in['pay_with']) && (int)$in['pay_with'] >= $total) ? min(100000, (int)$in['pay_with']) : null;
	$token = bin2hex(random_bytes(16));
	$number = shop_order_number();
	$today = date('Y-m-d');
	shop_dayno_lock(); // two orders arriving together (n8n imports, a phone order) must not get the same number of the day
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$now = date('Y-m-d H:i:s');
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, scheduled_at, eta_at, customer_name, phone, email, street, zip, city, address_note, lat, lng, zone_id,
		 ip_hash, subtotal_cents, fee_cents, tip_cents, total_cents, payment_method, payment_status, note, lang, source, is_test, created_at, updated_at, pay_with_cents, discount_cents, surcharge_cents, adjust_note, accepted_at)
		VALUES (?, ?, ?, ?, ?, 'accepted', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, 0, ?, ?, 'open', ?, 'de', 'phone', 0, ?, ?, ?, ?, ?, ?, ?)",
		'ssisss'.'s'.'sssssss'.'ddi'.'iii'.'ssss'.'i'.'iis'.'s', array($token, $number, $dayNo, $today, $type, $scheduled, $eta, $name, $phone, $email, $street, $zip, $city, $addrNote,
			$lat === null ? 0 : $lat, $lng === null ? 0 : $lng, $zoneId === null ? 0 : $zoneId, $sub, $fee, $total, $pay,
			mb_substr(trim((string)(isset($in['note']) ? $in['note'] : '')), 0, 500), $now, $now, $payWith, $adj['discount'], $adj['surcharge'], $adj['note'], $now));
	if (!$ok) { shop_dayno_unlock(); return array('ok' => false, 'error' => 'Die Bestellung konnte nicht gespeichert werden. Bitte versuche es noch einmal.'); }
	$id = (int)mysqli_insert_id(fb_db()); shop_dayno_unlock();
	foreach ($items as $it) {
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, variation_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			'iiisssiiis', array($id, $it['product_id'], $it['vid'] > 0 ? $it['vid'] : null, $it['title'], $it['variation'], json_encode($it['options'], JSON_UNESCAPED_UNICODE), $it['qty'], $it['unit_cents'], $it['line_cents'], $it['note']));
	}
	shop_log($id, 'created', 'phone: '.$pay.($adj['note'] !== '' ? ' - '.$adj['note'] : ''));
	return array('ok' => true, 'order' => shop_order($id));
}

// the guest's last few orders at this phone number, each with its items - lets the POS offer "diese Bestellung
// übernehmen" for a returning caller without a separate guest table (the order history already has everything)
function shop_guest_history($phone, $limit = 5) {
	$phone = trim((string)$phone);
	if ($phone === '' || !shop_phone_ok($phone)) { return array(); }
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE phone = ? AND is_test = 0 ORDER BY created_at DESC LIMIT ?", 'si', array($phone, (int)$limit));
	$out = array();
	foreach ($rows as $r) {
		$out[] = array(
			'id' => (int)$r['id'], 'created' => substr($r['created_at'], 0, 16), 'type' => $r['type'],
			'name' => $r['customer_name'], 'street' => $r['street'], 'zip' => $r['zip'], 'city' => $r['city'], 'address_note' => $r['address_note'],
			'total' => (int)$r['total_cents'],
			'items' => array_map(function ($it) {
				return array('product_id' => $it['product_id'] !== null ? (int)$it['product_id'] : null, 'title' => $it['title'], 'variation' => $it['variation'],
					'options' => is_array($it['options']) ? $it['options'] : (json_decode((string)$it['options'], true) ?: array()), 'qty' => (int)$it['qty'], 'note' => $it['note']); // shop_order_items() has decoded them already
			}, shop_order_items((int)$r['id'])),
		);
	}
	return $out;
}

// a test order for trying the monitors and the printing (needs neither the shop to be open nor a payment): marked as test,
// fake guest, a few dishes with options and notes, status "new" so it shows up for the dispatch first
function shop_create_demo_order($type, $street = '', $zip = '', $city = '') {
	shop_ensure_schema();
	$type = ($type === 'pickup') ? 'pickup' : 'delivery';
	$today = date('Y-m-d'); $now = date('Y-m-d H:i:s');
	$lines = array(
		array('Pizza Margherita', 'groß 32 cm', array('Extra Käse', 'Oliven'), 2, 1190, 'gut durchgebacken'),
		array('Spaghetti Bolognese', '', array(), 1, 1090, ''),
		array('Cola 0,33 l', '', array(), 2, 350, ''),
	);
	$sub = 0; foreach ($lines as $l) { $sub += $l[3] * $l[4]; }
	// a real coordinate (given address, or the restaurant's own as a fallback - it usually falls inside its
	// own delivery zone) rather than 0/0 - without one, the guest's status page has nothing to put on the
	// map at all, and the order never resolves a zone either, so it couldn't be tried from the driver app's
	// open-delivery list
	$lat = 0; $lng = 0; $zoneId = 0; $fee = ($type === 'delivery') ? 250 : 0;
	$dStreet = 'Teststraße 1'; $dZip = '31134'; $dCity = 'Hildesheim';
	if ($type === 'delivery') {
		$street = trim((string)$street); $zip = trim((string)$zip); $city = trim((string)$city);
		$point = null;
		if ($street !== '') {
			$g = shop_geocode($street, $zip, $city !== '' ? $city : 'Hildesheim');
			if ($g) { $point = array($g[0], $g[1]); $dStreet = $street; $dZip = $g[2] ? $g[2] : $zip; $dCity = $city !== '' ? $city : 'Hildesheim'; }
		}
		if (!$point) { $point = shop_origin(); }
		if ($point) {
			$lat = $point[0]; $lng = $point[1];
			$zone = shop_zone_for_point($lat, $lng);
			if ($zone) { $zoneId = $zone['id']; $fee = $zone['fee_cents']; }
		}
	}
	$total = $sub + $fee;
	shop_dayno_lock(); // two orders arriving together (n8n imports, a phone order) must not get the same number of the day
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, scheduled_at, eta_at, customer_name, phone, email, street, zip, city, address_note, lat, lng, zone_id,
		 ip_hash, subtotal_cents, fee_cents, tip_cents, total_cents, payment_method, payment_status, note, lang, is_test, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, 'new', NULL, ?, 'Test Kunde', '05121 000000', '', ?, ?, ?, ?, ?, ?, ?, '', ?, ?, 0, ?, 'cash', 'open', ?, 'de', 1, ?, ?)",
		'ssisss'.'ssss'.'ddi'.'iii'.'sss', array(bin2hex(random_bytes(16)), shop_order_number(), $dayNo, $today, $type, date('Y-m-d H:i:s', time() + 30 * 60),
			$type === 'delivery' ? $dStreet : '', $type === 'delivery' ? $dZip : '', $type === 'delivery' ? $dCity : '', $type === 'delivery' ? '2. Stock links' : '',
			$lat, $lng, $zoneId, $sub, $fee, $total, 'Testbestellung zum Ausprobieren des Drucks', $now, $now));
	if (!$ok) { return 0; }
	$id = (int)mysqli_insert_id(fb_db()); shop_dayno_unlock();
	foreach ($lines as $l) {
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)",
			'isssiiis', array($id, $l[0], $l[1], json_encode(array_map(function ($t) { return array('title' => $t, 'qty' => 1); }, $l[2]), JSON_UNESCAPED_UNICODE), $l[3], $l[4], $l[3] * $l[4], $l[5]));
	}
	shop_log($id, 'created', 'demo');
	return $id;
}

function shop_log($orderId, $event, $detail = '') {
	fb_exec("INSERT INTO ".fb_t('tp_shop_order_log')." (order_id, event, detail, at) VALUES (?, ?, ?, ?)", 'isss', array((int)$orderId, $event, mb_substr($detail, 0, 200), date('Y-m-d H:i:s')));
}
function shop_order($id) { return fb_row("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$id)); }
function shop_order_by_token($token) { return fb_row("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE token = ?", 's', array((string)$token)); }
function shop_order_items($id) {
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_order_items')." WHERE order_id = ? ORDER BY id", 'i', array((int)$id));
	foreach ($rows as &$r) { $r['options'] = json_decode((string)$r['options'], true) ?: array(); } unset($r);
	return $rows;
}

// staff or the payment provider move an order on. Timestamps are kept for the statistics.
function shop_set_status($id, $status, $by = '', $etaMinutes = 0) {
	if (!isset(SHOP_STATUS_LABEL[$status])) { return false; }
	$now = date('Y-m-d H:i:s');
	$set = "status = ?, updated_at = ?"; $types = 'ss'; $params = array($status, $now);
	// an order for a wish time keeps that time: the minutes staff give when accepting only make sense for "as soon as possible"
	$sched = ($status === 'accepted' && $etaMinutes > 0) ? fb_row("SELECT scheduled_at FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$id)) : null;
	if ($sched && $sched['scheduled_at']) { $etaMinutes = 0; }
	if ($status === 'accepted') { $set .= ", accepted_at = ?"; $types .= 's'; $params[] = $now; if ($etaMinutes > 0) { $set .= ", eta_at = ?"; $types .= 's'; $params[] = date('Y-m-d H:i:s', time() + $etaMinutes * 60); } }
	if ($status === 'ready') { $set .= ", ready_at = ?"; $types .= 's'; $params[] = $now; }
	if ($status === 'done' || $status === 'cancelled' || $status === 'failed') { $set .= ", done_at = ?"; $types .= 's'; $params[] = $now; }
	$types .= 'i'; $params[] = (int)$id;
	$st = fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET $set WHERE id = ?", $types, $params);
	if ($st) { shop_log($id, 'status', $status.($by !== '' ? ' ('.$by.')' : '')); shop_sms_status((int)$id, $status); if ($status === 'cancelled' || $status === 'failed') { shop_coupon_release((int)$id); } if ($status === 'done') { try { shop_stamp_award((int)$id); } catch (Throwable $e) { error_log('mySeat stamp: '.$e->getMessage()); } } }
	return (bool)$st;
}

// ---- Google Geocoding + what3words keys for the delivery-zone check (shop_geocode_google(),
// shop_geocode_w3w()). Stored encrypted the same way as the Mollie key (tp_shop_settings, backend
// UI in Einstellungen > Lieferservice); config.general.php's googlemap_key/what3wordsApiKey still
// work as a fallback for an operator who prefers editing the file directly.
function shop_google_key() {
	$blob = shop_setting('google_key');
	$k = $blob ? sms_decrypt($blob) : null;
	if ($k !== null && $k !== '') { return $k; }
	global $settings;
	return trim((string)(isset($settings['googlemap_key']) ? $settings['googlemap_key'] : ''));
}
function shop_google_key_info() {
	$k = shop_google_key();
	return array('set' => $k !== '', 'masked' => $k === '' ? '' : '••••'.substr($k, -4));
}
function shop_w3w_key() {
	$blob = shop_setting('w3w_key');
	$k = $blob ? sms_decrypt($blob) : null;
	if ($k !== null && $k !== '') { return $k; }
	global $settings;
	return trim((string)(isset($settings['what3wordsApiKey']) ? $settings['what3wordsApiKey'] : ''));
}
function shop_w3w_key_info() {
	$k = shop_w3w_key();
	return array('set' => $k !== '', 'masked' => $k === '' ? '' : '••••'.substr($k, -4));
}

// ---- sipgate caller-id: a webhook (order/sipgate_webhook.php, no login - sipgate.io authenticates itself with
// a shared secret in the webhook URL, see config/sipgate_webhook_key.php) records the number of every
// incoming call; the POS page (web/content/orders_pos.page.php) polls shop_last_call() to show a banner for a
// call still fresh enough to matter. No missed-call tracking, no history - this is a short-lived screen-pop, not a log.
// Best-effort "+" reconstruction for a caller-id string, scoped to the same DE/AT range sms_normalize_phone()
// supports - but unlike that function, this never rejects a landline or an already-odd number: all it needs
// to do is give every downstream exact-string match (POS "Übernehmen" + guest history, the reservation form's
// call banner) one consistent format, not validate deliverability. Whatever it can't confidently reconstruct
// (a bare leading "0" with no way to know the country, a non-DE/AT number, garbage) is returned untouched, so
// a call is never hidden from staff just because the number looks unusual.
function shop_normalize_caller_id($phone) {
	$raw = trim((string)$phone);
	$s = preg_replace('/[\s\-\/.()]/', '', $raw);
	if ($s === '' || !preg_match('/^\+?\d+$/', $s)) { return $raw; }
	if (strpos($s, '00') === 0) { $s = '+'.substr($s, 2); }
	elseif ($s[0] !== '+') {
		if (!preg_match('/^(49|43)\d{6,13}$/', $s)) { return $raw; }
		$s = '+'.$s;
	}
	// a dropped "+" sometimes keeps the national trunk "0" right after the country code (e.g. "+490151...")
	if (strpos($s, '+490') === 0) { $s = '+49'.substr($s, 4); }
	if (strpos($s, '+430') === 0) { $s = '+43'.substr($s, 4); }
	return $s;
}
function shop_record_incoming_call($phone) {
	shop_ensure_schema();
	$phone = mb_substr(trim((string)$phone), 0, 40);
	if ($phone === '') { return; }
	$phone = mb_substr(shop_normalize_caller_id($phone), 0, 40);
	fb_exec("INSERT INTO ".fb_t('tp_shop_calls')." (phone, received_at) VALUES (?, NOW())", 's', array($phone));
	fb_exec("DELETE FROM ".fb_t('tp_shop_calls')." WHERE received_at < ?", 's', array(date('Y-m-d H:i:s', time() - 3600)));
}
function shop_last_call($maxAgeSeconds = 60) {
	$r = fb_row("SELECT id, phone, received_at FROM ".fb_t('tp_shop_calls')." ORDER BY id DESC LIMIT 1");
	if (!$r || (time() - strtotime($r['received_at'])) > $maxAgeSeconds) { return null; }
	return array('id' => (int)$r['id'], 'phone' => $r['phone']);
}

// ---- Mollie (online payment). The key is stored encrypted (tp_shop_settings.mollie_key), a test_ key works in test mode.
function shop_mollie_key() {
	$blob = shop_setting('mollie_key');
	$k = $blob ? sms_decrypt($blob) : null;
	return ($k !== null && preg_match('/^(test|live)_[A-Za-z0-9]{20,}$/', $k)) ? $k : '';
}
function shop_mollie_info() {
	$k = shop_mollie_key();
	return array('set' => $k !== '', 'mode' => $k === '' ? '' : (strpos($k, 'test_') === 0 ? 'test' : 'live'), 'masked' => $k === '' ? '' : '••••'.substr($k, -4));
}
function shop_mollie_request($method, $path, $data = null) {
	$key = shop_mollie_key();
	if ($key === '') { return array('http' => 0, 'body' => null, 'error' => 'Kein Mollie-Schlüssel hinterlegt'); }
	$ch = curl_init('https://api.mollie.com/v2'.$path);
	$headers = array('Authorization: Bearer '.$key, 'Accept: application/json');
	$opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'mySeat-Lieferservice');
	if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($data); $headers[] = 'Content-Type: application/json'; }
	$opts[CURLOPT_HTTPHEADER] = $headers;
	curl_setopt_array($ch, $opts);
	$raw = curl_exec($ch);
	$errno = curl_errno($ch); $err = $errno ? curl_error($ch) : null;
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$j = is_string($raw) ? json_decode($raw, true) : null;
	return array('http' => $errno ? 0 : $http, 'body' => is_array($j) ? $j : null, 'error' => $err);
}
function shop_mollie_describe($r) {
	if ($r['http'] === 0) { return 'Mollie nicht erreichbar'.($r['error'] ? ' ('.$r['error'].')' : ''); }
	$detail = isset($r['body']['detail']) && is_string($r['body']['detail']) ? $r['body']['detail'] : '';
	return substr(($r['http'] === 401 ? 'Der Schlüssel wird von Mollie nicht akzeptiert' : ($detail !== '' ? $detail : 'Fehler')).' (HTTP '.$r['http'].')', 0, 250);
}
function shop_mollie_test() {
	$r = shop_mollie_request('GET', '/methods');
	if ($r['http'] !== 200) { return array('ok' => false, 'error' => shop_mollie_describe($r)); }
	$names = array();
	foreach ((array)($r['body']['_embedded']['methods'] ?? array()) as $m) { $names[] = $m['description']; }
	return array('ok' => true, 'methods' => $names);
}

// start the online payment of an order: returns array(ok, url) - the guest is sent to Mollie's page
function shop_mollie_create($order, $baseUrl) {
	$brand = 'Amadeus';
	$r = shop_mollie_request('POST', '/payments', array(
		'amount' => array('currency' => 'EUR', 'value' => number_format($order['total_cents'] / 100, 2, '.', '')),
		'description' => 'Bestellung '.$order['number'].' - '.$brand,
		'redirectUrl' => $baseUrl.'/order/status.php?t='.$order['token'],
		'webhookUrl' => $baseUrl.'/order/mollie_webhook.php',
		'locale' => 'de_DE',
		'metadata' => array('order' => $order['number']),
	));
	if (!in_array($r['http'], array(200, 201), true) || empty($r['body']['id']) || empty($r['body']['_links']['checkout']['href'])) {
		shop_log((int)$order['id'], 'mollie_error', shop_mollie_describe($r));
		return array('ok' => false, 'error' => 'Die Online-Zahlung ist gerade nicht erreichbar. Bitte wähle eine andere Zahlungsart oder versuche es gleich noch einmal.');
	}
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET mollie_id = ?, updated_at = ? WHERE id = ?", 'ssi', array($r['body']['id'], date('Y-m-d H:i:s'), (int)$order['id']));
	shop_log((int)$order['id'], 'mollie_created', $r['body']['id']);
	return array('ok' => true, 'url' => $r['body']['_links']['checkout']['href']);
}

// ask Mollie what happened to the payment of this order and update it (called by the webhook and when the guest returns)
function shop_mollie_sync($order) {
	if (empty($order['mollie_id']) || $order['payment_method'] !== 'mollie') { return $order; }
	if (in_array($order['payment_status'], array('paid'), true)) { return $order; }
	$r = shop_mollie_request('GET', '/payments/'.rawurlencode($order['mollie_id']));
	if ($r['http'] !== 200 || empty($r['body']['status'])) { return $order; }
	$st = $r['body']['status']; // open, pending, authorized, paid, canceled, expired, failed
	$map = array('paid' => 'paid', 'authorized' => 'paid', 'canceled' => 'canceled', 'expired' => 'expired', 'failed' => 'failed');
	if (isset($map[$st]) && $map[$st] !== $order['payment_status']) {
		// the webhook of Mollie and the guest coming back to the status page ask at the same moment: only the request whose UPDATE really changed the row goes on
		// (otherwise both of them would send the order mails)
		$upd = fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET payment_status = ?, updated_at = ? WHERE id = ? AND payment_status <> ?", 'ssis', array($map[$st], date('Y-m-d H:i:s'), (int)$order['id'], $map[$st]));
		if (!$upd || mysqli_stmt_affected_rows($upd) < 1) { return shop_order((int)$order['id']); }
		shop_log((int)$order['id'], 'payment', $map[$st]);
		if ($map[$st] === 'paid' && !empty($r['body']['method'])) { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET pay_detail = ? WHERE id = ?", 'si', array(mb_substr((string)$r['body']['method'], 0, 30), (int)$order['id'])); }
		if ($map[$st] === 'paid' && $order['status'] === 'pending') { shop_set_status((int)$order['id'], 'new', 'Zahlung'); shop_after_order_placed((int)$order['id']); }
		return shop_order((int)$order['id']);
	}
	return $order;
}

// an order reached the kitchen (cash/card orders at once, online orders when they are paid): notifications go here
function shop_after_order_placed($orderId) {
	$o = shop_order((int)$orderId);
	if (!$o || !empty($o['is_test'])) { return; }
	if (function_exists('shop_notify_order')) { try { shop_notify_order($o); } catch (Throwable $e) { error_log('mySeat shop notify: '.$e->getMessage()); } }
}

// an online order that was not paid within 45 minutes is given up (the kitchen never saw it)
function shop_expire_pending() {
	$rows = fb_rows("SELECT id FROM ".fb_t('tp_shop_orders')." WHERE status = 'pending' AND created_at < ?", 's', array(date('Y-m-d H:i:s', time() - 2700)));
	foreach ($rows as $r) { shop_set_status((int)$r['id'], 'cancelled', 'Zahlung nicht eingegangen'); }
}

// ---- staff side: board of open orders, day list, numbers
function shop_orders_with_items($rows) {
	if (!$rows) { return array(); }
	$ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
	$driverNames = shop_drivers_name_map();
	$items = array();
	foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_order_items')." WHERE order_id IN (".implode(',', $ids).") ORDER BY id") as $it) {
		$opts = array();
		foreach ((json_decode((string)$it['options'], true) ?: array()) as $o) { $opts[] = ($o['qty'] > 1 ? $o['qty'].'× ' : '').$o['title']; }
		$items[(int)$it['order_id']][] = array('qty' => (int)$it['qty'], 'title' => $it['title'], 'variation' => $it['variation'], 'options' => $opts, 'note' => $it['note'], 'line' => (int)$it['line_cents']);
	}
	$out = array();
	foreach ($rows as $r) {
		$due = $r['scheduled_at'] ?: ($r['eta_at'] ?: $r['created_at']);
		$out[] = array(
			'id' => (int)$r['id'], 'number' => $r['number'], 'day_no' => (int)$r['day_no'], 'type' => $r['type'], 'status' => $r['status'], 'test' => (int)$r['is_test'],
			'source' => $r['source'],
			'created' => substr($r['created_at'], 11, 5), 'created_ts' => strtotime($r['created_at']), 'scheduled' => $r['scheduled_at'] ? substr($r['scheduled_at'], 11, 5) : '',
			'due' => substr($due, 11, 5), 'due_date' => substr($due, 0, 10), 'name' => $r['customer_name'], 'phone' => $r['phone'], 'email' => $r['email'],
			'address' => $r['type'] === 'delivery' ? trim($r['street'].', '.$r['zip'].' '.$r['city']) : '', 'street' => (string)$r['street'], 'zip' => (string)$r['zip'], 'city' => (string)$r['city'], 'address_note' => $r['address_note'], 'note' => $r['note'],
			'pay' => $r['payment_method'], 'pay_status' => $r['payment_status'], 'total' => (int)$r['total_cents'], 'fee' => (int)$r['fee_cents'], 'tip' => (int)$r['tip_cents'], 'subtotal' => (int)$r['subtotal_cents'],
			'items' => isset($items[(int)$r['id']]) ? $items[(int)$r['id']] : array(), 'coupon' => (string)$r['coupon_code'], 'discount' => (int)$r['discount_cents'], 'surcharge' => (int)$r['surcharge_cents'], 'adjust' => (string)$r['adjust_note'],
			'token' => $r['token'],
			'driver_name' => $r['driver_id'] && isset($driverNames[(int)$r['driver_id']]) ? $driverNames[(int)$r['driver_id']] : '',
			'driver_age' => ($dp = shop_driver_position($r)) ? $dp['age'] : null,
			'fail_reason' => (string)$r['fail_reason'],
			// for the dispatch screen: when it has to be there, since when it is ready, which driver (id) carries it
			'due_ts' => strtotime($due), 'ready_ts' => $r['ready_at'] ? strtotime($r['ready_at']) : 0, 'updated_ts' => $r['updated_at'] ? strtotime($r['updated_at']) : 0, 'driver_id' => $r['driver_id'] ? (int)$r['driver_id'] : 0,
		);
	}
	return $out;
}

// what the kitchen has to do: everything from "new" to "delivering", by due time
function shop_board() {
	shop_ensure_schema();
	shop_expire_pending();
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE status IN ('new', 'accepted', 'preparing', 'ready', 'delivering', 'failed') ORDER BY COALESCE(scheduled_at, created_at), id");
	return shop_orders_with_items($rows);
}

// orders of one day (created that day or planned for it), newest first; filter 'open' = not finished
function shop_day_orders($date, $filter = 'all') {
	shop_ensure_schema();
	shop_expire_pending();
	$cond = $filter === 'open' ? "AND status IN ('new', 'accepted', 'preparing', 'ready', 'delivering')" : ($filter === 'closed' ? "AND status IN ('done', 'cancelled', 'failed')" : '');
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE (order_date = ? OR DATE(scheduled_at) = ?) AND status <> 'pending' $cond ORDER BY id DESC LIMIT 300", 'ss', array($date, $date));
	return shop_orders_with_items($rows);
}

function shop_day_stats($date) {
	$r = fb_row("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS sum, COALESCE(SUM(type = 'delivery'), 0) AS deliveries, COALESCE(SUM(type = 'pickup'), 0) AS pickups,
			COALESCE(SUM(status IN ('new', 'accepted', 'preparing', 'ready', 'delivering')), 0) AS open_n,
			COALESCE(SUM(payment_method = 'mollie' AND payment_status = 'paid'), 0) AS paid_online
		FROM ".fb_t('tp_shop_orders')." WHERE order_date = ? AND status NOT IN ('pending', 'cancelled', 'failed') AND is_test = 0", 's', array($date));
	$prep = fb_row("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, ready_at)) AS m FROM ".fb_t('tp_shop_orders')." WHERE order_date = ? AND ready_at IS NOT NULL AND is_test = 0", 's', array($date));
	return array('orders' => (int)$r['n'], 'revenue' => (int)$r['sum'], 'deliveries' => (int)$r['deliveries'], 'pickups' => (int)$r['pickups'], 'open' => (int)$r['open_n'], 'paid_online' => (int)$r['paid_online'],
		'avg_ready_min' => ($prep && $prep['m'] !== null) ? (int)round($prep['m']) : null);
}

// staff may cancel any order; test orders can be deleted for good
function shop_delete_test_order($id) {
	$o = shop_order((int)$id);
	if (!$o || !(int)$o['is_test']) { return false; }
	fb_exec("DELETE FROM ".fb_t('tp_shop_order_items')." WHERE order_id = ?", 'i', array((int)$id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_order_log')." WHERE order_id = ?", 'i', array((int)$id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$id));
	return true;
}

// what the kitchen screen needs and nothing more: no name, phone, address or payment of the guest
function shop_kitchen_board() {
	shop_ensure_schema();
	if (is_file(__DIR__.'/shop_feedback.class.php')) { require_once __DIR__.'/shop_feedback.class.php'; shop_fb_tick(); } // sends the feedback mails that are due (every ten minutes at most)
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE status IN ('accepted', 'preparing') ORDER BY COALESCE(scheduled_at, eta_at, created_at), id");
	$accepted = array();
	foreach ($rows as $r) { $accepted[(int)$r['id']] = $r['accepted_at'] ? strtotime($r['accepted_at']) : strtotime($r['created_at']); }
	$out = array();
	// the drive of a delivery is taken off the due time: out_ts is when the food has to leave the kitchen (pickup: the due time itself)
	$drive = max(0, min(60, (int)shop_setting('kitchen_drive_min')));
	foreach (shop_orders_with_items($rows) as $o) {
		$dueTs = strtotime($o['due_date'].' '.$o['due']);
		$min = ($o['type'] === 'delivery') ? $drive : 0;
		$out[] = array('id' => $o['id'], 'day_no' => $o['day_no'], 'number' => $o['number'], 'type' => $o['type'], 'status' => $o['status'], 'test' => $o['test'], 'source' => $o['source'],
			'due' => $o['due'], 'scheduled' => $o['scheduled'], 'asap' => ($o['scheduled'] === ''), 'due_ts' => $dueTs, 'drive_min' => $min, 'out_ts' => $dueTs - $min * 60, 'out' => date('H:i', $dueTs - $min * 60),
			// of a delivery only the name and the postcode: enough to talk the tours through with the kitchen, no street, no phone
			'name' => $o['name'], 'zip' => $o['type'] === 'delivery' ? $o['zip'] : '',
			'accepted_ts' => $accepted[$o['id']], 'note' => $o['note'], 'items' => $o['items']);
	}
	// what has to leave the kitchen first stands first
	usort($out, function ($a, $b) { return $a['out_ts'] === $b['out_ts'] ? $a['id'] - $b['id'] : $a['out_ts'] - $b['out_ts']; });
	return $out;
}

// What the kitchen has finished (status ready, delivering or done) in the last $minutes minutes, newest first, with the dishes: the kitchen screen shows them in its
// "Erledigt" column so that a slip that has been printed can be traced after the order has left the board. Same fields as the board, plus the time it was finished.
function shop_kitchen_done($minutes = 120) {
	$since = date('Y-m-d H:i:s', time() - max(10, min(720, (int)$minutes)) * 60);
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE status IN ('ready', 'delivering', 'done') AND COALESCE(ready_at, done_at, updated_at) >= ?
		ORDER BY COALESCE(ready_at, done_at, updated_at) DESC, id DESC LIMIT 40", 's', array($since));
	if (!$rows) { return array(); }
	$when = array();
	foreach ($rows as $r) { $when[(int)$r['id']] = strtotime($r['ready_at'] ?: ($r['done_at'] ?: $r['updated_at'])); }
	$out = array();
	foreach (shop_orders_with_items($rows) as $o) {
		$out[] = array('id' => $o['id'], 'day_no' => $o['day_no'], 'number' => $o['number'], 'type' => $o['type'], 'status' => $o['status'], 'test' => $o['test'], 'source' => $o['source'],
			'name' => $o['name'], 'zip' => $o['type'] === 'delivery' ? $o['zip'] : '', 'note' => $o['note'], 'items' => $o['items'],
			'finished_ts' => $when[$o['id']], 'finished' => date('H:i', $when[$o['id']]));
	}
	return $out;
}

// ---- menu editor (backend, page p=10). Every save returns the changed row so the page can update itself.
function shop_me_group_rows() {
	$groups = array();
	foreach (fb_rows("SELECT g.id, g.title, g.min_qty, g.max_qty, (SELECT COUNT(*) FROM ".fb_t('tp_shop_product_groups')." pg WHERE pg.group_id = g.id) AS used
		FROM ".fb_t('tp_shop_modgroups')." g WHERE EXISTS (SELECT 1 FROM ".fb_t('tp_shop_group_items')." i WHERE i.group_id = g.id)
			OR EXISTS (SELECT 1 FROM ".fb_t('tp_shop_product_groups')." pg WHERE pg.group_id = g.id) OR g.resmio_id IS NULL ORDER BY g.title, g.id") as $g) {
		$groups[(int)$g['id']] = array('id' => (int)$g['id'], 'title' => $g['title'], 'min' => (int)$g['min_qty'], 'max' => (int)$g['max_qty'], 'used' => (int)$g['used'], 'items' => array());
	}
	foreach (fb_rows("SELECT id, group_id, title, price_cents, max_qty, icon FROM ".fb_t('tp_shop_group_items')." ORDER BY sort, id") as $i) {
		// icon: what is stored ('' = automatic, 'none', or a symbol key); auto: the symbol the name leads to when it is automatic
		if (isset($groups[(int)$i['group_id']])) { $groups[(int)$i['group_id']]['items'][] = array('id' => (int)$i['id'], 'title' => $i['title'], 'price' => (int)$i['price_cents'], 'max' => (int)$i['max_qty'], 'icon' => (string)$i['icon'], 'auto' => shop_item_icon($i['title'], '')); }
	}
	return $groups;
}
function shop_me_products($onlyId = 0) {
	$where = $onlyId ? "WHERE id = ".(int)$onlyId : '';
	$out = array();
	foreach (fb_rows("SELECT id, category_id, title, description, image_url, price_cents, allergens, active, configurator FROM ".fb_t('tp_shop_products')." $where ORDER BY sort, id") as $p) {
		$out[(int)$p['id']] = array('id' => (int)$p['id'], 'category_id' => (int)$p['category_id'], 'title' => $p['title'], 'description' => $p['description'], 'image_url' => $p['image_url'],
			'price' => (int)$p['price_cents'], 'allergens' => $p['allergens'], 'active' => (int)$p['active'], 'configurator' => (int)$p['configurator'], 'variations' => array(), 'groups' => array());
	}
	if ($out) {
		foreach (fb_rows("SELECT id, product_id, title, price_cents, multiplier FROM ".fb_t('tp_shop_variations')." ORDER BY sort, id") as $v) {
			if (isset($out[(int)$v['product_id']])) { $out[(int)$v['product_id']]['variations'][] = array('id' => (int)$v['id'], 'title' => $v['title'], 'price' => (int)$v['price_cents'], 'mult' => (float)$v['multiplier']); }
		}
		foreach (fb_rows("SELECT product_id, group_id FROM ".fb_t('tp_shop_product_groups')." ORDER BY sort, group_id") as $l) {
			if (isset($out[(int)$l['product_id']])) { $out[(int)$l['product_id']]['groups'][] = (int)$l['group_id']; }
		}
	}
	return $out;
}
function shop_me_load() {
	shop_ensure_schema();
	foreach (fb_rows("SELECT id, image_url FROM ".fb_t('tp_shop_products')." WHERE image_url LIKE 'http%/uploads/menu/%'") as $r) { // pictures saved with the domain: now by path
		$n = shop_img_rel($r['image_url']);
		if ($n !== $r['image_url']) { fb_exec("UPDATE ".fb_t('tp_shop_products')." SET image_url = ? WHERE id = ?", 'si', array($n, (int)$r['id'])); }
	}
	$cats = array();
	foreach (fb_rows("SELECT id, name, description, active FROM ".fb_t('tp_shop_categories')." ORDER BY sort, id") as $c) {
		$cats[] = array('id' => (int)$c['id'], 'name' => $c['name'], 'description' => $c['description'], 'active' => (int)$c['active']);
	}
	return array('categories' => $cats, 'products' => array_values(shop_me_products()), 'groups' => array_values(shop_me_group_rows()), 'coupons' => shop_me_coupons(), 'img_prefix' => shop_img_prefix());
}

function shop_me_renumber($table, $scopeCol, $scopeVal) {
	$sql = "SELECT id FROM ".fb_t($table).($scopeCol ? " WHERE $scopeCol = ".(int)$scopeVal : '')." ORDER BY sort, id";
	$n = 0;
	foreach (fb_rows($sql) as $r) { fb_exec("UPDATE ".fb_t($table)." SET sort = ? WHERE id = ?", 'di', array(++$n, (int)$r['id'])); }
}
function shop_me_move($table, $id, $dir, $scopeCol = '') {
	$id = (int)$id;
	$row = fb_row("SELECT id".($scopeCol ? ", $scopeCol AS scope" : '')." FROM ".fb_t($table)." WHERE id = ?", 'i', array($id));
	if (!$row) { return false; }
	$scope = $scopeCol ? (int)$row['scope'] : 0;
	shop_me_renumber($table, $scopeCol, $scope);
	$ids = array_map(function ($r) { return (int)$r['id']; }, fb_rows("SELECT id FROM ".fb_t($table).($scopeCol ? " WHERE $scopeCol = $scope" : '')." ORDER BY sort, id"));
	$i = array_search($id, $ids, true); $j = $i + ($dir < 0 ? -1 : 1);
	if ($i === false || $j < 0 || $j >= count($ids)) { return true; }
	fb_exec("UPDATE ".fb_t($table)." SET sort = ? WHERE id = ?", 'di', array($j + 1, $ids[$i]));
	fb_exec("UPDATE ".fb_t($table)." SET sort = ? WHERE id = ?", 'di', array($i + 1, $ids[$j]));
	return true;
}

function shop_me_save_category($d) {
	$id = (int)(isset($d['id']) ? $d['id'] : 0);
	$name = mb_substr(trim((string)(isset($d['name']) ? $d['name'] : '')), 0, 120);
	if ($name === '') { return array('ok' => false, 'error' => 'Bitte gib der Kategorie einen Namen.'); }
	$desc = mb_substr(trim((string)(isset($d['description']) ? $d['description'] : '')), 0, 500);
	$active = empty($d['active']) ? 0 : 1;
	if ($id) {
		if (!fb_row("SELECT id FROM ".fb_t('tp_shop_categories')." WHERE id = ?", 'i', array($id))) { return array('ok' => false, 'error' => 'Diese Kategorie gibt es nicht mehr.'); }
		fb_exec("UPDATE ".fb_t('tp_shop_categories')." SET name = ?, description = ?, active = ? WHERE id = ?", 'ssii', array($name, $desc, $active, $id));
	} else {
		$max = fb_row("SELECT COALESCE(MAX(sort), 0) AS m FROM ".fb_t('tp_shop_categories'));
		fb_exec("INSERT INTO ".fb_t('tp_shop_categories')." (name, description, sort, active) VALUES (?, ?, ?, ?)", 'ssdi', array($name, $desc, (float)$max['m'] + 1, $active));
		$id = (int)mysqli_insert_id(fb_db());
	}
	return array('ok' => true, 'category' => array('id' => $id, 'name' => $name, 'description' => $desc, 'active' => $active));
}
function shop_me_delete_category($id) {
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_products')." WHERE category_id = ?", 'i', array((int)$id));
	if ($n && (int)$n['n'] > 0) { return array('ok' => false, 'error' => 'Die Kategorie enthält noch '.(int)$n['n'].' Gerichte. Verschiebe oder lösche sie zuerst.'); }
	fb_exec("DELETE FROM ".fb_t('tp_shop_categories')." WHERE id = ?", 'i', array((int)$id));
	return array('ok' => true);
}

function shop_me_save_product($d) {
	$id = (int)(isset($d['id']) ? $d['id'] : 0);
	$title = mb_substr(trim((string)(isset($d['title']) ? $d['title'] : '')), 0, 160);
	if ($title === '') { return array('ok' => false, 'error' => 'Bitte gib dem Gericht einen Namen.'); }
	$cat = (int)(isset($d['category_id']) ? $d['category_id'] : 0);
	if (!fb_row("SELECT id FROM ".fb_t('tp_shop_categories')." WHERE id = ?", 'i', array($cat))) { return array('ok' => false, 'error' => 'Bitte wähle eine Kategorie.'); }
	$price = shop_cents(isset($d['price']) ? $d['price'] : 0);
	if ($price < 0 || $price > 100000) { return array('ok' => false, 'error' => 'Der Preis ist nicht sinnvoll.'); }
	$image = shop_img_rel(trim((string)(isset($d['image_url']) ? $d['image_url'] : '')));
	if ($image !== '' && !shop_img_is_local($image) && !preg_match('#^https://[^\s"<>]+$#i', $image)) { return array('ok' => false, 'error' => 'Die Bildadresse muss mit https:// beginnen.'); }
	if (mb_strlen($image) > 300) { return array('ok' => false, 'error' => 'Die Bildadresse ist zu lang.'); }
	$oldImg = $id ? fb_row("SELECT image_url FROM ".fb_t('tp_shop_products')." WHERE id = ?", 'i', array($id)) : null;
	$desc = mb_substr(trim((string)(isset($d['description']) ? $d['description'] : '')), 0, 800);
	$all = mb_substr(trim((string)(isset($d['allergens']) ? $d['allergens'] : '')), 0, 400);
	$active = empty($d['active']) ? 0 : 1;
	$conf = max(0, min(2, (int)(isset($d['configurator']) ? $d['configurator'] : 0))); // 0 = off, 1 = round pizza, 2 = Flammkuchen (oval, extra thin)
	$vars = array();
	foreach ((isset($d['variations']) && is_array($d['variations'])) ? array_slice($d['variations'], 0, 20) : array() as $v) {
		$vt = mb_substr(trim((string)(isset($v['title']) ? $v['title'] : '')), 0, 120);
		if ($vt === '') { continue; }
		$vp = shop_cents(isset($v['price']) ? $v['price'] : 0); $vm = (float)str_replace(',', '.', (string)(isset($v['mult']) ? $v['mult'] : 1));
		if ($vp < 0 || $vp > 100000) { return array('ok' => false, 'error' => 'Der Preis der Variante "'.$vt.'" ist nicht sinnvoll.'); }
		$vars[] = array('id' => (int)(isset($v['id']) ? $v['id'] : 0), 'title' => $vt, 'price' => $vp, 'mult' => ($vm >= 0.1 && $vm <= 10) ? $vm : 1.0);
	}
	$groups = array();
	foreach ((isset($d['groups']) && is_array($d['groups'])) ? array_slice($d['groups'], 0, 30) : array() as $g) {
		$g = (int)$g;
		if ($g && !in_array($g, $groups, true) && fb_row("SELECT id FROM ".fb_t('tp_shop_modgroups')." WHERE id = ?", 'i', array($g))) { $groups[] = $g; }
	}
	if ($id) {
		if (!fb_row("SELECT id FROM ".fb_t('tp_shop_products')." WHERE id = ?", 'i', array($id))) { return array('ok' => false, 'error' => 'Dieses Gericht gibt es nicht mehr.'); }
		fb_exec("UPDATE ".fb_t('tp_shop_products')." SET category_id = ?, title = ?, description = ?, image_url = ?, price_cents = ?, allergens = ?, active = ?, configurator = ? WHERE id = ?", 'isssisiii', array($cat, $title, $desc, $image, $price, $all, $active, $conf, $id));
	} else {
		$max = fb_row("SELECT COALESCE(MAX(sort), 0) AS m FROM ".fb_t('tp_shop_products')." WHERE category_id = ?", 'i', array($cat));
		fb_exec("INSERT INTO ".fb_t('tp_shop_products')." (category_id, title, description, image_url, price_cents, allergens, active, configurator, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", 'isssisiid', array($cat, $title, $desc, $image, $price, $all, $active, $conf, (float)$max['m'] + 1));
		$id = (int)mysqli_insert_id(fb_db());
	}
	if ($oldImg && $oldImg['image_url'] !== '' && $oldImg['image_url'] !== $image) { shop_img_release($oldImg['image_url']); }
	// variations: keep the ids that stay, add new ones, drop the rest
	$keep = array(); $n = 0;
	foreach ($vars as $v) {
		$n++;
		$own = $v['id'] ? fb_row("SELECT id FROM ".fb_t('tp_shop_variations')." WHERE id = ? AND product_id = ?", 'ii', array($v['id'], $id)) : null;
		if ($own) { fb_exec("UPDATE ".fb_t('tp_shop_variations')." SET title = ?, price_cents = ?, multiplier = ?, sort = ? WHERE id = ?", 'sidii', array($v['title'], $v['price'], $v['mult'], $n, $v['id'])); $keep[] = $v['id']; }
		else { fb_exec("INSERT INTO ".fb_t('tp_shop_variations')." (product_id, title, price_cents, multiplier, sort) VALUES (?, ?, ?, ?, ?)", 'isidi', array($id, $v['title'], $v['price'], $v['mult'], $n)); $keep[] = (int)mysqli_insert_id(fb_db()); }
	}
	foreach (fb_rows("SELECT id FROM ".fb_t('tp_shop_variations')." WHERE product_id = ?", 'i', array($id)) as $r) {
		if (!in_array((int)$r['id'], $keep, true)) { fb_exec("DELETE FROM ".fb_t('tp_shop_variations')." WHERE id = ?", 'i', array((int)$r['id'])); }
	}
	fb_exec("DELETE FROM ".fb_t('tp_shop_product_groups')." WHERE product_id = ?", 'i', array($id));
	foreach ($groups as $i => $g) { fb_exec("INSERT INTO ".fb_t('tp_shop_product_groups')." (product_id, group_id, sort) VALUES (?, ?, ?)", 'iid', array($id, $g, $i + 1)); }
	$rows = shop_me_products($id);
	return array('ok' => true, 'product' => $rows[$id], 'groups' => array_values(shop_me_group_rows()));
}
// ---- dish pictures: kept on this server (uploads/menu). Every picture is decoded and written again (max 1200 px, WebP or JPEG), so
// nothing but a clean image is ever stored, whatever the file was called or contained. The file name is a hash of the result.
function shop_img_dir() { return dirname(__DIR__, 2).'/uploads/menu'; }
// pictures are stored by path (/uploads/menu/<name>), never with the domain: they work on every domain the installation answers to
function shop_img_prefix() { return rtrim((string)parse_url(shop_site_url(), PHP_URL_PATH), '/').'/uploads/menu/'; }
// a picture saved with its full address by an earlier version (https://domain/uploads/menu/<name>) becomes the path, when the file is here
function shop_img_rel($url) {
	$url = (string)$url; $pre = shop_img_prefix();
	if (preg_match('#^https?://[^/]+('.preg_quote($pre, '#').'[a-f0-9]{20}\.(webp|jpg))$#', $url, $m) && is_file(shop_img_dir().'/'.basename($m[1]))) { return $m[1]; }
	return $url;
}
function shop_img_is_local($url) { return strpos(shop_img_rel($url), shop_img_prefix()) === 0; }
function shop_img_file($url) {
	$url = shop_img_rel($url);
	if (strpos($url, shop_img_prefix()) !== 0) { return ''; }
	$n = substr($url, strlen(shop_img_prefix()));
	return preg_match('/^[a-f0-9]{20}\.(webp|jpg)$/', $n) ? shop_img_dir().'/'.$n : '';
}
// $bin = the bytes of an image file; array(ok, url | error)
function shop_img_store($bin) {
	if (strlen($bin) < 100) { return array('ok' => false, 'error' => 'Das ist kein Bild.'); }
	if (strlen($bin) > 20 * 1024 * 1024) { return array('ok' => false, 'error' => 'Das Bild ist zu groß (höchstens 20 MB).'); }
	if (!function_exists('imagecreatefromstring')) { return array('ok' => false, 'error' => 'Auf dem Server fehlt die Bildbearbeitung (GD).'); }
	$info = @getimagesizefromstring($bin);
	if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF), true)) { return array('ok' => false, 'error' => 'Bitte ein Bild als JPG, PNG oder WebP wählen.'); }
	if ($info[0] * $info[1] > 40000000) { return array('ok' => false, 'error' => 'Das Bild hat zu viele Pixel. Bitte verkleinern.'); }
	$im = @imagecreatefromstring($bin);
	if (!$im) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gelesen werden.'); }
	if (!imageistruecolor($im)) { imagepalettetotruecolor($im); }
	if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) { // phone photos: turn them the way they were taken
		$ex = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bin)); $ang = array(3 => 180, 6 => -90, 8 => 90);
		$o = isset($ex['Orientation']) ? (int)$ex['Orientation'] : 1;
		if (isset($ang[$o])) { $r = imagerotate($im, $ang[$o], 0); if ($r) { imagedestroy($im); $im = $r; } }
	}
	$w = imagesx($im); $h = imagesy($im);
	if (max($w, $h) > 1200) {
		$s = 1200 / max($w, $h); $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
		$dst = imagecreatetruecolor($nw, $nh); imagealphablending($dst, false); imagesavealpha($dst, true);
		imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
		imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagedestroy($im); $im = $dst;
	}
	if (function_exists('imagewebp')) { imagesavealpha($im, true); ob_start(); imagewebp($im, null, 82); $out = ob_get_clean(); $ext = 'webp'; }
	else {
		$flat = imagecreatetruecolor(imagesx($im), imagesy($im)); imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255)); imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
		ob_start(); imagejpeg($flat, null, 85); $out = ob_get_clean(); $ext = 'jpg'; imagedestroy($flat);
	}
	imagedestroy($im);
	if (!$out) { return array('ok' => false, 'error' => 'Das Bild konnte nicht umgewandelt werden.'); }
	$dir = shop_img_dir();
	if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
	$name = substr(sha1($out), 0, 20).'.'.$ext;
	if (!is_writable($dir) || @file_put_contents($dir.'/'.$name, $out, LOCK_EX) === false) { return array('ok' => false, 'error' => 'Das Bild konnte nicht auf dem Server gespeichert werden (Ordner uploads/menu nicht beschreibbar).'); }
	@chmod($dir.'/'.$name, 0644);
	return array('ok' => true, 'url' => shop_img_prefix().$name);
}
function shop_img_from_upload($f) {
	if (!is_array($f) || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
		$big = is_array($f) && isset($f['error']) && in_array($f['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true);
		return array('ok' => false, 'error' => $big ? 'Das Bild ist größer, als der Server erlaubt.' : 'Der Upload hat nicht geklappt. Bitte versuche es noch einmal.');
	}
	if (!is_uploaded_file($f['tmp_name'])) { return array('ok' => false, 'error' => 'Der Upload hat nicht geklappt.'); }
	return shop_img_store((string)file_get_contents($f['tmp_name']));
}
// fetch a picture from a foreign https address (checked: no internal addresses, at most 3 redirects, at most 20 MB) and keep it here
function shop_img_fetch($url) {
	$url = trim((string)$url);
	for ($hop = 0; $hop < 4; $hop++) {
		$p = parse_url($url);
		if (!$p || empty($p['host']) || strtolower((string)$p['scheme']) !== 'https') { return array('ok' => false, 'error' => 'Nur https-Adressen können geholt werden.'); }
		$ip = gethostbyname($p['host']);
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return array('ok' => false, 'error' => 'Die Adresse ist nicht erreichbar.'); }
		$port = isset($p['port']) ? (int)$p['port'] : 443; $loc = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 25,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => array($p['host'].':'.$port.':'.$ip), CURLOPT_USERAGENT => 'mySeat-Lieferservice/1.0 (Bilder)',
			CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => function ($c, $dt, $dn) { return $dn > 20 * 1024 * 1024 ? 1 : 0; },
			CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$loc) { if (stripos($line, 'location:') === 0) { $loc = trim(substr($line, 9)); } return strlen($line); }));
		$body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
		if (in_array($code, array(301, 302, 303, 307, 308), true) && $loc !== '') {
			if (strpos($loc, '//') === 0) { $loc = 'https:'.$loc; } elseif ($loc[0] === '/') { $loc = 'https://'.$p['host'].($port !== 443 ? ':'.$port : '').$loc; }
			$url = $loc; continue;
		}
		if ($body === false || $code !== 200) { return array('ok' => false, 'error' => 'Das Bild konnte nicht geladen werden'.($code ? ' (Fehler '.$code.')' : '').'.'); }
		return shop_img_store((string)$body);
	}
	return array('ok' => false, 'error' => 'Zu viele Weiterleitungen.');
}
// delete the file of a local picture that no dish uses any more
function shop_img_release($url) {
	$f = shop_img_file($url);
	if ($f === '' || !is_file($f)) { return; }
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_products')." WHERE image_url = ?", 's', array((string)$url));
	if ($n && (int)$n['n'] === 0) { @unlink($f); }
}
// pictures uploaded but never saved with a dish: gone after a day
function shop_img_gc() {
	$used = array();
	foreach (fb_rows("SELECT image_url FROM ".fb_t('tp_shop_products')." WHERE image_url <> ''") as $r) { $used[$r['image_url']] = 1; }
	foreach ((array)glob(shop_img_dir().'/*') as $f) {
		if (is_file($f) && filemtime($f) < time() - 86400 && empty($used[shop_img_prefix().basename($f)])) { @unlink($f); }
	}
}
// dishes whose picture is still linked from another site
function shop_img_external() {
	$out = array();
	foreach (fb_rows("SELECT id, title, image_url FROM ".fb_t('tp_shop_products')." WHERE image_url <> '' ORDER BY id") as $r) {
		if (!shop_img_is_local($r['image_url'])) { $out[] = array('id' => (int)$r['id'], 'title' => $r['title'], 'url' => $r['image_url']); }
	}
	return $out;
}
function shop_img_localize($id) {
	$p = fb_row("SELECT id, image_url FROM ".fb_t('tp_shop_products')." WHERE id = ?", 'i', array((int)$id));
	if (!$p) { return array('ok' => false, 'error' => 'Dieses Gericht gibt es nicht mehr.'); }
	if ($p['image_url'] === '' || shop_img_is_local($p['image_url'])) { return array('ok' => true, 'url' => $p['image_url']); }
	$r = shop_img_fetch($p['image_url']);
	if (!$r['ok']) { return $r; }
	fb_exec("UPDATE ".fb_t('tp_shop_products')." SET image_url = ? WHERE id = ?", 'si', array($r['url'], (int)$id));
	return $r;
}

function shop_me_delete_product($id) {
	$id = (int)$id;
	$old = fb_row("SELECT image_url FROM ".fb_t('tp_shop_products')." WHERE id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_variations')." WHERE product_id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_product_groups')." WHERE product_id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_products')." WHERE id = ?", 'i', array($id)); // placed orders keep name and price in their own rows
	if ($old && $old['image_url'] !== '') { shop_img_release($old['image_url']); }
	return array('ok' => true, 'groups' => array_values(shop_me_group_rows()));
}

function shop_me_save_group($d) {
	$id = (int)(isset($d['id']) ? $d['id'] : 0);
	$title = mb_substr(trim((string)(isset($d['title']) ? $d['title'] : '')), 0, 120);
	if ($title === '') { return array('ok' => false, 'error' => 'Bitte gib der Gruppe einen Namen.'); }
	$min = max(0, min(20, (int)(isset($d['min']) ? $d['min'] : 0))); $max = max(0, min(50, (int)(isset($d['max']) ? $d['max'] : 0)));
	if ($max > 0 && $min > $max) { return array('ok' => false, 'error' => 'Mindestens darf nicht größer sein als höchstens.'); }
	$items = array();
	foreach ((isset($d['items']) && is_array($d['items'])) ? array_slice($d['items'], 0, 200) : array() as $i) {
		$t = mb_substr(trim((string)(isset($i['title']) ? $i['title'] : '')), 0, 120);
		if ($t === '') { continue; }
		$pc = shop_cents(isset($i['price']) ? $i['price'] : 0);
		if ($pc < 0 || $pc > 50000) { return array('ok' => false, 'error' => 'Der Preis von "'.$t.'" ist nicht sinnvoll.'); }
		$ic = isset($i['icon']) ? (string)$i['icon'] : '';
		$items[] = array('id' => (int)(isset($i['id']) ? $i['id'] : 0), 'title' => $t, 'price' => $pc, 'max' => max(1, min(9, (int)(isset($i['max']) ? $i['max'] : 1))),
			'icon' => ($ic === 'none' || preg_match('/^[a-z_]{2,20}$/', $ic)) ? $ic : '');
	}
	if ($min > 0 && count($items) < $min) { return array('ok' => false, 'error' => 'Für "mindestens '.$min.'" braucht die Gruppe mindestens '.$min.' Optionen.'); }
	if ($id) {
		if (!fb_row("SELECT id FROM ".fb_t('tp_shop_modgroups')." WHERE id = ?", 'i', array($id))) { return array('ok' => false, 'error' => 'Diese Gruppe gibt es nicht mehr.'); }
		fb_exec("UPDATE ".fb_t('tp_shop_modgroups')." SET title = ?, min_qty = ?, max_qty = ? WHERE id = ?", 'siii', array($title, $min, $max, $id));
	} else {
		fb_exec("INSERT INTO ".fb_t('tp_shop_modgroups')." (resmio_id, title, min_qty, max_qty, sort) VALUES (NULL, ?, ?, ?, 0)", 'sii', array($title, $min, $max));
		$id = (int)mysqli_insert_id(fb_db());
	}
	$keep = array(); $n = 0;
	foreach ($items as $it) {
		$n++;
		$own = $it['id'] ? fb_row("SELECT id FROM ".fb_t('tp_shop_group_items')." WHERE id = ? AND group_id = ?", 'ii', array($it['id'], $id)) : null;
		if ($own) { fb_exec("UPDATE ".fb_t('tp_shop_group_items')." SET title = ?, price_cents = ?, max_qty = ?, icon = ?, sort = ? WHERE id = ?", 'siisdi', array($it['title'], $it['price'], $it['max'], $it['icon'], $n, $it['id'])); $keep[] = $it['id']; }
		else { fb_exec("INSERT INTO ".fb_t('tp_shop_group_items')." (group_id, title, price_cents, max_qty, icon, sort) VALUES (?, ?, ?, ?, ?, ?)", 'isiisd', array($id, $it['title'], $it['price'], $it['max'], $it['icon'], $n)); $keep[] = (int)mysqli_insert_id(fb_db()); }
	}
	foreach (fb_rows("SELECT id FROM ".fb_t('tp_shop_group_items')." WHERE group_id = ?", 'i', array($id)) as $r) {
		if (!in_array((int)$r['id'], $keep, true)) { fb_exec("DELETE FROM ".fb_t('tp_shop_group_items')." WHERE id = ?", 'i', array((int)$r['id'])); }
	}
	$rows = shop_me_group_rows();
	return array('ok' => true, 'group' => $rows[$id], 'groups' => array_values($rows));
}
function shop_me_copy_group($id) {
	$rows = shop_me_group_rows(); $g = isset($rows[(int)$id]) ? $rows[(int)$id] : null;
	if (!$g) { return array('ok' => false, 'error' => 'Diese Gruppe gibt es nicht mehr.'); }
	$g['id'] = 0; $g['title'] = mb_substr($g['title'], 0, 100).' (Kopie)';
	foreach ($g['items'] as &$i) { $i['id'] = 0; $i['price'] = number_format($i['price'] / 100, 2, '.', ''); } unset($i);
	return shop_me_save_group($g);
}
function shop_me_delete_group($id) {
	$id = (int)$id;
	fb_exec("DELETE FROM ".fb_t('tp_shop_product_groups')." WHERE group_id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_group_items')." WHERE group_id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_modgroups')." WHERE id = ?", 'i', array($id));
	return array('ok' => true, 'products' => array_values(shop_me_products()), 'groups' => array_values(shop_me_group_rows()));
}

// ---- tracking: where the restaurant is, the driver link and the driver's position
function shop_origin() {
	$st = trim((string)shop_setting('origin_street')); $zip = trim((string)shop_setting('origin_zip')); $city = trim((string)shop_setting('origin_city'));
	if ($st === '' || $city === '') { return null; }
	// every caller (incl. order/track.js, which treats this directly as a Leaflet [lat,lng] coordinate) wants
	// just the restaurant's own point - not shop_geocode()'s full postcode/road/candidates tuple
	$g = shop_geocode($st, $zip, $city);
	return $g ? array($g[0], $g[1]) : null;
}
function shop_driver_position($o) {
	if ($o['driver_at'] === null || $o['driver_lat'] === null || !in_array($o['status'], array('ready', 'delivering'), true)) { return null; }
	$age = time() - strtotime($o['driver_at']);
	return ($age < 600) ? array('lat' => (float)$o['driver_lat'], 'lng' => (float)$o['driver_lng'], 'age' => max(0, $age)) : null;
}

// ---- drivers (order/driver.php, order/driver_gps.php): a driver has no login, his phone's Traccar
// device id is the only credential. The operator maps device id -> name once (Einstellungen >
// Lieferservice); see shop_ensure_schema() for tp_shop_drivers / tp_shop_driver_positions.
// last_seen_min tells the operator apart "this device id has never sent a single ping" (null) from "it
// pinged 40 minutes ago and then stopped" (a number) - the GPS endpoint always answers 200 OK even for
// an unrecognized or deactivated device id (so the Traccar app itself never shows an error), so this is
// the only place a mismatched device id becomes visible at all
function shop_drivers_list() {
	shop_ensure_schema();
	$rows = fb_rows("SELECT d.id, d.device_id, d.name, d.active, d.phone, p.updated_at FROM ".fb_t('tp_shop_drivers')." d
		LEFT JOIN ".fb_t('tp_shop_driver_positions')." p ON p.driver_id = d.id ORDER BY d.name");
	foreach ($rows as &$r) { $r['last_seen_min'] = $r['updated_at'] ? max(0, (int)round((time() - strtotime($r['updated_at'])) / 60)) : null; } unset($r);
	return $rows;
}
function shop_driver_by_device($deviceId) {
	$deviceId = trim((string)$deviceId);
	if ($deviceId === '') { return null; }
	return fb_row("SELECT * FROM ".fb_t('tp_shop_drivers')." WHERE device_id = ? AND active = 1", 's', array($deviceId));
}
function shop_driver_save($id, $name, $deviceId, $active, $phone = null) {
	$name = mb_substr(trim((string)$name), 0, 80); $deviceId = mb_substr(trim((string)$deviceId), 0, 64);
	if ($name === '' || $deviceId === '') { return array('ok' => false, 'error' => 'Bitte Name und Geräte-ID angeben.'); }
	$dupe = fb_row("SELECT id FROM ".fb_t('tp_shop_drivers')." WHERE device_id = ? AND id <> ?", 'si', array($deviceId, (int)$id));
	if ($dupe) { return array('ok' => false, 'error' => 'Diese Geräte-ID ist schon einem anderen Fahrer zugeordnet.'); }
	if ($id > 0) {
		fb_exec("UPDATE ".fb_t('tp_shop_drivers')." SET name = ?, device_id = ?, active = ? WHERE id = ?", 'ssii', array($name, $deviceId, $active ? 1 : 0, (int)$id));
		if ($phone !== null) { fb_exec("UPDATE ".fb_t('tp_shop_drivers')." SET phone = ? WHERE id = ?", 'si', array(mb_substr(trim((string)$phone), 0, 40), (int)$id)); }
		return array('ok' => true, 'id' => (int)$id);
	}
	fb_exec("INSERT INTO ".fb_t('tp_shop_drivers')." (device_id, name, active, phone, created_at) VALUES (?, ?, ?, ?, NOW())", 'ssis', array($deviceId, $name, $active ? 1 : 0, mb_substr(trim((string)$phone), 0, 40)));
	return array('ok' => true, 'id' => (int)mysqli_insert_id(fb_db()));
}
function shop_driver_delete($id) {
	fb_exec("DELETE FROM ".fb_t('tp_shop_drivers')." WHERE id = ?", 'i', array((int)$id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_driver_positions')." WHERE driver_id = ?", 'i', array((int)$id));
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_id = NULL WHERE driver_id = ?", 'i', array((int)$id));
	return array('ok' => true);
}
// one row per driver, however old - the caller decides what counts as "live" (see shop_drivers_live())
function shop_drivers_name_map() {
	$out = array();
	foreach (fb_rows("SELECT id, name FROM ".fb_t('tp_shop_drivers')) as $d) { $out[(int)$d['id']] = $d['name']; }
	return $out;
}
// a ping from order/driver_gps.php: the driver's own current position always updates, and - only while
// he actually has a delivery running - the same ping mirrors into that order's driver_lat/lng/at, which
// is the column the guest's own live map (order/status.php) already reads, unchanged
function shop_driver_update_position($driverId, $lat, $lng) {
	fb_exec("REPLACE INTO ".fb_t('tp_shop_driver_positions')." (driver_id, lat, lng, updated_at) VALUES (?, ?, ?, NOW())", 'idd', array((int)$driverId, (float)$lat, (float)$lng));
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_lat = ?, driver_lng = ?, driver_at = NOW() WHERE driver_id = ? AND status = 'delivering'", 'ddi', array((float)$lat, (float)$lng, (int)$driverId));
	shop_driver_track_add($driverId, $lat, $lng);
}
// ---- driver map for the dispatch (web/fahrerkarte.php)
// The track of a driver: a point is stored when he has moved 25 m or a minute has passed, and kept for track_days days (setting, 0 = nothing is stored).
function shop_driver_track_add($driverId, $lat, $lng) {
	$days = max(0, min(30, (int)shop_setting('track_days')));
	if ($days === 0) { return; }
	$last = fb_row("SELECT lat, lng, at FROM ".fb_t('tp_shop_driver_track')." WHERE driver_id = ? ORDER BY id DESC LIMIT 1", 'i', array((int)$driverId));
	if ($last && time() - strtotime($last['at']) < 60 && shop_haversine_m((float)$last['lat'], (float)$last['lng'], (float)$lat, (float)$lng) < 25) { return; }
	fb_exec("INSERT INTO ".fb_t('tp_shop_driver_track')." (driver_id, lat, lng, at) VALUES (?, ?, ?, ?)", 'idds', array((int)$driverId, (float)$lat, (float)$lng, date('Y-m-d H:i:s')));
	if (mt_rand(1, 150) === 1) { fb_exec("DELETE FROM ".fb_t('tp_shop_driver_track')." WHERE at < ?", 's', array(date('Y-m-d H:i:s', time() - $days * 86400))); }
}
// the points of one driver in the last $hours hours, oldest first: array of array(lat, lng, unix time), thinned to at most $max points
function shop_driver_track($driverId, $hours, $max = 400) {
	$hours = max(1, min(24, (int)$hours));
	$rows = fb_rows("SELECT lat, lng, UNIX_TIMESTAMP(at) AS t FROM ".fb_t('tp_shop_driver_track')." WHERE driver_id = ? AND at >= ? ORDER BY at", 'is', array((int)$driverId, date('Y-m-d H:i:s', time() - $hours * 3600)));
	$step = count($rows) > $max ? (int)ceil(count($rows) / $max) : 1; $out = array();
	foreach ($rows as $i => $r) { if ($i % $step === 0 || $i === count($rows) - 1) { $out[] = array(round((float)$r['lat'], 6), round((float)$r['lng'], 6), (int)$r['t']); } }
	return $out;
}
// everything the dispatch map shows: the active drivers (position, state, stops, track of the last 90 minutes, what they did today), the deliveries
// that still need or have a driver (with the order of the drivers to ask for the open ones) and the restaurant
function shop_dispatch_map() {
	shop_ensure_schema();
	$now = time(); $origin = shop_origin();
	$sel = "SELECT o.*, z.name AS zone_name, (SELECT COALESCE(SUM(qty), 0) FROM ".fb_t('tp_shop_order_items')." WHERE order_id = o.id) AS n_items
		FROM ".fb_t('tp_shop_orders')." o LEFT JOIN ".fb_t('tp_shop_zones')." z ON z.id = o.zone_id
		WHERE o.type = 'delivery' AND o.status IN ('accepted', 'preparing', 'ready', 'delivering') ORDER BY COALESCE(o.scheduled_at, o.eta_at, o.created_at), o.id";
	$rows = fb_rows($sel);
	if (shop_order_geo_fill(array_map(function ($r) { return (int)$r['id']; }, $rows), 3)) { $rows = fb_rows($sel); }
	$orders = array();
	foreach ($rows as $r) {
		$c = shop_driver_card($r);
		$due = strtotime($r['scheduled_at'] ?: ($r['eta_at'] ?: $r['created_at']));
		$c['status'] = $r['status']; $c['driver_id'] = $r['driver_id'] ? (int)$r['driver_id'] : 0; $c['phone'] = $r['phone']; $c['note'] = $r['note']; $c['source'] = $r['source'];
		$c['due_ts'] = $due; $c['due'] = date('H:i', $due); $c['scheduled'] = $r['scheduled_at'] ? date('H:i', strtotime($r['scheduled_at'])) : '';
		$c['late_min'] = ($r['status'] !== 'delivering' && $due < $now) ? (int)floor(($now - $due) / 60) : 0;
		$c['lines'] = shop_driver_lines(shop_order_items((int)$r['id']));
		$orders[(int)$r['id']] = $c;
	}
	// drivers
	$drivers = array();
	foreach (shop_drivers_list() as $d) {
		if ((int)$d['active'] !== 1) { continue; }
		$pos = fb_row("SELECT lat, lng, UNIX_TIMESTAMP(updated_at) AS t FROM ".fb_t('tp_shop_driver_positions')." WHERE driver_id = ?", 'i', array((int)$d['id']));
		$age = $pos ? max(0, $now - (int)$pos['t']) : null;
		$stops = array();
		foreach ($orders as $o) { if ($o['driver_id'] === (int)$d['id'] && in_array($o['status'], array('ready', 'delivering'), true)) { $stops[] = $o; } }
		usort($stops, function ($a, $b) { return ($a['status'] === 'delivering' ? 0 : 1) <=> ($b['status'] === 'delivering' ? 0 : 1) ?: $a['due_ts'] <=> $b['due_ts']; });
		$online = $pos && $age <= 900;
		$cur = null; foreach ($stops as $s) { if ($s['status'] === 'delivering') { $cur = $s; break; } }
		$eta = null; // a rough estimate: straight line x 1.35 at about 28 km/h
		if ($online && $cur && $cur['lat'] !== null) { $eta = max(1, (int)round(shop_haversine_m((float)$pos['lat'], (float)$pos['lng'], $cur['lat'], $cur['lng']) * 1.35 / (28000 / 60))); }
		$drivers[] = array('id' => (int)$d['id'], 'name' => $d['name'], 'phone' => (string)($d['phone'] ?? ''), 'color' => (int)$d['id'] % 6,
			'lat' => $online ? (float)$pos['lat'] : null, 'lng' => $online ? (float)$pos['lng'] : null, 'age' => $age,
			'state' => !$online ? 'offline' : (!$stops ? 'free' : ($cur ? 'delivering' : 'queued')),
			'stops' => array_map(function ($s) { return $s['id']; }, $stops), 'eta_min' => $eta,
			'trail' => $online || $age !== null ? shop_driver_track((int)$d['id'], 2, 150) : array(), 'shift' => shop_driver_shift((int)$d['id']));
	}
	// who to ask for an open delivery: free drivers first by distance, a busy one counts when the order is close to his stops
	foreach ($orders as $id => $o) {
		$orders[$id]['cands'] = array();
		if ($o['status'] !== 'ready' || $o['driver_id'] || $o['lat'] === null) { continue; }
		foreach ($drivers as $d) {
			if ($d['state'] === 'offline') { continue; }
			$dist = shop_haversine_m($d['lat'], $d['lng'], $o['lat'], $o['lng']); $onWay = null;
			foreach ($d['stops'] as $sid) {
				$s = $orders[$sid]; if ($s['lat'] === null) { continue; }
				$m = shop_haversine_m($s['lat'], $s['lng'], $o['lat'], $o['lng']); if ($m <= 1500 && ($onWay === null || $m < $onWay)) { $onWay = $m; }
			}
			$orders[$id]['cands'][] = array('driver' => $d['id'], 'dist_m' => $dist, 'load' => count($d['stops']), 'on_way_m' => $onWay,
				'score' => $dist + count($d['stops']) * 1500 - ($onWay !== null ? 1200 : 0));
		}
		usort($orders[$id]['cands'], function ($a, $b) { return $a['score'] <=> $b['score']; });
		if ($orders[$id]['cands']) { $orders[$id]['cands'][0]['best'] = true; }
	}
	return array('now' => $now, 'origin' => $origin ? array('lat' => $origin[0], 'lng' => $origin[1]) : null, 'drivers' => $drivers, 'orders' => array_values($orders),
		'track_days' => max(0, min(30, (int)shop_setting('track_days'))));
}

// ---- driver page: how far a delivery is from the restaurant and in which district it lies.
// Both are looked up once per order (the first time a driver's page lists it) and stored on the order: the district as the raw name
// OpenStreetMap gives for the address (Nominatim reverse), the way as driving distance/time (OSRM). The shown district applies our own
// corrections on top (tp_shop_suburbs), so a change of a rule takes effect on the orders already stored. If a service does not answer,
// the page shows the straight line instead (marked as such) and the lookup is tried again after ten minutes.
function shop_haversine_m($lat1, $lng1, $lat2, $lng2) {
	$r = 6371000.0; $p1 = deg2rad($lat1); $p2 = deg2rad($lat2); $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
	$a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
	return (int)round(2 * $r * asin(min(1, sqrt($a))));
}
function shop_http_json($url, $timeout = 6) {
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => $timeout,
		CURLOPT_USERAGENT => 'mySeat-Lieferservice/1.0 (Amadeus Hildesheim; hamun@amds.at)'));
	$raw = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if ($http !== 200 || !is_string($raw)) { return null; }
	$j = json_decode($raw, true);
	return is_array($j) ? $j : null;
}
// the district name OpenStreetMap knows for a point: '' when it knows none, null when the service did not answer
function shop_suburb_osm($lat, $lng) {
	$j = shop_http_json('https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=16&accept-language=de&lat='.rawurlencode((string)$lat).'&lon='.rawurlencode((string)$lng));
	if ($j === null) { return null; }
	$a = isset($j['address']) && is_array($j['address']) ? $j['address'] : array();
	foreach (array('suburb', 'city_district', 'borough', 'quarter', 'neighbourhood', 'village', 'hamlet', 'town') as $k) {
		if (!empty($a[$k])) { return mb_substr(trim((string)$a[$k]), 0, 80); }
	}
	return '';
}
// driving way from the restaurant: array(meters, seconds) or null
function shop_route_osrm($fromLat, $fromLng, $toLat, $toLng) {
	$base = rtrim((string)shop_setting('route_url'), '/');
	if (strpos($base, 'https://') !== 0) { return null; }
	$j = shop_http_json($base.'/route/v1/driving/'.sprintf('%.6F,%.6F;%.6F,%.6F', $fromLng, $fromLat, $toLng, $toLat).'?overview=false');
	if (!$j || ($j['code'] ?? '') !== 'Ok' || empty($j['routes'][0]['distance'])) { return null; }
	return array((int)round($j['routes'][0]['distance']), (int)round($j['routes'][0]['duration']));
}
// looks up district and way for up to $max of the given orders that have none yet (Nominatim allows one request a second); returns how many it handled
function shop_order_geo_fill($ids, $max = 3) {
	$ids = array_values(array_filter(array_map('intval', (array)$ids)));
	if (!$ids) { return 0; }
	$rows = fb_rows("SELECT id, lat, lng, suburb, route_m, route_s FROM ".fb_t('tp_shop_orders')." WHERE id IN (".implode(',', $ids).") AND lat IS NOT NULL
		AND (geo_at IS NULL OR (geo_at < ? AND (suburb IS NULL OR route_m IS NULL))) ORDER BY id LIMIT ".(int)$max, 's', array(date('Y-m-d H:i:s', time() - 600)));
	if (!$rows) { return 0; }
	$origin = shop_origin(); $n = 0; $t0 = microtime(true); $done = 0;
	foreach ($rows as $r) {
		if ($done && microtime(true) - $t0 > 3.5) { break; } // the page must not wait long: what is missing is fetched with the next poll
		$lat = (float)$r['lat']; $lng = (float)$r['lng']; $sub = $r['suburb']; $m = $r['route_m']; $s = $r['route_s'];
		if ($sub === null) {
			if ($n++) { usleep(1100000); }
			$x = shop_suburb_osm($lat, $lng); if ($x !== null) { $sub = $x; }
		}
		if ($m === null && $origin) { $rt = shop_route_osrm($origin[0], $origin[1], $lat, $lng); if ($rt) { $m = $rt[0]; $s = $rt[1]; } }
		fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET suburb = ?, route_m = ?, route_s = ?, geo_at = ? WHERE id = ?", 'siisi', array($sub, $m, $s, date('Y-m-d H:i:s'), (int)$r['id']));
		$done++;
	}
	return $done;
}
// the street without its house number, lower case, "str." spelled out: the key of a street rule
function shop_suburb_street_key($street) {
	$s = mb_strtolower(trim((string)$street));
	$s = preg_replace('/[\s,]+\d+\s*[a-z]?(\s*[-\/]\s*\d+\s*[a-z]?)?\s*$/u', '', $s);
	$s = preg_replace('/str\.(?=\s|$)/u', 'straße', $s);
	$s = preg_replace('/str(?=\s|$)/u', 'straße', $s);
	return trim(preg_replace('/\s+/u', ' ', $s));
}
function shop_suburb_rules() {
	static $rules = null;
	if ($rules === null) {
		$rules = array('street' => array(), 'zip' => array(), 'name' => array());
		foreach (fb_rows("SELECT kind, pattern, suburb FROM ".fb_t('tp_shop_suburbs')) as $x) {
			if (!isset($rules[$x['kind']])) { continue; }
			$key = $x['kind'] === 'street' ? shop_suburb_street_key($x['pattern']) : mb_strtolower(trim($x['pattern']));
			$rules[$x['kind']][$key] = $x['suburb'];
		}
	}
	return $rules;
}
// the district shown to the driver: street rule, then PLZ rule, then what OpenStreetMap says (renamed by a name rule)
function shop_suburb_label($street, $zip, $osm) {
	$r = shop_suburb_rules();
	$sk = shop_suburb_street_key($street);
	if ($sk !== '' && isset($r['street'][$sk])) { return $r['street'][$sk]; }
	$zk = mb_strtolower(trim((string)$zip));
	if ($zk !== '' && isset($r['zip'][$zk])) { return $r['zip'][$zk]; }
	$osm = trim((string)$osm);
	if ($osm === '') { return ''; }
	$ok = mb_strtolower($osm);
	return isset($r['name'][$ok]) ? $r['name'][$ok] : $osm;
}
function shop_suburbs_list() { return fb_rows("SELECT id, kind, pattern, suburb FROM ".fb_t('tp_shop_suburbs')." ORDER BY FIELD(kind, 'name', 'zip', 'street'), pattern"); }
function shop_suburb_save($id, $kind, $pattern, $suburb) {
	$kind = (string)$kind; $pattern = trim(mb_substr((string)$pattern, 0, 120)); $suburb = trim(mb_substr((string)$suburb, 0, 80));
	if (!in_array($kind, array('street', 'zip', 'name'), true)) { return array('ok' => false, 'error' => 'Unbekannte Art der Regel.'); }
	if ($pattern === '' || $suburb === '') { return array('ok' => false, 'error' => 'Bitte beide Felder ausfüllen.'); }
	if ($kind === 'street') { $pattern = trim(preg_replace('/\s+/u', ' ', $pattern)); }
	if ((int)$id > 0) { fb_exec("UPDATE ".fb_t('tp_shop_suburbs')." SET kind = ?, pattern = ?, suburb = ? WHERE id = ?", 'sssi', array($kind, $pattern, $suburb, (int)$id)); }
	else { fb_exec("INSERT INTO ".fb_t('tp_shop_suburbs')." (kind, pattern, suburb) VALUES (?, ?, ?)", 'sss', array($kind, $pattern, $suburb)); }
	return array('ok' => true);
}
function shop_suburb_delete($id) { fb_exec("DELETE FROM ".fb_t('tp_shop_suburbs')." WHERE id = ?", 'i', array((int)$id)); return array('ok' => true); }
// what OpenStreetMap called the districts of the recent deliveries, with how often: the starting point for renaming
function shop_suburbs_seen() {
	return fb_rows("SELECT suburb AS name, COUNT(*) AS n FROM ".fb_t('tp_shop_orders')." WHERE type = 'delivery' AND suburb IS NOT NULL AND suburb <> '' AND created_at > ? GROUP BY suburb ORDER BY n DESC, suburb LIMIT 40",
		's', array(date('Y-m-d H:i:s', time() - 90 * 86400)));
}

// deliveries ready to go, either the open pool (driverId null, nobody has it yet) or one driver's own
// accepted-but-not-yet-started queue (driverId given) - same card shape either way
function shop_driver_pool_orders($driverId = null) {
	shop_ensure_schema();
	$cond = $driverId === null ? 'o.driver_id IS NULL' : 'o.driver_id = ?';
	$rows = fb_rows("SELECT o.id, o.day_no, o.zip, o.street, o.city, o.address_note, o.note, o.lat, o.lng, o.suburb, o.route_m, o.route_s, o.customer_name,
			o.subtotal_cents, o.fee_cents, o.total_cents, o.payment_method, o.payment_status, o.scheduled_at, o.created_at, o.ready_at, z.name AS zone_name,
			(SELECT COALESCE(SUM(qty), 0) FROM ".fb_t('tp_shop_order_items')." WHERE order_id = o.id) AS n_items
		FROM ".fb_t('tp_shop_orders')." o LEFT JOIN ".fb_t('tp_shop_zones')." z ON z.id = o.zone_id
		WHERE o.type = 'delivery' AND o.status = 'ready' AND $cond ORDER BY COALESCE(o.scheduled_at, o.created_at)",
		$driverId === null ? '' : 'i', $driverId === null ? array() : array((int)$driverId));
	return array_map('shop_driver_card', $rows);
}
// one delivery as the card of the driver page; distance and district as far as they are known
function shop_driver_card($r) {
	static $origin = false;
	if ($origin === false) { $origin = shop_origin(); }
	$lat = $r['lat'] !== null ? (float)$r['lat'] : null; $lng = $r['lng'] !== null ? (float)$r['lng'] : null;
	$km = null; $min = null; $approx = false;
	if ($r['route_m'] !== null) { $km = round((int)$r['route_m'] / 1000, 1); $min = $r['route_s'] !== null ? max(1, (int)round((int)$r['route_s'] / 60)) : null; }
	elseif ($origin && $lat !== null) { $km = round(shop_haversine_m($origin[0], $origin[1], $lat, $lng) / 1000, 1); $approx = true; }
	$paid = ($r['payment_method'] === 'mollie' || $r['payment_status'] === 'paid');
	$readyAt = !empty($r['ready_at']) ? strtotime($r['ready_at']) : null;
	return array('id' => (int)$r['id'], 'day_no' => (int)$r['day_no'], 'zip' => $r['zip'], 'zone' => isset($r['zone_name']) ? $r['zone_name'] : null, 'customer_name' => $r['customer_name'],
		'street' => $r['street'], 'door' => $r['address_note'], 'suburb' => shop_suburb_label($r['street'], $r['zip'], $r['suburb']),
		'km' => $km, 'min' => $min, 'approx' => $approx, 'lat' => $lat, 'lng' => $lng,
		'paykind' => $paid ? 'paid' : ($r['payment_method'] === 'cash' ? 'cash' : 'card'), 'collect' => $paid ? 0 : (int)$r['total_cents'],
		'waiting' => $readyAt ? max(0, (int)floor((time() - $readyAt) / 60)) : null,
		'items' => (int)(isset($r['n_items']) ? $r['n_items'] : 0), 'total' => (int)$r['total_cents'],
		'when' => $r['scheduled_at'] ? date('H:i', strtotime($r['scheduled_at'])) : 'so schnell wie möglich');
}
function shop_driver_open_orders() { return shop_driver_pool_orders(null); }
function shop_driver_queued_orders($driverId) { return shop_driver_pool_orders((int)$driverId); }
// claims an open delivery for a driver, atomically - the WHERE guards against two drivers tapping
// "Annehmen" on the same order at the same moment; only the one whose UPDATE actually matched a row wins.
// Claiming only reserves it into the driver's own queue (still 'ready') - it does not go live/trackable
// for the guest until he explicitly starts it (shop_driver_start_order()), so he can bundle several
// deliveries before setting off, while never having more than one guest watching him at once.
function shop_driver_claim_order($driverId, $orderId) {
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_id = ? WHERE id = ? AND driver_id IS NULL AND status = 'ready' AND type = 'delivery'",
		'ii', array((int)$driverId, (int)$orderId));
	// re-read rather than trust mysqli_affected_rows() here: it is the one check that must never say "ok"
	// while the UPDATE quietly matched nothing - that was exactly the bug where a claimed delivery vanished
	// from the open pool but never reached the driver's own queue
	$check = fb_row("SELECT driver_id FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$orderId));
	if (!$check || (int)$check['driver_id'] !== (int)$driverId) {
		return array('ok' => false, 'error' => 'Diese Lieferung wurde gerade von einem anderen Fahrer angenommen.');
	}
	return array('ok' => true);
}
// makes one of the driver's own queued (claimed, not yet started) deliveries the active one - only
// allowed while he has no other delivery already underway, so exactly one guest can ever watch him live
function shop_driver_start_order($driverId, $orderId) {
	$o = fb_row("SELECT id, status FROM ".fb_t('tp_shop_orders')." WHERE id = ? AND driver_id = ?", 'ii', array((int)$orderId, (int)$driverId));
	if (!$o || $o['status'] !== 'ready') { return array('ok' => false, 'error' => 'Diese Lieferung gehört nicht zu deiner Warteliste.'); }
	if (shop_driver_current_order($driverId)) { return array('ok' => false, 'error' => 'Du hast schon eine aktive Lieferung - erst die abschließen, pausieren oder zurückgeben.'); }
	$names = shop_drivers_name_map();
	if (!shop_set_status((int)$orderId, 'delivering', 'Fahrer: '.(isset($names[(int)$driverId]) ? $names[(int)$driverId] : ''))) {
		return array('ok' => false, 'error' => 'Die Lieferung konnte nicht auf "unterwegs" gesetzt werden. Bitte bei der Disposition melden.');
	}
	return array('ok' => true);
}
// takes the active delivery off "live" and back into the driver's own queue, without releasing it to
// other drivers - for switching to a different one of his own without losing this one
function shop_driver_pause_order($driverId, $orderId) {
	$o = fb_row("SELECT id, status FROM ".fb_t('tp_shop_orders')." WHERE id = ? AND driver_id = ?", 'ii', array((int)$orderId, (int)$driverId));
	if (!$o || $o['status'] !== 'delivering') { return array('ok' => false, 'error' => 'Diese Lieferung ist nicht unterwegs.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_lat = NULL, driver_lng = NULL, driver_at = NULL WHERE id = ?", 'i', array((int)$orderId));
	if (!shop_set_status((int)$orderId, 'ready', 'Fahrer: pausiert')) { return array('ok' => false, 'error' => 'Die Lieferung konnte nicht pausiert werden.'); }
	return array('ok' => true);
}
// the driver could not deliver (wrong address, guest unreachable, ...): terminal, frees him up for the
// next one. Kept apart from 'cancelled' so dispatch can tell "we called it off" from "it failed on the road"
function shop_driver_fail_order($driverId, $orderId, $reason) {
	$o = fb_row("SELECT id, status FROM ".fb_t('tp_shop_orders')." WHERE id = ? AND driver_id = ?", 'ii', array((int)$orderId, (int)$driverId));
	if (!$o || $o['status'] !== 'delivering') { return array('ok' => false, 'error' => 'Diese Lieferung ist nicht unterwegs.'); }
	$reason = trim(mb_substr((string)$reason, 0, 255));
	if ($reason === '') { return array('ok' => false, 'error' => 'Bitte kurz beschreiben, was das Problem war.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_lat = NULL, driver_lng = NULL, driver_at = NULL, fail_reason = ? WHERE id = ?", 'si', array($reason, (int)$orderId));
	if (!shop_set_status((int)$orderId, 'failed', 'Fahrer: '.$reason)) { return array('ok' => false, 'error' => 'Die Meldung konnte nicht gespeichert werden.'); }
	return array('ok' => true);
}
// the combined view the driver app polls: his active delivery (if any), his own queue (in a sensible tour order), the open pool, how fresh his
// own GPS position is, the restaurant for the overview map and what he has done today
function shop_driver_state($driverId) {
	$driverId = (int)$driverId;
	$cur = shop_driver_current_order($driverId);
	$queued = shop_driver_queued_orders($driverId); $open = shop_driver_open_orders();
	$ids = array(); if ($cur) { $ids[] = (int)$cur['id']; }
	foreach (array_merge($queued, $open) as $c) { $ids[] = $c['id']; }
	if (shop_order_geo_fill($ids)) { $cur = $cur ? shop_driver_current_order($driverId) : null; $queued = shop_driver_queued_orders($driverId); $open = shop_driver_open_orders(); }
	$view = $cur ? shop_driver_order_view($cur) : null;
	$origin = shop_origin();
	$pos = fb_row("SELECT lat, lng, updated_at FROM ".fb_t('tp_shop_driver_positions')." WHERE driver_id = ?", 'i', array($driverId));
	$gpsAge = $pos ? max(0, time() - strtotime($pos['updated_at'])) : null;
	$here = ($pos && $gpsAge <= 180) ? array((float)$pos['lat'], (float)$pos['lng']) : null;
	// tour order of the queue: always the nearest one next, starting where the driver is (the delivery he is on, else the restaurant)
	$from = ($view && $view['lat'] !== null) ? array($view['lat'], $view['lng']) : $origin;
	$rest = array(); foreach ($queued as $i => $c) { if ($c['lat'] !== null) { $rest[$i] = $c; } }
	$rank = 0;
	while ($rest && $from) {
		$best = null; $bd = null;
		foreach ($rest as $i => $c) { $d = shop_haversine_m($from[0], $from[1], $c['lat'], $c['lng']); if ($bd === null || $d < $bd) { $bd = $d; $best = $i; } }
		$queued[$best]['tour'] = ++$rank; $from = array($rest[$best]['lat'], $rest[$best]['lng']); unset($rest[$best]);
	}
	foreach ($queued as $i => $c) { if (!isset($c['tour'])) { $queued[$i]['tour'] = 99; } $queued[$i]['lines'] = shop_driver_lines(shop_order_items($c['id'])); } // the lines are for the check before the start
	usort($queued, function ($a, $b) { return $a['tour'] <=> $b['tour']; });
	// near other deliveries (same district or within 1.5 km): worth one trip; and the straight line from where the driver is now
	$all = array_merge($view ? array($view) : array(), $queued, $open);
	$near = function ($c) use ($all) {
		if ($c['lat'] === null) { return null; }
		$best = null;
		foreach ($all as $o) {
			if ($o['id'] === $c['id'] || $o['lat'] === null) { continue; }
			$d = shop_haversine_m($c['lat'], $c['lng'], $o['lat'], $o['lng']);
			if (($d <= 1500 || ($c['suburb'] !== '' && $c['suburb'] === $o['suburb'])) && ($best === null || $d < $best['m'])) { $best = array('no' => $o['day_no'], 'm' => $d); }
		}
		return $best;
	};
	foreach (array('queued', 'open') as $k) {
		foreach ($$k as $i => $c) {
			${$k}[$i]['near'] = $near($c);
			${$k}[$i]['you_km'] = ($here && $c['lat'] !== null) ? round(shop_haversine_m($here[0], $here[1], $c['lat'], $c['lng']) / 1000, 1) : null;
		}
	}
	if ($view) { $view['near'] = $near($view); }
	return array('current' => $view, 'queued' => $queued, 'open' => $open, 'gps' => array('age' => $gpsAge, 'lat' => ($pos && $gpsAge <= 900) ? (float)$pos['lat'] : null, 'lng' => ($pos && $gpsAge <= 900) ? (float)$pos['lng'] : null),
		'origin' => $origin ? array('lat' => $origin[0], 'lng' => $origin[1]) : null, 'shift' => shop_driver_shift($driverId));
}
// what this driver did since midnight: deliveries, kilometres from the restaurant, cash and card payments collected at the door
function shop_driver_shift($driverId) {
	$r = fb_row("SELECT SUM(status = 'done') AS n, SUM(status = 'failed') AS failed, COALESCE(SUM(CASE WHEN status = 'done' THEN route_m ELSE 0 END), 0) AS m,
			COALESCE(SUM(CASE WHEN status = 'done' AND payment_method = 'cash' THEN total_cents ELSE 0 END), 0) AS cash,
			COALESCE(SUM(CASE WHEN status = 'done' AND payment_method = 'card_door' THEN total_cents ELSE 0 END), 0) AS card
		FROM ".fb_t('tp_shop_orders')." WHERE driver_id = ? AND status IN ('done', 'failed') AND done_at >= ?", 'is', array((int)$driverId, date('Y-m-d 00:00:00')));
	return array('done' => (int)($r['n'] ?? 0), 'failed' => (int)($r['failed'] ?? 0), 'km' => round((int)($r['m'] ?? 0) * 2 / 1000, 1), // there and back, an estimate
		'cash' => (int)($r['cash'] ?? 0), 'card' => (int)($r['card'] ?? 0));
}
// hands a delivery the driver can no longer make back into the open pool for everyone else
// shared by the driver's own "Zurück in den Pool" and the dispatch override below - puts a claimed
// delivery back to unassigned/ready so any driver (the same one or another) can take it again
function shop_order_release_to_pool($orderId, $by) {
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_id = NULL, driver_lat = NULL, driver_lng = NULL, driver_at = NULL WHERE id = ?", 'i', array((int)$orderId));
	if (!shop_set_status((int)$orderId, 'ready', $by)) { return array('ok' => false, 'error' => 'Die Lieferung konnte nicht zurückgegeben werden.'); }
	return array('ok' => true);
}
function shop_driver_release_order($driverId, $orderId) {
	$o = fb_row("SELECT id FROM ".fb_t('tp_shop_orders')." WHERE id = ? AND driver_id = ?", 'ii', array((int)$orderId, (int)$driverId));
	if (!$o) { return array('ok' => false, 'error' => 'Diese Lieferung gehört nicht zu dir.'); }
	return shop_order_release_to_pool($orderId, 'Fahrer: zurückgegeben');
}
// dispatch's own override: puts a delivery back into the open pool regardless of which driver has it
// (or even if none does and it was just set "unterwegs" by hand) - for when a driver can't be reached
// the drivers the dispatch can hand a delivery to: active ones, with how long ago the phone last reported its position
function shop_dispatch_drivers() {
	$out = array();
	foreach (shop_drivers_list() as $d) {
		if ((int)$d['active'] !== 1) { continue; }
		$out[] = array('id' => (int)$d['id'], 'name' => $d['name'], 'seen_min' => $d['last_seen_min'] === null ? null : (int)$d['last_seen_min']);
	}
	return $out;
}
// A failed delivery (guest not met, address wrong) is fetched back: address details are corrected, the order goes back into the pool as
// ready and can be delivered again. A changed street/zip/city is checked against the delivery zones again (new coordinates and zone); the
// amount the guest paid stays as it is, a difference in the delivery fee only goes into the log.
function shop_dispatch_retry_order($orderId, $in, $by) {
	$o = shop_order($orderId);
	if (!$o || $o['type'] !== 'delivery' || $o['status'] !== 'failed') { return array('ok' => false, 'error' => 'Nur eine fehlgeschlagene Lieferung lässt sich zurückholen.'); }
	$f = function ($k, $max) use ($in) { return trim(mb_substr((string)(isset($in[$k]) ? $in[$k] : ''), 0, $max)); };
	$street = $f('street', 160); $zip = $f('zip', 10); $city = $f('city', 80); $note = $f('note', 200); $phone = $f('phone', 40);
	if ($street === '' || $city === '' || !preg_match('/\d/', $street)) { return array('ok' => false, 'error' => 'Bitte gib Straße mit Hausnummer und den Ort an.'); }
	if ($phone !== '' && (!preg_match('/^[+0-9 ()\/.\-]{6,40}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 6)) { return array('ok' => false, 'error' => 'Die Telefonnummer sieht nicht richtig aus.'); }
	$changed = ($street !== (string)$o['street'] || $zip !== (string)$o['zip'] || $city !== (string)$o['city']);
	$lat = $o['lat']; $lng = $o['lng']; $zone = $o['zone_id']; $feeNote = '';
	if ($changed) {
		$r = shop_find_zone($street, $zip, $city);
		if (!$r['ok']) { return array('ok' => false, 'error' => isset($r['reason']) && $r['reason'] === 'ambiguous' ? 'Es passen mehrere Adressen. Bitte ergänze die Postleitzahl.' : $r['error']); }
		$lat = $r['lat']; $lng = $r['lng']; $zone = $r['zone']['id'];
		if ($zip === '' && !empty($r['postcode'])) { $zip = (string)$r['postcode']; }
		if ((int)$r['zone']['fee_cents'] !== (int)$o['fee_cents']) { $feeNote = 'Liefergebühr der neuen Zone '.shop_money((int)$r['zone']['fee_cents']).' statt '.shop_money((int)$o['fee_cents']).', Betrag unverändert'; }
	}
	$diff = array();
	foreach (array('street' => $street, 'zip' => $zip, 'city' => $city, 'address_note' => $note, 'phone' => $phone) as $k => $v) { if ($v !== (string)$o[$k] && !($k === 'phone' && $phone === '')) { $diff[] = $k.': "'.$o[$k].'" -> "'.$v.'"'; } }
	if ($phone === '') { $phone = (string)$o['phone']; }
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET street = ?, zip = ?, city = ?, address_note = ?, phone = ?, lat = ?, lng = ?, zone_id = ?, fail_reason = '', driver_id = NULL,
		driver_lat = NULL, driver_lng = NULL, driver_at = NULL, done_at = NULL WHERE id = ?", 'sssssddii', array($street, $zip, $city, $note, $phone, $lat, $lng, $zone, (int)$orderId));
	shop_coupon_reclaim($orderId);
	if (!shop_set_status((int)$orderId, 'ready', $by.': Zustellung wiederholt')) { return array('ok' => false, 'error' => 'Die Lieferung konnte nicht zurückgeholt werden.'); }
	shop_log((int)$orderId, 'Zurückgeholt', mb_substr(($diff ? implode('; ', $diff) : 'Adresse unverändert').($feeNote !== '' ? ' | '.$feeNote : '').' | Grund vorher: '.$o['fail_reason'], 0, 200));
	return array('ok' => true, 'fee_note' => $feeNote);
}
// the dispatch hands a ready delivery to a driver: it lands in his own queue, exactly as if he had taken it himself. A delivery another
// driver holds (not yet underway) first goes back into the pool.
function shop_dispatch_assign_order($orderId, $driverId, $by) {
	$o = fb_row("SELECT id, type, status, driver_id FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$orderId));
	if (!$o || $o['type'] !== 'delivery' || $o['status'] !== 'ready') { return array('ok' => false, 'error' => 'Nur eine fertige Lieferung lässt sich einem Fahrer zuteilen.'); }
	$d = fb_row("SELECT id, name, active FROM ".fb_t('tp_shop_drivers')." WHERE id = ?", 'i', array((int)$driverId));
	if (!$d || (int)$d['active'] !== 1) { return array('ok' => false, 'error' => 'Diesen Fahrer gibt es nicht oder er ist nicht aktiv.'); }
	if ($o['driver_id'] !== null && (int)$o['driver_id'] === (int)$driverId) { return array('ok' => true); }
	if ($o['driver_id'] !== null) { $r = shop_order_release_to_pool($orderId, $by.': umgeteilt'); if (!$r['ok']) { return $r; } }
	$r = shop_driver_claim_order($driverId, $orderId);
	if ($r['ok']) { shop_log((int)$orderId, 'Zugeteilt', $by.': '.$d['name']); }
	return $r;
}
function shop_dispatch_release_order($orderId, $by) {
	$o = fb_row("SELECT id, type, status, driver_id FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$orderId));
	if (!$o || $o['type'] !== 'delivery' || $o['driver_id'] === null || !in_array($o['status'], array('ready', 'delivering'), true)) {
		return array('ok' => false, 'error' => 'Diese Lieferung ist keinem Fahrer zugeteilt.');
	}
	return shop_order_release_to_pool($orderId, $by.': zurück in den Pool');
}
function shop_driver_complete_order($driverId, $orderId) {
	$o = fb_row("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE id = ? AND driver_id = ?", 'ii', array((int)$orderId, (int)$driverId));
	if (!$o || $o['status'] !== 'delivering') { return array('ok' => false, 'error' => 'Diese Lieferung ist nicht unterwegs.'); }
	if ($o['payment_method'] !== 'mollie' && $o['payment_status'] !== 'paid') { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET payment_status = 'paid' WHERE id = ?", 'i', array((int)$orderId)); }
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_lat = NULL, driver_lng = NULL, driver_at = NULL WHERE id = ?", 'i', array((int)$orderId));
	if (!shop_set_status((int)$orderId, 'done', 'Fahrer')) { return array('ok' => false, 'error' => 'Die Lieferung konnte nicht abgeschlossen werden.'); }
	return array('ok' => true);
}
// the one delivery (if any) this driver currently has - the detail view shown instead of the open list
function shop_driver_current_order($driverId) {
	return fb_row("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE driver_id = ? AND status = 'delivering' LIMIT 1", 'i', array((int)$driverId));
}
// the full-order JSON order/driver.php shows once a driver has a delivery: address, what to collect,
// what to hand over - same facts the old token-link page showed, just reached a different way
function shop_driver_order_view($o) {
	$items = shop_order_items((int)$o['id']);
	$addr = trim($o['street'].', '.$o['zip'].' '.$o['city']);
	// a what3words-sourced order has no real street for Google Maps to search - it does have the
	// coordinate the code resolved to (shop_find_zone_w3w()), which is what the driver actually needs
	$isW3w = strpos($o['street'], 'what3words: ') === 0;
	$routeDest = ($isW3w && $o['lat'] !== null && $o['lng'] !== null) ? $o['lat'].','.$o['lng'] : $addr;
	$pay = ($o['payment_method'] === 'mollie' || $o['payment_status'] === 'paid') ? 'Bezahlt, nichts zu kassieren' :
		(($o['payment_method'] === 'cash' ? 'BAR kassieren: ' : 'KARTE kassieren: ').shop_money((int)$o['total_cents']));
	return array_merge(shop_driver_card($o), array('id' => (int)$o['id'], 'day_no' => (int)$o['day_no'], 'address' => $addr, 'address_note' => $o['address_note'], 'route_dest' => $routeDest,
		'customer_name' => $o['customer_name'], 'phone' => $o['phone'], 'pay' => $pay, 'pay_with' => !empty($o['pay_with_cents']) && $o['payment_method'] === 'cash' ? (int)$o['pay_with_cents'] : 0, 'note' => $o['note'], 'since' => !empty($o['updated_at']) ? strtotime($o['updated_at']) : null,
		'items' => shop_driver_lines($items)));
}
// the dishes of an order for the driver: quantity, title, size, the options as one text, the note of the guest
function shop_driver_lines($items) {
	return array_map(function ($it) {
		$opts = array(); foreach ($it['options'] as $op) { $opts[] = ($op['qty'] > 1 ? (int)$op['qty'].'× ' : '').$op['title']; }
		return array('qty' => (int)$it['qty'], 'title' => $it['title'], 'variation' => $it['variation'], 'opts' => implode(', ', $opts), 'note' => $it['note']);
	}, $items);
}
// for the dispatch live map: every driver whose own position ping is recent, with the delivery (if any) he currently has
function shop_drivers_live($maxAgeSeconds = 600) {
	$rows = fb_rows("SELECT d.id, d.name, p.lat, p.lng, p.updated_at,
			o.id AS order_id, o.day_no, o.street, o.zip, o.city
		FROM ".fb_t('tp_shop_drivers')." d
		JOIN ".fb_t('tp_shop_driver_positions')." p ON p.driver_id = d.id
		LEFT JOIN ".fb_t('tp_shop_orders')." o ON o.driver_id = d.id AND o.status = 'delivering'
		WHERE d.active = 1");
	$out = array();
	foreach ($rows as $r) {
		$age = time() - strtotime($r['updated_at']);
		if ($age > $maxAgeSeconds) { continue; }
		$out[] = array('id' => (int)$r['id'], 'name' => $r['name'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'age' => $age,
			'order' => $r['order_id'] ? array('id' => (int)$r['order_id'], 'day_no' => (int)$r['day_no'], 'address' => trim($r['street'].', '.$r['zip'].' '.$r['city'])) : null);
	}
	return $out;
}

// ---- SMS to the guest at the two moments that matter: the delivery is on its way (with the link to follow it), the pickup is ready.
// Only for real orders with a mobile number, once per order, and only when SMS sending is set up (Einstellungen > SMS).
function shop_site_url() {
	require_once __DIR__.'/hosts.class.php';
	$h = myseat_hosts(); if ($h['app'] !== '') { return $h['app']; } // config/hosts.inc.php: links for guests always lead to the guest domain
	if (function_exists('shop_base_url')) { return shop_base_url(); } // order pages
	require_once __DIR__.'/cancel_link.class.php';
	return cl_site_url();
}
function shop_sms_text($event, $brand, $number, $link) {
	$long = strlen($link) > 40; // the full status link (YOURLS not available) leaves less room: shorter sentences
	if ($event === 'order_delivering') { return sms_fit('', $brand, $long ? ': Dein Essen ist unterwegs! Live verfolgen: '.$link : ': Dein Essen ist unterwegs! Gleich klingelt es bei dir. Live verfolgen: '.$link); }
	return sms_fit('', $brand, $long ? ': Bestellung '.$number.' ist abholbereit! Details: '.$link : ': Deine Bestellung '.$number.' ist abholbereit. Wir freuen uns auf dich! Details: '.$link);
}
function shop_sms_status($id, $status) {
	if (!in_array($status, array('delivering', 'ready'), true)) { return; }
	try {
		$o = shop_order($id);
		if (!$o || (int)$o['is_test'] || !shop_flag('sms_orders')) { return; }
		if (in_array($o['source'], array('lieferando', 'uber_eats'), true)) { return; }   // the platform tells the guest itself
		$event = ($status === 'delivering' && $o['type'] === 'delivery') ? 'order_delivering' : (($status === 'ready' && $o['type'] === 'pickup') ? 'order_ready' : '');
		if ($event === '' || !sms_enabled()) { return; }
		$mobile = sms_normalize_phone((string)$o['phone']);
		if ($mobile === null) { return; }
		global $settings;
		$brand = html_entity_decode(!empty($settings['brandName']) ? (string)$settings['brandName'] : 'Amadeus', ENT_QUOTES, 'UTF-8');
		$link = shop_site_url().'/order/status.php?t='.$o['token'];
		if (sms_link_ready()) { $r = sms_yourls_shorten($link, 24 * 60, 'mySeat Bestellung'); if (!empty($r['ok']) && !empty($r['short'])) { $link = $r['short']; } }
		// the order id shares the "reservation" column of the outbox: an offset keeps the two apart and makes every text go out once
		sms_enqueue(1000000000 + (int)$o['id'], $mobile, $event, shop_sms_text($event, $brand, $o['number'], $link));
	} catch (Throwable $e) {
		error_log('mySeat order sms: '.$e->getMessage());
	}
}

// ---- coupons ("Gutscheine"): percent or fixed amount off the goods (never the fee or tip), once or many times, limited in time.
// Codes are upper case letters, digits and "-". A redemption is used up when the order is placed and given back when it is cancelled.
function shop_coupon_normalize($code) { return substr(preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)$code)), 0, 40); }
function shop_coupon_guest_keys($phone, $email) {
	$d = preg_replace('/\D/', '', (string)$phone); $d = strlen($d) > 9 ? substr($d, -9) : $d; // 0176 123456 and +49 176 123456 are the same guest
	$e = strtolower(trim((string)$email));
	return array($d !== '' ? sha1('phone|'.$d) : '', $e !== '' ? sha1('mail|'.$e) : '');
}
function shop_coupon_by_code($code) { return fb_row("SELECT * FROM ".fb_t('tp_shop_coupons')." WHERE code = ?", 's', array((string)$code)); }
function shop_coupon_describe($c) {
	return ($c['kind'] === 'percent' ? (int)$c['value'].' %' : shop_money((int)$c['value'])).' Rabatt';
}
// $type: delivery|pickup, $sub: goods in cents, $keys: guest keys (or array('', '') for a preview). Returns ok, error, coupon, discount.
function shop_coupon_check($code, $type, $sub, $keys = array('', '')) {
	shop_ensure_schema();
	$no = function ($msg) { return array('ok' => false, 'error' => $msg, 'coupon' => null, 'discount' => 0); };
	$c = shop_coupon_by_code(shop_coupon_normalize($code));
	if (!$c || !(int)$c['active']) { return $no('Diesen Gutscheincode kennen wir nicht. Bitte prüfe die Schreibweise.'); }
	// a personal coupon (stamp card) works only for the guest it was made for
	if ($c['guest_key'] !== '' || $c['guest_key2'] !== '') {
		if (!(($c['guest_key'] !== '' && $c['guest_key'] === $keys[0]) || ($c['guest_key2'] !== '' && $c['guest_key2'] === $keys[1]))) {
			return $no(($keys[0] !== '' || $keys[1] !== '') ? 'Dieser Gutschein gehört zu einer anderen Telefonnummer oder E-Mail-Adresse. Bitte gib die an, mit der du bestellt hast.' : 'Dieser Gutschein ist persönlich. Bitte gib zuerst deine Telefonnummer oder E-Mail-Adresse an.');
		}
	}
	$now = time();
	if ($c['valid_from'] && strtotime($c['valid_from']) > $now) { return $no('Dieser Gutschein ist erst ab '.date('d.m.Y', strtotime($c['valid_from'])).' gültig.'); }
	if ($c['valid_until'] && strtotime($c['valid_until']) < $now) { return $no('Dieser Gutschein ist leider abgelaufen.'); }
	if ((int)$c['max_uses'] > 0 && (int)$c['used'] >= (int)$c['max_uses']) { return $no((int)$c['max_uses'] === 1 ? 'Dieser Gutschein wurde schon eingelöst.' : 'Dieser Gutschein ist leider aufgebraucht.'); }
	if ($c['applies'] !== 'all' && $c['applies'] !== $type) { return $no($c['applies'] === 'delivery' ? 'Dieser Gutschein gilt nur bei Lieferung.' : 'Dieser Gutschein gilt nur bei Abholung.'); }
	if ((int)$c['min_order_cents'] > 0 && $sub < (int)$c['min_order_cents']) { return $no('Dieser Gutschein gilt ab einem Warenwert von '.shop_money((int)$c['min_order_cents']).'. Dir fehlen noch '.shop_money((int)$c['min_order_cents'] - $sub).'.'); }
	if ((int)$c['per_guest'] && ($keys[0] !== '' || $keys[1] !== '')) {
		$hit = fb_row("SELECT id FROM ".fb_t('tp_shop_coupon_uses')." WHERE coupon_id = ? AND ((guest_key <> '' AND guest_key = ?) OR (guest_key2 <> '' AND guest_key2 = ?)) LIMIT 1", 'iss', array((int)$c['id'], $keys[0], $keys[1]));
		if ($hit) { return $no('Du hast diesen Gutschein schon eingelöst. Er gilt pro Person nur einmal.'); }
	}
	$d = $c['kind'] === 'percent' ? (int)round($sub * (int)$c['value'] / 100) : (int)$c['value'];
	if ($c['kind'] === 'percent' && (int)$c['max_discount_cents'] > 0) { $d = min($d, (int)$c['max_discount_cents']); }
	$d = max(0, min($d, $sub));
	if ($d <= 0) { return $no('Dieser Gutschein bringt bei deiner Bestellung keinen Rabatt.'); }
	return array('ok' => true, 'error' => '', 'coupon' => $c, 'discount' => $d);
}
function shop_coupon_reserve($id) {
	$st = fb_exec("UPDATE ".fb_t('tp_shop_coupons')." SET used = used + 1 WHERE id = ? AND active = 1 AND (max_uses = 0 OR used < max_uses)", 'i', array((int)$id));
	if (!$st) { return false; }
	$n = mysqli_stmt_affected_rows($st); mysqli_stmt_close($st);
	return $n === 1; // the check and the count are one statement: the last redemption goes to exactly one guest
}
function shop_coupon_unreserve($id) { fb_exec("UPDATE ".fb_t('tp_shop_coupons')." SET used = GREATEST(used - 1, 0) WHERE id = ?", 'i', array((int)$id)); }
// a failed order gave its coupon back (shop_coupon_release); when the delivery is tried again the redemption counts again
function shop_coupon_reclaim($orderId) {
	$o = shop_order($orderId);
	if (!$o || (int)$o['is_test'] || (string)$o['coupon_code'] === '') { return; }
	$c = shop_coupon_by_code($o['coupon_code']);
	if (!$c || fb_row("SELECT id FROM ".fb_t('tp_shop_coupon_uses')." WHERE order_id = ?", 'i', array((int)$orderId))) { return; }
	fb_exec("INSERT INTO ".fb_t('tp_shop_coupon_uses')." (coupon_id, order_id, guest_key, guest_key2, discount_cents, created_at) VALUES (?, ?, ?, ?, ?, NOW())", 'iissi',
		array((int)$c['id'], (int)$orderId, (string)$o['guest_key'], (string)$o['guest_key2'], (int)$o['discount_cents']));
	fb_exec("UPDATE ".fb_t('tp_shop_coupons')." SET used = used + 1 WHERE id = ?", 'i', array((int)$c['id']));
}
function shop_coupon_release($orderId) {
	foreach (fb_rows("SELECT id, coupon_id FROM ".fb_t('tp_shop_coupon_uses')." WHERE order_id = ?", 'i', array((int)$orderId)) as $u) {
		fb_exec("DELETE FROM ".fb_t('tp_shop_coupon_uses')." WHERE id = ?", 'i', array((int)$u['id']));
		shop_coupon_unreserve((int)$u['coupon_id']);
	}
}

// ---- coupons in the backend editor
function shop_me_coupon_row($c) {
	$dt = function ($v) { return $v ? str_replace(' ', 'T', substr($v, 0, 16)) : ''; };
	return array('id' => (int)$c['id'], 'code' => $c['code'], 'note' => $c['note'], 'kind' => $c['kind'], 'value' => (int)$c['value'], 'max_discount' => (int)$c['max_discount_cents'], 'min_order' => (int)$c['min_order_cents'],
		'applies' => $c['applies'], 'valid_from' => $dt($c['valid_from']), 'valid_until' => $dt($c['valid_until']), 'max_uses' => (int)$c['max_uses'], 'per_guest' => (int)$c['per_guest'],
		'used' => (int)$c['used'], 'active' => (int)$c['active'],
		'state' => !(int)$c['active'] ? 'off' : (($c['valid_until'] && strtotime($c['valid_until']) < time()) ? 'expired' : (((int)$c['max_uses'] > 0 && (int)$c['used'] >= (int)$c['max_uses']) ? 'used' : (($c['valid_from'] && strtotime($c['valid_from']) > time()) ? 'soon' : 'on'))));
}
function shop_me_coupons() {
	$out = array();
	foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_coupons')." ORDER BY active DESC, created_at DESC, id DESC") as $c) { $out[] = shop_me_coupon_row($c); }
	return $out;
}
function shop_me_save_coupon($d) {
	$id = (int)(isset($d['id']) ? $d['id'] : 0);
	$code = shop_coupon_normalize(isset($d['code']) ? $d['code'] : '');
	if (strlen($code) < 3) { return array('ok' => false, 'error' => 'Der Code braucht mindestens 3 Zeichen (Buchstaben, Ziffern, Bindestrich).'); }
	$dup = fb_row("SELECT id FROM ".fb_t('tp_shop_coupons')." WHERE code = ? AND id <> ?", 'si', array($code, $id));
	if ($dup) { return array('ok' => false, 'error' => 'Diesen Code gibt es schon.'); }
	$kind = (isset($d['kind']) && $d['kind'] === 'fixed') ? 'fixed' : 'percent';
	$value = $kind === 'percent' ? (int)round((float)str_replace(',', '.', (string)(isset($d['value']) ? $d['value'] : 0))) : shop_cents(isset($d['value']) ? $d['value'] : 0);
	if ($kind === 'percent' && ($value < 1 || $value > 100)) { return array('ok' => false, 'error' => 'Der Rabatt in Prozent muss zwischen 1 und 100 liegen.'); }
	if ($kind === 'fixed' && ($value < 1 || $value > 50000)) { return array('ok' => false, 'error' => 'Der Rabatt in Euro muss zwischen 0,01 und 500 liegen.'); }
	$maxd = $kind === 'percent' ? max(0, shop_cents(isset($d['max_discount']) ? $d['max_discount'] : 0)) : 0;
	$min = max(0, shop_cents(isset($d['min_order']) ? $d['min_order'] : 0));
	$applies = (isset($d['applies']) && in_array($d['applies'], array('delivery', 'pickup'), true)) ? $d['applies'] : 'all';
	$from = (isset($d['valid_from']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)$d['valid_from'])) ? str_replace('T', ' ', $d['valid_from']).':00' : null;
	$until = (isset($d['valid_until']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)$d['valid_until'])) ? str_replace('T', ' ', $d['valid_until']).':59' : null;
	if ($from && $until && $from > $until) { return array('ok' => false, 'error' => '"Gültig bis" liegt vor "gültig ab".'); }
	$maxUses = max(0, min(1000000, (int)(isset($d['max_uses']) ? $d['max_uses'] : 0)));
	$perGuest = empty($d['per_guest']) ? 0 : 1; $active = empty($d['active']) ? 0 : 1;
	$note = mb_substr(trim((string)(isset($d['note']) ? $d['note'] : '')), 0, 160);
	if ($id) {
		if (!fb_row("SELECT id FROM ".fb_t('tp_shop_coupons')." WHERE id = ?", 'i', array($id))) { return array('ok' => false, 'error' => 'Diesen Gutschein gibt es nicht mehr.'); }
		fb_exec("UPDATE ".fb_t('tp_shop_coupons')." SET code = ?, note = ?, kind = ?, value = ?, max_discount_cents = ?, min_order_cents = ?, applies = ?, valid_from = ?, valid_until = ?, max_uses = ?, per_guest = ?, active = ? WHERE id = ?",
			'sssiiisssiiii', array($code, $note, $kind, $value, $maxd, $min, $applies, $from, $until, $maxUses, $perGuest, $active, $id));
	} else {
		fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_from, valid_until, max_uses, per_guest, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
			'sssiiisssiii', array($code, $note, $kind, $value, $maxd, $min, $applies, $from, $until, $maxUses, $perGuest, $active));
		$id = (int)mysqli_insert_id(fb_db());
	}
	return array('ok' => true, 'coupon' => shop_me_coupon_row(fb_row("SELECT * FROM ".fb_t('tp_shop_coupons')." WHERE id = ?", 'i', array($id))));
}
function shop_me_delete_coupon($id) {
	fb_exec("DELETE FROM ".fb_t('tp_shop_coupon_uses')." WHERE coupon_id = ?", 'i', array((int)$id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_coupons')." WHERE id = ?", 'i', array((int)$id)); // placed orders keep the code and amount in their own row
	return array('ok' => true);
}

// ---- upsell ("Noch etwas dazu?"): learns from past real orders which dishes are bought together with what is in the cart.
// Needs enough order history before it trusts the data; until then the caller falls back to its own heuristic.
function shop_upsell_candidates($cartProductIds, $limit = 3) {
	shop_ensure_schema();
	$cartIds = array_values(array_unique(array_filter(array_map('intval', (array)$cartProductIds))));
	$totalOrders = (int)fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND status <> 'cancelled'")['n'];
	$learned = $totalOrders >= 15; // a handful of test clicks should not steer real suggestions
	$found = array();
	if ($learned && $cartIds) {
		$in = implode(',', $cartIds);
		// dishes that showed up in the same past orders as something already in this cart, most often first
		$rows = fb_rows("SELECT oi2.product_id AS pid, COUNT(DISTINCT oi1.order_id) AS n
			FROM ".fb_t('tp_shop_order_items')." oi1
			JOIN ".fb_t('tp_shop_order_items')." oi2 ON oi2.order_id = oi1.order_id AND oi2.product_id IS NOT NULL AND oi2.product_id NOT IN ($in)
			JOIN ".fb_t('tp_shop_orders')." o ON o.id = oi1.order_id
			WHERE oi1.product_id IN ($in) AND o.is_test = 0 AND o.status <> 'cancelled'
			GROUP BY oi2.product_id HAVING n >= 2 ORDER BY n DESC LIMIT 20");
		foreach ($rows as $r) { $found[] = (int)$r['pid']; }
	}
	if (count($found) < $limit) {
		// fill up with the overall bestsellers the guest does not have yet
		$skip = array_merge($cartIds, $found); $skip[] = 0;
		$pop = fb_rows("SELECT oi.product_id AS pid, COUNT(DISTINCT oi.order_id) AS n FROM ".fb_t('tp_shop_order_items')." oi
			JOIN ".fb_t('tp_shop_orders')." o ON o.id = oi.order_id
			WHERE oi.product_id IS NOT NULL AND oi.product_id NOT IN (".implode(',', $skip).") AND o.is_test = 0 AND o.status <> 'cancelled'
			GROUP BY oi.product_id ORDER BY n DESC LIMIT 20");
		foreach ($pop as $r) { $found[] = (int)$r['pid']; }
	}
	$found = array_slice(array_unique($found), 0, $limit + 5);
	$items = array();
	if ($found) {
		$prods = fb_rows("SELECT id, title, price_cents,
				(SELECT MIN(price_cents) FROM ".fb_t('tp_shop_variations')." v WHERE v.product_id = p.id) AS vmin,
				(SELECT COUNT(*) FROM ".fb_t('tp_shop_variations')." v WHERE v.product_id = p.id) AS nvar,
				(SELECT COUNT(*) FROM ".fb_t('tp_shop_product_groups')." pg WHERE pg.product_id = p.id) AS ngroup
			FROM ".fb_t('tp_shop_products')." p WHERE p.id IN (".implode(',', $found).") AND p.active = 1");
		$byId = array(); foreach ($prods as $p) { $byId[(int)$p['id']] = $p; }
		foreach ($found as $pid) {
			if (!isset($byId[$pid]) || count($items) >= $limit) { continue; }
			$p = $byId[$pid];
			$choices = ((int)$p['nvar'] > 0 || (int)$p['ngroup'] > 0);
			$items[] = array('id' => (int)$p['id'], 'title' => $p['title'], 'price' => ($choices && (int)$p['nvar'] > 0) ? min((int)$p['price_cents'], (int)$p['vmin']) : (int)$p['price_cents'], 'choices' => $choices);
		}
	}
	return array('learned' => $learned, 'items' => $items);
}

/*
 * ---- shared basket ("Gemeinsam bestellen"): several guests fill one basket, one person (the organizer) orders and pays.
 * Tables tp_shop_baskets / _members / _lines. A member is known only by a secret id (`me`) that the server hands out and the
 * browser keeps; it is never part of an answer to anybody else. Lines are stored as choices only (dish, variation, options,
 * amount, note) and priced from the menu on every read, exactly like the single cart. status: open (everybody may change
 * their lines), locked (the organizer is at the checkout), ordered (the order exists).
 */
function shop_basket_clean_name($n) {
	return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$n))), 0, 24);
}
function shop_basket_hex($s, $len) {
	$s = preg_replace('/[^a-f0-9]/', '', (string)$s);
	return strlen($s) === $len ? $s : '';
}
function shop_basket_get($token) {
	$token = shop_basket_hex($token, 24);
	if ($token === '') { return null; }
	$b = fb_row("SELECT * FROM ".fb_t('tp_shop_baskets')." WHERE token = ?", 's', array($token));
	return ($b && strtotime($b['expires_at']) >= time()) ? $b : null;
}
function shop_basket_touch($id, $seconds = 21600) {
	fb_exec("UPDATE ".fb_t('tp_shop_baskets')." SET expires_at = ? WHERE id = ?", 'si', array(date('Y-m-d H:i:s', time() + $seconds), (int)$id));
}
function shop_basket_member($b, $me) {
	$me = shop_basket_hex($me, 32);
	return $me === '' ? null : fb_row("SELECT * FROM ".fb_t('tp_shop_basket_members')." WHERE basket_id = ? AND member = ?", 'is', array((int)$b['id'], $me));
}
function shop_basket_delete($id) {
	foreach (array('tp_shop_basket_lines', 'tp_shop_basket_members') as $t) { fb_exec("DELETE FROM ".fb_t($t)." WHERE basket_id = ?", 'i', array((int)$id)); }
	fb_exec("DELETE FROM ".fb_t('tp_shop_baskets')." WHERE id = ?", 'i', array((int)$id));
}
function shop_basket_add_member($bid, $name) {
	$taken = array_map(function ($r) { return mb_strtolower($r['name']); }, fb_rows("SELECT name FROM ".fb_t('tp_shop_basket_members')." WHERE basket_id = ?", 'i', array((int)$bid)));
	$base = $name; $n = 1;
	while (in_array(mb_strtolower($name), $taken, true)) { $n++; $name = mb_substr($base, 0, 20).' '.$n; }
	$me = bin2hex(random_bytes(16));
	fb_exec("INSERT INTO ".fb_t('tp_shop_basket_members')." (basket_id, member, name, created_at) VALUES (?, ?, ?, ?)", 'isss', array((int)$bid, $me, $name, date('Y-m-d H:i:s')));
	return $me;
}

function shop_basket_create($name) {
	$name = shop_basket_clean_name($name);
	if ($name === '') { return array('ok' => false, 'error' => 'Bitte gib deinen Namen an.'); }
	foreach (fb_rows("SELECT id FROM ".fb_t('tp_shop_baskets')." WHERE expires_at < ?", 's', array(date('Y-m-d H:i:s', time() - 86400))) as $r) { shop_basket_delete((int)$r['id']); }
	$token = bin2hex(random_bytes(12)); $now = date('Y-m-d H:i:s');
	$me = bin2hex(random_bytes(16));
	if (!fb_exec("INSERT INTO ".fb_t('tp_shop_baskets')." (token, owner, status, created_at, expires_at) VALUES (?, ?, 'open', ?, ?)", 'ssss', array($token, $me, $now, date('Y-m-d H:i:s', time() + 21600)))) {
		return array('ok' => false, 'error' => 'Die gemeinsame Bestellung konnte nicht angelegt werden.');
	}
	$bid = (int)mysqli_insert_id(fb_db());
	fb_exec("INSERT INTO ".fb_t('tp_shop_basket_members')." (basket_id, member, name, created_at) VALUES (?, ?, ?, ?)", 'isss', array($bid, $me, $name, $now));
	return array('ok' => true, 'token' => $token, 'me' => $me, 'name' => $name);
}

function shop_basket_join($token, $name) {
	$b = shop_basket_get($token);
	if (!$b) { return array('ok' => false, 'error' => 'Diese gemeinsame Bestellung gibt es nicht mehr.'); }
	if ($b['status'] !== 'open') { return array('ok' => false, 'error' => 'Hier wird gerade bestellt, du kannst nicht mehr beitreten.'); }
	$name = shop_basket_clean_name($name);
	if ($name === '') { return array('ok' => false, 'error' => 'Bitte gib deinen Namen an.'); }
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_basket_members')." WHERE basket_id = ?", 'i', array((int)$b['id']));
	if ($n && (int)$n['n'] >= 30) { return array('ok' => false, 'error' => 'Es sind schon 30 Personen dabei.'); }
	$me = shop_basket_add_member($b['id'], $name);
	shop_basket_touch($b['id']);
	return array('ok' => true, 'me' => $me);
}

// one choice list as stored: {optionId: amount} with positive whole amounts, in a fixed order, so equal choices compare equal
function shop_basket_opts_json($opts) {
	$o = array();
	if (is_array($opts)) { foreach ($opts as $k => $v) { if ((int)$k > 0 && (int)$v > 0) { $o[(int)$k] = (int)$v; } } }
	ksort($o);
	return json_encode($o, JSON_FORCE_OBJECT);
}

// a short fingerprint of what is in the basket (status, people, lines and amounts): equal fingerprint = nothing changed
function shop_basket_rev($status, $members, $rows) {
	$sig = $status.'|'.count($members);
	foreach ($members as $m) { $sig .= '|'.$m['name']; }
	foreach ($rows as $r) { $sig .= '|'.$r['id'].':'.$r['qty']; }
	return substr(md5($sig), 0, 12);
}

/*
 * What a member sees: everybody's lines grouped by person, priced from the menu. $rev is the revision the browser already
 * shows - when nothing changed since, only {same:true} comes back (the page asks every few seconds, pricing is the costly part).
 */
function shop_basket_view($token, $me, $rev = '') {
	$b = shop_basket_get($token);
	if (!$b) { return array('ok' => false, 'gone' => true, 'error' => 'Diese gemeinsame Bestellung gibt es nicht mehr.'); }
	$bid = (int)$b['id'];
	$members = fb_rows("SELECT member, name FROM ".fb_t('tp_shop_basket_members')." WHERE basket_id = ? ORDER BY id", 'i', array($bid));
	$ownerName = ''; $mine = null;
	$meId = shop_basket_hex($me, 32);
	foreach ($members as $m) {
		if ($m['member'] === $b['owner']) { $ownerName = $m['name']; }
		if ($meId !== '' && $m['member'] === $meId) { $mine = $m; }
	}
	if (!$mine) { return array('ok' => true, 'joined' => false, 'status' => $b['status'], 'owner_name' => $ownerName); }
	$rows = fb_rows("SELECT id, member, pid, vid, opts, qty, note FROM ".fb_t('tp_shop_basket_lines')." WHERE basket_id = ? ORDER BY id", 'i', array($bid));
	$cur = shop_basket_rev($b['status'], $members, $rows);
	if ($rev !== '' && $rev === $cur) { return array('ok' => true, 'joined' => true, 'same' => true); }
	$isOwner = ($mine['member'] === $b['owner']);
	$per = array(); $sub = 0; $count = 0;
	foreach ($members as $m) { $per[$m['member']] = array('name' => $m['name'], 'mine' => ($m['member'] === $mine['member']), 'owner' => ($m['member'] === $b['owner']), 'lines' => array(), 'sum' => 0); }
	foreach ($rows as $r) {
		if (!isset($per[$r['member']])) { continue; }
		$opts = json_decode((string)$r['opts'], true); $opts = is_array($opts) ? $opts : array();
		$p = shop_price_line(array('pid' => (int)$r['pid'], 'vid' => (int)$r['vid'], 'opts' => $opts, 'qty' => (int)$r['qty'], 'note' => $r['note']));
		$can = ($b['status'] === 'open' && ($isOwner || $r['member'] === $mine['member']));
		if (!$p['ok']) {
			$per[$r['member']]['lines'][] = array('id' => (int)$r['id'], 'title' => 'Nicht mehr verfügbar', 'vtitle' => '', 'optText' => '', 'unit' => 0, 'qty' => (int)$r['qty'], 'note' => '', 'unavailable' => true, 'can' => $can);
			continue;
		}
		$l = $p['line'];
		$parts = array();
		foreach ($l['options'] as $o) { $parts[] = ($o['qty'] > 1 ? $o['qty'].'× ' : '').$o['title']; }
		$per[$r['member']]['lines'][] = array('id' => (int)$r['id'], 'pid' => (int)$r['pid'], 'vid' => (int)$r['vid'], 'opts' => (object)$opts, 'title' => $l['title'], 'vtitle' => $l['variation'],
			'optText' => implode(', ', $parts), 'unit' => $l['unit_cents'], 'qty' => $l['qty'], 'note' => $l['note'], 'can' => $can);
		$per[$r['member']]['sum'] += $l['line_cents']; $sub += $l['line_cents']; $count += $l['qty'];
	}
	// the person looking comes first
	$list = array_values($per);
	usort($list, function ($a, $b2) { return ($b2['mine'] ? 1 : 0) - ($a['mine'] ? 1 : 0); });
	return array('ok' => true, 'joined' => true, 'status' => $b['status'], 'owner' => $isOwner, 'owner_name' => $ownerName, 'name' => $mine['name'], 'members' => $list,
		'subtotal' => $sub, 'count' => $count, 'rev' => $cur, 'short' => (string)$b['short_url']);
}

/*
 * Short link for the invitation (own YOURLS, like the cancel link; it expires after 24 hours). Made once, on the first request of
 * a member, and kept with the basket; '' when YOURLS is off or does not answer - the page then keeps showing the long link.
 */
function shop_basket_link($token, $me, $longUrl) {
	$c = shop_basket_for_change($token, $me, false);
	if (!$c['ok']) { return $c; }
	$b = $c['basket'];
	if ((string)$b['short_url'] !== '') { return array('ok' => true, 'link' => $b['short_url']); }
	if (!sms_link_ready()) { return array('ok' => true, 'link' => ''); }
	try {
		$res = sms_yourls_shorten($longUrl, 1440, 'mySeat Gemeinsam bestellen');
	} catch (Throwable $e) { $res = array('ok' => false, 'error' => $e->getMessage()); }
	if (empty($res['ok']) || empty($res['short'])) { error_log('mySeat basket link: '.(isset($res['error']) ? $res['error'] : 'no short link')); return array('ok' => true, 'link' => ''); }
	fb_exec("UPDATE ".fb_t('tp_shop_baskets')." SET short_url = ? WHERE id = ?", 'si', array(mb_substr($res['short'], 0, 120), (int)$b['id']));
	return array('ok' => true, 'link' => $res['short']);
}

function shop_basket_for_change($token, $me, $needOpen) {
	$b = shop_basket_get($token);
	if (!$b) { return array('ok' => false, 'error' => 'Diese gemeinsame Bestellung gibt es nicht mehr.'); }
	$m = shop_basket_member($b, $me);
	if (!$m) { return array('ok' => false, 'error' => 'Du bist bei dieser gemeinsamen Bestellung nicht dabei.'); }
	if ($needOpen && $b['status'] === 'locked') { return array('ok' => false, 'error' => 'Der Warenkorb ist abgeschlossen, es wird gerade bestellt.'); }
	if ($needOpen && $b['status'] === 'ordered') { return array('ok' => false, 'error' => 'Die Bestellung ist schon abgeschickt.'); }
	return array('ok' => true, 'basket' => $b, 'member' => $m, 'owner' => ($m['member'] === $b['owner']));
}

function shop_basket_add($token, $me, $line) {
	$c = shop_basket_for_change($token, $me, true);
	if (!$c['ok']) { return $c; }
	$b = $c['basket']; $bid = (int)$b['id'];
	$line = is_array($line) ? $line : array();
	$r = shop_price_line($line);
	if (!$r['ok']) { return $r; }
	$tot = fb_row("SELECT COUNT(*) AS n, SUM(member = ?) AS mine FROM ".fb_t('tp_shop_basket_lines')." WHERE basket_id = ?", 'si', array($c['member']['member'], $bid));
	$opts = shop_basket_opts_json(isset($line['opts']) ? $line['opts'] : array());
	$vid = (int)(isset($line['vid']) ? $line['vid'] : 0); $qty = max(1, min(50, (int)(isset($line['qty']) ? $line['qty'] : 1)));
	$note = mb_substr(trim((string)(isset($line['note']) ? $line['note'] : '')), 0, 160);
	$hit = fb_row("SELECT id, qty FROM ".fb_t('tp_shop_basket_lines')." WHERE basket_id = ? AND member = ? AND pid = ? AND vid = ? AND opts = ? AND note = ?", 'isiiss',
		array($bid, $c['member']['member'], (int)$line['pid'], $vid, $opts, $note));
	if ($hit) {
		fb_exec("UPDATE ".fb_t('tp_shop_basket_lines')." SET qty = ? WHERE id = ?", 'ii', array(min(50, (int)$hit['qty'] + $qty), (int)$hit['id']));
	} else {
		if ($tot && ((int)$tot['n'] >= 150 || (int)$tot['mine'] >= 40)) { return array('ok' => false, 'error' => 'Der gemeinsame Warenkorb ist voll.'); }
		fb_exec("INSERT INTO ".fb_t('tp_shop_basket_lines')." (basket_id, member, pid, vid, opts, qty, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'isiisiss',
			array($bid, $c['member']['member'], (int)$line['pid'], $vid, $opts, $qty, $note, date('Y-m-d H:i:s')));
	}
	shop_basket_touch($bid);
	return array('ok' => true);
}

// $qty <= 0 removes the line; a member changes his own lines, the organizer every line
function shop_basket_set_qty($token, $me, $lineId, $qty) {
	$c = shop_basket_for_change($token, $me, true);
	if (!$c['ok']) { return $c; }
	$bid = (int)$c['basket']['id'];
	$l = fb_row("SELECT id, member FROM ".fb_t('tp_shop_basket_lines')." WHERE id = ? AND basket_id = ?", 'ii', array((int)$lineId, $bid));
	if (!$l) { return array('ok' => true); }
	if (!$c['owner'] && $l['member'] !== $c['member']['member']) { return array('ok' => false, 'error' => 'Du kannst nur deine eigenen Gerichte ändern.'); }
	if ((int)$qty <= 0) { fb_exec("DELETE FROM ".fb_t('tp_shop_basket_lines')." WHERE id = ?", 'i', array((int)$l['id'])); }
	else { fb_exec("UPDATE ".fb_t('tp_shop_basket_lines')." SET qty = ? WHERE id = ?", 'ii', array(min(50, (int)$qty), (int)$l['id'])); }
	shop_basket_touch($bid);
	return array('ok' => true);
}

// the organizer locks the basket while he is at the checkout (nobody changes it under him) and may open it again
function shop_basket_set_status($token, $me, $to) {
	$c = shop_basket_for_change($token, $me, false);
	if (!$c['ok']) { return $c; }
	if (!$c['owner']) { return array('ok' => false, 'error' => 'Das kann nur die Person, die bestellt.'); }
	if ($c['basket']['status'] === 'ordered') { return array('ok' => false, 'error' => 'Die Bestellung ist schon abgeschickt.'); }
	if (!in_array($to, array('open', 'locked'), true)) { return array('ok' => false, 'error' => 'Unbekannter Zustand.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_baskets')." SET status = ? WHERE id = ?", 'si', array($to, (int)$c['basket']['id']));
	shop_basket_touch($c['basket']['id']);
	return array('ok' => true);
}

function shop_basket_close($token, $me) {
	$c = shop_basket_for_change($token, $me, false);
	if (!$c['ok']) { return $c; }
	if (!$c['owner']) { return array('ok' => false, 'error' => 'Das kann nur die Person, die bestellt.'); }
	shop_basket_delete($c['basket']['id']);
	return array('ok' => true);
}

// the lines of the order, as the checkout takes them; each carries the name of the person it is for (the kitchen sees it on the bon)
function shop_basket_order_lines($token, $me, $rev = '') {
	$c = shop_basket_for_change($token, $me, false);
	if (!$c['ok']) { return $c; }
	if (!$c['owner']) { return array('ok' => false, 'error' => 'Nur die Person, die bestellt, kann die gemeinsame Bestellung abschicken.'); }
	if ($c['basket']['status'] === 'ordered') { return array('ok' => false, 'error' => 'Diese gemeinsame Bestellung ist schon abgeschickt.'); }
	$names = array();
	$members = fb_rows("SELECT member, name FROM ".fb_t('tp_shop_basket_members')." WHERE basket_id = ? ORDER BY id", 'i', array((int)$c['basket']['id']));
	foreach ($members as $m) { $names[$m['member']] = $m['name']; }
	$rows = fb_rows("SELECT id, member, pid, vid, opts, qty, note FROM ".fb_t('tp_shop_basket_lines')." WHERE basket_id = ? ORDER BY id", 'i', array((int)$c['basket']['id']));
	// the checkout page shows the basket as it was when it loaded; if somebody changed it since, the organizer first looks again
	if ($rev !== '' && $rev !== shop_basket_rev($c['basket']['status'], $members, $rows)) {
		return array('ok' => false, 'changed' => true, 'error' => 'Der gemeinsame Warenkorb wurde inzwischen geändert. Bitte lade die Seite neu und prüfe die Bestellung.');
	}
	$lines = array(); $who = array();
	foreach ($rows as $r) {
		$opts = json_decode((string)$r['opts'], true);
		$n = isset($names[$r['member']]) ? $names[$r['member']] : '';
		$lines[] = array('pid' => (int)$r['pid'], 'vid' => (int)$r['vid'], 'opts' => is_array($opts) ? $opts : array(), 'qty' => (int)$r['qty'],
			'note' => mb_substr(($n !== '' ? 'für '.$n : '').($r['note'] !== '' ? ($n !== '' ? ' – ' : '').$r['note'] : ''), 0, 200));
		if ($n !== '') { $who[$n] = true; }
	}
	if (!$lines) { return array('ok' => false, 'error' => 'Der gemeinsame Warenkorb ist leer.'); }
	return array('ok' => true, 'lines' => $lines, 'names' => array_keys($who));
}

function shop_basket_mark($token, $status, $orderId) {
	$b = shop_basket_get($token);
	if (!$b) { return; }
	fb_exec("UPDATE ".fb_t('tp_shop_baskets')." SET status = ?, order_id = ? WHERE id = ?", 'sii', array($status, $orderId > 0 ? $orderId : null, (int)$b['id']));
	if ($status === 'ordered') { shop_basket_touch($b['id'], 3600); }
}

/*
 * ---- stamp card (like the program of the delivery platforms): every finished order of a guest is one stamp; a full card
 * (5 stamps) becomes a personal voucher worth 10 % of the goods of those orders. A stamp counts 12 months, a voucher 90 days.
 * The guest is recognised by phone and e-mail of the order (the same keys the coupons use). The voucher is a coupon of the own
 * coupon system (tp_shop_coupons.source = 'stamp', fixed amount, one use, bound to the guest keys) and is taken off the next
 * order automatically. It is always used up completely: it applies from a goods value equal to its amount (min_order_cents), so
 * there is no rest voucher. Numbers: shop
 * settings stamp_percent / stamp_goal / stamp_months / voucher_days.
 */
function shop_stamp_cfg() {
	$n = function ($k, $min, $max) { return max($min, min($max, (int)shop_setting($k))); };
	return array('on' => shop_flag('stamp_on'), 'percent' => $n('stamp_percent', 1, 100), 'goal' => $n('stamp_goal', 2, 12), 'months' => $n('stamp_months', 1, 60), 'days' => $n('voucher_days', 7, 730));
}
function shop_stamp_match() { return "((guest_key <> '' AND guest_key = ?) OR (guest_key2 <> '' AND guest_key2 = ?))"; }
function shop_stamp_has_keys($keys) { return is_array($keys) && ($keys[0] !== '' || $keys[1] !== ''); }

// the stamps of the guest that are not yet part of a voucher and not expired, oldest first
function shop_stamp_open($keys, $limit = 0) {
	if (!shop_stamp_has_keys($keys)) { return array(); }
	return fb_rows("SELECT id, base_cents, earned_at, expires_at FROM ".fb_t('tp_shop_stamps')." WHERE coupon_id IS NULL AND expires_at > ? AND ".shop_stamp_match()." ORDER BY earned_at, id".($limit > 0 ? ' LIMIT '.(int)$limit : ''),
		'sss', array(date('Y-m-d H:i:s'), $keys[0], $keys[1]));
}

// called when an order is finished; safe to call twice. base_cents = what the guest paid for the goods (no fee, no tip, after discounts)
function shop_stamp_award($orderId) {
	shop_ensure_schema();
	$cfg = shop_stamp_cfg();
	if (!$cfg['on']) { return; }
	$o = shop_order((int)$orderId);
	if (!$o || !empty($o['is_test'])) { return; }
	if (in_array($o['source'], array('lieferando', 'uber_eats'), true)) { return; }   // orders of a platform do not collect stamps
	$keys = shop_coupon_guest_keys($o['phone'], $o['email']);
	// stamps are only collected with a guest account: the order must have been placed signed in, and then the account's keys count
	if (shop_flag('account_on')) {
		$a = !empty($o['account_id']) ? fb_row("SELECT key_phone, key_mail, contact_phone, contact_mail FROM ".fb_t('tp_shop_accounts')." WHERE id = ?", 'i', array((int)$o['account_id'])) : null;
		if (!$a) { return; }
		// the mail / SMS about stamps and voucher go to what the order held, else to what the guest signed in with
		if (trim((string)$o['email']) === '') { $o['email'] = (string)$a['contact_mail']; }
		if (trim((string)$o['phone']) === '') { $o['phone'] = (string)$a['contact_phone']; }
		$keys = array($a['key_phone'] !== null ? $a['key_phone'] : '', $a['key_mail'] !== null ? $a['key_mail'] : '');
	}
	if (!shop_stamp_has_keys($keys)) { return; }
	$base = max(0, (int)$o['subtotal_cents'] - (int)$o['discount_cents']);
	$st = fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_stamps')." (order_id, guest_key, guest_key2, base_cents, earned_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)", 'ississ',
		array((int)$o['id'], $keys[0], $keys[1], $base, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+'.$cfg['months'].' months'))));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return; }
	$issued = shop_stamp_check($keys, $o['phone'], $o['email'], $o['customer_name']);
	if (!$issued) { shop_stamp_notify_stamp($o['email'], $o['customer_name'], $keys); }
}

/*
 * A full card (goal stamps that did not expire) becomes a voucher; repeated while enough stamps are left. Returns how many vouchers
 * were made. The stamps are used up even for a worthless card, so it cannot come up again and again.
 */
function shop_stamp_check($keys, $phone = '', $email = '', $name = '') {
	$cfg = shop_stamp_cfg(); $made = 0;
	$db = fb_db(); $lock = 'shop_stamp_'.substr(sha1($keys[0].'|'.$keys[1]), 0, 24);
	mysqli_query($db, "SELECT GET_LOCK('".$lock."', 5)");
	try {
		for ($i = 0; $i < 5; $i++) {
			$rows = shop_stamp_open($keys, $cfg['goal']);
			if (count($rows) < $cfg['goal']) { break; }
			$sum = 0; $ids = array();
			foreach ($rows as $r) { $sum += (int)$r['base_cents']; $ids[] = (int)$r['id']; }
			$value = (int)round($sum * $cfg['percent'] / 100);
			$cid = 0;
			if ($value > 0) {
				$until = date('Y-m-d H:i:s', time() + $cfg['days'] * 86400);
				$c = shop_stamp_issue($keys, $value, $until, 0, 'Stempelkarte');
				if ($c) { $cid = (int)$c['id']; $made++; shop_stamp_notify_voucher($phone, $email, $value, strtotime($until), $name, $c['code']); }
			}
			fb_exec("UPDATE ".fb_t('tp_shop_stamps')." SET coupon_id = ? WHERE id IN (".implode(',', $ids).")", 'i', array($cid));
		}
	} finally { mysqli_query($db, "SELECT RELEASE_LOCK('".$lock."')"); }
	return $made;
}

function shop_stamp_code() {
	$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	for ($t = 0; $t < 6; $t++) {
		$c = 'STEMPEL-';
		for ($i = 0; $i < 6; $i++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; }
		if (!shop_coupon_by_code($c)) { return $c; }
	}
	return 'STEMPEL-'.strtoupper(bin2hex(random_bytes(4)));
}
// a personal voucher in the own coupon system; returns the coupon row or null
function shop_stamp_issue($keys, $value, $validUntil, $parentId, $note) {
	$code = shop_stamp_code();
	// always used up completely: it applies from a goods value that equals its amount (no rest voucher)
	$st = fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_from, valid_until, max_uses, per_guest, used, active, created_at, source, guest_key, guest_key2, parent_id)
		VALUES (?, ?, 'fixed', ?, 0, ?, 'all', NULL, ?, 1, 0, 0, 1, ?, 'stamp', ?, ?, ?)", 'ssiissssi',
		array($code, $note, (int)$value, (int)$value, $validUntil, date('Y-m-d H:i:s'), $keys[0], $keys[1], $parentId > 0 ? (int)$parentId : null));
	return $st ? shop_coupon_by_code($code) : null;
}
// the voucher the guest can use now (the one that ends first), or null; vouchers stay valid when the program is switched off
function shop_stamp_voucher($keys) {
	if (!shop_stamp_has_keys($keys)) { return null; }
	shop_ensure_schema();
	return fb_row("SELECT * FROM ".fb_t('tp_shop_coupons')." WHERE source = 'stamp' AND active = 1 AND used < max_uses AND (valid_until IS NULL OR valid_until > ?) AND ".shop_stamp_match()." ORDER BY valid_until, id LIMIT 1",
		'sss', array(date('Y-m-d H:i:s'), $keys[0], $keys[1]));
}

// what the pages show: the card of this guest (keys from phone/e-mail), the voucher and what it would take off this cart
function shop_stamp_state($keys, $type = 'delivery', $sub = 0) {
	$cfg = shop_stamp_cfg();
	$out = array('on' => $cfg['on'], 'goal' => $cfg['goal'], 'percent' => $cfg['percent'], 'months' => $cfg['months'], 'days' => $cfg['days'], 'count' => 0, 'saved' => 0, 'until' => '', 'voucher' => null);
	if (!shop_stamp_has_keys($keys)) { return $out; }
	foreach (shop_stamp_open($keys) as $i => $s) {
		$out['count']++; $out['saved'] += (int)round((int)$s['base_cents'] * $cfg['percent'] / 100);
		if ($i === 0) { $out['until'] = date('d.m.Y', strtotime($s['expires_at'])); }
	}
	$v = shop_stamp_voucher($keys);
	if ($v) {
		$chk = shop_coupon_check($v['code'], $type, (int)$sub, $keys);
		$out['voucher'] = array('value' => (int)$v['value'], 'until' => date('d.m.Y', strtotime($v['valid_until'])), 'discount' => $chk['ok'] ? (int)$chk['discount'] : 0,
			'min' => (int)$v['min_order_cents'], 'missing' => max(0, (int)$v['min_order_cents'] - (int)$sub), 'code' => $v['code']);
	}
	return $out;
}

// ---- notices to the guest: a mail for every stamp, a mail or an SMS for a full card
function shop_stamp_first_name($name) { return trim((string)strtok(trim((string)$name), ' ')); }
function shop_stamp_shop_url() {
	// the mails are made in the backend (staff sets the order to "done"), where shop_base_url() of the order pages does not exist
	try { $u = rtrim((string)shop_site_url(), '/'); } catch (Throwable $e) { $u = ''; }
	return $u !== '' ? $u.'/order/' : '';
}
// the card as a picture for the mail (order/mail/stempel-N-von-5.png, made for the default of five stamps)
function shop_stamp_card_image($n, $goal) {
	$base = shop_stamp_shop_url();
	return ($goal === 5 && $n >= 1 && $n <= 5 && $base !== '') ? $base.'mail/stempel-'.(int)$n.'-von-5.png' : '';
}
/*
 * One mail of the stamp card in the look of the order confirmation (white card, serif headline, gold box, brown button, provider
 * lines). $m: subject, headline, lead, image, alt, box (label, value, note) or null, after, btn, url, signoff.
 */
function shop_stamp_mail($to, $m) {
	global $settings;
	require_once __DIR__.'/shop_mail.class.php';
	$from = shop_mail_from();
	$to = trim((string)$to);
	if ($to === '' || $from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return; }
	$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$font = 'font-family:Arial,Helvetica,sans-serif;';
	$serif = 'font-family:Georgia,\'Times New Roman\',serif;';
	$legal = bm_legal_lines(array());
	$links = array();
	if (!empty($settings['imprintUrl'])) { $links[] = '<a href="'.$h($settings['imprintUrl']).'" style="color:#8a6d3b;">Impressum</a>'; }
	if (!empty($settings['privacyUrl'])) { $links[] = '<a href="'.$h($settings['privacyUrl']).'" style="color:#8a6d3b;">Datenschutz</a>'; }
	$footer = $legal ? '<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#6e685c;"><strong>Angaben zum Anbieter</strong><br>'.implode('<br>', array_map($h, $legal)).($links ? '<br>'.implode(' &middot; ', $links) : '').'</td></tr>' : '';
	$html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($m['subject']).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="padding:30px 32px 6px;"><div style="'.$serif.'font-size:28px;color:#1c1a18;">'.$h($m['headline']).'</div>'
		.'<div style="'.$font.'font-size:15px;color:#555;line-height:1.6;padding-top:8px;">'.$h($m['lead']).'</div></td></tr>'
		.($m['image'] !== '' ? '<tr><td style="padding:18px 32px 4px;"><img src="'.$h($m['image']).'" width="496" alt="'.$h($m['alt']).'" style="display:block;width:100%;max-width:496px;height:auto;border:0;border-radius:10px;"></td></tr>' : '')
		.(!empty($m['box']) ? '<tr><td style="padding:14px 32px 4px;"><div style="background:#f8f2e4;border:1px solid #e6d9b8;border-radius:10px;padding:14px 18px;"><div style="'.$font.'font-size:13px;color:#7a6a45;">'.$h($m['box']['label']).'</div>'
			.'<div style="'.$serif.'font-size:40px;line-height:1.1;color:#6b5330;">'.$h($m['box']['value']).'</div><div style="'.$font.'font-size:13px;color:#7a6a45;padding-top:2px;">'.$h($m['box']['note']).'</div></div></td></tr>' : '')
		.'<tr><td style="padding:14px 32px 4px;"><div style="'.$font.'font-size:15px;color:#555;line-height:1.6;">'.$h($m['after']).'</div></td></tr>'
		.($m['url'] !== '' ? '<tr><td style="padding:14px 32px 6px;"><a href="'.$h($m['url']).'" style="'.$font.'display:inline-block;padding:13px 24px;background:#8a6d3b;border-radius:6px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">'.$h($m['btn']).'</a></td></tr>' : '')
		.'<tr><td style="padding:14px 32px 30px;"><div style="'.$font.'font-size:14px;color:#555;line-height:1.6;">'.$h($m['signoff']).'</div></td></tr>'
		.$footer.'</table></td></tr></table></body></html>';
	$plain = $m['headline']."\r\n\r\n".$m['lead']."\r\n\r\n".(!empty($m['box']) ? $m['box']['label'].': '.$m['box']['value'].' ('.$m['box']['note'].")\r\n\r\n" : '').$m['after']."\r\n".($m['url'] !== '' ? "\r\n".$m['btn'].': '.$m['url']."\r\n" : '')."\r\n".$m['signoff']."\r\n";
	bm_send_guest_mail($to, array('subject' => $m['subject'], 'plain' => $plain, 'html' => $html, 'ics' => '', 'ics_filename' => ''), $brand, $from);
}
function shop_stamp_signoff() { global $settings; return 'Guten Appetit wünscht dein Team von '.(!empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus'); }

// after every stamp (not for the one that fills the card, that gets the voucher mail)
function shop_stamp_notify_stamp($email, $name, $keys) {
	try {
		if (trim((string)$email) === '') { return; }
		$st = shop_stamp_state($keys);
		$n = (int)$st['count']; $goal = (int)$st['goal']; $left = max(0, $goal - $n); $first = shop_stamp_first_name($name);
		shop_stamp_mail(trim($email), array(
			'subject' => 'Dein Stempel '.$n.' von '.$goal.($first !== '' ? ', '.$first : '').'!',
			'headline' => $first !== '' ? 'Danke, '.$first.'!' : 'Danke!',
			'lead' => 'Schön, dass du bei uns bestellt hast. Dafür gibt es einen Stempel auf deine Karte.',
			'image' => shop_stamp_card_image($n, $goal), 'alt' => 'Deine Stempelkarte: '.$n.' von '.$goal.' Stempeln', 'box' => null,
			'after' => ($left === 1 ? 'Noch 1 Stempel' : 'Noch '.$left.' Stempel').' bis zu deinem Gutschein. Mit der vollen Karte schenken wir dir '.$st['percent'].' % deiner Bestellungen zurück, und wir ziehen den Gutschein automatisch ab.'.($st['until'] !== '' ? ' Deine Stempel gelten bis zum '.$st['until'].'.' : ''),
			'btn' => 'Wieder bestellen', 'url' => shop_stamp_shop_url(), 'signoff' => shop_stamp_signoff()));
	} catch (Throwable $e) { error_log('mySeat stamp notice: '.$e->getMessage()); }
}
// when the card is full: the voucher, by mail, or by SMS for a guest without e-mail
function shop_stamp_notify_voucher($phone, $email, $value, $until, $name = '', $code = '') {
	try {
		$first = shop_stamp_first_name($name); $cfg = shop_stamp_cfg();
		if (trim((string)$email) !== '') {
			shop_stamp_mail(trim($email), array(
				'subject' => 'Geschafft'.($first !== '' ? ', '.$first : '').': dein Gutschein über '.shop_money($value),
				'headline' => 'Geschafft'.($first !== '' ? ', '.$first : '').'!',
				'lead' => 'Deine Stempelkarte ist voll. Zur Belohnung schenken wir dir einen Gutschein.',
				'image' => shop_stamp_card_image($cfg['goal'], $cfg['goal']), 'alt' => 'Deine volle Stempelkarte', 'box' => array('label' => 'Dein Gutschein', 'value' => shop_money($value), 'note' => 'gültig bis '.date('d.m.Y', $until).' · ab '.shop_money($value).' Warenwert'),
				'after' => 'Wir ziehen ihn bei deiner nächsten Bestellung automatisch ab, du musst nichts eingeben. Er gilt für eine Bestellung ab '.shop_money($value).' Warenwert und wird dann komplett abgezogen.'.($code !== '' ? ' Falls du ihn lieber selbst eingibst: '.$code : ''),
				'btn' => 'Jetzt bestellen', 'url' => shop_stamp_shop_url(), 'signoff' => shop_stamp_signoff()));
			return;
		}
		$m = sms_normalize_phone(html_entity_decode((string)$phone, ENT_QUOTES, 'UTF-8'));
		if ($m !== null && sms_enabled()) { global $settings; $b = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus'; sms_enqueue(null, $m, 'voucher', sms_gsm_clean($b.': Geschafft'.($first !== '' ? ', '.$first : '').'! Stempelkarte voll, Gutschein '.shop_money($value).' bis '.date('d.m.Y', $until).', ab '.shop_money($value).' Warenwert. Wird automatisch abgezogen.')); }
	} catch (Throwable $e) { error_log('mySeat voucher notice: '.$e->getMessage()); }
}
