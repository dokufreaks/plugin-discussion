<?php
/**
 * Options for the discussion plugin
 */

$conf['automatic']       = 0;   // discussion section on every page by default
$conf['allowguests']     = 1;   // should unregistred users be able to comment?
$conf['linkemail']       = 0;   // link usernames with e-mail addresses
$conf['usegravatar']     = 1;   // use gravatars in comments
$conf['gravatar_size']   = 40;  // default size of gravatar: 20, 40 or 80 pixel
$conf['gravatar_rating'] = 'R'; // max rating of gravatar images: G, PG, R or X - see http://gravatar.com/rating.php
$conf['urlfield']        = 0;   // allow entering an URL
$conf['addressfield']    = 0;   // allow entering an address
$conf['adminimport']     = 0;   // allow admins to set all the fields for import
$conf['usecocomment']    = 1;   // use coComment comment tracking
$conf['wikisyntaxok']    = 1;   // allow wiki syntax in comments

$conf['threads_formposition'] = 'bottom'; // position of new thread form

//Setup VIM: ex: et ts=2 enc=utf-8 :