<?php
namespace BitsnBolts\Flysystem\Adapter\MSGraph\Test;

use Exception;

/**
 * To run the tests, you must supply your Microsoft Azure Application
 * ID and Password. This must be done via environment variables before
 * loading the tests.
 *
 *
*/
if (!getenv("test_sharepoint_username") || !getenv("test_sharepoint_password")) {
    throw new Exception("No username or password specified in environment.");
}

define("SHAREPOINT_SITE_URL", getenv("test_sharepoint_site_url") ? getenv("test_sharepoint_site_url") : "https://example.sharepoint.com/sites/TestSite");
define("SHAREPOINT_USERNAME", getenv("test_sharepoint_username") ? getenv("test_sharepoint_username") : "admin@example.com");
define("SHAREPOINT_PASSWORD", getenv("test_sharepoint_password") ? getenv("test_sharepoint_password") : "top-secret");
define("TEST_FILE_PREFIX", getenv("test_file_prefix") ? getenv("test_file_prefix") : "");
