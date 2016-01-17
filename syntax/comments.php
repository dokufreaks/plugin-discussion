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

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_discussion_comments extends DokuWiki_Syntax_Plugin {

    /**
     * Syntax Type
     *
     * @return string
     */
    function getType() { return 'substition'; }

    /**
     * Paragraph Type
     *
     * @return string
     */
    function getPType() { return 'block'; }

    /**
     * Sort for applying this mode
     *
     * @return int
     */
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
     * Handler to prepare matched data for the rendering process
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {

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

    /**
     * Handles the actual output creation.
     *
     * @param   $mode   string        output format being rendered
     * @param   $renderer Doku_Renderer the current renderer object
     * @param   $data     array         data created by handler()
     * @return  boolean                 rendered correctly?
     */
    function render($mode, Doku_Renderer $renderer, $data) {
        list($status, $title) = $data;
        if ($mode == 'metadata') {
            /** @var $renderer Doku_Renderer_metadata */
            $renderer->meta['plugin_discussion'] = array('status' => $status, 'title' => $title);
        }
        return true;
    }
}
// vim:ts=4:sw=4:et:enc=utf-8:
