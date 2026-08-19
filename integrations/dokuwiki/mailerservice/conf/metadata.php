<?php

/**
 * Описание настроек для менеджера конфигурации.
 */

$meta['url']       = ['string'];
$meta['apikey']    = ['password'];
$meta['mode']      = ['multichoice', '_choices' => ['queue', 'sync']];
$meta['transport'] = ['string'];
$meta['tag']       = ['string'];
$meta['timeout']   = ['numeric', '_min' => 1, '_max' => 120];
$meta['fallback']  = ['onoff'];
