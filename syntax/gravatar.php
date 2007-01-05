<?php
/**
 * Gravatar Plugin: displays gravatar images with syntax{{gravatar>email@domain.com}}
 * Optionally you can add a title attribute: {{gravatar>email@domain.com|My Name}}
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
class syntax_plugin_discussion_gravatar extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2007-01-05',
      'name'   => 'Discussion Plugin (gravatar component)',
      'desc'   => 'Displays Gravatar images',
      'url'    => 'http://www.wikidesign.ch/en/plugin/discussion/start',
    );
  }

  function getType(){ return 'substition'; }
  function getSort(){ return 315; }
  function connectTo($mode) { $this->Lexer->addSpecialPattern("{{gravatar>.+?}}",$mode,'plugin_discussion_gravatar'); }
  
  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    $match = substr($match, 11, -2); // Strip markup
    $match = preg_split('/\|/u', $match, 2); // Split title from URL
    
    // Check alignment
    $ralign = (bool)preg_match('/^ /', $match[0]);
    $lalign = (bool)preg_match('/ $/', $match[0]);
    if ($lalign & $ralign) $align = 'center';
    else if ($ralign)      $align = 'right';
    else if ($lalign)      $align = 'left';
    else                   $align = NULL;
    
    //split into src and size parameter (using the very last questionmark)
    $pos = strrpos($match[0], '?');
    if($pos !== false){
      $src   = substr($match[0], 0, $pos);
      $param = substr($match[0], $pos+1);
      if (preg_match('/^s/', $param))      $size = 20;
      else if (preg_match('/^l/', $param)) $size = 80;
      else if (preg_match('/^m/', $param)) $size = 40;
      else $size = NULL;
    } else {
      $src   = $match[0];
      $size  = NULL;
    }
    
    if (!isset($match[1])) $match[1] = NULL;
    return array(trim($src), trim($match[1]), $align, $size);
  } 
 
  /**
   * Create output
   */
  function render($mode, &$renderer, $data){
    // a string to be added to the gravatar url
    // see http://gravatar.com/implement.php#section_1_1
  
    if($mode == 'xhtml'){
      $default = DOKU_URL.'lib/plugins/discussion/images/default.gif';
      $size    = (is_int($data[3]) ? $data[3] : $this->getConf('gravatar_size'));
      $email   = $data[0];
      // Do not pass invalid or empty emails to gravatar site...
      if (isvalidemail($email)){
        $src = ml('http://www.gravatar.com/avatar.php?'.
          'gravatar_id='.md5($email).
          '&default='.urlencode($default).
          '&size='.$size.
          '&rating='.$this->getConf('gravatar_rating').
          '&.jpg', 'cache=recache');
      // Show only default image if invalid or empty email given
      } else {
        $src = $default;
      }
      $title = ($data[1] ? hsc($data[1]) : obfuscate($email));
      
      $renderer->doc .= '<span class="vcard">'.
        '<img src="'.$src.'" class="media'.$data[2].' photo fn"'.
        ' title="'.$title.'" alt="'.$title.'"'.
        ' width="'.$size.'" height="'.$size.'" />'.
        '</span>';
      return true;
    }
    return false;
  }
     
}
 
//Setup VIM: ex: et ts=4 enc=utf-8 :