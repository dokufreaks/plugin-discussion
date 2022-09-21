<?php
/**
 * Discussion Plugin, threads component: displays a list of recently active discussions
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

/**
 * Class syntax_plugin_discussion_threads
 */
class syntax_plugin_discussion_threads extends DokuWiki_Syntax_Plugin
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
     * @see Doku_Handler_Block
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
        return 306;
    }

    /**
     * @param string $mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{threads>.+?\}\}', $mode, 'plugin_discussion_threads');
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
        global $ID;
        $customFlags = [
            'count' => 0,
            'skipempty' => false,
            'nonewthreadform' => false
        ];

        $match = substr($match, 10, -2); // strip {{threads> from start and }} from end
        list($match, $flags) = array_pad(explode('&', $match, 2), 2, '');
        $flags = explode('&', $flags);

        // Identify the count/skipempty flag and remove it before passing it to pagelist
        foreach ($flags as $key => $flag) {
            if (substr($flag, 0, 5) == "count") {
                list(,$cnt) = array_pad(explode('=', $flag, 2), 2, 0);
                if(is_numeric($cnt) && $cnt > 0) {
                    $customFlags['count'] = $cnt;
                }
                unset($flags[$key]);
            } elseif (substr($flag, 0, 9) == "skipempty") {
                $customFlags['skipempty'] = true;
                unset($flags[$key]);
            } elseif (substr($flag, 0, 15) == "nonewthreadform") {
                $customFlags['nonewthreadform'] = true;
                unset($flags[$key]);
            }
        }

        list($ns, $refine) = array_pad(explode(' ', $match, 2), 2, '');

        if ($ns == '*' || $ns == ':') {
            $ns = '';
        } elseif ($ns == '.') {
            $ns = getNS($ID);
        } else {
            $ns = cleanID($ns);
        }

        return [$ns, $flags, $refine, $customFlags];
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
        list($ns, $flags, $refine, $customFlags) = $data;
        $count = $customFlags['count'];
        $skipEmpty = $customFlags['skipempty'];
        $noNewThreadForm = $customFlags['nonewthreadform'];
        $i = 0;

        $pages = [];
        /** @var helper_plugin_discussion $helper */
        if ($helper = $this->loadHelper('discussion')) {
            $pages = $helper->getThreads($ns, null, $skipEmpty);
        }

        // use tag refinements?
        if ($refine) {
            /** @var helper_plugin_tag $tag */
            if (!$tag = $this->loadHelper('tag', false)) {
                msg('The Tag Plugin must be installed to use tag refinements.', -1);
            } else {
                $pages = $tag->tagRefine($pages, $refine);
            }
        }

        if (!$pages) {
            if (auth_quickaclcheck($ns . ':*') >= AUTH_CREATE && $format == 'xhtml') {
                $renderer->nocache();
                if ($noNewThreadForm !== true) {
                    $renderer->doc .= $this->newThreadForm($ns);
                }
            }
            return true; // nothing to display
        }

        if ($format == 'xhtml') {
            /** @var Doku_Renderer_xhtml $renderer */
            // prevent caching to ensure content is always fresh
            $renderer->nocache();

            // show form to start a new discussion thread?
            if ($noNewThreadForm !== true) {
                $hasCreatePermission = auth_quickaclcheck($ns . ':*') >= AUTH_CREATE;
                if ($hasCreatePermission && $this->getConf('threads_formposition') == 'top') {
                    $renderer->doc .= $this->newThreadForm($ns);
                }
            }

            // let Pagelist Plugin do the work for us
            /** @var helper_plugin_pagelist $pagelist */
            if (!$pagelist = $this->loadHelper('pagelist', false)) {
                msg('The Pagelist Plugin must be installed for threads lists to work.', -1);
                return false;
            }
            $pagelist->addColumn('discussion', 'comments');
            $pagelist->setFlags($flags);
            $pagelist->startList();
            foreach ($pages as $page) {
                $page['class'] = 'discussion_status' . $page['status'];
                $pagelist->addPage($page);

                $i++;
                if ($count > 0 && $i >= $count) {
                    // Only display the n discussion threads specified by the count flag
                    break;
                }
            }
            $renderer->doc .= $pagelist->finishList();

            // show form to start a new discussion thread?
            if ($noNewThreadForm !== true) {
                if ($hasCreatePermission && $this->getConf('threads_formposition') == 'bottom') {
                    $renderer->doc .= $this->newThreadForm($ns);
                }
            }

            return true;

            // for metadata renderer
        } elseif ($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */
            foreach ($pages as $page) {
                $renderer->meta['relation']['references'][$page['id']] = true;
            }

            return true;
        }
        return false;
    }

    /* ---------- (X)HTML Output Functions ---------- */

    /**
     * Show the form to start a new discussion thread
     *
     * @param string $ns
     * @return string html
     */
    protected function newThreadForm($ns)
    {
        global $ID;
        global $lang;

        return '<div class="newthread_form">'
                . '<form id="discussion__newthread_form"  method="post" action="' . script() . '" accept-charset="' . $lang['encoding'] . '">'
                    . '<fieldset>'
                        . '<legend> ' . $this->getLang('newthread') . ': </legend>'
                        . '<input type="hidden" name="id" value="' . $ID . '" />'
                        . '<input type="hidden" name="do" value="newthread" />'
                        . '<input type="hidden" name="ns" value="' . $ns . '" />'
                        . '<input class="edit" type="text" name="title" id="discussion__newthread_title" size="40" tabindex="1" />'
                        . '<input class="button" type="submit" value="' . $lang['btn_create'] . '" tabindex="2" />'
                    . '</fieldset>'
                . '</form>'
            . '</div>';
    }
}
