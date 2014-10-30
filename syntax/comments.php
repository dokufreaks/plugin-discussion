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

    function getType() { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort() { return 230; }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        if ($mode == 'base') {
            $this->Lexer->addSpecialPattern('~~DISCUSSION[^\r\n]*?~~', $mode, 'plugin_discussion_comments');
        }
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {
        global $ID, $ACT, $REV;

        // strip markup
        $match = substr($match, 12, -2);

        // split title (if there is one)
        list($match, $title) = explode('|', $match, 2);

        // assign discussion state
        if ($match == ':off') $status = 0;
        else if ($match == ':closed') $status = 2;
        else $status = 1;

        return array($status, $title);
    }

    function render($mode, &$renderer, $data) {
        list($status, $title) = $data;
        if ($mode == 'metadata') {
            /** @var $renderer Doku_Renderer_metadata */
            $renderer->meta['plugin_discussion'] = array('status' => $status, 'title' => $title);
        }
        return true;
    }
}
// vim:ts=4:sw=4:et:enc=utf-8:
