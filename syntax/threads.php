<?php
/**
 * Discussion Plugin, threads component: displays a list of recently active discussions
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
class syntax_plugin_discussion_threads extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-10-05',
      'name'   => 'Discussion Plugin (threads component)',
      'desc'   => 'Displays a list of recently active discussions',
      'url'    => 'http://wiki.splitbrain.org/plugin:discussion',
    );
  }

  function getType(){ return 'substition'; }
  function getPType(){ return 'block'; }
  function getSort(){ return 306; }
  function connectTo($mode) { $this->Lexer->addSpecialPattern('\{\{threads>.+?\}\}',$mode,'plugin_discussion_threads'); }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    $match = substr($match, 10, -2); // strip {{threads> from start and }} from end
    return cleanID($match);
  }

  /**
   * Create output
   */
  function render($mode, &$renderer, $ns) {
    global $ID;
    global $conf;
    
    if ($ns == ':') $ns = '';
    elseif ($ns == '.') $ns = getNS($ID);

    $pages = $this->_threadList($ns);
    
    if (!count($pages)) return true; // nothing to display
        
    if ($mode == 'xhtml'){
      
      // prevent caching to ensure content is always fresh
      $renderer->info['cache'] = false;
      
      // main table
      $renderer->doc .= '<table class="threads">';
      foreach ($pages as $page){
        $renderer->doc .= '<tr><td class="page">';
        
        // page title
        $id    = $page['id'];
        $title = $page['title'];
        if (!$title) $title = str_replace('_', ' ', noNS($id));
        $renderer->doc .= $renderer->internallink(':'.$id, $title).'</td>';
        
        // topic starter
        if ($this->getConf('threads_showuser')){
          if ($page['user']) $renderer->doc .= '<td class="user">'.$page['user'].'</td>';
          else $renderer->doc .= '<td class="user">&nbsp;</td>';
        }
        
        // number of replies
        if ($page['num'] == 0) $repl = '';
        elseif ($page['num'] == 1) $repl = '1 '.$this->getLang('reply');
        else $repl = $page['num'].' '.$this->getLang('replies');
        $renderer->doc .= '<td class="num">'.$repl.'</td>';
        
        // last comment date
        if ($this->getConf('threads_showdate')){
          $renderer->doc .= '<td class="date">'.date($conf['dformat'], $page['date']).
            '</td>';
        }
        $renderer->doc .= '</tr>';
      }
      $renderer->doc .= '</table>';
      
      // show form to start a new discussion thread?
      if (auth_quickaclcheck($ns.':*') >= AUTH_CREATE)
        $renderer->doc .= $this->_newthreadForm($ns);
      
      return true;
      
    // for metadata renderer
    } elseif ($mode == 'metadata'){
      foreach ($pages as $page){
        $id  = $page['id'];
        $renderer->meta['relation']['references'][$id] = true;
      }
      
      return true;
    }
    return false;
  }
    
  /**
   * Returns an array of files with discussion sections, sorted by recent comments
   */
  function _threadList($ns){
    global $conf;
    
    require_once(DOKU_INC.'inc/search.php');
    
    $dir = $conf['datadir'].($ns ? '/'.str_replace(':', '/', $ns): '');
        
    // returns the list of pages in the given namespace, acl checked
    $items = array();
    search($items, $dir, 'search_list', '');
            
    // add pages with comments to result
    $result = array();
    foreach ($items as $item){
      $id   = ($ns ? $ns.':' : '').$item['id'];
      $file = metaFN($id, '.comments');
      if (!@file_exists($file)) continue; // skip if no comments file
      $data = unserialize(io_readFile($file, false));
      if ($data['status'] == 0) continue; // skip if comments are off
      $date = filemtime($file);
      $meta = p_get_metadata($id);
      $result[$date] = array(
        'id'    => $id,
        'title' => $meta['title'],
        'user'  => $meta['creator'],
        'num'   => $data['number'],
        'date'  => $date,
      );
    }
    
    // finally sort by time of last comment
    krsort($result);
      
    return $result;
  }
  
  /**
   * Show the form to start a new discussion thread
   */
  function _newthreadForm($ns){
    global $ID;
    global $lang;
    
    return '<div class="newthread_form">'.
      '<form id="discussion__newthread_form"  method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">'.
      '<div class="no">'.
      '<input type="hidden" name="id" value="'.$ID.'" />'.
      '<input type="hidden" name="do" value="newthread" />'.
      '<input type="hidden" name="ns" value="'.$ns.'" />'.
      '<label class="block" for="discussion__newthread_title">'.
      '<span>'.$this->getLang('newthread').':</span> '.
      '<input class="edit" type="text" name="title" id="discussion__newthread_title" size="40" tabindex="1" />'.
      '</label>'.
      '<input class="button" type="submit" value="'.$lang['btn_create'].'" tabindex="2" />'.
      '</div>'.
      '</form>'.
      '</div>';
  }
          
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
