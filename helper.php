<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");

class helper_plugin_discussion extends DokuWiki_Plugin {

  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2007-01-05',
      'name'   => 'Discussion Plugin (helper class)',
      'desc'   => 'Functions to get info about comments to a wiki page',
      'url'    => 'http://www.wikidesign/en/plugin/discussion/start',
    );
  }
  
  function getMethods(){
    $result = array();
    $result[] = array(
      'name'   => 'th',
      'desc'   => 'returns the header of the comments column for pagelist',
      'return' => array('header' => 'string'),
    );
    $result[] = array(
      'name'   => 'td',
      'desc'   => 'returns the link to the discussion section with number of comments',
      'params' => array(
        'id' => 'string',
        'number of comments (optional)' => 'integer'),
      'return' => array('link' => 'string'),
    );
    $result[] = array(
      'name'   => 'getThreads',
      'desc'   => 'returns pages with discussion sections, sorted by recent comments',
      'params' => array(
        'namespace' => 'string',
        'number (optional)' => 'integer'),
      'return' => array('pages' => 'array'),
    );
    $result[] = array(
      'name'   => 'getComments',
      'desc'   => 'returns recently added or edited comments individually',
      'params' => array(
        'namespace' => 'string',
        'number (optional)' => 'integer'),
      'return' => array('pages' => 'array'),
    );
    return $result;
  }
  
  /**
   * Returns the column header for the Pagelist Plugin
   */
  function th(){
    return $this->getLang('discussion');
  }
    
  /**
   * Returns the link to the discussion section of a page
   */
  function td($id, $num = NULL){
    $section = '#discussion__section';
    
    if (!isset($num)){
      $cfile = metaFN($id, '.comments');
      $comments = unserialize(io_readFile($cfile, false));
      
      $num = $comments['number'];
      if ((!$comments['status']) || (($comments['status'] == 2) && (!$num))) return '';
    }
    
    if ($num == 0) $comment = '0&nbsp;'.$this->getLang('comments');
    elseif ($num == 1) $comment = '1&nbsp;'.$this->getLang('comment');
    else $comment = $num.'&nbsp;'.$this->getLang('comments');
    
    return '<a href="'.wl($id).$section.'" class="wikilink1" title="'.$id.$section.'">'.
      $comment.'</a>';
  }
    
  /**
   * Returns an array of pages with discussion sections, sorted by recent comments
   */
  function getThreads($ns, $num = NULL, $all = false){
    global $conf;
    
    require_once(DOKU_INC.'inc/search.php');
    
    $dir = $conf['datadir'].($ns ? '/'.str_replace(':', '/', $ns): '');
        
    // returns the list of pages in the given namespace and it's subspaces
    $items = array();
    search($items, $dir, 'search_allpages', '');
            
    // add pages with comments to result
    $result = array();
    foreach ($items as $item){
      $id   = ($ns ? $ns.':' : '').$item['id'];
      
      // some checks
      $perm = auth_quickaclcheck($id);
      if ($perm < AUTH_READ) continue;    // skip if no permission
      $file = metaFN($id, '.comments');
      if (!@file_exists($file)) continue; // skip if no comments file
      $data = unserialize(io_readFile($file, false));
      $status = $data['status'];
      $number = $data['number']; // skip if comments are off or closed without comments
      if (!$all && (!$status || (($status == 2) && (!$number)))) continue;
      
      $date = filemtime($file);
      $meta = p_get_metadata($id);
      $result[$date] = array(
        'id'       => $id,
        'file'     => $file,
        'title'    => $meta['title'],
        'date'     => $date,
        'user'     => $meta['creator'],
        'desc'     => $meta['description']['abstract'],
        'num'      => $number,
        'comments' => $this->td($id, $number),
        'status'   => $status,
        'perm'     => $perm,
        'exists'   => true,
      );
    }
    
    // finally sort by time of last comment
    krsort($result);
    
    if (is_numeric($num)) $result = array_slice($result, 0, $num);
      
    return $result;
  }
  
  /**
   * Returns an array of recently added comments to a given page or namespace
   */
  function getComments($ns, $num = NULL){
    global $conf;
    
    $first  = $_REQUEST['first'];
    if (!is_numeric($first)) $first = 0;
    
    if ((!$num) || (!is_numeric($num))) $num = $conf['recent'];
    
    $result = array();
    $count  = 0;
   
    if (!@file_exists($conf['metadir'].'/_comments.changes')) return $result;
    
    // read all recent changes. (kept short)
    $lines = file($conf['metadir'].'/_comments.changes');
   
    // handle lines
    for ($i = count($lines)-1; $i >= 0; $i--){
      $rec = $this->_handleRecentComment($lines[$i], $ns);
      if ($rec !== false) {
        if (--$first >= 0) continue; // skip first entries
        $result[$rec['date']] = $rec;
        $count++;
        // break when we have enough entries
        if ($count >= $num) break;
      }
    }
     
    // finally sort by time of last comment
    krsort($result);
    
    return $result;
  }
  
  
  /**
   * Returns the full comments data for a given wiki page
   */
  function getFullComments($thread){
    $id = $thread['id'];
    
    if (!$thread['file']) $thread['file'] = metaFN($id, '.comments');
    if (!@file_exists($thread['file'])) return false; // no discussion thread at all
    
    $data = unserialize(io_readFile($thread['file'], false));
    
    if (!$data['status']) return false;               // comments are turned off
    if (!isset($data['comments']) || !$data['number']) return array(); // no comments
    
    $result = array();
    foreach ($data['comments'] as $cid => $comment){
      $this->_addComment($cid, $data, $result);
    }
    
    return $result;
  }
  
  /**
   * Recursive function to add the comment hierarchy to the result
   */
  function _addComment($cid, &$data, &$result, $parent = '', $level = 1){
    if (!is_array($data['comments'][$cid])) return; // corrupt datatype
    $comment = $data['comments'][$cid];
    if ($comment['parent'] != $parent) return;      // answer to another comment
    
    // okay, add the comment to the result
    $comment['id'] = $cid;
    $comment['level'] = $level;
    $result[] = $comment;
    
    // check answers to this comment
    if (count($comment['replies'])){
      foreach ($comment['replies'] as $rid){
        $this->_addComment($rid, $data, $result, $cid, $level + 1);
      }
    }
  }
  
/* ---------- Changelog function adapted for the Discussion Plugin ---------- */
   
  /**
   * Internal function used by $this->getComments()
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
    $recent['perm'] = auth_quickaclcheck($recent['id']);
    if ($recent['perm'] < AUTH_READ) return false;
   
    // check existance
    $recent['file'] = wikiFN($recent['id']);
    $recent['exists'] = @file_exists($recent['file']);
    if (!$recent['exists']) return false;
    if ($recent['type'] === 'dc') return false;
    
    // get discussion meta file name
    $data = unserialize(io_readFile(metaFN($recent['id'], '.comments'), false));
    
    // check if discussion is turned off
    if ($data['status'] === 0) return false;
    
    // okay, then add some additional info
    if (is_array($data['comments'][$cid]['user']))
      $recent['name'] = $data['comments'][$cid]['user']['name'];
    else $recent['name'] = $data['comments'][$cid]['name'];
    $recent['desc'] = strip_tags($data['comments'][$cid]['xhtml']);
   
    return $recent;
  }
        
}
  
//Setup VIM: ex: et ts=4 enc=utf-8 :
