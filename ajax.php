<?php
/**
 * AJAX functionality for DokuWiki Plugin Discussion
 */

if(!count($_POST) && $HTTP_RAW_POST_DATA){
  parse_str($HTTP_RAW_POST_DATA, $_POST);
}
 
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/parserutils.php');

print p_locale_xhtml('preview');
print '<div class="comment_preview">';
if($_REQUEST['wikisyntaxok']) {
    print p_render('xhtml', p_get_instructions($_REQUEST['comment']), $info);
} else {
    print hsc($_REQUEST['comment']);
}
print '</div>';
// vim:ts=4:sw=4:et:enc=utf-8:
