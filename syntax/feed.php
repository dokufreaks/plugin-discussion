<?php
/**
 * Discussion Plugin, feed component: Displays a feed link for recent comments
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_discussion_feed extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-11-06',
      'name'   => 'Discussion Plugin (feed component)',
      'desc'   => 'Displays a feed link for recent comments',
      'url'    => 'http://wiki.splitbrain.org/plugin:discussion',
    );
  }

  function getType(){ return 'substition'; }
  function getSort(){ return 308; }
  function connectTo($mode) { $this->Lexer->addSpecialPattern('\{\{commentfeed>.+?\}\}',$mode,'plugin_discussion_feed'); }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    $match = substr($match, 14, -2); // strip {{commentfeed> from start and }} from end
    list($match, $title) = explode('|', $match, 2);
    list($ns, $num) = explode('?', $match, 2);
    if (!is_numeric($num)) $num = '';

    return array($ns, $num, $title);
  }

  /**
   * Create output
   */
  function render($mode, &$renderer, $data){
    $ns    = $data[0];
    if (!$ns) $ns = $this->getConf('namespace');
    $title = ($data[2] ? $data[2] : ucwords($ns));
  
    if($mode == 'xhtml'){
      $url   = DOKU_BASE.'lib/plugins/discussion/feed.php?ns='.cleanID($ns);
      if ($data[1]) $url .= '&num='.$data[1];
      $url .= '&title='.urlencode($this->getLang('comments'));
      $title = $renderer->_xmlEntities($title);
      
      $renderer->doc .= '<a href="'.$url.'" class="feed" rel="nofollow"'.
        ' type="application/rss+xml" title="'.$title.'">'.$title.'</a>';
                
      return true;
    
    // for metadata renderer
    } elseif ($mode == 'metadata'){
      if ($renderer->capture) $renderer->doc .= $title;
      
      return true;
    }
    return false;
  }
        
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
