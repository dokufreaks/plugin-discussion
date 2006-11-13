<?php
/**
 * XML feed export
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../').'/');
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/common.php');
require_once(DOKU_INC.'inc/events.php');
require_once(DOKU_INC.'inc/parserutils.php');
require_once(DOKU_INC.'inc/feedcreator.class.php');
require_once(DOKU_INC.'inc/auth.php');
require_once(DOKU_INC.'inc/pageutils.php');

//close session
session_write_close();

$num   = $_REQUEST['num'];
$type  = $_REQUEST['type'];
$ns    = $_REQUEST['ns'];

if($type == '')
  $type = $conf['rss_type'];

switch ($type){
  case 'rss':
    $type = 'RSS0.91';
    $mime = 'text/xml';
    break;
  case 'rss2':
    $type = 'RSS2.0';
    $mime = 'text/xml';
    break;
  case 'atom':
    $type = 'ATOM0.3';
    $mime = 'application/xml';
    break;
  case 'atom1':
    $type = 'ATOM1.0';
    $mime = 'application/atom+xml';
    break;
  default:
    $type = 'RSS1.0';
    $mime = 'application/xml';
}

// the feed is dynamic - we need a cache for each combo
// (but most people just use the default feed so it's still effective)
$cache = getCacheName('comment'.$num.$type.$ns.$_SERVER['REMOTE_USER'],'.feed');

// check cacheage and deliver if nothing has changed since last
// time or the update interval has not passed, also handles conditional requests
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Type: application/xml; charset=utf-8');
$cmod = @filemtime($cache); // 0 if not exists
if ($cmod &&
  (($cmod + $conf['rss_update'] > time()) || ($cmod > @filemtime($conf['changelog'])))){
  http_conditionalRequest($cmod);
  if($conf['allowdebug']) header("X-CacheUsed: $cache");
  print io_readFile($cache);
  exit;
} else {
  http_conditionalRequest(time());
}

// create new feed
$rss = new DokuWikiFeedCreator();
$rss->title = $conf['title'].(($ns) ? ' '.ucwords($ns) : '');
$rss->link  = DOKU_URL;
$rss->syndicationURL = DOKU_URL.'lib/plugins/discussion/feed.php';
$rss->cssStyleSheet  = DOKU_URL.'lib/styles/feed.css';

$image = new FeedImage();
$image->title = $conf['title'];
$image->url = DOKU_URL."lib/images/favicon.ico";
$image->link = DOKU_URL;
$rss->image = $image;

rssRecentComments($rss, $num, $ns);

$feed = $rss->createFeed($type, 'utf-8');

// save cachefile
io_saveFile($cache, $feed);

// finally deliver
print $feed;

/* ---------- */

/**
 * Add blog entries to feed object
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author  Esther Brunner <wikidesign@gmail.com>
 */
function rssRecentComments(&$rss, $num, $ns){
  global $conf;
  
  if (!$num) $num = $conf['recent'];
  
  $comments = getRecentComments(0, $num, $ns);
 
  foreach ($comments as $comment){
    $item = new FeedItem();
    $meta = p_get_metadata($comment['id']);

    if ($meta['title']) $item->title = $meta['title'];
    else $item->title = ucwords($entry['id']);

    $item->link = wl($comment['id'].'#'.$comment['extra'], '', true);

    $item->description = $comment['comment'];
    $item->date        = date('r', $comment['date']);
    $item->author      = $comment['name'];

    $rss->addItem($item);
  }
}

/**
 * returns an array of recently changed files using the
 * changelog
 *
 * @param int    $first   number of first entry returned (for paginating
 * @param int    $num     return $num entries
 * @param string $ns      restrict to given namespace
 *
 * @author Ben Coburn <btcoburn@silicodon.net>
 * @author Esther Brunner <wikidesign@gmail.com>
 */
function getRecentComments($first, $num, $ns=''){
  global $conf;
  $recent = array();
  $count  = 0;

  if ((!$num) || (!@file_exists($conf['metadir'].'/_comments.changes'))) return $recent;
  
  // read all recent changes. (kept short)
  $lines = file($conf['metadir'].'/_comments.changes');

  // handle lines
  for ($i = count($lines)-1; $i >= 0; $i--){
    $rec = _handleRecentComment($lines[$i], $ns);
    if ($rec !== false) {
      if (--$first >= 0) continue; // skip first entries
      $recent[$rec['date']] = $rec;
      $count++;
      // break when we have enough entries
      if ($count >= $num) break;
    }
  }

  krsort($recent);
  return $recent;
}

/**
 * Internal function used by getRecentComments
 *
 * don't call directly
 *
 * @see getRecentComments()
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Ben Coburn <btcoburn@silicodon.net>
 * @author Esther Brunner <wikidesign@gmail.com>
 */
function _handleRecentComment($line, $ns){
  static $seen  = array();         //caches seen pages and skip them
  if (empty($line)) return false;  //skip empty lines

  // split the line into parts
  $recent = parseChangelogLine($line);
  if ($recent === false) return false;
  
  $cid     = $recent['extra'];
  $fullcid = $recent['id'].'#'.$recent['extra'];

  // skip seen ones
  if (isset($seen[$fullcid])) return false;
  
  // skip 'show comment' log entries
  if ($recent['type'] === 'sc') return false;

  // remember in seen to skip additional sights
  $seen[$fullcid] = 1;

  // check if it's a hidden page or comment
  if (isHiddenPage($recent['id'])) return false;
  if ($recent['type'] === 'hc') return false;

  // filter namespace or id
  if (($ns) && (strpos($recent['id'].':', $ns.':') !== 0)) return false;

  // check ACL
  if (auth_quickaclcheck($recent['id']) < AUTH_READ) return false;

  // check existance
  if (!@file_exists(wikiFN($recent['id']))) return false;
  if ($recent['type'] === 'dc') return false;
  
  // get discussion meta file name
  $data = unserialize(io_readFile(metaFN($ID, '.comments'), false));
  
  // check if discussion is turned off
  if ($data['status'] === 0) return false;
  
  // okay, then add some additional info
  $recent['name']    = $data['comments'][$cid]['name'];
  $recent['comment'] = strip_tags($data['comments'][$cid]['xhtml']);

  return $recent;
}

//Setup VIM: ex: et ts=2 enc=utf-8 :
