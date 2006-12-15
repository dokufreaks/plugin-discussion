<?php
/**
 * Discussion Plugin
 *
 * Enables/disables discussion features based on config settings.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Esther Brunner <wikidesign@gmail.com>
 * @author  Dave Lawson <dlawson@masterytech.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_discussion_comments extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-02',
      'name'   => 'Discussion Plugin (comments component)',
      'desc'   => 'Enables discussion features',
      'url'    => 'http://www.wikidesign.ch/en/plugin/discussion/start',
    );
  }

  function getType(){ return 'substition'; }
  function getPType(){ return 'block'; }
  function getSort(){ return 230; }

  /**
   * Connect pattern to lexer
   */
  function connectTo($mode){
    if ($mode == 'base'){
      $this->Lexer->addSpecialPattern('~~DISCUSSION(?:|:off|:closed)~~', $mode, 'plugin_discussion_comments');
    }
  }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    global $ID;
    global $ACT;
    
    // don't show discussion section on blog mainpages
    if (defined('IS_BLOG_MAINPAGE')) return false;
    
    // assign discussion state
    if ($match == '~~DISCUSSION:off~~') $status = 0;
    else if ($match == '~~DISCUSSION:closed~~') $status = 2;
    else $status = 1;
        
    // get discussion meta file name
    $file = metaFN($ID, '.comments');
    
    $data = array();
    if (@file_exists($file)) $data = unserialize(io_readFile($file, false));
    if ($data['status'] != $status){
      $data['status'] = $status;
      io_saveFile($file, serialize($data));
    }
        
    return $status;
  }

  function render($mode, &$renderer, $status){
    return true; // do nothing -> everything is handled in action component
  }

}

//Setup VIM: ex: et ts=4 enc=utf-8 :