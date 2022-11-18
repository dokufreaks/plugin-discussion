<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

use dokuwiki\Extension\Event;
use dokuwiki\Subscriptions\SubscriberManager;
use dokuwiki\Utf8\PhpString;

/**
 * Class action_plugin_discussion
 *
 * Data format of file metadir/<id>.comments:
 * array = [
 *  'status' => int whether comments are 0=disabled/1=open/2=closed,
 *  'number' => int number of visible comments,
 *  'title' => string|null alternative title for discussion section
 *  'comments' => [
 *      '<cid>'=> [
 *          'cid' => string comment id - long random string
 *          'raw' => string comment text,
 *          'xhtml' => string rendered html,
 *          'parent' => null|string null or empty string at highest level, otherwise comment id of parent
 *          'replies' => string[] array with comment ids
 *          'user' => [
 *              'id' => string,
 *              'name' => string,
 *              'mail' => string,
 *              'address' => string,
 *              'url' => string
 *          ],
 *          'date' => [
 *              'created' => int timestamp,
 *              'modified' => int (not defined if not modified)
 *          ],
 *          'show' => bool, whether shown (still be moderated, or hidden by moderator or user self)
 *      ],
 *      ...
 *   ]
 *   'subscribers' => [
 *      '<email>' => [
 *          'hash' => string unique token,
 *          'active' => bool, true if confirmed
 *          'confirmsent' => bool, true if confirmation mail is sent
 *      ],
 *      ...
 *   ]
 */
class action_plugin_discussion extends DokuWiki_Action_Plugin
{

    /** @var helper_plugin_avatar */
    protected $avatar = null;
    /** @var null|string */
    protected $style = null;
    /** @var null|bool */
    protected $useAvatar = null;
    /** @var helper_plugin_discussion */
    protected $helper = null;

    /**
     * load helper
     */
    public function __construct()
    {
        $this->helper = plugin_load('helper', 'discussion');
    }

    /**
     * Register the handlers
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object.
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleCommentActions');
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'renderCommentsSection');
        $controller->register_hook('INDEXER_PAGE_ADD', 'AFTER', $this, 'addCommentsToIndex', ['id' => 'page', 'text' => 'body']);
        $controller->register_hook('FULLTEXT_SNIPPET_CREATE', 'BEFORE', $this, 'addCommentsToIndex', ['id' => 'id', 'text' => 'text']);
        $controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'addIndexVersion', []);
        $controller->register_hook('FULLTEXT_PHRASE_MATCH', 'AFTER', $this, 'fulltextPhraseMatchInComments', []);
        $controller->register_hook('PARSER_METADATA_RENDER', 'AFTER', $this, 'updateCommentStatusFromMetadata', []);
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'addToolbarToCommentfield', []);
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'modifyToolbar', []);
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'ajaxPreviewComments', []);
        $controller->register_hook('TPL_TOC_RENDER', 'BEFORE', $this, 'addDiscussionToTOC', []);
    }

    /**
     * Preview Comments
     *
     * @param Doku_Event $event
     * @author Michael Klier <chi@chimeric.de>
     */
    public function ajaxPreviewComments(Doku_Event $event)
    {
        global $INPUT;
        if ($event->data != 'discussion_preview') return;

        $event->preventDefault();
        $event->stopPropagation();
        print p_locale_xhtml('preview');
        print '<div class="comment_preview">';
        if (!$INPUT->server->str('REMOTE_USER') && !$this->getConf('allowguests')) {
            print p_locale_xhtml('denied');
        } else {
            print $this->renderComment($INPUT->post->str('comment'));
        }
        print '</div>';
    }

    /**
     * Adds a TOC item if a discussion exists
     *
     * @param Doku_Event $event
     * @author Michael Klier <chi@chimeric.de>
     */
    public function addDiscussionToTOC(Doku_Event $event)
    {
        global $ACT;
        if ($this->hasDiscussion($title) && $event->data && $ACT != 'admin') {
            $tocitem = [
                'hid' => 'discussion__section',
                'title' => $title ?: $this->getLang('discussion'),
                'type' => 'ul',
                'level' => 1
            ];

            $event->data[] = $tocitem;
        }
    }

    /**
     * Modify Toolbar for use with discussion plugin
     *
     * @param Doku_Event $event
     * @author Michael Klier <chi@chimeric.de>
     */
    public function modifyToolbar(Doku_Event $event)
    {
        global $ACT;
        if ($ACT != 'show') return;

        if ($this->hasDiscussion($title) && $this->getConf('wikisyntaxok')) {
            $toolbar = [];
            foreach ($event->data as $btn) {
                if ($btn['type'] == 'mediapopup') continue;
                if ($btn['type'] == 'signature') continue;
                if ($btn['type'] == 'linkwiz') continue;
                if ($btn['type'] == 'NewTable') continue; //skip button for Edittable Plugin
                //FIXME does nothing. Checks for '=' on toplevel, but today it are special buttons and a picker with subarray
                if (isset($btn['open']) && preg_match("/=+?/", $btn['open'])) continue;

                $toolbar[] = $btn;
            }
            $event->data = $toolbar;
        }
    }

    /**
     * Dirty workaround to add a toolbar to the discussion plugin
     *
     * @param Doku_Event $event
     * @author Michael Klier <chi@chimeric.de>
     */
    public function addToolbarToCommentfield(Doku_Event $event)
    {
        global $ACT;
        global $ID;
        if ($ACT != 'show') return;

        if ($this->hasDiscussion($title) && $this->getConf('wikisyntaxok')) {
            // FIXME ugly workaround, replace this once DW the toolbar code is more flexible
            @require_once(DOKU_INC . 'inc/toolbar.php');
            ob_start();
            print 'NS = "' . getNS($ID) . '";'; // we have to define NS, otherwise we get get JS errors
            toolbar_JSdefines('toolbar');
            $script = ob_get_clean();
            $event->data['script'][] = ['type' => 'text/javascript', 'charset' => "utf-8", '_data' => $script];
        }
    }

    /**
     * Handles comment actions, dispatches data processing routines
     *
     * @param Doku_Event $event
     */
    public function handleCommentActions(Doku_Event $event)
    {
        global $ID, $INFO, $lang, $INPUT;

        // handle newthread ACTs
        if ($event->data == 'newthread') {
            // we can handle it -> prevent others
            $event->data = $this->newThread();
        }

        // enable captchas
        if (in_array($INPUT->str('comment'), ['add', 'save'])) {
            $this->captchaCheck();
            $this->recaptchaCheck();
        }

        // if we are not in show mode or someone wants to unsubscribe, that was all for now
        if ($event->data != 'show'
            && $event->data != 'discussion_unsubscribe'
            && $event->data != 'discussion_confirmsubscribe') {
            return;
        }

        if ($event->data == 'discussion_unsubscribe' or $event->data == 'discussion_confirmsubscribe') {
            if ($INPUT->has('hash')) {
                $file = metaFN($ID, '.comments');
                $data = unserialize(io_readFile($file));
                $matchedMail = '';
                foreach ($data['subscribers'] as $mail => $info) {
                    // convert old style subscribers just in case
                    if (!is_array($info)) {
                        $hash = $data['subscribers'][$mail];
                        $data['subscribers'][$mail]['hash'] = $hash;
                        $data['subscribers'][$mail]['active'] = true;
                        $data['subscribers'][$mail]['confirmsent'] = true;
                    }

                    if ($data['subscribers'][$mail]['hash'] == $INPUT->str('hash')) {
                        $matchedMail = $mail;
                    }
                }

                if ($matchedMail != '') {
                    if ($event->data == 'discussion_unsubscribe') {
                        unset($data['subscribers'][$matchedMail]);
                        msg(sprintf($lang['subscr_unsubscribe_success'], $matchedMail, $ID), 1);
                    } else { //$event->data == 'discussion_confirmsubscribe'
                        $data['subscribers'][$matchedMail]['active'] = true;
                        msg(sprintf($lang['subscr_subscribe_success'], $matchedMail, $ID), 1);
                    }
                    io_saveFile($file, serialize($data));
                    $event->data = 'show';
                }

            }
            return;
        }

        // do the data processing for comments
        $cid = $INPUT->str('cid');
        switch ($INPUT->str('comment')) {
            case 'add':
                if (empty($INPUT->str('text'))) return; // don't add empty comments

                if ($INPUT->server->has('REMOTE_USER') && !$this->getConf('adminimport')) {
                    $comment['user']['id'] = $INPUT->server->str('REMOTE_USER');
                    $comment['user']['name'] = $INFO['userinfo']['name'];
                    $comment['user']['mail'] = $INFO['userinfo']['mail'];
                } elseif (($INPUT->server->has('REMOTE_USER') && $this->getConf('adminimport') && $this->helper->isDiscussionModerator())
                    || !$INPUT->server->has('REMOTE_USER')) {
                    // don't add anonymous comments
                    if (empty($INPUT->str('name')) or empty($INPUT->str('mail'))) {
                        return;
                    }

                    if (!mail_isvalid($INPUT->str('mail'))) {
                        msg($lang['regbadmail'], -1);
                        return;
                    } else {
                        $comment['user']['id'] = ''; //prevent overlap with loggedin users, before: 'test<ipadress>'
                        $comment['user']['name'] = hsc($INPUT->str('name'));
                        $comment['user']['mail'] = hsc($INPUT->str('mail'));
                    }
                }
                $comment['user']['address'] = ($this->getConf('addressfield')) ? hsc($INPUT->str('address')) : '';
                $comment['user']['url'] = ($this->getConf('urlfield')) ? $this->checkURL($INPUT->str('url')) : '';
                $comment['subscribe'] = ($this->getConf('subscribe')) ? $INPUT->has('subscribe') : '';
                $comment['date'] = ['created' => $INPUT->str('date')];
                $comment['raw'] = cleanText($INPUT->str('text'));
                $reply = $INPUT->str('reply');
                if ($this->getConf('moderate') && !$this->helper->isDiscussionModerator()) {
                    $comment['show'] = false;
                } else {
                    $comment['show'] = true;
                }
                $this->add($comment, $reply);
                break;

            case 'save':
                $raw = cleanText($INPUT->str('text'));
                $this->save([$cid], $raw);
                break;

            case 'delete':
                $this->save([$cid], '');
                break;

            case 'toogle':
                $this->save([$cid], '', 'toogle');
                break;
        }
    }

    /**
     * Main function; dispatches the visual comment actions
     *
     * @param Doku_Event $event
     */
    public function renderCommentsSection(Doku_Event $event)
    {
        global $INPUT;
        if ($event->data != 'show') return; // nothing to do for us

        $cid = $INPUT->str('cid');

        if (!$cid) {
            $cid = $INPUT->str('reply');
        }

        switch ($INPUT->str('comment')) {
            case 'edit':
                $this->showDiscussionSection(null, $cid);
                break;
            default: //'reply' or no action specified
                $this->showDiscussionSection($cid);
                break;
        }
    }

    /**
     * Redirects browser to given comment anchor
     *
     * @param string $cid comment id
     */
    protected function redirect($cid)
    {
        global $ID;
        global $ACT;

        if ($ACT !== 'show') return;

        if ($this->getConf('moderate') && !$this->helper->isDiscussionModerator()) {
            msg($this->getLang('moderation'), 1);
            @session_start();
            global $MSG;
            $_SESSION[DOKU_COOKIE]['msg'] = $MSG;
            session_write_close();
            $url = wl($ID);
        } else {
            $url = wl($ID) . '#comment_' . $cid;
        }

        if (function_exists('send_redirect')) {
            send_redirect($url);
        } else {
            header('Location: ' . $url);
        }
        exit();
    }

    /**
     * Checks config settings to enable/disable discussions
     *
     * @return bool true if enabled
     */
    public function isDiscussionEnabled()
    {
        global $ID;

        if ($this->getConf('excluded_ns') == '') {
            $isNamespaceExcluded = false;
        } else {
            $ns = getNS($ID); // $INFO['namespace'] is not yet available, if used in update_comment_status()
            $isNamespaceExcluded = preg_match($this->getConf('excluded_ns'), $ns);
        }

        if ($this->getConf('automatic')) {
            if ($isNamespaceExcluded) {
                return false;
            } else {
                return true;
            }
        } else {
            if ($isNamespaceExcluded) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Shows all comments of the current page, if no reply or edit requested, then comment form is shown on the end
     *
     * @param null|string $reply comment id on which the user requested a reply
     * @param null|string $edit comment id which the user requested for editing
     */
    protected function showDiscussionSection($reply = null, $edit = null)
    {
        global $ID, $INFO, $INPUT;

        // get .comments meta file name
        $file = metaFN($ID, '.comments');

        if (!$INFO['exists']) return;
        if (!@file_exists($file) && !$this->isDiscussionEnabled()) return;
        if (!$INPUT->server->has('REMOTE_USER') && !$this->getConf('showguests')) return;

        // load data
        $data = [];
        if (@file_exists($file)) {
            $data = unserialize(io_readFile($file, false));
            // comments are turned off
            if (!$data['status']) {
                return;
            }
        } elseif (!@file_exists($file) && $this->isDiscussionEnabled()) {
            // set status to show the comment form
            $data['status'] = 1;
            $data['number'] = 0;
            $data['title'] = null;
        }

        // show discussion wrapper only on certain circumstances
        if (empty($data['comments']) || !is_array($data['comments'])) {
            $cnt = 0;
            $cids = [];
        } else {
            $cnt = count($data['comments']);
            $cids = array_keys($data['comments']);
        }

        $show = false;
        if ($cnt > 1 || ($cnt == 1 && $data['comments'][$cids[0]]['show'] == 1)
            || $this->getConf('allowguests')
            || $INPUT->server->has('REMOTE_USER')) {
            $show = true;
            // section title
            $title = (!empty($data['title']) ? hsc($data['title']) : $this->getLang('discussion'));
            ptln('<div class="comment_wrapper" id="comment_wrapper">'); // the id value is used for visibility toggling the section
            ptln('<h2><a name="discussion__section" id="discussion__section">', 2);
            ptln($title, 4);
            ptln('</a></h2>', 2);
            ptln('<div class="level2 hfeed">', 2);
        }

        // now display the comments
        if (isset($data['comments'])) {
            if (!$this->getConf('usethreading')) {
                $data['comments'] = $this->flattenThreads($data['comments']);
                uasort($data['comments'], [$this, 'sortThreadsOnCreation']);
            }
            if ($this->getConf('newestfirst')) {
                $data['comments'] = array_reverse($data['comments']);
            }
            foreach ($data['comments'] as $cid => $value) {
                if ($cid == $edit) { // edit form
                    $this->showCommentForm($value['raw'], 'save', $edit);
                } else {
                    $this->showCommentWithReplies($cid, $data, '', $reply);
                }
            }
        }

        // comment form shown on the end, if no comment form of $reply or $edit is requested before
        if ($data['status'] == 1 && (!$reply || !$this->getConf('usethreading')) && !$edit) {
            $this->showCommentForm('', 'add');
        }

        if ($show) {
            ptln('</div>', 2); // level2 hfeed
            ptln('</div>'); // comment_wrapper
        }

        // check for toggle print configuration
        if ($this->getConf('visibilityButton')) {
            // print the hide/show discussion section button
            $this->showDiscussionToggleButton();
        }
    }

    /**
     * Remove the parent-child relation, such that the comment structure becomes flat
     *
     * @param array $comments array with all comments
     * @param null|array $cids comment ids of replies, which should be flatten
     * @return array returned array with flattened comment structure
     */
    protected function flattenThreads($comments, $cids = null)
    {
        if (is_null($cids)) {
            $cids = array_keys($comments);
        }

        foreach ($cids as $cid) {
            if (!empty($comments[$cid]['replies'])) {
                $rids = $comments[$cid]['replies'];
                $comments = $this->flattenThreads($comments, $rids);
                $comments[$cid]['replies'] = [];
            }
            $comments[$cid]['parent'] = '';
        }
        return $comments;
    }

    /**
     * Adds a new comment and then displays all comments
     *
     * @param array $comment with
     *  'raw' => string comment text,
     *  'user' => [
     *      'id' => string,
     *      'name' => string,
     *      'mail' => string
     *  ],
     *  'date' => [
     *      'created' => int timestamp
     *  ]
     *  'show' => bool
     *  'subscribe' => bool
     * @param string $parent comment id of parent
     * @return bool
     */
    protected function add($comment, $parent)
    {
        global $ID, $TEXT, $INPUT;

        $originalTxt = $TEXT; // set $TEXT to comment text for wordblock check
        $TEXT = $comment['raw'];

        // spamcheck against the DokuWiki blacklist
        if (checkwordblock()) {
            msg($this->getLang('wordblock'), -1);
            return false;
        }

        if (!$this->getConf('allowguests')
            && $comment['user']['id'] != $INPUT->server->str('REMOTE_USER')
        ) {
            return false; // guest comments not allowed
        }

        $TEXT = $originalTxt; // restore global $TEXT

        // get discussion meta file name
        $file = metaFN($ID, '.comments');

        // create comments file if it doesn't exist yet
        if (!@file_exists($file)) {
            $data = [
                'status' => 1,
                'number' => 0,
                'title' => null
            ];
            io_saveFile($file, serialize($data));
        } else {
            $data = unserialize(io_readFile($file, false));
            // comments off or closed
            if ($data['status'] != 1) {
                return false;
            }
        }

        if ($comment['date']['created']) {
            $date = strtotime($comment['date']['created']);
        } else {
            $date = time();
        }

        if ($date == -1) {
            $date = time();
        }

        $cid = md5($comment['user']['id'] . $date); // create a unique id

        if (!isset($data['comments'][$parent]) || !is_array($data['comments'][$parent])) {
            $parent = null; // invalid parent comment
        }

        // render the comment
        $xhtml = $this->renderComment($comment['raw']);

        // fill in the new comment
        $data['comments'][$cid] = [
            'user' => $comment['user'],
            'date' => ['created' => $date],
            'raw' => $comment['raw'],
            'xhtml' => $xhtml,
            'parent' => $parent,
            'replies' => [],
            'show' => $comment['show']
        ];

        if ($comment['subscribe']) {
            $mail = $comment['user']['mail'];
            if (isset($data['subscribers'])) {
                if (!$data['subscribers'][$mail]) {
                    $data['subscribers'][$mail]['hash'] = md5($mail . mt_rand());
                    $data['subscribers'][$mail]['active'] = false;
                    $data['subscribers'][$mail]['confirmsent'] = false;
                } else {
                    // convert old style subscribers and set them active
                    if (!is_array($data['subscribers'][$mail])) {
                        $hash = $data['subscribers'][$mail];
                        $data['subscribers'][$mail]['hash'] = $hash;
                        $data['subscribers'][$mail]['active'] = true;
                        $data['subscribers'][$mail]['confirmsent'] = true;
                    }
                }
            } else {
                $data['subscribers'][$mail]['hash'] = md5($mail . mt_rand());
                $data['subscribers'][$mail]['active'] = false;
                $data['subscribers'][$mail]['confirmsent'] = false;
            }
        }

        // update parent comment
        if ($parent) {
            $data['comments'][$parent]['replies'][] = $cid;
        }

        // update the number of comments
        $data['number']++;

        // notify subscribers of the page
        $data['comments'][$cid]['cid'] = $cid;
        $this->notify($data['comments'][$cid], $data['subscribers']);

        // save the comment metadata file
        io_saveFile($file, serialize($data));
        $this->addLogEntry($date, $ID, 'cc', '', $cid);

        $this->redirect($cid);
        return true;
    }

    /**
     * Saves the comment with the given ID and then displays all comments
     *
     * @param array|string $cids array with comment ids to save, or a single string comment id
     * @param string $raw if empty comment is deleted, otherwise edited text is stored (note: storing is per one cid!)
     * @param string|null $act 'toogle', 'show', 'hide', null. If null, it depends on $raw
     * @return bool succeed?
     */
    public function save($cids, $raw, $act = null)
    {
        global $ID, $INPUT;

        if (empty($cids)) return false; // do nothing if we get no comment id

        if ($raw) {
            global $TEXT;

            $otxt = $TEXT; // set $TEXT to comment text for wordblock check
            $TEXT = $raw;

            // spamcheck against the DokuWiki blacklist
            if (checkwordblock()) {
                msg($this->getLang('wordblock'), -1);
                return false;
            }

            $TEXT = $otxt; // restore global $TEXT
        }

        // get discussion meta file name
        $file = metaFN($ID, '.comments');
        $data = unserialize(io_readFile($file, false));

        if (!is_array($cids)) {
            $cids = [$cids];
        }
        foreach ($cids as $cid) {

            if (is_array($data['comments'][$cid]['user'])) {
                $user = $data['comments'][$cid]['user']['id'];
                $convert = false;
            } else {
                $user = $data['comments'][$cid]['user'];
                $convert = true;
            }

            // someone else was trying to edit our comment -> abort
            if ($user != $INPUT->server->str('REMOTE_USER') && !$this->helper->isDiscussionModerator()) {
                return false;
            }

            $date = time();

            // need to convert to new format?
            if ($convert) {
                $data['comments'][$cid]['user'] = [
                    'id' => $user,
                    'name' => $data['comments'][$cid]['name'],
                    'mail' => $data['comments'][$cid]['mail'],
                    'url' => $data['comments'][$cid]['url'],
                    'address' => $data['comments'][$cid]['address'],
                ];
                $data['comments'][$cid]['date'] = [
                    'created' => $data['comments'][$cid]['date']
                ];
            }

            if ($act == 'toogle') {     // toogle visibility
                $now = $data['comments'][$cid]['show'];
                $data['comments'][$cid]['show'] = !$now;
                $data['number'] = $this->countVisibleComments($data);

                $type = ($data['comments'][$cid]['show'] ? 'sc' : 'hc');

            } elseif ($act == 'show') { // show comment
                $data['comments'][$cid]['show'] = true;
                $data['number'] = $this->countVisibleComments($data);

                $type = 'sc'; // show comment

            } elseif ($act == 'hide') { // hide comment
                $data['comments'][$cid]['show'] = false;
                $data['number'] = $this->countVisibleComments($data);

                $type = 'hc'; // hide comment

            } elseif (!$raw) {          // remove the comment
                $data['comments'] = $this->removeComment($cid, $data['comments']);
                $data['number'] = $this->countVisibleComments($data);

                $type = 'dc'; // delete comment

            } else {                   // save changed comment
                $xhtml = $this->renderComment($raw);

                // now change the comment's content
                $data['comments'][$cid]['date']['modified'] = $date;
                $data['comments'][$cid]['raw'] = $raw;
                $data['comments'][$cid]['xhtml'] = $xhtml;

                $type = 'ec'; // edit comment
            }
        }

        // save the comment metadata file
        io_saveFile($file, serialize($data));
        $this->addLogEntry($date, $ID, $type, '', $cid);

        $this->redirect($cid);
        return true;
    }

    /**
     * Recursive function to remove a comment from the data array
     *
     * @param string $cid comment id to be removed
     * @param array $comments array with all comments
     * @return array returns modified array with all remaining comments
     */
    protected function removeComment($cid, $comments)
    {
        if (is_array($comments[$cid]['replies'])) {
            foreach ($comments[$cid]['replies'] as $rid) {
                $comments = $this->removeComment($rid, $comments);
            }
        }
        unset($comments[$cid]);
        return $comments;
    }

    /**
     * Prints an individual comment
     *
     * @param string $cid comment id
     * @param array $data array with all comments by reference
     * @param string $parent comment id of parent
     * @param string $reply comment id on which the user requested a reply
     * @param bool $isVisible is marked as visible
     */
    protected function showCommentWithReplies($cid, &$data, $parent = '', $reply = '', $isVisible = true)
    {
        // comment was removed
        if (!isset($data['comments'][$cid])) {
            return;
        }
        $comment = $data['comments'][$cid];

        // corrupt datatype
        if (!is_array($comment)) {
            return;
        }

        // handle only replies to given parent comment
        if ($comment['parent'] != $parent) {
            return;
        }

        // comment hidden, only shown for moderators
        if (!$comment['show'] && !$this->helper->isDiscussionModerator()) {
            return;
        }

        // print the actual comment
        $this->showComment($cid, $data, $reply, $isVisible);
        // replies to this comment entry?
        $this->showReplies($cid, $data, $reply, $isVisible);
        // reply form
        $this->showReplyForm($cid, $reply);
    }

    /**
     * Print the comment
     *
     * @param string $cid comment id
     * @param array $data array with all comments
     * @param string $reply comment id on which the user requested a reply
     * @param bool $isVisible (grand)parent is marked as visible
     */
    protected function showComment($cid, $data, $reply, $isVisible)
    {
        global $conf, $lang, $HIGH, $INPUT;
        $comment = $data['comments'][$cid];

        //only moderators can arrive here if hidden
        $class = '';
        if (!$comment['show'] || !$isVisible) {
            $class = ' comment_hidden';
        }
        if($cid === $reply) {
            $class .= ' reply';
        }
        // comment head with date and user data
        ptln('<div class="hentry' . $class . '">', 4);
        ptln('<div class="comment_head">', 6);
        ptln('<a name="comment_' . $cid . '" id="comment_' . $cid . '"></a>', 8);
        $head = '<span class="vcard author">';

        // prepare variables
        if (is_array($comment['user'])) { // new format
            $user = $comment['user']['id'];
            $name = $comment['user']['name'];
            $mail = $comment['user']['mail'];
            $url = $comment['user']['url'];
            $address = $comment['user']['address'];
        } else {                         // old format
            $user = $comment['user'];
            $name = $comment['name'];
            $mail = $comment['mail'];
            $url = $comment['url'];
            $address = $comment['address'];
        }
        if (is_array($comment['date'])) { // new format
            $created = $comment['date']['created'];
            $modified = $comment['date']['modified'] ?? null;
        } else {                         // old format
            $created = $comment['date'];
            $modified = $comment['edited'];
        }

        // show username or real name?
        if (!$this->getConf('userealname') && $user) {
            //not logged-in users have currently username set to '', but before 'test<Ipaddress>'
            if(substr($user, 0,4) === 'test'
                && (strpos($user, ':', 4) !== false || strpos($user, '.', 4) !== false)) {
                $showname = $name;
            } else {
                $showname = $user;
            }
        } else {
            $showname = $name;
        }

        // show avatar image?
        if ($this->useAvatar()) {
            $user_data['name'] = $name;
            $user_data['user'] = $user;
            $user_data['mail'] = $mail;
            $align = $lang['direction'] === 'ltr' ? 'left' : 'right';
            $avatar = $this->avatar->getXHTML($user_data, $name, $align);
            if ($avatar) {
                $head .= $avatar;
            }
        }

        if ($this->getConf('linkemail') && $mail) {
            $head .= $this->email($mail, $showname, 'email fn');
        } elseif ($url) {
            $head .= $this->external_link($this->checkURL($url), $showname, 'urlextern url fn');
        } else {
            $head .= '<span class="fn">' . $showname . '</span>';
        }

        if ($address) {
            $head .= ', <span class="adr">' . $address . '</span>';
        }
        $head .= '</span>, ' .
            '<abbr class="published" title="' . strftime('%Y-%m-%dT%H:%M:%SZ', $created) . '">' .
            dformat($created, $conf['dformat']) . '</abbr>';
        if ($modified) {
            $head .= ', <abbr class="updated" title="' .
                strftime('%Y-%m-%dT%H:%M:%SZ', $modified) . '">' . dformat($modified, $conf['dformat']) .
                '</abbr>';
        }
        ptln($head, 8);
        ptln('</div>', 6); // class="comment_head"

        // main comment content
        ptln('<div class="comment_body entry-content"' .
            ($this->useAvatar() ? $this->getWidthStyle() : '') . '>', 6);
        echo ($HIGH ? html_hilight($comment['xhtml'], $HIGH) : $comment['xhtml']) . DOKU_LF;
        ptln('</div>', 6); // class="comment_body"

        if ($isVisible) {
            ptln('<div class="comment_buttons">', 6);

            // show reply button?
            if ($data['status'] == 1 && !$reply && $comment['show']
                && ($this->getConf('allowguests') || $INPUT->server->has('REMOTE_USER'))
                && $this->getConf('usethreading')
            ) {
                $this->showButton($cid, $this->getLang('btn_reply'), 'reply', true);
            }

            // show edit, show/hide and delete button?
            if (($user == $INPUT->server->str('REMOTE_USER') && $user != '') || $this->helper->isDiscussionModerator()) {
                $this->showButton($cid, $lang['btn_secedit'], 'edit', true);
                $label = ($comment['show'] ? $this->getLang('btn_hide') : $this->getLang('btn_show'));
                $this->showButton($cid, $label, 'toogle');
                $this->showButton($cid, $lang['btn_delete'], 'delete');
            }
            ptln('</div>', 6); // class="comment_buttons"
        }
        ptln('</div>', 4); // class="hentry"
    }

    /**
     * If requested by user, show comment form to write a reply
     *
     * @param string $cid current comment id
     * @param string $reply comment id on which the user requested a reply
     */
    protected function showReplyForm($cid, $reply)
    {
        if ($this->getConf('usethreading') && $reply == $cid) {
            ptln('<div class="comment_replies reply">', 4);
            $this->showCommentForm('', 'add', $cid);
            ptln('</div>', 4); // class="comment_replies"
        }
    }

    /**
     * Show the replies to the given comment
     *
     * @param string $cid comment id
     * @param array $data array with all comments by reference
     * @param string $reply comment id on which the user requested a reply
     * @param bool $isVisible is marked as visible by reference
     */
    protected function showReplies($cid, &$data, $reply, &$isVisible)
    {
        $comment = $data['comments'][$cid];
        if (!count($comment['replies'])) {
            return;
        }
        ptln('<div class="comment_replies"' . $this->getWidthStyle() . '>', 4);
        $isVisible = ($comment['show'] && $isVisible);
        foreach ($comment['replies'] as $rid) {
            $this->showCommentWithReplies($rid, $data, $cid, $reply, $isVisible);
        }
        ptln('</div>', 4);
    }

    /**
     * Is an avatar displayed?
     *
     * @return bool
     */
    protected function useAvatar()
    {
        if (is_null($this->useAvatar)) {
            $this->useAvatar = $this->getConf('useavatar')
                && ($this->avatar = $this->loadHelper('avatar', false));
        }
        return $this->useAvatar;
    }

    /**
     * Calculate width of indent
     *
     * @return string
     */
    protected function getWidthStyle()
    {
        global $lang;

        if (is_null($this->style)) {
            $side = $lang['direction'] === 'ltr' ? 'left' : 'right';

            if ($this->useAvatar()) {
                $this->style = ' style="margin-' . $side . ': ' . ($this->avatar->getConf('size') + 14) . 'px;"';
            } else {
                $this->style = ' style="margin-' . $side . ': 20px;"';
            }
        }
        return $this->style;
    }

    /**
     * Show the button which toggles between show/hide of the entire discussion section
     */
    protected function showDiscussionToggleButton()
    {
        ptln('<div id="toggle_button" class="toggle_button">');
        ptln('<input type="submit" id="discussion__btn_toggle_visibility" title="Toggle Visibiliy" class="button"'
            . 'value="' . $this->getLang('toggle_display') . '">');
        ptln('</div>');
    }

    /**
     * Outputs the comment form
     *
     * @param string $raw the existing comment text in case of edit
     * @param string $act action 'add' or 'save'
     * @param string|null $cid comment id to be responded to or null
     */
    protected function showCommentForm($raw, $act, $cid = null)
    {
        global $lang, $conf, $ID, $INPUT;

        // not for unregistered users when guest comments aren't allowed
        if (!$INPUT->server->has('REMOTE_USER') && !$this->getConf('allowguests')) {
            ?>
            <div class="comment_form">
                <?php echo $this->getLang('noguests'); ?>
            </div>
            <?php
            return;
        }

        // fill $raw with $INPUT->str('text') if it's empty (for failed CAPTCHA check)
        if (!$raw && $INPUT->str('comment') == 'show') {
            $raw = $INPUT->str('text');
        }
        ?>

        <div class="comment_form">
            <form id="discussion__comment_form" method="post" action="<?php echo script() ?>"
                  accept-charset="<?php echo $lang['encoding'] ?>">
                <div class="no">
                    <input type="hidden" name="id" value="<?php echo $ID ?>"/>
                    <input type="hidden" name="do" value="show"/>
                    <input type="hidden" name="comment" value="<?php echo $act ?>"/>
                    <?php
                    // for adding a comment
                    if ($act == 'add') {
                        ?>
                        <input type="hidden" name="reply" value="<?php echo $cid ?>"/>
                        <?php
                        // for guest/adminimport: show name, e-mail and subscribe to comments fields
                        if (!$INPUT->server->has('REMOTE_USER') or ($this->getConf('adminimport') && $this->helper->isDiscussionModerator())) {
                            ?>
                            <input type="hidden" name="user" value=""/>
                            <div class="comment_name">
                                <label class="block" for="discussion__comment_name">
                                    <span><?php echo $lang['fullname'] ?>:</span>
                                    <input type="text"
                                           class="edit<?php if ($INPUT->str('comment') == 'add' && empty($INPUT->str('name'))) echo ' error' ?>"
                                           name="name" id="discussion__comment_name" size="50" tabindex="1"
                                           value="<?php echo hsc($INPUT->str('name')) ?>"/>
                                </label>
                            </div>
                            <div class="comment_mail">
                                <label class="block" for="discussion__comment_mail">
                                    <span><?php echo $lang['email'] ?>:</span>
                                    <input type="text"
                                           class="edit<?php if ($INPUT->str('comment') == 'add' && empty($INPUT->str('mail'))) echo ' error' ?>"
                                           name="mail" id="discussion__comment_mail" size="50" tabindex="2"
                                           value="<?php echo hsc($INPUT->str('mail')) ?>"/>
                                </label>
                            </div>
                            <?php
                        }

                        // allow entering an URL
                        if ($this->getConf('urlfield')) {
                            ?>
                            <div class="comment_url">
                                <label class="block" for="discussion__comment_url">
                                    <span><?php echo $this->getLang('url') ?>:</span>
                                    <input type="text" class="edit" name="url" id="discussion__comment_url" size="50"
                                           tabindex="3" value="<?php echo hsc($INPUT->str('url')) ?>"/>
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
                                    <input type="text" class="edit" name="address" id="discussion__comment_address"
                                           size="50" tabindex="4" value="<?php echo hsc($INPUT->str('address')) ?>"/>
                                </label>
                            </div>
                            <?php
                        }

                        // allow setting the comment date
                        if ($this->getConf('adminimport') && ($this->helper->isDiscussionModerator())) {
                            ?>
                            <div class="comment_date">
                                <label class="block" for="discussion__comment_date">
                                    <span><?php echo $this->getLang('date') ?>:</span>
                                    <input type="text" class="edit" name="date" id="discussion__comment_date"
                                           size="50"/>
                                </label>
                            </div>
                            <?php
                        }

                        // for saving a comment
                    } else {
                        ?>
                        <input type="hidden" name="cid" value="<?php echo $cid ?>"/>
                        <?php
                    }
                    ?>
                    <div class="comment_text">
                        <?php echo $this->getLang('entercomment');
                        echo($this->getConf('wikisyntaxok') ? "" : ":");
                        if ($this->getConf('wikisyntaxok')) echo '. ' . $this->getLang('wikisyntax') . ':'; ?>

                        <!-- Fix for disable the toolbar when wikisyntaxok is set to false. See discussion's script.jss -->
                        <?php if ($this->getConf('wikisyntaxok')) { ?>
                        <div id="discussion__comment_toolbar" class="toolbar group">
                            <?php } else { ?>
                            <div id="discussion__comment_toolbar_disabled">
                                <?php } ?>
                            </div>
                            <textarea
                                class="edit<?php if ($INPUT->str('comment') == 'add' && empty($INPUT->str('text'))) echo ' error' ?>"
                                name="text" cols="80" rows="10" id="discussion__comment_text" tabindex="5"><?php
                                if ($raw) {
                                    echo formText($raw);
                                } else {
                                    echo hsc($INPUT->str('text'));
                                }
                                ?></textarea>
                        </div>

                        <?php
                        /** @var helper_plugin_captcha $captcha */
                        $captcha = $this->loadHelper('captcha', false);
                        if ($captcha && $captcha->isEnabled()) {
                            echo $captcha->getHTML();
                        }

                        /** @var helper_plugin_recaptcha $recaptcha */
                        $recaptcha = $this->loadHelper('recaptcha', false);
                        if ($recaptcha && $recaptcha->isEnabled()) {
                            echo $recaptcha->getHTML();
                        }
                        ?>

                        <input class="button comment_submit" id="discussion__btn_submit" type="submit" name="submit"
                               accesskey="s" value="<?php echo $lang['btn_save'] ?>"
                               title="<?php echo $lang['btn_save'] ?> [S]" tabindex="7"/>
                        <?php
                        //if enabled, let not logged-in users subscribe, and logged-in only if no page-subcriptions are used
                        if ((!$INPUT->server->has('REMOTE_USER')
                                || $INPUT->server->has('REMOTE_USER') && !$conf['subscribers'])
                            && $this->getConf('subscribe')) { ?>
                            <label class="nowrap" for="discussion__comment_subscribe">
                                <input type="checkbox" id="discussion__comment_subscribe" name="subscribe"
                                       tabindex="6"/>
                                <span><?php echo $this->getLang('subscribe') ?></span>
                            </label>
                        <?php } ?>
                        <input class="button comment_preview_button" id="discussion__btn_preview" type="button"
                               name="preview" accesskey="p" value="<?php echo $lang['btn_preview'] ?>"
                               title="<?php echo $lang['btn_preview'] ?> [P]"/>
                        <?php if ($cid) { ?>
                            <a class="button comment_cancel" href="<?php echo wl($ID) . '#comment_' . $cid ?>" ><?php echo $lang['btn_cancel'] ?></a>
                        <?php } ?>

                        <div class="clearer"></div>
                        <div id="discussion__comment_preview">&nbsp;</div>
                    </div>
            </form>
        </div>
        <?php
    }

    /**
     * Action button below a comment
     *
     * @param string $cid comment id
     * @param string $label translated label
     * @param string $act action
     * @param bool $jump whether to scroll to the commentform
     */
    protected function showButton($cid, $label, $act, $jump = false)
    {
        global $ID;

        $anchor = ($jump ? '#discussion__comment_form' : '');

        $submitClass = '';
        if($act === 'delete') {
            $submitClass = ' dcs_confirmdelete';
        }
        ?>
        <form class="button discussion__<?php echo $act ?>" method="get" action="<?php echo script() . $anchor ?>">
            <div class="no">
                <input type="hidden" name="id" value="<?php echo $ID ?>"/>
                <input type="hidden" name="do" value="show"/>
                <input type="hidden" name="comment" value="<?php echo $act ?>"/>
                <input type="hidden" name="cid" value="<?php echo $cid ?>"/>
                <input type="submit" value="<?php echo $label ?>" class="button<?php echo $submitClass ?>" title="<?php echo $label ?>"/>
            </div>
        </form>
        <?php
    }

    /**
     * Adds an entry to the comments changelog
     *
     * @param int $date
     * @param string $id page id
     * @param string $type create/edit/delete/show/hide comment 'cc', 'ec', 'dc', 'sc', 'hc'
     * @param string $summary
     * @param string $extra
     * @author Ben Coburn <btcoburn@silicodon.net>
     *
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    protected function addLogEntry($date, $id, $type = 'cc', $summary = '', $extra = '')
    {
        global $conf, $INPUT;

        $changelog = $conf['metadir'] . '/_comments.changes';

        //use current time if none supplied
        if (!$date) {
            $date = time();
        }
        $remote = $INPUT->server->str('REMOTE_ADDR');
        $user = $INPUT->server->str('REMOTE_USER');

        $strip = ["\t", "\n"];
        $logline = [
            'date' => $date,
            'ip' => $remote,
            'type' => str_replace($strip, '', $type),
            'id' => $id,
            'user' => $user,
            'sum' => str_replace($strip, '', $summary),
            'extra' => str_replace($strip, '', $extra)
        ];

        // add changelog line
        $logline = implode("\t", $logline) . "\n";
        io_saveFile($changelog, $logline, true); //global changelog cache
        $this->trimRecentCommentsLog($changelog);

        // tell the indexer to re-index the page
        @unlink(metaFN($id, '.indexed'));
    }

    /**
     * Trims the recent comments cache to the last $conf['changes_days'] recent
     * changes or $conf['recent'] items, which ever is larger.
     * The trimming is only done once a day.
     *
     * @param string $changelog file path
     * @return bool
     * @author Ben Coburn <btcoburn@silicodon.net>
     *
     */
    protected function trimRecentCommentsLog($changelog)
    {
        global $conf;

        if (@file_exists($changelog)
            && (filectime($changelog) + 86400) < time()
            && !@file_exists($changelog . '_tmp')
        ) {

            io_lock($changelog);
            $lines = file($changelog);
            if (count($lines) < $conf['recent']) {
                // nothing to trim
                io_unlock($changelog);
                return true;
            }

            // presave tmp as 2nd lock
            io_saveFile($changelog . '_tmp', '');
            $trim_time = time() - $conf['recent_days'] * 86400;
            $out_lines = [];

            $num = count($lines);
            for ($i = 0; $i < $num; $i++) {
                $log = parseChangelogLine($lines[$i]);
                if ($log === false) continue;                      // discard junk
                if ($log['date'] < $trim_time) {
                    $old_lines[$log['date'] . ".$i"] = $lines[$i]; // keep old lines for now (append .$i to prevent key collisions)
                } else {
                    $out_lines[$log['date'] . ".$i"] = $lines[$i]; // definitely keep these lines
                }
            }

            // sort the final result, it shouldn't be necessary,
            // however the extra robustness in making the changelog cache self-correcting is worth it
            ksort($out_lines);
            $extra = $conf['recent'] - count($out_lines);        // do we need extra lines do bring us up to minimum
            if ($extra > 0) {
                ksort($old_lines);
                $out_lines = array_merge(array_slice($old_lines, -$extra), $out_lines);
            }

            // save trimmed changelog
            io_saveFile($changelog . '_tmp', implode('', $out_lines));
            @unlink($changelog);
            if (!rename($changelog . '_tmp', $changelog)) {
                // rename failed so try another way...
                io_unlock($changelog);
                io_saveFile($changelog, implode('', $out_lines));
                @unlink($changelog . '_tmp');
            } else {
                io_unlock($changelog);
            }
            return true;
        }
        return true;
    }

    /**
     * Sends a notify mail on new comment
     *
     * @param array $comment data array of the new comment
     * @param array $subscribers data of the subscribers by reference
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    protected function notify($comment, &$subscribers)
    {
        global $conf, $ID, $INPUT, $auth;

        $notify_text = io_readfile($this->localfn('subscribermail'));
        $confirm_text = io_readfile($this->localfn('confirmsubscribe'));
        $subject_notify = '[' . $conf['title'] . '] ' . $this->getLang('mail_newcomment');
        $subject_subscribe = '[' . $conf['title'] . '] ' . $this->getLang('subscribe');

        $mailer = new Mailer();
        if (!$INPUT->server->has('REMOTE_USER')) {
            $mailer->from($conf['mailfromnobody']);
        }

        $replace = [
            'PAGE' => $ID,
            'TITLE' => $conf['title'],
            'DATE' => dformat($comment['date']['created'], $conf['dformat']),
            'NAME' => $comment['user']['name'],
            'TEXT' => $comment['raw'],
            'COMMENTURL' => wl($ID, '', true) . '#comment_' . $comment['cid'],
            'UNSUBSCRIBE' => wl($ID, 'do=subscribe', true, '&'),
            'DOKUWIKIURL' => DOKU_URL
        ];

        $confirm_replace = [
            'PAGE' => $ID,
            'TITLE' => $conf['title'],
            'DOKUWIKIURL' => DOKU_URL
        ];


        $mailer->subject($subject_notify);
        $mailer->setBody($notify_text, $replace);

        // send mail to notify address
        if ($conf['notify']) {
            $mailer->bcc($conf['notify']);
            $mailer->send();
        }

        // send email to moderators
        if ($this->getConf('moderatorsnotify')) {
            $moderatorgrpsString = trim($this->getConf('moderatorgroups'));
            if (!empty($moderatorgrpsString)) {
                // create a clean mods list
                $moderatorgroups = explode(',', $moderatorgrpsString);
                $moderatorgroups = array_map('trim', $moderatorgroups);
                $moderatorgroups = array_unique($moderatorgroups);
                $moderatorgroups = array_filter($moderatorgroups);
                // search for moderators users
                foreach ($moderatorgroups as $moderatorgroup) {
                    if (!$auth->isCaseSensitive()) {
                        $moderatorgroup = PhpString::strtolower($moderatorgroup);
                    }
                    // create a clean mailing list
                    $bccs = [];
                    if ($moderatorgroup[0] == '@') {
                        foreach ($auth->retrieveUsers(0, 0, ['grps' => $auth->cleanGroup(substr($moderatorgroup, 1))]) as $user) {
                            if (!empty($user['mail'])) {
                                $bccs[] = $user['mail'];
                            }
                        }
                    } else {
                        //it is an user
                        $userdata = $auth->getUserData($auth->cleanUser($moderatorgroup));
                        if (!empty($userdata['mail'])) {
                            $bccs[] = $userdata['mail'];
                        }
                    }
                    $bccs = array_unique($bccs);
                    // notify the users
                    $mailer->bcc(implode(',', $bccs));
                    $mailer->send();
                }
            }
        }

        // notify page subscribers
        if (actionOK('subscribe')) {
            $data = ['id' => $ID, 'addresslist' => '', 'self' => false];
            //FIXME default callback, needed to mentioned it again?
            Event::createAndTrigger(
                'COMMON_NOTIFY_ADDRESSLIST', $data,
                [new SubscriberManager(), 'notifyAddresses']
            );

            $to = $data['addresslist'];
            if (!empty($to)) {
                $mailer->bcc($to);
                $mailer->send();
            }
        }

        // notify comment subscribers
        if (!empty($subscribers)) {

            foreach ($subscribers as $mail => $data) {
                $mailer->bcc($mail);
                if ($data['active']) {
                    $replace['UNSUBSCRIBE'] = wl($ID, 'do=discussion_unsubscribe&hash=' . $data['hash'], true, '&');

                    $mailer->subject($subject_notify);
                    $mailer->setBody($notify_text, $replace);
                    $mailer->send();
                } elseif (!$data['confirmsent']) {
                    $confirm_replace['SUBSCRIBE'] = wl($ID, 'do=discussion_confirmsubscribe&hash=' . $data['hash'], true, '&');

                    $mailer->subject($subject_subscribe);
                    $mailer->setBody($confirm_text, $confirm_replace);
                    $mailer->send();
                    $subscribers[$mail]['confirmsent'] = true;
                }
            }
        }
    }

    /**
     * Counts the number of visible comments
     *
     * @param array $data array with all comments
     * @return int
     */
    protected function countVisibleComments($data)
    {
        $number = 0;
        foreach ($data['comments'] as $comment) {
            if ($comment['parent']) continue;
            if (!$comment['show']) continue;

            $number++;
            $rids = $comment['replies'];
            if (count($rids)) {
                $number = $number + $this->countVisibleReplies($data, $rids);
            }
        }
        return $number;
    }

    /**
     * Count visible replies on the comments
     *
     * @param array $data
     * @param array $rids
     * @return int counted replies
     */
    protected function countVisibleReplies(&$data, $rids)
    {
        $number = 0;
        foreach ($rids as $rid) {
            if (!isset($data['comments'][$rid])) continue; // reply was removed
            if (!$data['comments'][$rid]['show']) continue;

            $number++;
            $rids = $data['comments'][$rid]['replies'];
            if (count($rids)) {
                $number = $number + $this->countVisibleReplies($data, $rids);
            }
        }
        return $number;
    }

    /**
     * Renders the raw comment (wiki)text to html
     *
     * @param string $raw comment text
     * @return null|string
     */
    protected function renderComment($raw)
    {
        if ($this->getConf('wikisyntaxok')) {
            // Note the warning for render_text:
            //   "very ineffecient for small pieces of data - try not to use"
            // in dokuwiki/inc/plugin.php
            $xhtml = $this->render_text($raw);
        } else { // wiki syntax not allowed -> just encode special chars
            $xhtml = hsc(trim($raw));
            $xhtml = str_replace("\n", '<br />', $xhtml);
        }
        return $xhtml;
    }

    /**
     * Finds out whether there is a discussion section for the current page
     *
     * @param string $title set to title from metadata or empty string
     * @return bool discussion section is shown?
     */
    protected function hasDiscussion(&$title)
    {
        global $ID;

        $file = metaFN($ID, '.comments');

        if (!@file_exists($file)) {
            if ($this->isDiscussionEnabled()) {
                return true;
            } else {
                return false;
            }
        }

        $data = unserialize(io_readFile($file, false));

        $title = $data['title'] ?? '';

        $num = $data['number'] ?? 0;
        if (!$data['status'] || ($data['status'] == 2 && $num == 0)) {
            //disabled, or closed and no comments
            return false;
        } else {
            return true;
        }
    }

    /**
     * Creates a new thread page
     *
     * @return string
     */
    protected function newThread()
    {
        global $ID, $INFO, $INPUT;

        $ns = cleanID($INPUT->str('ns'));
        $title = str_replace(':', '', $INPUT->str('title'));
        $back = $ID;
        $ID = ($ns ? $ns . ':' : '') . cleanID($title);
        $INFO = pageinfo();

        // check if we are allowed to create this file
        if ($INFO['perm'] >= AUTH_CREATE) {

            //check if locked by anyone - if not lock for my self
            if ($INFO['locked']) {
                return 'locked';
            } else {
                lock($ID);
            }

            // prepare the new thread file with default stuff
            if (!@file_exists($INFO['filepath'])) {
                global $TEXT;

                $TEXT = pageTemplate(($ns ? $ns . ':' : '') . $title);
                if (!$TEXT) {
                    $data = ['id' => $ID, 'ns' => $ns, 'title' => $title, 'back' => $back];
                    $TEXT = $this->pageTemplate($data);
                }
                return 'preview';
            } else {
                return 'edit';
            }
        } else {
            return 'show';
        }
    }

    /**
     * Adapted version of pageTemplate() function
     *
     * @param array $data
     * @return string
     */
    protected function pageTemplate($data)
    {
        global $conf, $INFO, $INPUT;

        $id = $data['id'];
        $user = $INPUT->server->str('REMOTE_USER');
        $tpl = io_readFile(DOKU_PLUGIN . 'discussion/_template.txt');

        // standard replacements
        $replace = [
            '@NS@' => $data['ns'],
            '@PAGE@' => strtr(noNS($id), '_', ' '),
            '@USER@' => $user,
            '@NAME@' => $INFO['userinfo']['name'],
            '@MAIL@' => $INFO['userinfo']['mail'],
            '@DATE@' => dformat(time(), $conf['dformat']),
        ];

        // additional replacements
        $replace['@BACK@'] = $data['back'];
        $replace['@TITLE@'] = $data['title'];

        // avatar if useavatar and avatar plugin available
        if ($this->getConf('useavatar') && !plugin_isdisabled('avatar')) {
            $replace['@AVATAR@'] = '{{avatar>' . $user . ' }} ';
        } else {
            $replace['@AVATAR@'] = '';
        }

        // tag if tag plugin is available
        if (!plugin_isdisabled('tag')) {
            $replace['@TAG@'] = "\n\n{{tag>}}";
        } else {
            $replace['@TAG@'] = '';
        }

        // perform the replacements in tpl
        return str_replace(array_keys($replace), array_values($replace), $tpl);
    }

    /**
     * Checks if the CAPTCHA string submitted is valid, modifies action if needed
     */
    protected function captchaCheck()
    {
        global $INPUT;
        /** @var helper_plugin_captcha $captcha */
        if (!$captcha = $this->loadHelper('captcha', false)) {
            // CAPTCHA is disabled or not available
            return;
        }

        if ($captcha->isEnabled() && !$captcha->check()) {
            if ($INPUT->str('comment') == 'save') {
                $INPUT->set('comment', 'edit');
            } elseif ($INPUT->str('comment') == 'add') {
                $INPUT->set('comment', 'show');
            }
        }
    }

    /**
     * checks if the submitted reCAPTCHA string is valid, modifies action if needed
     *
     * @author Adrian Schlegel <adrian@liip.ch>
     */
    protected function recaptchaCheck()
    {
        global $INPUT;
        /** @var helper_plugin_recaptcha $recaptcha */
        if (!$recaptcha = plugin_load('helper', 'recaptcha'))
            return; // reCAPTCHA is disabled or not available

        // do nothing if logged in user and no reCAPTCHA required
        if (!$recaptcha->getConf('forusers') && $INPUT->server->has('REMOTE_USER')) return;

        $response = $recaptcha->check();
        if (!$response->is_valid) {
            msg($recaptcha->getLang('testfailed'), -1);
            if ($INPUT->str('comment') == 'save') {
                $INPUT->str('comment', 'edit');
            } elseif ($INPUT->str('comment') == 'add') {
                $INPUT->str('comment', 'show');
            }
        }
    }

    /**
     * Add discussion plugin version to the indexer version
     * This means that all pages will be indexed again in order to add the comments
     * to the index whenever there has been a change that concerns the index content.
     *
     * @param Doku_Event $event
     */
    public function addIndexVersion(Doku_Event $event)
    {
        $event->data['discussion'] = '0.1';
    }

    /**
     * Adds the comments to the index
     *
     * @param Doku_Event $event
     * @param array $param with
     *  'id' => string 'page'/'id' for respectively INDEXER_PAGE_ADD and FULLTEXT_SNIPPET_CREATE event
     *  'text' => string 'body'/'text'
     */
    public function addCommentsToIndex(Doku_Event $event, $param)
    {
        // get .comments meta file name
        $file = metaFN($event->data[$param['id']], '.comments');

        if (!@file_exists($file)) return;
        $data = unserialize(io_readFile($file, false));

        // comments are turned off or no comments available to index
        if (!$data['status'] || $data['number'] == 0) return;

        // now add the comments
        if (isset($data['comments'])) {
            foreach ($data['comments'] as $key => $value) {
                $event->data[$param['text']] .= DOKU_LF . $this->addCommentWords($key, $data);
            }
        }
    }

    /**
     * Checks if the phrase occurs in the comments and return event result true if matching
     *
     * @param Doku_Event $event
     */
    public function fulltextPhraseMatchInComments(Doku_Event $event)
    {
        if ($event->result === true) return;

        // get .comments meta file name
        $file = metaFN($event->data['id'], '.comments');

        if (!@file_exists($file)) return;
        $data = unserialize(io_readFile($file, false));

        // comments are turned off or no comments available to match
        if (!$data['status'] || $data['number'] == 0) return;

        $matched = false;

        // now add the comments
        if (isset($data['comments'])) {
            foreach ($data['comments'] as $cid => $value) {
                $matched = $this->phraseMatchInComment($event->data['phrase'], $cid, $data);
                if ($matched) break;
            }
        }

        if ($matched) {
            $event->result = true;
        }
    }

    /**
     * Match the phrase in the comment and its replies
     *
     * @param string $phrase phrase to search
     * @param string $cid comment id
     * @param array $data array with all comments by reference
     * @param string $parent cid of parent
     * @return bool if match true, otherwise false
     */
    protected function phraseMatchInComment($phrase, $cid, &$data, $parent = '')
    {
        if (!isset($data['comments'][$cid])) return false; // comment was removed

        $comment = $data['comments'][$cid];

        if (!is_array($comment)) return false;             // corrupt datatype
        if ($comment['parent'] != $parent) return false;   // reply to an other comment
        if (!$comment['show']) return false;               // hidden comment

        $text = PhpString::strtolower($comment['raw']);
        if (strpos($text, $phrase) !== false) {
            return true;
        }

        if (is_array($comment['replies'])) {               // and the replies
            foreach ($comment['replies'] as $rid) {
                if ($this->phraseMatchInComment($phrase, $rid, $data, $cid)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Saves the current comment status and title from metadata into the .comments file
     *
     * @param Doku_Event $event
     */
    public function updateCommentStatusFromMetadata(Doku_Event $event)
    {
        global $ID;

        $meta = $event->data['current'];

        $file = metaFN($ID, '.comments');
        $configurationStatus = ($this->isDiscussionEnabled() ? 1 : 0); // 0=off, 1=enabled
        $title = null;
        if (isset($meta['plugin_discussion'])) {
            $status = (int) $meta['plugin_discussion']['status']; // 0=off, 1=enabled or 2=closed
            $title = $meta['plugin_discussion']['title'];

            // do we have metadata that differs from general config?
            $saveNeededFromMetadata = $configurationStatus !== $status || ($status > 0 && $title);
        } else {
            $status = $configurationStatus;
            $saveNeededFromMetadata = false;
        }

        // if .comment file exists always update it with latest status
        if ($saveNeededFromMetadata || file_exists($file)) {

            $data = [];
            if (@file_exists($file)) {
                $data = unserialize(io_readFile($file, false));
            }

            if (!array_key_exists('title', $data) || $data['title'] !== $title || !isset($data['status']) || $data['status'] !== $status) {
                //title can be only set from metadata
                $data['title'] = $title;
                $data['status'] = $status;
                if (!isset($data['number'])) {
                    $data['number'] = 0;
                }
                io_saveFile($file, serialize($data));
            }
        }
    }

    /**
     * Return words of a given comment and its replies, suitable to be added to the index
     *
     * @param string $cid comment id
     * @param array $data array with all comments by reference
     * @param string $parent cid of parent
     * @return string
     */
    protected function addCommentWords($cid, &$data, $parent = '')
    {

        if (!isset($data['comments'][$cid])) return ''; // comment was removed

        $comment = $data['comments'][$cid];

        if (!is_array($comment)) return '';             // corrupt datatype
        if ($comment['parent'] != $parent) return '';   // reply to an other comment
        if (!$comment['show']) return '';               // hidden comment

        $text = $comment['raw'];                        // we only add the raw comment text
        if (is_array($comment['replies'])) {            // and the replies
            foreach ($comment['replies'] as $rid) {
                $text .= $this->addCommentWords($rid, $data, $cid);
            }
        }
        return ' ' . $text;
    }

    /**
     * Only allow http(s) URLs and append http:// to URLs if needed
     *
     * @param string $url
     * @return string
     */
    protected function checkURL($url)
    {
        if (preg_match("#^http://|^https://#", $url)) {
            return hsc($url);
        } elseif (substr($url, 0, 4) == 'www.') {
            return hsc('https://' . $url);
        } else {
            return '';
        }
    }

    /**
     * Sort threads
     *
     * @param array $a array with comment properties
     * @param array $b array with comment properties
     * @return int
     */
    function sortThreadsOnCreation($a, $b)
    {
        if (is_array($a['date'])) {
            // new format
            $createdA = $a['date']['created'];
        } else {
            // old format
            $createdA = $a['date'];
        }

        if (is_array($b['date'])) {
            // new format
            $createdB = $b['date']['created'];
        } else {
            // old format
            $createdB = $b['date'];
        }

        if ($createdA == $createdB) {
            return 0;
        } else {
            return ($createdA < $createdB) ? -1 : 1;
        }
    }

}


