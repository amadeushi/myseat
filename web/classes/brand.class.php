<?php
/*
 * The restaurant's logo for the booking widget, the guest page, the backend and the login page. One address for
 * all of them: $settings['logoUrl'] in config.general.php, otherwise the logo of the restaurant's website
 * (white on transparent, made for the dark pages). If the image cannot load, the name is shown as text.
 */
function brand_logo_url() {
	global $settings;
	return !empty($settings['logoUrl']) ? $settings['logoUrl'] : 'https://www.amadeus-hildesheim.de/images/logo.png';
}

function brand_logo_html($name, $class = 'brand-logo') {
	return '<img class="'.htmlspecialchars($class).'" src="'.htmlspecialchars(brand_logo_url()).'" alt="'.htmlspecialchars((string)$name).'" width="109" height="40" onerror="this.replaceWith(document.createTextNode(this.alt))">';
}
