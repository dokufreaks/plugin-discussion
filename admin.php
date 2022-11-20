<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

use dokuwiki\Utf8\PhpString;

/**
 * Class admin_plugin_discussion
 */
class admin_plugin_discussion extends DokuWiki_Admin_Plugin
{

    /**
     * @return int
     */
    public function getMenuSort()
    {
        return 200;
    }

    /**
     * @return bool
     */
    public function forAdminOnly()
    {
        return false;
    }

    public function handle()
    {
        global $lang, $INPUT;

        $cids = $INPUT->post->arr('cid');
        if (is_array($cids)) {
            $cids = array_keys($cids);
        }
        /** @var action_plugin_discussion $action */
        $action = plugin_load('action', 'discussion');
        if (!$action) return; // couldn't load action plugin component

        $act = $INPUT->post->str('comment');
        if ($act && !checkSecurityToken()) {
            return;
        }
        switch ($act) {
            case $lang['btn_delete']:
                $action->save($cids, '');
                break;

            case $this->getLang('btn_show'):
                $action->save($cids, '', 'show');
                break;

            case $this->getLang('btn_hide'):
                $action->save($cids, '', 'hide');
                break;

            case $this->getLang('btn_change'):
                $this->changeStatus($INPUT->post->str('status'));
                break;
        }
    }

    public function html()
    {
        global $conf, $INPUT;

        $first = $INPUT->int('first');

        $num = $conf['recent'] ?: 20;

        ptln('<h1>' . $this->getLang('menu') . '</h1>');

        $threads = $this->getThreads();

        // slice the needed chunk of discussion pages
        $isMore = count($threads) > ($first + $num);
        $threads = array_slice($threads, $first, $num);

        foreach ($threads as $thread) {
            $comments = $this->getComments($thread);
            $this->threadHead($thread);
            if ($comments === false) {
                ptln('</div>', 6); // class="level2"
                continue;
            }

            ptln('<form method="post" action="' . wl($thread['id']) . '">', 8);
            ptln('<div class="no">', 10);
            ptln('<input type="hidden" name="do" value="admin" />', 10);
            ptln('<input type="hidden" name="page" value="discussion" />', 10);
            ptln('<input type="hidden" name="sectok" value="'.getSecurityToken().'" />', 10);
            echo html_buildlist($comments, 'admin_discussion', [$this, 'commentItem'], [$this, 'liComment']);
            $this->actionButtons();
            ptln('</div>', 10); // class="no"
            ptln('</form>', 8);
            ptln('</div>', 6); // class="level2"
        }
        $this->browseDiscussionLinks($isMore, $first, $num);

    }

    /**
     * Returns an array of pages with discussion sections, sorted by recent comments
     *
     * @return array
     */
    protected function getThreads()
    {
        global $conf;

        // returns the list of pages in the given namespace and it's subspaces
        $items = [];
        search($items, $conf['datadir'], 'search_allpages', []);

        // add pages with comments to result
        $result = [];
        foreach ($items as $item) {
            $id = $item['id'];

            // some checks
            $file = metaFN($id, '.comments');
            if (!@file_exists($file)) continue; // skip if no comments file

            $date = filemtime($file);
            $result[] = [
                'id' => $id,
                'file' => $file,
                'date' => $date,
            ];
        }

        // finally sort by time of last comment
        usort($result, ['admin_plugin_discussion', 'threadCmp']);

        return $result;
    }

    /**
     * Callback for comparison of thread data.
     *
     * Used for sorting threads in descending order by date of last comment.
     * If this date happens to be equal for the compared threads, page id
     * is used as second comparison attribute.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    protected function threadCmp($a, $b)
    {
        if ($a['date'] == $b['date']) {
            return strcmp($a['id'], $b['id']);
        }
        if ($a['date'] < $b['date']) {
            return 1;
        } else {
            return -1;
        }
    }

    /**
     * Outputs header, page ID and status of a discussion thread
     *
     * @param array $thread
     * @return bool
     */
    protected function threadHead($thread)
    {
        global $lang;

        $id = $thread['id'];

        $labels = [
            0 => $this->getLang('off'),
            1 => $this->getLang('open'),
            2 => $this->getLang('closed')
        ];
        $title = p_get_metadata($id, 'title');
        if (!$title) {
            $title = $id;
        }
        $align = $lang['direction'] === 'rtl' ? 'left' : 'right';
        echo '<h2 name="' . $id . '" id="' . $id . '">' . hsc($title) . '</h2>'
            . '<form method="post" action="' . wl($id) . '">'
            . '<div class="media' . $align . '">'
            . '<input type="hidden" name="do" value="admin" />'
            . '<input type="hidden" name="page" value="discussion" />'
            . '<input type="hidden" name="sectok" value="' . getSecurityToken() . '" />'
            . $this->getLang('status') . ': '
            . '<select name="status" size="1">';
        foreach ($labels as $key => $label) {
            $selected = ($key == $thread['status'] ? ' selected="selected"' : '');
            echo '<option value="' . $key . '"' . $selected . '>' . $label . '</option>';
        }
        echo '</select> '
            . '<input type="submit" class="button" name="comment" value="' . $this->getLang('btn_change') . '" '
                . 'title="' . $this->getLang('btn_change') . '" />'
            . '</div>'
            . '</form>'
            . '<div class="level2">'
            . '<a href="' . wl($id) . '" class="wikilink1">' . $id . '</a> ';
        return true;
    }

    /**
     * Returns the full comments data for a given wiki page
     *
     * @param array $thread by reference with:
     *  'id' => string page id,
     *  'file' => string file location of .comments metadata file
     *  'status' => int
     *  'number' => int number of visible comments
     *
     * @return array|bool
     */
    protected function getComments(&$thread)
    {
        $id = $thread['id'];

        if (!$thread['file']) {
            $thread['file'] = metaFN($id, '.comments');
        }
        if (!@file_exists($thread['file'])) return false; // no discussion thread at all

        $data = unserialize(io_readFile($thread['file'], false));

        $thread['status'] = $data['status'];
        $thread['number'] = $data['number'];
        if (empty($data['status'])) return false;   // comments are turned off
        if (empty($data['comments'])) return false; // no comments

        $result = [];
        foreach ($data['comments'] as $cid => $comment) {
            $this->addComment($cid, $data, $result, $id);
        }

        if (empty($result)) {
            return false;
        } else {
            return $result;
        }
    }

    /**
     * Recursive function to add the comment hierarchy to the result
     *
     * @param string $cid comment id of current comment
     * @param array $data array with all comments by reference
     * @param array $result array with all comments by reference enhanced with level
     * @param string $id page id
     * @param string $parent comment id of parent or empty
     * @param int $level level of current comment, higher is deeper
     */
    protected function addComment($cid, &$data, &$result, $id, $parent = '', $level = 1)
    {
        if (!isset($data['comments'][$cid]) || !is_array($data['comments'][$cid])) return; // corrupt datatype

        $comment = $data['comments'][$cid];
        // handle only replies to given parent comment
        if ($comment['parent'] != $parent) return;

        // okay, add the comment to the result
        $comment['id'] = $id;
        $comment['level'] = $level;
        $result[] = $comment;

        // check answers to this comment
        if (count($comment['replies'])) {
            foreach ($comment['replies'] as $rid) {
                $this->addComment($rid, $data, $result, $id, $cid, $level + 1);
            }
        }
    }

    /**
     * Returns html of checkbox and info about a comment item
     *
     * @param array $comment array with comment data
     * @return string html of checkbox and info
     */
    public function commentItem($comment)
    {
        global $conf;

        // prepare variables
        if (is_array($comment['user'])) { // new format
            $name = $comment['user']['name'];
            $mail = $comment['user']['mail'];
        } else {                          // old format
            $name = $comment['name'];
            $mail = $comment['mail'];
        }
        if (is_array($comment['date'])) { // new format
            $created = $comment['date']['created'];
        } else {                          // old format
            $created = $comment['date'];
        }
        $abstract = preg_replace('/\s+?/', ' ', strip_tags($comment['xhtml']));
        if (PhpString::strlen($abstract) > 160) {
            $abstract = PhpString::substr($abstract, 0, 160) . '...';
        }

        return '<input type="checkbox" name="cid[' . $comment['cid'] . ']" value="1" /> '
            . $this->email($mail, $name, 'email')
            . ', <a href="' . wl($comment['id']) . '#comment_' . $comment['cid'] . '" class="wikilink1">'
            . strftime($conf['dformat'], $created) . ': '
            . '</a>'
            . '<span class="abstract">' . $abstract . '</span>';
    }

    /**
     * Returns html of list item openings tag
     *
     * @param array $comment
     * @return string
     */
    public function liComment($comment)
    {
        $showclass = ($comment['show'] ? '' : ' hidden');
        return '<li class="level' . $comment['level'] . $showclass . '">';
    }

    /**
     * Show buttons to bulk remove, hide or show comments
     */
    protected function actionButtons()
    {
        global $lang;

        ptln('<div class="comment_buttons">', 12);
        ptln('<input type="submit" name="comment" value="' . $this->getLang('btn_show') . '" class="button" title="' . $this->getLang('btn_show') . '" />', 14);
        ptln('<input type="submit" name="comment" value="' . $this->getLang('btn_hide') . '" class="button" title="' . $this->getLang('btn_hide') . '" />', 14);
        ptln('<input type="submit" name="comment" value="' . $lang['btn_delete'] . '" class="button" title="' . $lang['btn_delete'] . '" />', 14);
        ptln('</div>', 12); // class="comment_buttons"
    }

    /**
     * Displays links to older newer discussions
     *
     * @param bool $isMore whether there are more pages needed
     * @param int $first first entry on this page
     * @param int $num number of entries per page
     */
    protected function browseDiscussionLinks($isMore, $first, $num)
    {
        global $ID;

        if ($first == 0 && !$isMore) return;

        $params = ['do' => 'admin', 'page' => 'discussion'];
        $last = $first + $num;
        ptln('<div class="level1">', 8);
        $return = '';
        if ($first > 0) {
            $first -= $num;
            if ($first < 0) {
                $first = 0;
            }
            $params['first'] = $first;
            ptln('<p class="centeralign">', 8);
            $return = '<a href="' . wl($ID, $params) . '" class="wikilink1">&lt;&lt; ' . $this->getLang('newer') . '</a>';
            if ($isMore) {
                $return .= ' | ';
            } else {
                ptln($return, 10);
                ptln('</p>', 8);
            }
        } elseif ($isMore) {
            ptln('<p class="centeralign">', 8);
        }
        if ($isMore) {
            $params['first'] = $last;
            $return .= '<a href="' . wl($ID, $params) . '" class="wikilink1">' . $this->getLang('older') . ' &gt;&gt;</a>';
            ptln($return, 10);
            ptln('</p>', 8);
        }
        ptln('</div>', 6); // class="level1"
    }

    /**
     * Changes the status of a comment section
     *
     * @param int $new 0=disabled, 1=enabled, 2=closed
     */
    protected function changeStatus($new)
    {
        global $ID;

        // get discussion meta file name
        $file = metaFN($ID, '.comments');
        $data = unserialize(io_readFile($file, false));

        $old = $data['status'];
        if ($old == $new) {
            return;
        }

        // save the comment metadata file
        $data['status'] = $new;
        io_saveFile($file, serialize($data));

        // look for ~~DISCUSSION~~ command in page file and change it accordingly
        $patterns = ['~~DISCUSSION:off\2~~', '~~DISCUSSION\2~~', '~~DISCUSSION:closed\2~~'];
        $replace = $patterns[$new];
        $wiki = preg_replace('/~~DISCUSSION([\w:]*)(\|?.*?)~~/', $replace, rawWiki($ID));
        saveWikiText($ID, $wiki, $this->getLang('statuschanged'), true);
    }
}
