<?php
// Where this installation is reachable (see web/classes/hosts.class.php). Leave a value empty to use the address of the current request.
// Only the address itself: https://, the name, no slash and no path.
return array(
	'app_url'   => 'https://app.amds.at', // guests: order page, account, order status, driver page. e.g. 'https://app.amds.at'
	'admin_url' => 'https://reservierung.amds.at', // backend, monitors, table reservations (cancel and feedback links). e.g. 'https://reservierung.amds.at'
);
