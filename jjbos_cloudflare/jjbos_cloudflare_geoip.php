<?php

/**
 * @file
 * Script to deliver GeoIP header value.
 */

/**
 * Header name to detect region.
 */
define('IPCOUNTRY_HEADER', 'HTTP_CF_IPCOUNTRY');

// No additional output, e.g. errors.
ini_set('display_errors', '0');

// Get data.
$code = !empty($_SERVER[IPCOUNTRY_HEADER]) && $_SERVER[IPCOUNTRY_HEADER] != 'XX' ? $_SERVER[IPCOUNTRY_HEADER] : '';

// Output.
header('Content-Type: text/plain');
header('Cache-Control: no-cache, no-store, must-revalidate');
print $code;
