<?php
/**
 * Sumber Monitoring PPPoE.
 * Hanya endpoint GET /api/pppoes yang digunakan oleh Billing.
 * Billing tidak mengirim POST/PUT/DELETE ke aplikasi PPPoE.
 */
define('PPPOE_MONITOR_BASE_URL', 'http://202.169.232.239:8078');
define('PPPOE_MONITOR_API_URL', PPPOE_MONITOR_BASE_URL . '/api/pppoes');
define('PPPOE_MONITOR_TIMEOUT_SECONDS', 6);
?>
