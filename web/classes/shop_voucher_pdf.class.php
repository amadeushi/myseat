<?php
/*
 * The picture of a voucher and its PDF. The card (3:2, 190 x 127 mm) is drawn with GD in the fonts of the shop (Cormorant Garamond, Raleway: web/fonts/gutschein, SIL Open Font License):
 * photo or drawing as the background, a dark fade for the text, wordmark, value, names, message, number, security code, validity and a QR code. The PDF is one A4 page made of
 * a 300 dpi picture of the page (card + how to redeem + short terms): that keeps the typography exactly as drawn and needs no PDF library.
 * Own pictures of the guests are stored re-encoded (no EXIF, no metadata) in uploads/gutschein, which is closed for the web; they are only shown through the preview and the PDF.
 */

require_once __DIR__.'/qrcode.class.php';

const GV_CARD_W_MM = 148;   // A6 landscape: the same card is the PDF, the preview and the front of the postcard sent by EchtPost
const GV_CARD_H_MM = 105;

// the motifs the guest can choose: key => label, group, file (in order/gutschein/motive). The owner can switch single ones off with the setting gv_motifs_off (keys, comma separated).
function gv_motifs() {
	return array(
		'geburtstag' => array('label' => 'Geburtstag', 'group' => 'Zum Anlass'), 'jubilaeum' => array('label' => 'Jubiläum', 'group' => 'Zum Anlass'), 'umschlag' => array('label' => 'Brief', 'group' => 'Zum Anlass'),
		'schnitzel' => array('label' => 'Schnitzel', 'group' => 'Von unserem Tisch'), 'steak' => array('label' => 'Steak', 'group' => 'Von unserem Tisch'), 'pizza' => array('label' => 'Pizza', 'group' => 'Von unserem Tisch'),
		'salat' => array('label' => 'Salat', 'group' => 'Von unserem Tisch'), 'kuchen' => array('label' => 'Kuchen und Kaffee', 'group' => 'Von unserem Tisch'));
}
function gv_motifs_active() {
	$off = array_filter(array_map('trim', explode(',', (string)shop_setting('gv_motifs_off'))));
	return array_diff_key(gv_motifs(), array_flip($off));
}
function gv_motif_dir() { return dirname(__DIR__, 2).'/order/gutschein/motive'; }
function gv_upload_dir() { return dirname(__DIR__, 2).'/uploads/gutschein'; }
function gv_font($name) { return dirname(__DIR__).'/fonts/gutschein/'.$name.'.ttf'; }

// the file of a motif: a key of the list, or "own:<name>" for the picture of a guest; '' if unknown
function gv_motif_file($motif) {
	$motif = (string)$motif;
	if (preg_match('/^own:([a-f0-9]{24})$/', $motif, $m)) { $f = gv_upload_dir().'/'.$m[1].'.jpg'; return is_file($f) ? $f : ''; }
	$all = gv_motifs();
	if (isset($all[$motif])) { $f = gv_motif_dir().'/'.$motif.'.jpg'; return is_file($f) ? $f : ''; }
	return '';
}

// keep a picture of a guest: checked, turned upright, scaled down to at most 2400 px on the long side, saved as JPEG without metadata. Returns array(ok, key|error)
function gv_upload_store($bin) {
	if (!function_exists('imagecreatefromstring')) { return array('ok' => false, 'error' => 'Auf dem Server fehlt die Bildbearbeitung.'); }
	if (strlen($bin) < 1000) { return array('ok' => false, 'error' => 'Das ist kein Bild.'); }
	if (strlen($bin) > 12 * 1024 * 1024) { return array('ok' => false, 'error' => 'Das Bild ist zu groß (höchstens 12 MB).'); }
	$info = @getimagesizefromstring($bin);
	if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP), true)) { return array('ok' => false, 'error' => 'Bitte ein Bild als JPG, PNG oder WebP wählen.'); }
	if ($info[0] * $info[1] > 40000000) { return array('ok' => false, 'error' => 'Das Bild hat zu viele Pixel. Bitte verkleinern.'); }
	if ($info[0] < 900 || $info[1] < 600) { return array('ok' => false, 'error' => 'Das Bild ist zu klein für einen scharfen Druck (mindestens 900 x 600 Pixel).'); }
	$im = @imagecreatefromstring($bin);
	if (!$im) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gelesen werden.'); }
	if (!imageistruecolor($im)) { imagepalettetotruecolor($im); }
	if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
		$ex = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bin)); $ang = array(3 => 180, 6 => -90, 8 => 90);
		$o = isset($ex['Orientation']) ? (int)$ex['Orientation'] : 1;
		if (isset($ang[$o])) { $r = imagerotate($im, $ang[$o], 0); if ($r) { $im = $r; } }
	}
	$w = imagesx($im); $h = imagesy($im); $long = max($w, $h);
	if ($long > 2400) { $s = 2400 / $long; $r = imagescale($im, (int)round($w * $s), (int)round($h * $s), IMG_BICUBIC); if ($r) { $im = $r; } }
	$dir = gv_upload_dir();
	if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
	if (!is_file($dir.'/.htaccess')) { @file_put_contents($dir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"); }
	if (!is_dir($dir) || !is_writable($dir)) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.'); }
	$key = bin2hex(random_bytes(12));
	$white = imagecreatetruecolor(imagesx($im), imagesy($im)); imagefill($white, 0, 0, 0xFFFFFF); imagecopy($white, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
	if (!imagejpeg($white, $dir.'/'.$key.'.jpg', 86)) { return array('ok' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.'); }
	return array('ok' => true, 'key' => $key);
}
// pictures nobody ordered with disappear after two days
function gv_upload_gc() {
	$used = array();
	foreach (fb_rows("SELECT motif FROM ".fb_t('tp_gv_orders')." WHERE motif LIKE 'own:%'") as $r) { $used[substr($r['motif'], 4)] = 1; }
	foreach ((array)glob(gv_upload_dir().'/*.jpg') as $f) { if (filemtime($f) < time() - 2 * 86400 && empty($used[basename($f, '.jpg')])) { @unlink($f); } }
}

function gv_money_short($cents) { return ($cents % 100 === 0 ? number_format($cents / 100, 0, ',', '.') : number_format($cents / 100, 2, ',', '.')).' €'; }
function gv_hex($im, $hex, $alpha = 0) { return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha); }
function gv_text_w($size, $font, $s) { $b = imagettfbbox($size, 0, $font, $s); return $b ? $b[2] - $b[0] : 0; }
// text with letter spacing (GD has none); returns the width
function gv_text_spaced($im, $size, $x, $y, $col, $font, $s, $space) {
	$w = 0;
	foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
		imagettftext($im, $size, 0, (int)round($x + $w), (int)round($y), $col, $font, $ch);
		$w += gv_text_w($size, $font, $ch) + $space;
		if ($ch === ' ') { $w += $size * 0.18; }
	}
	return $w - $space;
}
// word wrap to a width in pixels; returns lines (at most $maxLines, the last one cut with an ellipsis when the text is longer)
function gv_wrap($text, $font, $size, $maxw, $maxLines = 99) {
	$lines = array();
	foreach (preg_split('/\R/u', trim((string)$text)) as $para) {
		$cur = '';
		foreach (preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY) as $word) {
			$try = $cur === '' ? $word : $cur.' '.$word;
			if (gv_text_w($size, $font, $try) <= $maxw || $cur === '') { $cur = $try; } else { $lines[] = $cur; $cur = $word; }
		}
		$lines[] = $cur;
	}
	if (count($lines) > $maxLines) {
		$lines = array_slice($lines, 0, $maxLines);
		$last = $lines[$maxLines - 1];
		while ($last !== '' && gv_text_w($size, $font, $last.' …') > $maxw) { $last = mb_substr($last, 0, -1); }
		$lines[$maxLines - 1] = rtrim($last).' …';
	}
	return $lines;
}

// the logo files of the restaurant in white (for dark pictures) and black (for bright ones); '' when missing
function gv_logo_file($dark) {
	$f = dirname(__DIR__, 2).'/order/gutschein/logo-'.($dark ? 'schwarz' : 'weiss').'.png';
	return is_file($f) ? $f : '';
}
// the provider lines (name, address, contact) of the restaurant, as in the mails
function gv_legal_lines() {
	$p = fb_row("SELECT * FROM ".fb_t('properties')." ORDER BY id LIMIT 1");
	return bm_legal_lines($p ? $p : array());
}

// what the QR code on the voucher opens: the page of the voucher for the team (order/gutschein_karte.php, found by the token that only the card carries); without a token (sample cards) the guest page
function gv_qr_url($number, $token = '') {
	return rtrim(shop_site_url(), '/').($token !== '' ? '/order/gutschein_karte.php?t='.rawurlencode($token) : '/order/gutschein.php?g='.rawurlencode($number));
}

/*
 * Draw the card. $o: motif, amount_cents, recipient, from, message, number, code, valid_until (Y-m-d or ''), brand. Returns a GD image of $w x ($w * 105 / 148) pixels.
 */
function gv_render_card($o, $w) {
	$H = (int)round($w * GV_CARD_H_MM / GV_CARD_W_MM); $u = $w / 1000;
	$im = imagecreatetruecolor($w, $H); imagealphablending($im, true);
	imagefill($im, 0, 0, gv_hex($im, '14100c'));
	$file = gv_motif_file($o['motif'] ?? '');
	if ($file !== '') {   // the picture fills the card (cut at the edges, never stretched)
		$src = @imagecreatefromjpeg($file);
		if ($src) {
			$sw = imagesx($src); $sh = imagesy($src); $s = max($w / $sw, $H / $sh);
			$cw = (int)round($w / $s); $ch = (int)round($H / $s);
			imagecopyresampled($im, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $w, $H, $cw, $ch);
		}
	}
	// the logo is white on a dark picture and black on a bright one: measured where it sits (before the fades)
	$lum = 0; $cnt = 0;
	for ($yy = (int)(20 * $u); $yy < 150 * $u; $yy += max(2, (int)(8 * $u))) { for ($xx = (int)(30 * $u); $xx < 300 * $u; $xx += max(2, (int)(8 * $u))) { $c = imagecolorat($im, $xx, $yy); $lum += (0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255)) / 255; $cnt++; } }
	$bright = $cnt > 0 && $lum / $cnt > 0.6;
	for ($y = 0; $y < $H; $y++) {   // fades: at the top (logo) light on a bright picture, else dark; dark at the bottom (text)
		$t = $y / $H; $a = 0.0; $top = 0.0;
		if ($t < 0.28) { $top = ($bright ? 0.5 : 0.62) * (1 - $t / 0.28); }
		if ($top > 0.004) { imagefilledrectangle($im, 0, $y, $w, $y, gv_hex($im, $bright ? 'fbf4e2' : '120d09', (int)round(127 * (1 - $top)))); }
		if ($t > 0.34) { $a = min(0.93, 0.93 * pow(($t - 0.34) / 0.5, 0.9)); }
		if ($a > 0.004) { imagefilledrectangle($im, 0, $y, $w, $y, gv_hex($im, '120d09', (int)round(127 * (1 - $a)))); }
	}
	$serif = gv_font('CormorantGaramond-SemiBold'); $serifM = gv_font('CormorantGaramond-Medium'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$gold = gv_hex($im, 'e6c57e'); $cream = gv_hex($im, 'fbf4e2'); $soft = gv_hex($im, 'fbf4e2', 30);
	$px = function ($v) use ($u) { return $v * $u * 0.75; };   // design size in "u" -> GD point size (points are 4/3 pixels)
	$m = 44 * $u;

	$logoFile = gv_logo_file($bright);
	if ($logoFile !== '' && ($lg = @imagecreatefrompng($logoFile))) {   // the logo of the restaurant (order/gutschein/logo-weiss.png / logo-schwarz.png)
		$lw = (int)round(215 * $u); $lh = (int)round($lw * imagesy($lg) / imagesx($lg));
		imagealphablending($im, true); imagecopyresampled($im, $lg, (int)$m, (int)(34 * $u), 0, 0, $lw, $lh, imagesx($lg), imagesy($lg));
	} else {
		imagettftext($im, $px(46), 0, (int)$m, (int)(76 * $u), $bright ? gv_hex($im, '1a140e') : $cream, $serif, (string)($o['brand'] ?? 'Amadeus'));
		gv_text_spaced($im, $px(11.5), $m + 2 * $u, 104 * $u, $bright ? gv_hex($im, '5b4520') : $gold, $sansB, 'CAFÉ · RESTAURANT · BAR', 3.2 * $u);
	}

	// bottom block, built from the bottom up
	$y = $H - 40 * $u;
	$info = 'Nr. '.($o['number'] ?? '').(($o['code'] ?? '') !== '' ? '     Code '.$o['code'] : '').(!empty($o['valid_until']) ? '     gültig bis '.date('d.m.Y', strtotime($o['valid_until'])) : '');
	imagettftext($im, $px(14.5), 0, (int)$m, (int)$y, $soft, $sans, $info);
	$y -= 52 * $u;
	$msg = trim((string)($o['message'] ?? ''));
	if ($msg !== '') {
		$lines = gv_wrap($msg, $serifM, $px(27), 560 * $u, 3);
		for ($i = count($lines) - 1; $i >= 0; $i--) { imagettftext($im, $px(27), 0, (int)$m, (int)$y, $cream, $serifM, $lines[$i]); $y -= 33 * $u; }
		$y -= 6 * $u;
	}
	$names = array();
	if (trim((string)($o['recipient'] ?? '')) !== '') { $names[] = 'Für '.trim($o['recipient']); }
	if (trim((string)($o['from'] ?? '')) !== '') { $names[] = 'Von '.trim($o['from']); }
	if ($names) { imagettftext($im, $px(16.5), 0, (int)$m, (int)$y, $gold, $sansB, implode('   ·   ', $names)); $y -= 50 * $u; }
	imagettftext($im, $px(112), 0, (int)($m - 4 * $u), (int)$y, $cream, $serif, gv_money_short((int)($o['amount_cents'] ?? 0)));
	$y -= 112 * $u;
	gv_text_spaced($im, $px(13), $m + 2 * $u, $y, $gold, $sansB, (string)($o['label'] ?? 'GUTSCHEIN'), 5.5 * $u);

	// QR code on a light square
	$qr = qr_matrix(gv_qr_url($o['number'] ?? '', $o['token'] ?? ''));
	if ($qr) {
		$n = count($qr); $mod = max(1, (int)floor(138 * $u / ($n + 2))); $side = ($n + 2) * $mod; $pad = 9 * $u;
		$bx = $w - $m - $side - 2 * $pad; $by = $H - 40 * $u - $side - 2 * $pad;
		imagefilledrectangle($im, (int)$bx, (int)$by, (int)($bx + $side + 2 * $pad), (int)($by + $side + 2 * $pad), gv_hex($im, 'fbf4e2'));
		$ink = gv_hex($im, '1a140e');
		for ($r = 0; $r < $n; $r++) { for ($c = 0; $c < $n; $c++) { if ($qr[$r][$c]) {
			$x0 = (int)($bx + $pad + ($c + 1) * $mod); $y0 = (int)($by + $pad + ($r + 1) * $mod);
			imagefilledrectangle($im, $x0, $y0, $x0 + $mod - 1, $y0 + $mod - 1, $ink);
		} } }
	}
	return $im;
}
function gv_card_jpeg($o, $w, $q = 86) {
	$im = gv_render_card($o, $w);
	ob_start(); imagejpeg($im, null, $q); $b = ob_get_clean();
	return $b;
}

// The front of the postcard EchtPost prints: A6 landscape (148 x 105 mm) plus 2 mm bleed = 152 x 109 mm = exactly 1795 x 1287 px (their upload size, 300 dpi). The card is drawn at the
// trimmed size, the bleed is made by repeating its edge pixels (text and QR code sit more than 5 mm inside the edge). Returns a JPEG.
const GV_POSTCARD_W = 1795;
const GV_POSTCARD_H = 1287;
function gv_postcard_front_jpeg($o, $q = 90) {
	$bleed = 23; $cw = GV_POSTCARD_W - 2 * $bleed; $ch = GV_POSTCARD_H - 2 * $bleed;
	$card = gv_render_card($o, $cw);
	if (imagesy($card) !== $ch) { $c2 = imagecreatetruecolor($cw, $ch); imagecopyresampled($c2, $card, 0, 0, 0, 0, $cw, $ch, imagesx($card), imagesy($card)); $card = $c2; }
	$im = imagecreatetruecolor(GV_POSTCARD_W, GV_POSTCARD_H);
	imagecopy($im, $card, $bleed, $bleed, 0, 0, $cw, $ch);
	imagecopyresampled($im, $card, 0, $bleed, 0, 0, $bleed, $ch, 1, $ch);                                          // left
	imagecopyresampled($im, $card, GV_POSTCARD_W - $bleed, $bleed, $cw - 1, 0, $bleed, $ch, 1, $ch);               // right
	imagecopyresampled($im, $im, 0, 0, 0, $bleed, GV_POSTCARD_W, $bleed, GV_POSTCARD_W, 1);                         // top (corners included)
	imagecopyresampled($im, $im, 0, GV_POSTCARD_H - $bleed, 0, GV_POSTCARD_H - $bleed - 1, GV_POSTCARD_W, $bleed, GV_POSTCARD_W, 1);   // bottom
	ob_start(); imagejpeg($im, null, $q); $b = ob_get_clean();
	return $b;
}

// the small print at the foot of the PDF and of the back of a printed card, like the FirstVoucher original: reference (voucher, order, purchase date), where the terms are, address, contact, website. Array of lines.
function gv_footer_lines($o) {
	$ref = array('Gutschein '.($o['number'] ?? ''));
	if (!empty($o['order_number'])) { $ref[] = 'Bestellung '.$o['order_number']; }
	if (!empty($o['bought'])) { $ref[] = 'gekauft am '.date('d.m.Y', strtotime($o['bought'])); }
	$lines = array(implode('  ·  ', $ref), 'Es gelten die Gutschein-Bedingungen: '.rtrim(shop_site_url(), '/').'/order/gutschein_bedingungen.php');
	$site = trim((string)shop_setting('gv_site_host'));
	$contact = array_merge(gv_legal_lines(), $site !== '' ? array($site) : array());
	if ($contact) { $lines[] = implode('  ·  ', $contact); }
	return $lines;
}

// the texts under the card on the PDF (draft after AGB 5 and 6; the owner approves the wording): array of array(heading, text)
function gv_pdf_terms($o) {
	$shop = trim((string)shop_setting('gv_shop_host'));   // the public address of the web shop as printed (amadeus.delivery); the QR code leads to the order system itself
	return array(
		array('Bei uns am Tisch.', 'Nenn uns die Gutscheinnummer oder zeig den QR-Code. Du kannst alles auf einmal einlösen oder nur einen Teil, der Rest bleibt auf dem Gutschein.'),
		array('Von zu Hause.', 'Im Webshop '.($shop !== '' ? $shop.' ' : '').'gibst du Nummer und Sicherheitscode ein. Der Betrag wird von deiner Bestellung abgezogen. Geliefert wird im Stadtgebiet Hildesheim.'),
		array('Gut zu wissen.', 'Gültig bis '.(!empty($o['valid_until']) ? date('d.m.Y', strtotime($o['valid_until'])) : 'zum aufgedruckten Datum').'. Eine Barauszahlung gibt es nicht. Der Gutschein gilt wie Bargeld: Bewahr ihn sorgfältig auf, bei Verlust gibt es keinen Ersatz. Mehrzweck-Gutschein: Die Umsatzsteuer entsteht erst beim Einlösen.'),
	);
}

// Clickable spots for the PDF: every web address, website and e-mail address in a drawn line of text. Returns array(array(x0, y0, x1, y1, uri), ...) in picture pixels
// ($base = baseline of the line, $size = GD point size of the line which is the pixel height at the 300 dpi page).
function gv_links_in($line, $x, $base, $size, $font) {
	$out = array();
	if (!preg_match_all('~https?://[^\s]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|(?:www\.)?[A-Za-z0-9-]+\.(?:de|delivery|at|com)\b(?:/[^\s]*)?~u', $line, $m, PREG_OFFSET_CAPTURE)) { return $out; }
	foreach ($m[0] as $hit) {
		$t = rtrim($hit[0], '.,;:)'); if ($t === '') { continue; }
		$pre = substr($line, 0, $hit[1]);
		$x0 = $x + gv_text_w($size, $font, $pre); $x1 = $x0 + gv_text_w($size, $font, $t);
		$uri = strpos($t, '@') !== false ? 'mailto:'.$t : (preg_match('~^https?://~', $t) ? $t : 'https://'.$t);
		$out[] = array((int)$x0, (int)($base - $size * 1.05), (int)$x1, (int)($base + $size * 0.3), $uri);
	}
	return $out;
}

// the A4 page as a picture (300 dpi): card at the top, instructions below
function gv_page_jpeg($o) {
	$W = 2480; $H = 3508; $cw = 2244; $ch = (int)round($cw * GV_CARD_H_MM / GV_CARD_W_MM);
	$page = imagecreatetruecolor($W, $H); imagefill($page, 0, 0, 0xFFFFFF);
	$card = gv_render_card($o, $cw);
	$x = (int)(($W - $cw) / 2); $y = 177;
	imagecopy($page, $card, $x, $y, 0, 0, $cw, $ch);
	$gray = gv_hex($page, '9a9488'); $ink = gv_hex($page, '2a2118'); $mut = gv_hex($page, '5b5346');   // cut marks at the corners of the card
	foreach (array(array($x, $y, -1, -1), array($x + $cw, $y, 1, -1), array($x, $y + $ch, -1, 1), array($x + $cw, $y + $ch, 1, 1)) as $c) {
		imageline($page, $c[0] + $c[2] * 16, $c[1], $c[0] + $c[2] * 56, $c[1], $gray); imageline($page, $c[0], $c[1] + $c[3] * 16, $c[0], $c[1] + $c[3] * 56, $gray);
	}
	$serif = gv_font('CormorantGaramond-SemiBold'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$ty = $y + $ch + 150; $links = array();
	imagettftext($page, 50 * 0.75, 0, $x, $ty, $ink, $serif, 'So löst du ihn ein'); $ty += 96;
	foreach (gv_pdf_terms($o) as $t) {
		imagettftext($page, 34 * 0.75, 0, $x, $ty, $ink, $sansB, $t[0]); $ty += 58;
		foreach (gv_wrap($t[1], $sans, 34 * 0.75, $cw) as $line) { imagettftext($page, 34 * 0.75, 0, $x, $ty, $mut, $sans, $line); $links = array_merge($links, gv_links_in($line, $x, $ty, 34 * 0.75, $sans)); $ty += 52; }
		$ty += 46;
	}
	$lf = gv_logo_file(true);   // black logo on the white page
	if ($lf !== '' && ($lg = @imagecreatefrompng($lf))) { $lw = 520; $lh = (int)round($lw * imagesy($lg) / imagesx($lg)); imagecopyresampled($page, $lg, $x + $cw - $lw, $H - 120 - $lh, 0, 0, $lw, $lh, imagesx($lg), imagesy($lg)); }
	$fy = $H - 150; $fl = array(); foreach (gv_footer_lines($o) as $part) { foreach (gv_wrap($part, $sans, 26 * 0.75, $cw - 640) as $line) { $fl[] = $line; } }
	foreach (array_reverse($fl) as $line) { imagettftext($page, 26 * 0.75, 0, $x, $fy, $gray, $sans, $line); $links = array_merge($links, gv_links_in($line, $x, $fy, 26 * 0.75, $sans)); $fy -= 40; }
	ob_start(); imagejpeg($page, null, 88); $b = ob_get_clean();
	return array($b, $W, $H, $links);
}

// one-page PDF (A4) around a JPEG; no library needed
function gv_pdf_wrap_jpeg($jpeg, $px, $py, $title) { return gv_pdf_wrap_jpegs(array(array($jpeg, $px, $py)), $title); }
// PDF (A4) with one full-page JPEG per page: $pages = array(array(jpeg, px, py, links), ...); links = array(array(x0, y0, x1, y1, uri)) in picture pixels become clickable areas
function gv_pdf_wrap_jpegs($pages, $title, $pw = 595.28, $ph = 841.89) {
	$objs = array(); $annots = array(); $n = count($pages);
	$objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
	$kids = array(); for ($i = 0; $i < $n; $i++) { $kids[] = (3 + 3 * $i).' 0 R'; }
	$objs[2] = "<< /Type /Pages /Kids [".implode(' ', $kids)."] /Count $n >>";
	foreach ($pages as $i => $pg) {
		$pn = 3 + 3 * $i; $cn = $pn + 1; $in = $pn + 2;
		$ann = array();
		foreach ((isset($pg[3]) ? $pg[3] : array()) as $lk) {
			$an = 4 + 3 * $n + count($annots);   // annotation objects come after the info object
			$sx = $pw / $pg[1]; $sy = $ph / $pg[2];
			$uri = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), preg_replace('/[^\x21-\x7E]/', '', $lk[4]));
			$annots[$an] = sprintf("<< /Type /Annot /Subtype /Link /Rect [%.2f %.2f %.2f %.2f] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>", $lk[0] * $sx, $ph - $lk[3] * $sy, $lk[2] * $sx, $ph - $lk[1] * $sy, $uri);
			$ann[] = $an.' 0 R';
		}
		$objs[$pn] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Resources << /XObject << /Im0 $in 0 R >> /ProcSet [/PDF /ImageC] >> /Contents $cn 0 R".($ann ? ' /Annots ['.implode(' ', $ann).']' : '')." >>";
		$content = "q $pw 0 0 $ph 0 0 cm /Im0 Do Q";
		$objs[$cn] = "<< /Length ".strlen($content)." >>\nstream\n$content\nendstream";
		$objs[$in] = "<< /Type /XObject /Subtype /Image /Width {$pg[1]} /Height {$pg[2]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($pg[0])." >>\nstream\n".$pg[0]."\nendstream";
	}
	$info = 3 + 3 * $n;
	$t = preg_replace('/[^\x20-\x7E]/', '', (string)$title);
	$objs[$info] = "<< /Title (".str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $t).") /Producer (mySeat) /CreationDate (D:".date('YmdHis').") >>";
	foreach ($annots as $an => $body) { $objs[$an] = $body; }
	$out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = array();
	foreach ($objs as $n => $body) { $off[$n] = strlen($out); $out .= "$n 0 obj\n$body\nendobj\n"; }
	$xref = strlen($out);
	$out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
	foreach ($objs as $n => $_) { $out .= sprintf("%010d 00000 n \n", $off[$n]); }
	$out .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
	return $out;
}
function gv_pdf($o) {
	list($jpeg, $w, $h, $links) = gv_page_jpeg($o);
	return gv_pdf_wrap_jpegs(array(array($jpeg, $w, $h, $links)), 'Gutschein '.($o['number'] ?? ''));
}

/*
 * The print file for a card the restaurant prints itself (orders "Per Post" and "Abholung"): an A4 PDF of two pages, the card at its true size of A6 landscape (148 x 105 mm, cut marks)
 * on page 1 and the way to redeem it on the back of the card on page 2 (same place on the sheet, print double-sided on card stock, flip on the long edge). The value stays inside an envelope.
 */
function gv_render_back($o, $w) {
	$H = (int)round($w * GV_CARD_H_MM / GV_CARD_W_MM); $u = $w / 1000;
	$im = imagecreatetruecolor($w, $H); imagefill($im, 0, 0, gv_hex($im, 'fbf7ee'));
	$serif = gv_font('CormorantGaramond-SemiBold'); $sans = gv_font('Raleway-Regular'); $sansB = gv_font('Raleway-SemiBold');
	$ink = gv_hex($im, '2a2118'); $mut = gv_hex($im, '5b5346'); $gold = gv_hex($im, '9a7a3a');
	$m = 60 * $u; $maxw = (int)(880 * $u); $y = 92 * $u;
	imagettftext($im, 30 * $u * 0.75, 0, (int)$m, (int)$y, $ink, $serif, 'So löst du ihn ein'); $y += 24 * $u;
	imageline($im, (int)$m, (int)$y, (int)($m + 70 * $u), (int)$y, $gold); $y += 36 * $u;
	foreach (gv_pdf_terms($o) as $t) {
		imagettftext($im, 14.5 * $u * 0.75, 0, (int)$m, (int)$y, $ink, $sansB, $t[0]); $y += 24 * $u;
		foreach (gv_wrap($t[1], $sans, 13.5 * $u * 0.75, $maxw) as $line) { imagettftext($im, 13.5 * $u * 0.75, 0, (int)$m, (int)$y, $mut, $sans, $line); $y += 21 * $u; }
		$y += 16 * $u;
	}
	$fl = array(); $fs = 10.5 * $u * 0.75; foreach (gv_footer_lines($o) as $part) { foreach (gv_wrap($part, $sans, $fs, (int)(640 * $u)) as $line) { $fl[] = $line; } }
	$fy = $H - 44 * $u; foreach (array_reverse($fl) as $line) { imagettftext($im, $fs, 0, (int)$m, (int)$fy, gv_hex($im, '8a8273'), $sans, $line); $fy -= 17 * $u; }
	$lf = gv_logo_file(true);
	if ($lf !== '' && ($lg = @imagecreatefrompng($lf))) { $lw = (int)(190 * $u); $lh = (int)round($lw * imagesy($lg) / imagesx($lg)); imagecopyresampled($im, $lg, (int)($w - $m - $lw), (int)($H - 52 * $u - $lh), 0, 0, $lw, $lh, imagesx($lg), imagesy($lg)); }
	return $im;
}
/*
 * The label for a numbered blank, for a DYMO LabelWriter (label 54 x 101 mm = 99014, landscape, 300 dpi, black on white): the security code with the words that tell the guest what it is for
 * (redeem online in the web shop) and the QR code with the words that it is for the team. Built for reading at arm's length: the code is about 34 pt, the lines under it 13 pt, nothing is
 * smaller than 10 pt, everything is black on white in a semi bold face with lining figures, and there is only one thing to read per place (a heading, the code, one instruction; the QR code and one caption).
 * $o: number, code, token, valid_until. Returns a one-page PDF of that size.
 */
function gv_label_pdf($o) {
	$W = 1193; $H = 638; $m = 46;   // 101 x 54 mm at 300 dpi; 4 mm margin (the printer cannot print to the edge)
	$im = imagecreatetruecolor($W, $H); imagefill($im, 0, 0, 0xFFFFFF);
	$k = imagecolorallocate($im, 0, 0, 0);
	$f = gv_font('Raleway-SemiBold');
	$S = function ($pt) { return $pt * 300 / 72; };   // real printed points -> GD size (GD counts pixels at 300 dpi here)
	$colW = 690; $divX = 770; $cap = 0.71;   // cap height of the face
	// 1. heading
	$hs = $S(16); $heading = 'Dein Webshop-Code';
	while ($hs > $S(12) && gv_text_w($hs, $f, $heading) > $colW) { $hs -= 2; }
	$y = $m + (int)round($cap * $hs);
	imagettftext($im, $hs, 0, $m, $y, $k, $f, $heading);
	// 2. the code, as large as the column allows (up to 36 pt)
	$code = (string)($o['code'] ?? ''); $cs = $S(36); $track = 12;
	while ($cs > $S(24) && gv_text_w($cs, $f, $code) + (max(1, strlen($code)) - 1) * $track > $colW) { $cs -= 2; }
	$y += 54 + (int)round($cap * $cs);
	gv_text_spaced($im, $cs, $m, $y, $k, $f, $code, $track);
	// 3. one instruction, 13 pt, two short lines
	$is = $S(13); $shop = trim((string)shop_setting('gv_shop_host'));
	$y += 86; imagettftext($im, $is, 0, $m, $y, $k, $f, 'Einlösen auf');
	$y += 64; imagettftext($im, $is, 0, $m, $y, $k, $f, $shop !== '' ? $shop : 'amadeus.delivery');
	// 4. the number (to match the label to its blank) and the validity, 10 pt
	$ms = $S(10);
	imagettftext($im, $ms, 0, $m, $H - $m - 54, $k, $f, 'Nr. '.($o['number'] ?? ''));
	if (!empty($o['valid_until'])) { imagettftext($im, $ms, 0, $m, $H - $m, $k, $f, 'gültig bis '.date('d.m.Y', strtotime($o['valid_until']))); }
	// right column: divider, QR code for the team (at least 25 mm, with its quiet zone) and one caption
	for ($yy = $m; $yy < $H - $m; $yy += 24) { imageline($im, $divX, $yy, $divX, min($H - $m, $yy + 12), $k); imageline($im, $divX + 1, $yy, $divX + 1, min($H - $m, $yy + 12), $k); }
	$qr = qr_matrix(gv_qr_url($o['number'] ?? '', $o['token'] ?? ''));
	$cx = $divX + (($W - $m - $divX) / 2); $ty = $m + 40;
	if ($qr) {
		$n = count($qr); $mod = max(1, (int)floor(310 / ($n + 2))); $side = ($n + 2) * $mod; $qx = (int)round($cx - $side / 2); $qy = $m - 6;
		for ($r = 0; $r < $n; $r++) { for ($c = 0; $c < $n; $c++) { if ($qr[$r][$c]) { $x0 = $qx + ($c + 1) * $mod; $y0 = $qy + ($r + 1) * $mod; imagefilledrectangle($im, $x0, $y0, $x0 + $mod - 1, $y0 + $mod - 1, $k); } } }
		$ty = $qy + $side + 62;
	}
	$cs2 = $S(13);
	foreach (array('Nur für', 'das Team') as $line) { imagettftext($im, $cs2, 0, (int)round($cx - gv_text_w($cs2, $f, $line) / 2), $ty, $k, $f, $line); $ty += 62; }
	ob_start(); imagejpeg($im, null, 92); $jpeg = ob_get_clean();
	return gv_pdf_wrap_jpegs(array(array($jpeg, $W, $H)), 'Etikett '.($o['number'] ?? ''), 286.30, 153.07);
}

function gv_print_pdf($o) {
	$W = 2480; $H = 3508; $cw = 1748; $ch = 1240; $x = (int)(($W - $cw) / 2); $y = 330;   // 148 x 105 mm at 300 dpi
	$pages = array();
	foreach (array('front', 'back') as $side) {
		$page = imagecreatetruecolor($W, $H); imagefill($page, 0, 0, 0xFFFFFF);
		$card = $side === 'front' ? gv_render_card($o, $cw) : gv_render_back($o, $cw);
		imagecopy($page, $card, $x, $y, 0, 0, $cw, $ch);
		$gray = gv_hex($page, '9a9488');   // cut marks at the corners of the card
		foreach (array(array($x, $y, -1, -1), array($x + $cw, $y, 1, -1), array($x, $y + $ch, -1, 1), array($x + $cw, $y + $ch, 1, 1)) as $c) {
			imageline($page, $c[0] + $c[2] * 20, $c[1], $c[0] + $c[2] * 70, $c[1], $gray); imageline($page, $c[0], $c[1] + $c[3] * 20, $c[0], $c[1] + $c[3] * 70, $gray);
		}
		$sans = gv_font('Raleway-Regular');
		imagettftext($page, 26 * 0.75, 0, $x, $y + $ch + 140, gv_hex($page, '9a9488'), $sans, ($side === 'front' ? 'Vorderseite' : 'Rückseite').': auf Karton (mindestens 250 g) drucken, an den Marken ausschneiden. Beidseitig drucken, Wenden an der langen Kante.');
		ob_start(); imagejpeg($page, null, 90); $pages[] = array(ob_get_clean(), $W, $H);
	}
	return gv_pdf_wrap_jpegs($pages, 'Gutschein-Karte '.($o['number'] ?? ''));
}
