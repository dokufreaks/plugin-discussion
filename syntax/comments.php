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

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_discussion_comments extends DokuWiki_Syntax_Plugin
{

    /**
     * Syntax Type
     *
     * @return string
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * Paragraph Type
     *
     * @return string
     */
    public function getPType()
    {
        return 'block';
    }

    /**
     * Sort for applying this mode
     *
     * @return int
     */
    public function getSort()
    {
        return 230;
    }

    /**
     * Connect pattern to lexer
     */
    public function connectTo($mode)
    {
        if ($mode == 'base') {
            $this->Lexer->addSpecialPattern('~~DISCUSSION[^\r\n]*?~~', $mode, 'plugin_discussion_comments');
        }
    }

    /**
     * Handler to prepare matched data for the rendering process
     *
     * @param string $match The text matched by the patterns
     * @param int $state The lexer state for the match
     * @param int $pos The character position of the matched text
     * @param Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        // strip markup
        $match = substr($match, 12, -2);

        // split title (if there is one)
        list($match, $title) = array_pad(explode('|', $match, 2), 2, '');

        // assign discussion state
        if ($match == ':off') {
            $status = 0;
        } elseif ($match == ':closed') {
            $status = 2;
        } else {
            // comments enabled
            $status = 1;
        }

        return [$status, $title];
    }

    /**
     * Handles the actual output creation.
     *
     * @param string $format output format being rendered
     * @param Doku_Renderer $renderer the current renderer object
     * @param array $data data created by handler()
     * @return boolean rendered correctly?
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        list($status, $title) = $data;
        if ($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */
            $renderer->meta['plugin_discussion'] = ['status' => $status, 'title' => $title];
        }
        return true;
    }
}
