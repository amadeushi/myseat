<?php
/*
 * Small QR code encoder (ISO 18004): byte mode, error correction level M, versions 1 to 10 (up to 213 bytes). No library needed; it is used for the QR code on the voucher card (shop_voucher_pdf.class.php).
 * qr_matrix($text) returns the modules as rows of 0/1 (without the quiet zone) or null when the text is too long.
 */

// per version at level M: array(error correction codewords per block, blocks of group 1, data codewords per block of group 1, blocks of group 2, data codewords per block of group 2)
const QR_M_BLOCKS = array(
	1 => array(10, 1, 16, 0, 0), 2 => array(16, 1, 28, 0, 0), 3 => array(26, 1, 44, 0, 0), 4 => array(18, 2, 32, 0, 0), 5 => array(24, 2, 43, 0, 0),
	6 => array(16, 4, 27, 0, 0), 7 => array(18, 4, 31, 0, 0), 8 => array(22, 2, 38, 2, 39), 9 => array(22, 3, 36, 2, 37), 10 => array(26, 4, 43, 1, 44));
const QR_ALIGN = array(1 => array(), 2 => array(6, 18), 3 => array(6, 22), 4 => array(6, 26), 5 => array(6, 30), 6 => array(6, 34), 7 => array(6, 22, 38), 8 => array(6, 24, 42), 9 => array(6, 26, 46), 10 => array(6, 28, 50));

function qr_gf() {
	static $t = null;
	if ($t === null) {
		$exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0); $x = 1;
		for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) { $x ^= 0x11D; } }
		for ($i = 255; $i < 512; $i++) { $exp[$i] = $exp[$i - 255]; }
		$t = array($exp, $log);
	}
	return $t;
}
function qr_mul($a, $b) { if ($a === 0 || $b === 0) { return 0; } list($exp, $log) = qr_gf(); return $exp[$log[$a] + $log[$b]]; }

// the error correction codewords of one block of data codewords
function qr_rs($data, $n) {
	list($exp) = qr_gf();
	$g = array(1);
	for ($i = 0; $i < $n; $i++) {   // multiply by (x - alpha^i)
		$ng = array_fill(0, count($g) + 1, 0);
		foreach ($g as $j => $c) { $ng[$j] ^= $c; $ng[$j + 1] ^= qr_mul($c, $exp[$i]); }
		$g = $ng;
	}
	$rem = array_fill(0, $n, 0);
	foreach ($data as $d) {
		$f = $d ^ $rem[0];
		array_shift($rem); $rem[] = 0;
		if ($f !== 0) { for ($i = 0; $i < $n; $i++) { $rem[$i] ^= qr_mul($g[$i + 1], $f); } }
	}
	return $rem;
}

function qr_penalty($m, $size) {
	$p = 0;
	for ($pass = 0; $pass < 2; $pass++) {   // runs of five or more equal modules in rows, then columns
		for ($a = 0; $a < $size; $a++) {
			$run = 1; $prev = -1;
			for ($b = 0; $b < $size; $b++) {
				$v = $pass ? $m[$b][$a] : $m[$a][$b];
				if ($v === $prev) { $run++; if ($run === 5) { $p += 3; } elseif ($run > 5) { $p++; } } else { $run = 1; $prev = $v; }
			}
		}
	}
	for ($r = 0; $r < $size - 1; $r++) { for ($c = 0; $c < $size - 1; $c++) { $v = $m[$r][$c]; if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) { $p += 3; } } }
	$pat1 = array(1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0); $pat2 = array(0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1);
	for ($a = 0; $a < $size; $a++) {
		for ($b = 0; $b <= $size - 11; $b++) {
			foreach (array($pat1, $pat2) as $pat) {
				$h = true; $v = true;
				for ($k = 0; $k < 11; $k++) { if ($m[$a][$b + $k] !== $pat[$k]) { $h = false; } if ($m[$b + $k][$a] !== $pat[$k]) { $v = false; } }
				if ($h) { $p += 40; } if ($v) { $p += 40; }
			}
		}
	}
	$dark = 0; foreach ($m as $row) { $dark += array_sum($row); }
	$p += (int)(ceil(abs($dark * 20 - $size * $size * 10) / ($size * $size)) - 1) * 10;
	return $p;
}

function qr_matrix($text) {
	$bytes = array_values(unpack('C*', (string)$text));
	$n = count($bytes);
	$ver = 0;
	foreach (QR_M_BLOCKS as $v => $b) {
		$cap = $b[1] * $b[2] + $b[3] * $b[4];
		if (4 + ($v < 10 ? 8 : 16) + 8 * $n <= $cap * 8) { $ver = $v; break; }
	}
	if ($ver === 0) { return null; }
	list($ecPer, $g1n, $g1d, $g2n, $g2d) = QR_M_BLOCKS[$ver];
	$dataCw = $g1n * $g1d + $g2n * $g2d;

	// data bits: mode 0100, count, bytes, terminator, padding
	$bits = array();
	$put = function ($val, $len) use (&$bits) { for ($i = $len - 1; $i >= 0; $i--) { $bits[] = ($val >> $i) & 1; } };
	$put(4, 4); $put($n, $ver < 10 ? 8 : 16);
	foreach ($bytes as $b) { $put($b, 8); }
	for ($i = 0; $i < 4 && count($bits) < $dataCw * 8; $i++) { $bits[] = 0; }
	while (count($bits) % 8) { $bits[] = 0; }
	$cw = array();
	for ($i = 0; $i < count($bits); $i += 8) { $v = 0; for ($k = 0; $k < 8; $k++) { $v = ($v << 1) | $bits[$i + $k]; } $cw[] = $v; }
	for ($pad = 0; count($cw) < $dataCw; $pad ^= 1) { $cw[] = $pad ? 0x11 : 0xEC; }

	// blocks, error correction, interleaving
	$blocks = array(); $pos = 0;
	for ($i = 0; $i < $g1n + $g2n; $i++) { $len = $i < $g1n ? $g1d : $g2d; $blocks[] = array_slice($cw, $pos, $len); $pos += $len; }
	$ecs = array(); foreach ($blocks as $b) { $ecs[] = qr_rs($b, $ecPer); }
	$final = array();
	for ($i = 0; $i < max($g1d, $g2d); $i++) { foreach ($blocks as $b) { if ($i < count($b)) { $final[] = $b[$i]; } } }
	for ($i = 0; $i < $ecPer; $i++) { foreach ($ecs as $e) { $final[] = $e[$i]; } }
	$dbits = array();
	foreach ($final as $c) { for ($i = 7; $i >= 0; $i--) { $dbits[] = ($c >> $i) & 1; } }

	// function patterns
	$size = 17 + 4 * $ver;
	$m = array_fill(0, $size, array_fill(0, $size, 0));
	$fn = array_fill(0, $size, array_fill(0, $size, false));
	$set = function ($x, $y, $dark) use (&$m, &$fn, $size) { if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) { $m[$y][$x] = $dark ? 1 : 0; $fn[$y][$x] = true; } };
	foreach (array(array(0, 0), array($size - 7, 0), array(0, $size - 7)) as $f) {
		for ($dy = -1; $dy <= 7; $dy++) { for ($dx = -1; $dx <= 7; $dx++) {
			$in = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
			$set($f[0] + $dx, $f[1] + $dy, $in && ($dx == 0 || $dx == 6 || $dy == 0 || $dy == 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)));
		} }
	}
	for ($i = 8; $i < $size - 8; $i++) { $set($i, 6, $i % 2 == 0); $set(6, $i, $i % 2 == 0); }
	$al = QR_ALIGN[$ver]; $last = count($al) - 1;
	foreach ($al as $ai => $ay) { foreach ($al as $bi => $ax) {
		if (($ai == 0 && $bi == 0) || ($ai == 0 && $bi == $last) || ($ai == $last && $bi == 0)) { continue; }
		for ($dy = -2; $dy <= 2; $dy++) { for ($dx = -2; $dx <= 2; $dx++) { $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) != 1); } }
	} }
	$format = function ($mask) use ($set, $size) {
		$d = $mask; $rem = $d;   // level M = 00
		for ($i = 0; $i < 10; $i++) { $rem = ($rem << 1) ^ (($rem >> 9) * 0x537); }
		$bits = (($d << 10) | $rem) ^ 0x5412;
		$bit = function ($i) use ($bits) { return (($bits >> $i) & 1) === 1; };
		for ($i = 0; $i <= 5; $i++) { $set(8, $i, $bit($i)); }
		$set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
		for ($i = 9; $i < 15; $i++) { $set(14 - $i, 8, $bit($i)); }
		for ($i = 0; $i < 8; $i++) { $set($size - 1 - $i, 8, $bit($i)); }
		for ($i = 8; $i < 15; $i++) { $set(8, $size - 15 + $i, $bit($i)); }
		$set(8, $size - 8, true);
	};
	$format(0);
	if ($ver >= 7) {
		$rem = $ver; for ($i = 0; $i < 12; $i++) { $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25); }
		$vb = ($ver << 12) | $rem;
		for ($i = 0; $i < 18; $i++) { $d = (($vb >> $i) & 1) === 1; $a = $size - 11 + $i % 3; $b = intdiv($i, 3); $set($a, $b, $d); $set($b, $a, $d); }
	}

	// data in the zigzag, then the best mask
	$idx = 0; $up = true;
	for ($col = $size - 1; $col > 0; $col -= 2) {
		if ($col == 6) { $col--; }
		for ($i = 0; $i < $size; $i++) {
			$row = $up ? $size - 1 - $i : $i;
			for ($k = 0; $k < 2; $k++) { $c = $col - $k; if (!$fn[$row][$c]) { $m[$row][$c] = $idx < count($dbits) ? $dbits[$idx++] : 0; } }
		}
		$up = !$up;
	}
	$maskFn = array(
		function ($i, $j) { return ($i + $j) % 2 == 0; }, function ($i, $j) { return $i % 2 == 0; }, function ($i, $j) { return $j % 3 == 0; }, function ($i, $j) { return ($i + $j) % 3 == 0; },
		function ($i, $j) { return (intdiv($i, 2) + intdiv($j, 3)) % 2 == 0; }, function ($i, $j) { return ($i * $j) % 2 + ($i * $j) % 3 == 0; },
		function ($i, $j) { return (($i * $j) % 2 + ($i * $j) % 3) % 2 == 0; }, function ($i, $j) { return (($i + $j) % 2 + ($i * $j) % 3) % 2 == 0; });
	$best = null; $bestP = PHP_INT_MAX;
	for ($mask = 0; $mask < 8; $mask++) {
		$t = $m;
		for ($r = 0; $r < $size; $r++) { for ($c = 0; $c < $size; $c++) { if (!$fn[$r][$c] && $maskFn[$mask]($r, $c)) { $t[$r][$c] ^= 1; } } }
		// the format bits of this mask go in before the penalty is counted
		$m2 = $m; $m = $t; $format($mask); $t = $m; $m = $m2;
		$p = qr_penalty($t, $size);
		if ($p < $bestP) { $bestP = $p; $best = $t; }
	}
	return $best;
}
