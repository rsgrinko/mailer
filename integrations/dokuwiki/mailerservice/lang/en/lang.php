<?php

$lang['menu']            = 'Mailer service';

$lang['err_noturl']      = 'Mailer service URL is not set';
$lang['err_json']        = 'Could not build the request';
$lang['err_connect']     = 'Service is unreachable';
$lang['err_status']      = 'Service responded with code';
$lang['err_empty']       = 'DokuWiki failed to build the message';

$lang['notconfigured']   = 'The plugin is not configured: set the service URL and the API key in the configuration manager. Until then mail is sent the usual way.';
$lang['settings']        = 'Settings';
$lang['s_url']           = 'Service URL';
$lang['s_mode']          = 'Mode';
$lang['s_transport']     = 'Transport';
$lang['s_tag']           = 'Tag';
$lang['s_fallback']      = 'Fallback sending';
$lang['yes']             = 'yes';
$lang['no']              = 'no';

$lang['health']          = 'Service health';
$lang['h_check']         = 'Check';
$lang['h_status']        = 'Status';
$lang['h_database']      = 'Database';
$lang['h_queue']         = 'Queue';
$lang['h_worker']        = 'Worker';
$lang['h_ok']            = 'ok';
$lang['h_bad']           = 'problem';
$lang['h_ready']         = 'ready';
$lang['h_delayed']       = 'delayed';
$lang['h_failed']        = 'failed';
$lang['h_lastseen']      = 'last seen';
$lang['h_never']         = 'never seen';
$lang['h_fail']          = 'Check failed';

$lang['test']            = 'Test message';
$lang['test_hint']       = 'The message goes through the regular DokuWiki mailer, so the whole chain is tested.';
$lang['test_to']         = 'To';
$lang['test_send']       = 'Send';
$lang['test_subject']    = 'Mail delivery test';
$lang['test_body']       = "This is a test message from DokuWiki.\nIf you can read it, sending through the mailer service works.";
$lang['test_ok']         = 'The service accepted the message';
$lang['test_fail']       = 'Sending failed, see the DokuWiki error log';
$lang['test_bademail']   = 'Invalid address';
