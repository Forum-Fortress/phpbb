<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * @ignore
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_FORUMFORTRESS_TITLE' => 'Forum Fortress',
	'ACP_FORUMFORTRESS_SETTINGS' => 'Settings',
	'ACP_FORUMFORTRESS_PROTECTION' => 'Protection',
	'ACP_FORUMFORTRESS_BYPASS_ADMINISTRATORS' => 'Bypass checks for administrators',
	'ACP_FORUMFORTRESS_BYPASS_ADMINISTRATORS_EXPLAIN' => 'Skip Forum Fortress spam checks for board founders and users with global ACP permissions.',
	'ACP_FORUMFORTRESS_BYPASS_MODERATORS' => 'Bypass checks for moderators',
	'ACP_FORUMFORTRESS_BYPASS_MODERATORS_EXPLAIN' => 'Skip Forum Fortress spam checks for users with global moderator permissions.',
	'ACP_FORUMFORTRESS_SETTINGS_SAVED' => 'Forum Fortress settings have been saved.',
	'ACP_FORUMFORTRESS_ENABLED' => 'Enable Forum Fortress',
	'ACP_FORUMFORTRESS_API_BASE_URL' => 'API base URL',
	'ACP_FORUMFORTRESS_TIMEOUT' => 'HTTP timeout (seconds)',
	'ACP_FORUMFORTRESS_API_KEY' => 'API key',
	'ACP_FORUMFORTRESS_SITE_ID' => 'Site ID',
	'ACP_FORUMFORTRESS_FAIL_OPEN' => 'Fail open on API errors',
	'ACP_FORUMFORTRESS_DEBUG_LOG' => 'Write debug log entries',
	'ACP_FORUMFORTRESS_SEND_HAM' => 'Send ham feedback after moderator approval',
	'ACP_FORUMFORTRESS_DELETE_REJECTED_USERS' => 'Delete rejected users created by Forum Fortress',
	'ACP_FORUMFORTRESS_EXPLAIN' => 'Configure the Forum Fortress API connection and local behaviour for phpBB spam checks.',
	'ACP_FORUMFORTRESS_DATA_PROCESSING' => 'Remote data processing',
	'ACP_FORUMFORTRESS_DATA_PROCESSING_EXPLAIN' => 'When protection is enabled, this extension sends relevant spam-check data to Forum Fortress.' .
		' Depending on the action, this includes IP address, username, email address or domain, user agent, submitted post,' .
		' contact, profile or signature content, links, and account metadata. Review the Forum Fortress Privacy Policy' .
		' and update your board privacy information before enabling protection.',
	'ACP_FORUMFORTRESS_PRIVACY_POLICY' => 'Forum Fortress Privacy Policy',
	'ACP_FORUMFORTRESS_PLUGIN_VERSION' => 'Plugin version',
	'ACP_FORUMFORTRESS_CONNECTION' => 'Connection',
	'ACP_FORUMFORTRESS_STATUS' => 'Status',
	'ACP_FORUMFORTRESS_STATUS_ACTIONS' => 'Status and actions',
	'ACP_FORUMFORTRESS_SITE_STATUS' => 'Site status',
	'ACP_FORUMFORTRESS_ENDPOINT_HEALTH' => 'Endpoint routing',
	'ACP_FORUMFORTRESS_PORTAL_LOGIN' => 'Portal Login',
	'ACP_FORUMFORTRESS_ACTIONS' => 'Actions',
	'ACP_FORUMFORTRESS_AUTO_BOOTSTRAP' => 'Automatic bootstrap',
	'ACP_FORUMFORTRESS_CONNECTION_TEST' => 'Connection test',
	'ACP_FORUMFORTRESS_REGISTRATION' => 'Complete registration',
	'ACP_FORUMFORTRESS_REGISTRATION_EMAIL' => 'Registration email',
	'ACP_FORUMFORTRESS_ENABLED_YES' => 'Enabled',
	'ACP_FORUMFORTRESS_ENABLED_NO' => 'Disabled',
	'ACP_FORUMFORTRESS_NOT_SET' => 'Not set',
	'ACP_FORUMFORTRESS_UNKNOWN' => 'Unknown',
	'ACP_FORUMFORTRESS_MASKED' => 'Stored',
	'ACP_FORUMFORTRESS_RUN_TEST' => 'Run connection test',
	'ACP_FORUMFORTRESS_COMPLETE_REGISTRATION' => 'Complete registration',
	'ACP_FORUMFORTRESS_ATTACK_MODE_ENABLE' => 'Enable attack mode',
	'ACP_FORUMFORTRESS_ATTACK_MODE_DISABLE' => 'Disable attack mode',
	'ACP_FORUMFORTRESS_TEST_RESULT' => 'Latest test result',
	'ACP_FORUMFORTRESS_RESULT_ERROR' => 'Error',
	'ACP_FORUMFORTRESS_RESULT_PAYLOADS' => 'Response payloads',
	'ACP_FORUMFORTRESS_TEST_BOOTSTRAP' => 'Bootstrap',
	'ACP_FORUMFORTRESS_TEST_HEALTH' => 'Health',
	'ACP_FORUMFORTRESS_TEST_CAPABILITIES' => 'Capabilities',
	'ACP_FORUMFORTRESS_TEST_SITE_STATUS' => 'Site status',
	'ACP_FORUMFORTRESS_PLAN' => 'Plan',
	'ACP_FORUMFORTRESS_REGISTRATION_REQUIRED' => 'Registration required',
	'ACP_FORUMFORTRESS_ATTACK_MODE' => 'Attack mode',
	'ACP_FORUMFORTRESS_PREFERRED_ENDPOINT' => 'GeoDNS route',
	'ACP_FORUMFORTRESS_PREFERRED_ENDPOINT_OVERRIDE' => 'Preferred API endpoint',
	'ACP_FORUMFORTRESS_API_REGION' => 'API region',
	'ACP_FORUMFORTRESS_API_REGION_EXPLAIN' => 'Lock check traffic to a region, or use the recommended global network.',
	'ACP_FORUMFORTRESS_REGION_GLOBAL' => 'Global - Recommended',
	'ACP_FORUMFORTRESS_REGION_UK' => 'United Kingdom only',
	'ACP_FORUMFORTRESS_REGION_EU' => 'European Union only',
	'ACP_FORUMFORTRESS_REGION_US' => 'United States only',
	'ACP_FORUMFORTRESS_ALLOW_GLOBAL_FALLBACK' => 'Allow global emergency fallback',
	'ACP_FORUMFORTRESS_ALLOW_GLOBAL_FALLBACK_EXPLAIN' => 'If the selected regional service remains unavailable after retrying, permit the request to use the global network. Processing may occur outside the selected region.',
	'ACP_FORUMFORTRESS_PREFERRED_MISSING_ENDPOINT' => 'GeoDNS route missing',
	'ACP_FORUMFORTRESS_LAST_HEALTH_AT' => 'Last endpoint health check',
	'ACP_FORUMFORTRESS_LAST_FAILURE' => 'Last API failover',
	'ACP_FORUMFORTRESS_ENDPOINT_LATENCY' => 'Fallback endpoint catalog',
	'ACP_FORUMFORTRESS_ENDPOINT' => 'Endpoint',
	'ACP_FORUMFORTRESS_LATENCY' => 'Routing role',
	'ACP_FORUMFORTRESS_PREFERRED_LABEL' => 'GeoDNS primary',
	'ACP_FORUMFORTRESS_NO' => 'No',
	'ACP_FORUMFORTRESS_YES' => 'Yes',
	'ACP_FORUMFORTRESS_NONE' => 'None',
	'ACP_FORUMFORTRESS_ACTIVE' => 'Active',
	'ACP_FORUMFORTRESS_INACTIVE' => 'Inactive',
	'ACP_FORUMFORTRESS_CONNECTED' => 'Connected',
	'ACP_FORUMFORTRESS_CONFIGURED' => 'Configured',
	'ACP_FORUMFORTRESS_REFRESH' => 'Refresh',
	'ACP_FORUMFORTRESS_CURRENT_DETAILS' => 'Current protection and service details.',
	'ACP_FORUMFORTRESS_DECISIONS' => 'Decisions',
	'ACP_FORUMFORTRESS_ALLOWED' => 'allowed',
	'ACP_FORUMFORTRESS_BLOCKED_COUNT' => 'blocked',
	'ACP_FORUMFORTRESS_MAINTENANCE' => 'Maintenance and configuration',
	'ACP_FORUMFORTRESS_MAINTENANCE_EXPLAIN' => 'Connection, protection policy, and registration',
	'ACP_FORUMFORTRESS_API_KEY_PRESERVE' => 'Leave blank to keep the current API key.',
	'ACP_FORUMFORTRESS_DOMAIN' => 'Domain',
	'ACP_FORUMFORTRESS_DIAGNOSTICS' => 'Diagnostics',
	'ACP_FORUMFORTRESS_FORUM_LAST_SYNCED' => 'Forum last synchronised',
	'ACP_FORUMFORTRESS_PLUGIN_UPDATES' => 'Plugin updates',
	'ACP_FORUMFORTRESS_UPDATE_AVAILABLE' => 'Update available.',
	'ACP_FORUMFORTRESS_LATEST_VERSION' => 'Latest version',
	'ACP_FORUMFORTRESS_DOWNLOAD_UPDATE' => 'Download update',
	'ACP_FORUMFORTRESS_UP_TO_DATE' => 'Current version is up to date.',
	'ACP_FORUMFORTRESS_ENDPOINT_HEALTH_EXPLAIN' => 'GeoDNS primary followed by catalog fallbacks. No client-side ranking is performed.',
	'ACP_FORUMFORTRESS_API_KEY_LINE_BREAKS' => 'The API key must not contain line breaks.',
	'ACP_FORUMFORTRESS_REGISTRATION_EMAIL_REQUIRED' => 'Registration email is required.',
	'ACP_FORUMFORTRESS_REGISTRATION_EMAIL_INVALID' => 'Enter a valid registration email address.',
	'ACP_FORUMFORTRESS_FAILURE_STATUS' => 'status %1$d',
	'ACP_FORUMFORTRESS_FAILURE_PATH' => 'on %1$s',
	'ACP_FORUMFORTRESS_FAILURE_BASE' => 'via %1$s',
	'ACP_FORUMFORTRESS_FAILURE_TIME' => 'at %1$s',
	'ACP_FORUMFORTRESS_TEST_ANSWERED_ENDPOINT' => 'Connection test answered by',
	'ACP_FORUMFORTRESS_DATASET_VERSION' => 'Dataset version',
	'ACP_FORUMFORTRESS_CURRENT_MONTH_CHECKS' => 'Current month checks',
	'ACP_FORUMFORTRESS_ALLOWS' => 'Allows',
	'ACP_FORUMFORTRESS_BLOCKS' => 'Blocks',
	'ACP_FORUMFORTRESS_SITE_IDENTITY_SAVED' => 'Forum Fortress returned and stored a site identity automatically.',
	'ACP_FORUMFORTRESS_NO_RESPONSE' => 'No response returned by the Forum Fortress API.',
	'FORUMFORTRESS_BLOCKED' => 'Your submission was blocked by Forum Fortress.',
	'FORUMFORTRESS_ABOVE_LIMIT' => 'Forum Fortress limits are exceeded for this site. Complete registration in the Forum Fortress portal or enable attack mode.',
]);
