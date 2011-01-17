<?php
/**
 * Discussion Plugin, recent component: displays a list of recently added comments
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Michael Hamann <michael@content-space.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_discussion_recent extends DokuWiki_Syntax_Plugin {

  function getType() { return 'substition'; }
  function getPType() { return 'block'; }
  function getSort() { return 306; }

  function connectTo($mode) {
    $this->Lexer->addSpecialPattern('\{\{recent_comments>.+?\}\}', $mode, 'plugin_discussion_recent');
  }

  function handle($match, $state, $pos, &$handler) {
    global $ID;

    $match = substr($match, 18, -2); // strip {{recent_comments> from start and }} from end
    list($ns, $number) = explode('?', $match, 2);

    if (($ns == '*') || ($ns == ':')) $ns = '';
    elseif ($ns == '.') $ns = getNS($ID);
    else $ns = cleanID($ns);

    if (!$number || !is_numeric($number))
      $number = 10;

    return array($ns, $number);
  }

  function render($mode, &$renderer, $data) {
    list($ns, $number) = $data;

    if ($my =& plugin_load('helper', 'discussion')) $comments = $my->getComments($ns, $number);

    if (empty($comments)) {
      return true; // nothing to display
    }

    if ($mode == 'xhtml') {

      // prevent caching to ensure content is always fresh
      $renderer->info['cache'] = false;

      foreach ($comments as &$comment) {
        $comment['level'] = 1;
      }

      $renderer->doc .= html_buildlist($comments, '', array($this, 'formatLink'));

      return true;

      // for metadata renderer
    } elseif ($mode == 'metadata') {
      foreach ($comments as $comment) {
        $renderer->meta['relation']['references'][$comment['id']] = true;
      }

      return true;
    }
    return false;
  }

  function formatLink($item) {
    global $conf;
    $meta = p_get_metadata($item['id']);
    $result = '';
    $result .= 'On <a href="'.wl($item['id']).'#'.$item['anchor'].'">'.$meta['title'].'</a> ';
    $result .= 'by '.$this->getUserLink($item['user'], $item['name'], $item['url']);
    $result .= dformat($item['date']).DOKU_LF;
    return $result;
  }

  function getUserLink($user, $name, $url = null) {
    global $auth;
    if ($this->getConf('usernamespace') && $auth->getUserData($user)) {
      return html_wikilink($this->getConf('usernamespace').':'.$user.':', $name);
    } elseif ($url) {
      return $this->external_link($url, $name, 'url urlextern fn');
    } else {
      return hsc($name);
    }
  }
}
