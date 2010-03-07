<?php defined('SYSPATH') or die('No direct script access.');

// The API URI's for accessing YQL
$config['api'] = YQL::YQL_PUBLIC_API;

// Cache lifetime in seconds, if FALSE no caching
$config['cache'] = FALSE;

// Include diagnostics
$config['diagnostics'] = TRUE;

// Environment file to use for queries, if FALSE none used
$config['environment'] = 'http://datatables.org/alltables.env';
