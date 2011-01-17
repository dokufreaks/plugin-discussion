<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");

class helper_plugin_discussion extends DokuWiki_Plugin {

    function getInfo() {
        return array(
                'author' => 'Gina Häußge, Michael Klier, Esther Brunner',
                'email'  => 'dokuwiki@chimeric.de',
                'date'   => @file_get_contents(DOKU_PLUGIN.'discussion/VERSION'),
                'name'   => 'Discussion Plugin (helper class)',
                'desc'   => 'Functions to get info about comments to a wiki page',
                'url'    => 'http://wiki.splitbrain.org/plugin:discussion',
                );
    }

    function getMethods() {
        $result = array();
        $result[] = array(
                'name'   => 'th',
                'desc'   => 'returns the header of the comments column for pagelist',
                'return' => array('header' => 'string'),
                );
        $result[] = array(
                'name'   => 'td',
                'desc'   => 'returns the link to the discussion section with number of comments',
                'params' => array(
                    'id' => 'string',
                    'number of comments (optional)' => 'integer'),
                'return' => array('link' => 'string'),
                );
        $result[] = array(
                'name'   => 'getThreads',
                'desc'   => 'returns pages with discussion sections, sorted by recent comments',
                'params' => array(
                    'namespace' => 'string',
                    'number (optional)' => 'integer'),
                'return' => array('pages' => 'array'),
                );
        $result[] = array(
                'name'   => 'getComments',
                'desc'   => 'returns recently added or edited comments individually',
                'params' => array(
                    'namespace' => 'string',
                    'number (optional)' => 'integer'),
                'return' => array('pages' => 'array'),
                );
        $result[] = array(
                'name'   => 'getCommentsData',
                'desc'   => 'returns the comments-data-array',
                'params' => array('id' => 'string'),
                'return' => array('comments_data' => 'array')
            );
        $result[] = array(
                'name'   => 'saveCommentsData',
                'desc'   => 'saves the changed comments-data-array',
                'params' => array(
                    'id' => 'string',
                    'data' => 'array'),
                'return' => array('success' => 'bool')
            );

        $result[] = array(
                'name'   => 'getCommentACL',
                'desc'   => 'gets the permissions the user has for a comment or for comments in general',
                'params' => array(
                    'id'  => 'string',
                    'comment (optional)' => 'array',
                    'exists (optional)'  => 'bool'),
                'return' => array('acl' => 'int')
            );
        $result[] = array(
                'name'   => 'addComment',
                'desc'   => 'adds a new comment',
                'params' => array(
                    'id' => 'string',
                    'comment' => 'array'),
                'return' => array('success' => 'boolean')
            );
        $result[] = array(
                'name'   => 'deleteComment',
                'desc'   => 'deletes a comment',
                'params' => array(
                    'id' => 'string',
                    'cid' => 'string'),
                'return' => array('success' => 'boolean')
            );
        $result[] = array(
                'name'   => 'editComment',
                'desc'   => 'edits a comment',
                'params' => array(
                    'id' => 'string',
                    'cid' => 'string',
                    'comment' => 'array'),
                'return' => array('success' => 'boolean')
            );
        $result[] = array(
                'name'   => 'renderComment',
                'desc'   => 'renders a comment',
                'params' => array(
                    'id' => 'string',
                    'comment' => 'array'),
                'return' => array('html' => 'string')
            );
        $result[] = array(
                'name'   => 'renderCommentForm',
                'desc'   => 'renders a comment form',
                'params' => array(
                    'id' => 'string',
                    'comment' => 'array'),
                'return' => array('html' => 'string')
            );
        return $result;
    }

    /**
     * Returns the column header for the Pagelist Plugin
     */
    function th() {
        return $this->getLang('discussion');
    }

    /**
     * Returns the link to the discussion section of a page
     */
    function td($id, $num = NULL) {
        $section = '#discussion__section';

        if (!isset($num)) {
            $cfile = metaFN($id, '.comments');
            $comments = unserialize(io_readFile($cfile, false));

            $num = $comments['number'];
            if ((!$comments['status']) || (($comments['status'] == 2) && (!$num))) return '';
        }

        if ($num == 0) $comment = '0&nbsp;'.$this->getLang('nocomments');
        elseif ($num == 1) $comment = '1&nbsp;'.$this->getLang('comment');
        else $comment = $num.'&nbsp;'.$this->getLang('comments');

        return '<a href="'.wl($id).$section.'" class="comment wikilink1" title="'.$id.$section.'">'.
            $comment.'</a>';
    }

    /**
     * Returns an array of pages with discussion sections, sorted by recent comments
     */
    function getThreads($ns, $num = NULL) {
        global $conf;

        require_once(DOKU_INC.'inc/search.php');

        $dir = $conf['datadir'].($ns ? '/'.str_replace(':', '/', $ns): '');

        // returns the list of pages in the given namespace and it's subspaces
        $items = array();
        search($items, $dir, 'search_allpages', '');

        // add pages with comments to result
        $result = array();
        foreach ($items as $item) {
            $id   = ($ns ? $ns.':' : '').$item['id'];

            // some checks
            $perm = auth_quickaclcheck($id);
            if ($perm < AUTH_READ) continue;    // skip if no permission
            $file = metaFN($id, '.comments');
            if (!@file_exists($file)) continue; // skip if no comments file
            $data = unserialize(io_readFile($file, false));
            $status = $data['status'];
            $number = $data['number']; // skip if comments are off or closed without comments
            if (!$status || (($status == 2) && (!$number))) continue;

            $date = filemtime($file);
            $meta = p_get_metadata($id);
            $result[$date] = array(
                    'id'       => $id,
                    'file'     => $file,
                    'title'    => $meta['title'],
                    'date'     => $date,
                    'user'     => $meta['creator'],
                    'desc'     => $meta['description']['abstract'],
                    'num'      => $number,
                    'comments' => $this->td($id, $number),
                    'status'   => $status,
                    'perm'     => $perm,
                    'exists'   => true,
                    'anchor'   => 'discussion__section',
                    );
        }

        // finally sort by time of last comment
        krsort($result);

        if (is_numeric($num)) $result = array_slice($result, 0, $num);

        return $result;
    }

    /**
     * Returns an array of recently added comments to a given page or namespace
     */
    function getComments($ns, $num = NULL) {
        global $conf;

        $first  = $_REQUEST['first'];
        if (!is_numeric($first)) $first = 0;

        if ((!$num) || (!is_numeric($num))) $num = $conf['recent'];

        $result = array();
        $count  = 0;

        if (!@file_exists($conf['metadir'].'/_comments.changes')) return $result;

        // read all recent changes. (kept short)
        $lines = file($conf['metadir'].'/_comments.changes');

        $seen = array(); //caches seen pages in order to skip them
        // handle lines
        $line_num = count($lines);
        for ($i = ($line_num - 1); $i >= 0; $i--) {
            $rec = $this->_handleRecentComment($lines[$i], $ns, $seen);
            if ($rec !== false) {
                if (--$first >= 0) continue; // skip first entries
                $result[$rec['date']] = $rec;
                $count++;
                // break when we have enough entries
                if ($count >= $num) break;
            }
        }

        // finally sort by time of last comment
        krsort($result);

        return $result;
    }

    /* ---------- Changelog function adapted for the Discussion Plugin ---------- */

    /**
     * Internal function used by $this->getComments()
     *
     * don't call directly
     *
     * @see getRecentComments()
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Ben Coburn <btcoburn@silicodon.net>
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    function _handleRecentComment($line, $ns, &$seen) {
        if (empty($line)) return false;  //skip empty lines

        // split the line into parts
        $recent = parseChangelogLine($line);
        if ($recent === false) return false;

        $cid     = $recent['extra'];
        $fullcid = $recent['id'].'#'.$recent['extra'];

        // skip seen ones
        if (isset($seen[$fullcid])) return false;

        // skip 'show comment' log entries
        if ($recent['type'] === 'sc') return false;

        // remember in seen to skip additional sights
        $seen[$fullcid] = 1;

        // check if it's a hidden page or comment
        if (isHiddenPage($recent['id'])) return false;
        if ($recent['type'] === 'hc') return false;

        // filter namespace or id
        if (($ns) && (strpos($recent['id'].':', $ns.':') !== 0)) return false;

        // check ACL
        $recent['perm'] = auth_quickaclcheck($recent['id']);
        if ($recent['perm'] < AUTH_READ) return false;

        // check existance
        $recent['file'] = wikiFN($recent['id']);
        $recent['exists'] = @file_exists($recent['file']);
        if (!$recent['exists']) return false;
        if ($recent['type'] === 'dc') return false;

        // get discussion meta file name
        $data = unserialize(io_readFile(metaFN($recent['id'], '.comments'), false));

        // check if discussion is turned off
        if ($data['status'] === 0) return false;

        // check if the comment still exists
        if (!isset($data['comments'][$cid])) return false;

        // okay, then add some additional info
        if (is_array($data['comments'][$cid]['user'])) {
            $recent['name'] = $data['comments'][$cid]['user']['name'];
            $recent['url']  = $data['comments'][$cid]['user']['url'];
        } else {
            $recent['name'] = $data['comments'][$cid]['name'];
            $recent['url']  = $comment['url'];
        }
        $recent['desc'] = strip_tags($data['comments'][$cid]['xhtml']);
        $recent['anchor'] = 'comment_'.$cid;

        return $recent;
    }

    /**
     * loads the comment-data-file
     *
     * @param string $id the id of the page for which the comments shall be loaded
     * @return array the data-array or an empty array when the comments don't exist
     */
    function &getCommentsData($id) {
        static $data_cache = array();
        if (isset($data_cache[$id])) return $data_cache[$id];
        $file = metaFN($id, '.comments');

        if (!@file_exists($file)) {
            // generate the comments_file when automatic is on
            if ($this->getConf('automatic') && page_exists($id)) {
                $data = array('status' => 1, 'number' => 0, 'comments' => array(), 'user_array' => true);
                io_saveFile($file, serialize($data));
                $data_cache[$id] =& $data;
                return $data;
            } else {
                return array();
            }
        }

        $data = unserialize(io_readFile($file, false));
        if (!isset($data['user_array'])) {
            foreach (array_keys($data['comments']) as $cid) {
                $comment =& $data['comments'][$cid];
                if (!is_array($comment['user'])) {
                    $comment['user'] = array(
                        'id'      => $comment['user'],
                        'name'    => $comment['name'],
                        'mail'    => $comment['mail'],
                        'url'     => $comment['url'],
                        'address' => $comment['address'],
                    );
                }
                $comment['cid'] = $cid;
            }
            $data['user_array'] = true;
            io_saveFile($file, serialize($data));
        }
        $data_cache[$id] =& $data;
        return $data;
    }

    function getCommentACL($id, $comment = null) {
        $data =& $this->getCommentsData($id);
        if ((count($data) != 0) && !$data['status']) return AUTH_NONE;
        if (count($data) == 0) return AUTH_NONE;
        if (auth_ismanager()) return AUTH_ADMIN;
        if ($comment && $data['comments'][$comment['cid']]) {
            // check the permissions for this $cid
            $edit_own = $this->getConf('edit_own');
            if ($_SERVER['REMOTE_USER'] === $comment['user']['id'] && ($edit_own == 'yes' || ($edit_own == 'noreplies' && !count($comment['replies'])))) return AUTH_WRITE;
            if ($comment['show'])
                return AUTH_READ;
            else
                return AUTH_NONE;
 
        } else {
            if (($_SERVER['REMOTE_USER'] && 
                ($comment ? $comment['user']['id'] == $_SERVER['REMOTE_USER'] : true))
                || $this->getConf('allowguests'))
                return AUTH_CREATE;
            else
                return AUTH_READ;
        }
    }

    function addComment($id, $comment) {
        if ($this->getCommentACL($id, $comment) < AUTH_CREATE) return false;
        global $TEXT;

        $otxt = $TEXT; // set $TEXT to comment text for wordblock check
        $TEXT = $comment['raw'];

        // spamcheck against the DokuWiki blacklist
        if (checkwordblock()) {
            msg($this->getLang('wordblock'), -1);
            $TEXT = $otxt; // restore global $TEXT
            return false;
        }

        $TEXT = $otxt; // restore global $TEXT

        if ($comment['date']['created']) {
            $date = strtotime($comment['date']['created']);
        } else { 
            $date = time();
        }

        if ($date == -1) {
            $date = time();
        }

        $cid  = md5($comment['user']['id'].$date); // create a unique id

        $data =& $this->getCommentsData($id);

        $paraent = $comment['parent'];
        if (!is_array($data['comments'][$parent])) {
            $parent = NULL; // invalid parent comment
        }

        // render the comment
        $xhtml = $this->_render($comment['raw']);


        // fill in the new comment
        $data['comments'][$cid] = array(
                'user'    => $comment['user'],
                'date'    => array('created' => $date),
                'show'    => true,
                'raw'     => $comment['raw'],
                'xhtml'   => $xhtml,
                'parent'  => $parent,
                'replies' => array()
                'cid'     => $cid;
                );

        if($comment['subscribe']) {
            $mail = $comment['user']['mail'];
            if($data['subscribers']) {
                if(!$data['subscribers'][$mail]) {
                    $data['subscribers'][$mail] = md5($mail . mt_rand());
                }
            } else {
                $data['subscribers'][$mail] = md5($mail . mt_rand());
            }
        }

        // update parent comment
        if ($parent) $data['comments'][$parent]['replies'][] = $cid;

        // update the number of comments
        $data['number']++;

        // save the comment metadata file
        $this->_notify($data['comments'][$cid], $data['subscribers']);
        $this->saveCommentsData($id, $data);
        $this->_addLogEntry($date, $id, 'cc', '', $cid);
        return $cid;
    }

    function deleteComment($id, $cid, $force = false) {
        $data =& $this->getCommentsData($id);
        $comment =& $data['comments'][$cid];
        if (!@is_array($comment)) return false;
        if (!$force && $this->getCommentACL($id, $comment) < AUTH_WRITE) return false;

        if ($this->getConf('usethreading') && is_array($comments[$cid]['replies'])) {
            foreach ($comments[$cid]['replies'] as $rid) {
                $this->deleteComment($id, $rid, true);
            }
        }
        // delete this comment from the replies of the parent. even when threads are turned of, this is intentional.
        $pcid = $comment['parent'];
        if ($pcid && $data['comments'][$pcid])
            $data['comments'][$pcid]['replies'] = array_diff($data['comments'][$pcid]['replies'], array($cid)); 

        unset($data['comments'][$cid]);
        // save the comment metadata file
        $this->saveCommentsData($id, $data);
        $this->_addLogEntry($date, $id, 'dc', '', $cid);
        return true;
    }

    function editComment($id, $comment) {
        $data =& $this->getCommentsData($id);
        if (@is_array($data['comments'][$comment['cid'])) return false;
        if ($this->getCommentACL($id, $comment['cid']) < AUTH_WRITE) return false;
        
        global $TEXT;

        $otxt = $TEXT; // set $TEXT to comment text for wordblock check
        $TEXT = $comment['raw'];

        // spamcheck against the DokuWiki blacklist
        if (checkwordblock()) {
            msg($this->getLang('wordblock'), -1);
            return false;
        }

        $TEXT = $otxt; // restore global $TEXT

        $date = time();

        $xhtml = $this->_render($comment['raw']);
        $comment['date']['modified'] = $date;
        $comment['raw']              = $raw;
        $comment['xhtml']            = $xhtml;

        // check which type of modification we had
        $orig_comment = $data['comments'][$comment['cid']];
        if ($orig_comment['show'] && !$comment['show']) {
            $type = 'hc';
            $data['number']--;
        } elseif (!$orig_comment['show'] && $comment['show']) {
            $type = 'sc';
            $data['number']++;
        } else {
            $type = 'ec';
        }

        $data['comments'][$comment['cid']] = $comment;

        // save the comment metadata file
        $this->saveCommentsData($id, $data);
        $this->_addLogEntry($date, $id, $type, '', $comment['cid']);
        return true;
    }

    function renderComment($id, $comment) {
        $acl = $this->getCommentACL($id, $comment);
        if ($acl < AUTH_READ) return false;
        $hidden = ($comment['show'] ? ' comment_hidden' : '');

        // comment head with date and user data
        ptln('<div class="hentry'.$hidden.'">', 4);
        ptln('<div class="comment_head">', 6);
        ptln('<a name="comment__'.$comment['cid'].'" id="comment__'.$comment['cid'].'"></a>', 8);
        $head = '<span class="vcard author">';

        // show avatar image?
        if ($this->getConf('useavatar')
                && (!plugin_isdisabled('avatar'))
                && ($avatar =& plugin_load('helper', 'avatar'))) {

                    $files = @glob(mediaFN($avatar->getConf('namespace')) . '/' . $comment['user']['id'] . '.*');
            if ($files) {
                foreach ($files as $file) {
                    if (preg_match('/jpg|jpeg|gif|png/', $file)) {
                        $head .= $avatar->getXHTML($comment['user']['id'], $comment['user']['name'], 'left');
                        break;
                    }
                }
            } elseif ($comment['user']['mail']) {
                $head .= $avatar->getXHTML($comment['user']['mail'], $comment['user']['name'], 'left');
            } else { 
                $head .= $avatar->getXHTML($comment['user']['id'], $comment['user']['name'], 'left');
            }
            $style = ' style="margin-left: '.($avatar->getConf('size') + 14).'px;"';
        } else {
            $style = ' style="margin-left: 20px;"';
        }

        if ($this->getConf('linkemail') && $comment['user']['mail']) {
            $head .= $this->email($comment['user']['mail'], $comment['user']['name'], 'email fn');
        } elseif ($this->getConf('usernamespace') && $auth->getUserData($comment['user']['id'])) {
            $head .= '<a class="wikilink1" href="'.wl($this->getConf('usernamespace').':'.$comment['user']['id'].':').'">'.hsc($comment['user']['name']).'</a>';
        } elseif ($comment['user']['url']) {
            $head .= $this->external_link($url, $comment['user']['name'], 'urlextern url fn');
        } else {
            $head .= '<span class="fn">'.$comment['user']['name'].'</span>';
        }
        if ($comment['user']['address']) $head .= ', <span class="adr">'.$comment['user']['address'].'</span>';
        $head .= '</span>, '.
            '<abbr class="published" title="'.strftime('%Y-%m-%dT%H:%M:%SZ', $comment['date']['created']).'">'.
            strftime($conf['dformat'], $comment['date']['created']).'</abbr>';
        if ($comment['edited']) $head .= ' (<abbr class="updated" title="'.
                strftime('%Y-%m-%dT%H:%M:%SZ', $modified).'">'.strftime($conf['dformat'], $comment['date']['modified']).
                '</abbr>)';
        ptln($head, 8);
        ptln('</div>', 6); // class="comment_head"

        // main comment content
        ptln('<div class="comment_body entry-content"'.
                ($this->getConf('useavatar') ? $style : '').'>', 6);
        echo $comment['xhtml'].DOKU_LF;
        ptln('</div>', 6); // class="comment_body"

        $id_acl = $this->getCommentACL($id);
        if ($acl >= AUTH_WRITE || $id_acl >= AUTH_CREATE) {
            ptln('<div class="comment_buttons">', 6);

            // show reply button?
            if ($id_acl >= AUTH_CREATE && $this->getConf('usethreading'))
                $this->_button($cid, $this->getLang('btn_reply'), 'reply', true);

            // show edit, show/hide and delete button?
            if ($acl >= AUTH_WRITE) {
                $this->_button($cid, $lang['btn_secedit'], 'edit', true);
                $label = ($comment['show'] ? $this->getLang('btn_hide') : $this->getLang('btn_show'));
                $this->_button($cid, $label, 'toogle');
                $this->_button($cid, $lang['btn_delete'], 'delete');
            }
            ptln('</div>', 6); // class="comment_buttons"
        }
        ptln('</div>', 4); // class="hentry"
    }

    /**
     * Outputs the comment form
     */
    function _form($id, $comment, $act = 'add') {
        global $lang;
        global $conf;
        global $INFO;

        if ($comment['cid']) {
            $acl = $this->getCommentACL($id, $comment);
            if ($acl < AUTH_WRITE) return false;
        } else {
            $acl = $this->getCommentACL($id);
            if ($acl < AUTH_CREATE) return false;
        }

        // FIXME: where is that needed, can it be replaced somewhere???
        // fill $raw with $_REQUEST['text'] if it's empty (for failed CAPTCHA check)
        if (!$raw && ($_REQUEST['comment'] == 'show')) $raw = $_REQUEST['text'];
        ?>

        <div class="comment_form">
          <form id="discussion__comment_form" method="post" action="<?php echo script() ?>" accept-charset="<?php echo $lang['encoding'] ?>" onsubmit="return validate(this);">
            <div class="no">
              <input type="hidden" name="id" value="<?php echo $id ?>" />
              <input type="hidden" name="do" value="show" />
              <input type="hidden" name="comment" value="<?php echo $act ?>" />

        <?php
        // for adding a comment
        if ($act == 'add') {
        ?>
              <input type="hidden" name="reply" value="<?php echo $comment['cid'] ?>" />
        <?php
        // for registered user (and we're not in admin import mode)
        if ($conf['useacl'] && $_SERVER['REMOTE_USER']
                && (!($this->getConf('adminimport') && (auth_ismanager())))) {
        ?>
              <input type="hidden" name="user" value="<?php echo hsc($_SERVER['REMOTE_USER']) ?>" />
              <input type="hidden" name="name" value="<?php echo hsc($INFO['userinfo']['name']) ?>" />
              <input type="hidden" name="mail" value="<?php echo hsc($INFO['userinfo']['mail']) ?>" />
        <?php
        // for guest: show name, e-mail and subscribe to comments fields
        } else {
        ?>
              <input type="hidden" name="user" value="<?php echo clientIP() ?>" />
              <div class="comment_name">
                <label class="block" for="discussion__comment_name">
                  <span><?php echo $lang['fullname'] ?>:</span>
                  <input type="text" class="edit" name="name" id="discussion__comment_name" size="50" tabindex="1" value="<?php echo hsc($_REQUEST['name'])?>" />
                </label>
              </div>
              <div class="comment_mail">
                <label class="block" for="discussion__comment_mail">
                  <span><?php echo $lang['email'] ?>:</span>
                  <input type="text" class="edit" name="mail" id="discussion__comment_mail" size="50" tabindex="2" value="<?php echo hsc($_REQUEST['mail'])?>" />
                </label>
              </div>
        <?php
        }

        // allow entering an URL
        if ($this->getConf('urlfield') && !($this->getConf('usernamespace') && $_SERVER['REMOTE_USER'])) {
        ?>
              <div class="comment_url">
                <label class="block" for="discussion__comment_url">
                  <span><?php echo $this->getLang('url') ?>:</span>
                  <input type="text" class="edit" name="url" id="discussion__comment_url" size="50" tabindex="3" value="<?php echo hsc($_REQUEST['url'])?>" />
                </label>
              </div>
        <?php
        }

        // allow entering an address
        if ($this->getConf('addressfield')) {
        ?>
              <div class="comment_address">
                <label class="block" for="discussion__comment_address">
                  <span><?php echo $this->getLang('address') ?>:</span>
                  <input type="text" class="edit" name="address" id="discussion__comment_address" size="50" tabindex="4" value="<?php echo hsc($_REQUEST['address'])?>" />
                </label>
              </div>
        <?php
        }

        // allow setting the comment date
        if ($this->getConf('adminimport') && (auth_ismanager())) {
        ?>
              <div class="comment_date">
                <label class="block" for="discussion__comment_date">
                  <span><?php echo $this->getLang('date') ?>:</span>
                  <input type="text" class="edit" name="date" id="discussion__comment_date" size="50" />
                </label>
              </div>
        <?php
        }

        // for saving a comment
        } else {
        ?>
              <input type="hidden" name="cid" value="<?php echo $cid ?>" />
        <?php
        }
        ?>
              <div class="comment_text">
        <?php
        echo $this->getLang('entercomment');
        if ($this->getConf('wikisyntaxok')) echo ' ('.$this->getLang('wikisyntax').')';
        echo ':<br />'.DOKU_LF;
        ?>
        <textarea class="edit" name="text" cols="80" rows="10" id="discussion__comment_text" tabindex="5"><?php echo formText($comment['raw']) ?></textarea>
              </div>
        <?php //bad and dirty event insert hook
        $evdata = array('writable' => true);
        trigger_event('HTML_EDITFORM_INJECTION', $evdata);
        ?>
              <input class="button comment_submit" type="submit" name="submit" accesskey="s" value="<?php echo $lang['btn_save'] ?>" title="<?php echo $lang['btn_save']?> [ALt+S]" tabindex="7" />

        <?php if(!$_SERVER['REMOTE_USER']) { ?>
              <div class="comment_subscribe">
                <input type="checkbox" id="discussion__comment_subscribe" name="subscribe" tabindex=="6" />
                <label class="block" for="discussion__comment_subscribe">
                  <span><?php echo $this->getLang('subscribe') ?></span>
                </label>
              </div>
        <?php } ?>

              <div class="clearer"></div>

            </div>
          </form>
        </div>
        <?php
        if ($this->getConf('usecocomment')) echo $this->_coComment();
    }

//TODO:
    //move redirect in the action-handler
    //functions:
        //_render()
        //_notify()
        //_addLogEntry()
        //_button();
        //_coComment()
    /* modify according to on getCommentsData()
     *
        if (is_array($comment['date'])) { // new format
            $created  = $comment['date']['created'];
            $modified = $comment['date']['modified'];
        } else {                         // old format
            $created  = $comment['date'];
            $modified = $comment['edited'];
        }
     */
}
// vim:ts=4:sw=4:et:enc=utf-8:
