<?php

$lang['url']       = 'Mailer service URL, e.g. <code>http://mail.internal</code>';
$lang['apikey']    = 'Project API key (<code>php bin/mailer key:create</code>)';
$lang['mode']      = 'Sending mode';
$lang['mode_o_queue'] = 'queue — return immediately, the worker delivers the mail';
$lang['mode_o_sync']  = 'sync — wait for the delivery result';
$lang['transport'] = 'Transport name in the service (empty — default transport)';
$lang['tag']       = 'Tag for messages in the service panel';
$lang['timeout']   = 'Request timeout, seconds';
$lang['fallback']  = 'Fall back to the built-in DokuWiki mailer when the service is unavailable';
