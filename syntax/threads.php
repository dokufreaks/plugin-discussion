<?php
/**
 * Discussion Plugin, threads component: displays a list of recently active discussions
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_discussion_threads extends DokuWiki_Syntax_Plugin {

    function getType() { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort() { return 306; }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{threads>.+?\}\}', $mode, 'plugin_discussion_threads');
    }

    function handle($match, $state, $pos, &$handler) {
        global $ID;
        $customFlags = array();

        $match = substr($match, 10, -2); // strip {{threads> from start and }} from end
        list($match, $flags) = explode('&', $match, 2);
        $flags = explode('&', $flags);

        // Identify the count/skipempty flag and remove it before passing it to pagelist
        foreach($flags as $key => $flag) {
            if(substr($flag, 0, 5) == "count") {
                $tmp = explode('=', $flag);
                $customFlags['count'] = $tmp[1];
                unset($flags[$key]);
            }
            if(substr($flag, 0, 9) == "skipempty") {
                $customFlags['skipempty'] = true;
                unset($flags[$key]);
            }
        }

        // Ignore params if invalid values have been passed
        if(!array_key_exists('count', $customFlags) || $customFlags['count'] <= 0 || !is_numeric($customFlags['count'])) $customFlags['count'] = false;
        if(!array_key_exists('skipempty', $customFlags) && !$customFlags['skipempty']) $customFlags['skipempty'] = false;

        list($ns, $refine) = explode(' ', $match, 2);

        if (($ns == '*') || ($ns == ':')) $ns = '';
        elseif ($ns == '.') $ns = getNS($ID);
        else $ns = cleanID($ns);

        return array($ns, $flags, $refine, $customFlags);
    }

    function render($mode, &$renderer, $data) {
        list($ns, $flags, $refine, $customFlags) = $data;
        $count = $customFlags['count'];
        $skipEmpty = $customFlags['skipempty'];
        $i = 0;

        if ($my =& plugin_load('helper', 'discussion')) $pages = $my->getThreads($ns, NULL, $skipEmpty);

        // use tag refinements?
        if ($refine) {
            if (plugin_isdisabled('tag') || (!$tag = plugin_load('helper', 'tag'))) {
                msg('The Tag Plugin must be installed to use tag refinements.', -1);
            } else {
                $pages = $tag->tagRefine($pages, $refine);
            }
        }

        if (!$pages) {
            if ((auth_quickaclcheck($ns.':*') >= AUTH_CREATE) && ($mode == 'xhtml')) {
                $renderer->info['cache'] = false;
                $renderer->doc .= $this->_newThreadForm($ns);
            }
            return true; // nothing to display
        } 

        if ($mode == 'xhtml') {

            // prevent caching to ensure content is always fresh
            $renderer->info['cache'] = false;

            // show form to start a new discussion thread?
            $perm_create = (auth_quickaclcheck($ns.':*') >= AUTH_CREATE);
            if ($perm_create && ($this->getConf('threads_formposition') == 'top'))
                $renderer->doc .= $this->_newThreadForm($ns);

            // let Pagelist Plugin do the work for us
            if (plugin_isdisabled('pagelist')
                    || (!$pagelist =& plugin_load('helper', 'pagelist'))) {
                msg('The Pagelist Plugin must be installed for threads lists to work.', -1);
                return false;
            }
            $pagelist->column['comments'] = true;
            $pagelist->setFlags($flags);
            $pagelist->startList();
            foreach ($pages as $key => $page) {
                $page['class'] = 'discussion_status'.$page['status'];
                $pagelist->addPage($page);

                $i++;
                if($count != false && $i >= $count) break; // Only display the n discussion threads specified by the count flag
            }
            $renderer->doc .= $pagelist->finishList();

            // show form to start a new discussion thread?
            if ($perm_create && ($this->getConf('threads_formposition') == 'bottom'))
                $renderer->doc .= $this->_newThreadForm($ns);

            return true;

            // for metadata renderer
        } elseif ($mode == 'metadata') {
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
     */
    function _newThreadForm($ns) {
        global $ID;
        global $lang;

        return '<div class="newthread_form">'.DOKU_LF.
            '<form id="discussion__newthread_form"  method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">'.DOKU_LF.
            DOKU_TAB.'<fieldset>'.DOKU_LF.
            DOKU_TAB.DOKU_TAB.'<legend> '.$this->getLang('newthread').': </legend>'.DOKU_LF.
            DOKU_TAB.DOKU_TAB.'<input type="hidden" name="id" value="'.$ID.'" />'.DOKU_LF.
            DOKU_TAB.DOKU_TAB.'<input type="hidden" name="do" value="newthread" />'.DOKU_LF.
            DOKU_TAB.DOKU_TAB.'<input type="hidden" name="ns" value="'.$ns.'" />'.DOKU_LF.
            DOKU_TAB.DOKU_TAB.'<input class="edit" type="text" name="title" id="discussion__newthread_title" size="40" tabindex="1" />'.DOKU_LF.
            DOKU_TAB.DOKU_TAB.'<input class="button" type="submit" value="'.$lang['btn_create'].'" tabindex="2" />'.DOKU_LF.
            DOKU_TAB.'</fieldset>'.DOKU_LF.
            '</form>'.DOKU_LF.
            '</div>'.DOKU_LF;
    }
}
// vim:ts=4:sw=4:et:enc=utf-8:
