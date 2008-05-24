<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'action.php');

class action_plugin_discussion extends DokuWiki_Action_Plugin{

  function getInfo(){
    return array(
      'author' => 'Gina Häußge, Michael Klier, Esther Brunner',
      'email'  => 'dokuwiki@chimeric.de',
      'date'   => '2008-04-20',
      'name'   => 'Discussion Plugin (action component)',
      'desc'   => 'Enables discussion features',
      'url'    => 'http://wiki.splitbrain.org/plugin:discussion',
    );
  }

  function register(&$contr){
    $contr->register_hook(
      'ACTION_ACT_PREPROCESS',
      'BEFORE',
      $this,
      'handle_act_preprocess',
      array()
    );
    $contr->register_hook(
      'TPL_ACT_RENDER',
      'AFTER',
      $this,
      'comments',
      array()
    );
    $contr->register_hook(
      'RENDERER_CONTENT_POSTPROCESS',
      'AFTER',
      $this,
      'add_toc_item',
      array()
    );
    $contr->register_hook(
      'INDEXER_PAGE_ADD',
      'AFTER',
      $this,
      'idx_add_discussion',
      array()
    );
  }
    
  /**
   * Handles comment actions, dispatches data processing routines
   */
  function handle_act_preprocess(&$event, $param) {
      
    // handle newthread ACTs
    if ($event->data == 'newthread'){
      // we can handle it -> prevent others
      // $event->stopPropagation();
      $event->preventDefault();
      
      $event->data = $this->_newThread();
    }
    
    // enable captchas
    if ((in_array($_REQUEST['comment'], array('add', 'save')))
      && (@file_exists(DOKU_PLUGIN.'captcha/action.php'))){
      $this->_captchaCheck();
    }

	// if we are not in show mode, that was all for now    
    if ($event->data != 'show') return;
    
    // do the data processing for comments
    $cid  = $_REQUEST['cid'];  
    switch ($_REQUEST['comment']){
      case 'add':
        $comment = array(
          'user'    => array(
            'id'      => hsc($_REQUEST['user']),
            'name'    => hsc($_REQUEST['name']),
            'mail'    => hsc($_REQUEST['mail']),
            'url'     => hsc($_REQUEST['url']),
            'address' => hsc($_REQUEST['address'])),
          'date'    => array('created' => $_REQUEST['date']),
          'raw'     => cleanText($_REQUEST['text'])
        );
        $repl = $_REQUEST['reply'];
        $this->_add($comment, $repl);
        break;
      
      case 'save':
        $raw  = cleanText($_REQUEST['text']);
        $this->_save(array($cid), $raw);
        break;
        
      case 'delete':
        $this->_save(array($cid), '');
        break;
        
      case 'toogle':
        $this->_save(array($cid), '', 'toogle');
        break;
    }
  }
  
  /**
   * Main function; dispatches the visual comment actions
   */
  function comments(&$event, $param){
    if ($event->data != 'show') return; // nothing to do for us
    
    $cid  = $_REQUEST['cid'];  
    switch ($_REQUEST['comment']){
      case 'edit':
        $this->_show(NULL, $cid);
        break;

      default: // 'show' => $this->_show(), 'reply' => $this->_show($cid)
        $this->_show($cid);
    }
  }
  
  /**
   * Redirects browser to given comment anchor
   */
  function _redirect($cid) {
      global $ID;
      global $ACT;
      
      if ($ACT !== 'show') return;
   	  header('Location: ' . wl($ID) . '#comment__' . $cid);
  }
  
  /**
   * Shows all comments of the current page
   */
  function _show($reply = NULL, $edit = NULL){
    global $ID, $INFO, $ACT;
    
    if ($ACT !== 'show') return false;
    
    // get .comments meta file name
    $file = metaFN($ID, '.comments');
    
    if (!@file_exists($file)){
      // create .comments meta file if automatic setting is switched on
      if ($this->getConf('automatic') && $INFO['exists']){
        $data = array('status' => 1, 'number' => 0);
        io_saveFile($file, serialize($data));
      }
    } else { // load data
      $data = unserialize(io_readFile($file, false));
    }
        
    if (!$data['status']) return false; // comments are turned off
        
    // section title
    $title = ($data['title'] ? hsc($data['title']) : $this->getLang('discussion'));
    ptln('<div class="comment_wrapper">');
    ptln('<h2><a name="discussion__section" id="discussion__section">', 2);
    ptln($title, 4);
    ptln('</a></h2>', 2);
    ptln('<div class="level2 hfeed">', 2);
        
    // now display the comments
    if (isset($data['comments'])){
      foreach ($data['comments'] as $key => $value){
        if ($key == $edit) $this->_form($value['raw'], 'save', $edit); // edit form
        else $this->_print($key, $data, '', $reply);
      }
    }
    
    // comment form
    if (($data['status'] == 1) && !$reply && !$edit) $this->_form('');
    
    ptln('</div>', 2); // level2 hfeed
    ptln('</div>'); // comment_wrapper
    
    return true;
  }
  
  /**
   * Adds a new comment and then displays all comments
   */
  function _add($comment, $parent){
    global $ID, $TEXT;

    $otxt = $TEXT; // set $TEXT to comment text for wordblock check
    $TEXT = $comment['raw'];
    
    // spamcheck against the DokuWiki blacklist
    if (checkwordblock()){
      msg($this->getLang('wordblock'), -1);
      return false;
    }
    
    $TEXT = $otxt; // restore global $TEXT
    
    // get discussion meta file name
    $file = metaFN($ID, '.comments');
    
    $data = array();
    $data = unserialize(io_readFile($file, false));
    
    if ($data['status'] != 1) return false;                // comments off or closed
    if ((!$this->getConf('allowguests'))
      && ($comment['user']['id'] != $_SERVER['REMOTE_USER']))
      return false;                                        // guest comments not allowed 
    
    if ($comment['date']['created']) $date = strtotime($comment['date']['created']);
    else $date = time();
    if ($date == -1) $date = time();
    $cid  = md5($comment['user']['id'].$date);             // create a unique id
    
    if (!is_array($data['comments'][$parent])) $parent = NULL; // invalid parent comment
    
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
    );
    
    // update parent comment
    if ($parent) $data['comments'][$parent]['replies'][] = $cid;
    
    // update the number of comments
    $data['number']++;
        
    // save the comment metadata file
    io_saveFile($file, serialize($data));
    $this->_addLogEntry($date, $ID, 'cc', '', $cid);
    
    // notify subscribers of the page
    $this->_notify($data['comments'][$cid]);
  
    $this->_redirect($cid);
    return true;
  }
    
  /**
   * Saves the comment with the given ID and then displays all comments
   */
  function _save($cids, $raw, $act = NULL){
    global $ID;
    
    if ($raw){
      global $TEXT;
          
      $otxt = $TEXT; // set $TEXT to comment text for wordblock check
      $TEXT = $raw;
      
      // spamcheck against the DokuWiki blacklist
      if (checkwordblock()){
        msg($this->getLang('wordblock'), -1);
        return false;
      }
      
      $TEXT = $otxt; // restore global $TEXT
    }
    
    // get discussion meta file name
    $file = metaFN($ID, '.comments');
    $data = unserialize(io_readFile($file, false));
    
    if (!is_array($cids)) $cids = array($cids);
    foreach ($cids as $cid){
    
      if (is_array($data['comments'][$cid]['user'])){
        $user    = $data['comments'][$cid]['user']['id'];
        $convert = false;
      } else {
        $user    = $data['comments'][$cid]['user'];
        $convert = true;
      }
          
      // someone else was trying to edit our comment -> abort
      if (($user != $_SERVER['REMOTE_USER']) && (!auth_ismanager())) return false;
        
      $date = time();
      
      // need to convert to new format?
      if ($convert){
        $data['comments'][$cid]['user'] = array(
          'id'      => $user,
          'name'    => $data['comments'][$cid]['name'],
          'mail'    => $data['comments'][$cid]['mail'],
          'url'     => $data['comments'][$cid]['url'],
          'address' => $data['comments'][$cid]['address'],
        );
        $data['comments'][$cid]['date'] = array(
          'created' => $data['comments'][$cid]['date']
        );
      }
      
      if ($act == 'toogle'){     // toogle visibility
        $now = $data['comments'][$cid]['show'];
        $data['comments'][$cid]['show'] = !$now;
        $data['number'] = $this->_count($data);
        
        $type = ($data['comments'][$cid]['show'] ? 'sc' : 'hc');
      
      } elseif ($act == 'show'){ // show comment
        $data['comments'][$cid]['show'] = true;
        $data['number'] = $this->_count($data);
        
        $type = 'sc'; // show comment
      
      } elseif ($act == 'hide'){ // hide comment
        $data['comments'][$cid]['show'] = false;
        $data['number'] = $this->_count($data);
        
        $type = 'hc'; // hide comment
        
      } elseif (!$raw){          // remove the comment
        $data['comments'] = $this->_removeComment($cid, $data['comments']);
        $data['number'] = $this->_count($data);
        
        $type = 'dc'; // delete comment
              
      } else {                   // save changed comment
        $xhtml = $this->_render($raw);
        
        // now change the comment's content
        $data['comments'][$cid]['date']['modified'] = $date;
        $data['comments'][$cid]['raw']              = $raw;
        $data['comments'][$cid]['xhtml']            = $xhtml;
        
        $type = 'ec'; // edit comment
      }
    }
    
    // save the comment metadata file
    io_saveFile($file, serialize($data));
    $this->_addLogEntry($date, $ID, $type, '', $cid);
    
    $this->_redirect($cid);
    return true;
  }
  
  /**
   * Recursive function to remove a comment
   */
  function _removeComment($cid, $comments){
    if (is_array($comments[$cid]['replies'])){
      foreach ($comments[$cid]['replies'] as $rid){
        $comments = $this->_removeComment($rid, $comments);
      }
    }
    unset($comments[$cid]);
    return $comments;
  }
      
  /**
   * Prints an individual comment
   */
  function _print($cid, &$data, $parent = '', $reply = '', $visible = true){
    global $conf, $lang, $ID;
    
    if (!isset($data['comments'][$cid])) return false; // comment was removed
    $comment = $data['comments'][$cid];
    
    if (!is_array($comment)) return false;             // corrupt datatype
    
    if ($comment['parent'] != $parent) return true;    // reply to an other comment
    
    if (!$comment['show']){                            // comment hidden
      if (auth_ismanager()) $hidden = ' comment_hidden';
      else return true;
    } else {
      $hidden = '';
    }
        
    // comment head with date and user data
    ptln('<div class="hentry'.$hidden.'">', 4);
    ptln('<div class="comment_head">', 6);
    ptln('<a name="comment__'.$cid.'" id="comment__'.$cid.'"></a>', 8);
    $head = '<span class="vcard author">';
      
    // prepare variables
    if (is_array($comment['user'])){ // new format
      $user    = $comment['user']['id'];
      $name    = $comment['user']['name'];
      $mail    = $comment['user']['mail'];
      $url     = $comment['user']['url'];
      $address = $comment['user']['address'];
    } else {                         // old format
      $user    = $comment['user'];
      $name    = $comment['name'];
      $mail    = $comment['mail'];
      $url     = $comment['url'];
      $address = $comment['address'];
    }
    if (is_array($comment['date'])){ // new format
      $created  = $comment['date']['created'];
      $modified = $comment['date']['modified'];
    } else {                         // old format
      $created  = $comment['date'];
      $modified = $comment['edited'];
    }
    
    // show avatar image?
    if ($this->getConf('useavatar')
	    && (!plugin_isdisabled('avatar'))
	    && ($avatar =& plugin_load('helper', 'avatar'))){
      if ($mail) $head .= $avatar->getXHTML($mail, $name, 'left');
      else $head .= $avatar->getXHTML($user, $name, 'left');
      $style = ' style="margin-left: '.($avatar->getConf('size') + 14).'px;"';
    } else {
      $style = ' style="margin-left: 20px;"';
    }
    
    if ($this->getConf('linkemail') && $mail){
      $head .= $this->email($mail, $name, 'email fn');
    } elseif ($url){
      $head .= $this->external_link($url, $name, 'urlextern url fn');
    } else {
      $head .= '<span class="fn">'.$name.'</span>';
    }
    if ($address) $head .= ', <span class="adr">'.$address.'</span>';
    $head .= '</span>, '.
      '<abbr class="published" title="'.strftime('%Y-%m-%dT%H:%M:%SZ', $created).'">'.
      strftime($conf['dformat'], $created).'</abbr>';
    if ($comment['edited']) $head .= ' (<abbr class="updated" title="'.
      strftime('%Y-%m-%dT%H:%M:%SZ', $modified).'">'.strftime($conf['dformat'], $modified).
      '</abbr>)';
    ptln($head, 8);
    ptln('</div>', 6); // class="comment_head"
    
    // main comment content
    ptln('<div class="comment_body entry-content"'.
      ($this->getConf('useavatar') ? $style : '').'>', 6);
    echo $comment['xhtml'].DOKU_LF;
    ptln('</div>', 6); // class="comment_body"
    
    if ($visible){
      ptln('<div class="comment_buttons">', 6);
          
      // show reply button?
      if (($data['status'] == 1) && !$reply && $comment['show']
        && ($this->getConf('allowguests') || $_SERVER['REMOTE_USER']))
        $this->_button($cid, $this->getLang('btn_reply'), 'reply', true);
      
      // show edit, show/hide and delete button?
      if ((($user == $_SERVER['REMOTE_USER']) && ($user != '')) || (auth_ismanager())){
        $this->_button($cid, $lang['btn_secedit'], 'edit', true);
        $label = ($comment['show'] ? $this->getLang('btn_hide') : $this->getLang('btn_show'));
        $this->_button($cid, $label, 'toogle');
        $this->_button($cid, $lang['btn_delete'], 'delete');
      }
      ptln('</div>', 6); // class="comment_buttons"
    }
    ptln('</div>', 4); // class="hentry"

    // replies to this comment entry?
    if (count($comment['replies'])){
      ptln('<div class="comment_replies"'.$style.'>', 4);
      $visible = ($comment['show'] && $visible);
      foreach ($comment['replies'] as $rid){
        $this->_print($rid, $data, $cid, $reply, $visible);
      }
      ptln('</div>', 4); // class="comment_replies"
    }
        
    // reply form
    if ($reply == $cid){
      ptln('<div class="comment_replies">', 4);
      $this->_form('', 'add', $cid);
      ptln('</div>', 4); // class="comment_replies"
    }
  }

  /**
   * Outputs the comment form
   */
  function _form($raw = '', $act = 'add', $cid = NULL){
    global $lang, $conf, $ID, $INFO;

    // not for unregistered users when guest comments aren't allowed
    if (!$_SERVER['REMOTE_USER'] && !$this->getConf('allowguests')) return false;
    
    // fill $raw with $_REQUEST['text'] if it's empty (for failed CAPTCHA check)
    if (!$raw && ($_REQUEST['comment'] == 'show')) $raw = $_REQUEST['text'];
    
    ?>


    <div class="comment_form">
      <form id="discussion__comment_form" method="post" action="<?php echo script() ?>" accept-charset="<?php echo $lang['encoding'] ?>" onsubmit="return validate(this);">
        <div class="no">
          <input type="hidden" name="id" value="<?php echo $ID ?>" />
          <input type="hidden" name="do" value="show" />
          <input type="hidden" name="comment" value="<?php echo $act ?>" />
    <?php
    
    // for adding a comment
    if ($act == 'add'){
      ?>
          <input type="hidden" name="reply" value="<?php echo $cid ?>" />
      <?php
      // for registered user (and we're not in admin import mode)
      if ($conf['useacl'] && $_SERVER['REMOTE_USER']
        && (!($this->getConf('adminimport') && (auth_ismanager())))){
      ?>
          <input type="hidden" name="user" value="<?php echo hsc($_SERVER['REMOTE_USER']) ?>" />
          <input type="hidden" name="name" value="<?php echo hsc($INFO['userinfo']['name']) ?>" />
          <input type="hidden" name="mail" value="<?php echo hsc($INFO['userinfo']['mail']) ?>" />
      <?php
      // for guest: show name and e-mail entry fields
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
      if ($this->getConf('urlfield')){
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
      if ($this->getConf('addressfield')){
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
      if ($this->getConf('adminimport') && (auth_ismanager())){
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
            <textarea class="edit" name="text" cols="80" rows="10" id="discussion__comment_text" tabindex="5"><?php echo formText($raw) ?></textarea>
          </div>
    <?php //bad and dirty event insert hook
    $evdata = array('writable' => true);
    trigger_event('HTML_EDITFORM_INJECTION', $evdata);
    ?>
          <input class="button" type="submit" name="submit" value="<?php echo $lang['btn_save'] ?>" tabindex="6" />
        </div>
      </form>
    </div>
    <?php
    if ($this->getConf('usecocomment')) echo $this->_coComment();
  }
  
  /**
   * Adds a javascript to interact with coComments
   */
  function _coComment(){
    global $ID, $conf, $INFO;
    
    $user = $_SERVER['REMOTE_USER'];
    
    ?>
    <script type="text/javascript"><!--//--><![CDATA[//><!--
      var blogTool  = "DokuWiki";
      var blogURL   = "<?php echo DOKU_URL ?>";
      var blogTitle = "<?php echo $conf['title'] ?>";
      var postURL   = "<?php echo wl($ID, '', true) ?>";
      var postTitle = "<?php echo tpl_pagetitle($ID, true) ?>";
    <?php
    if ($user){
    ?>
      var commentAuthor = "<?php echo $INFO['userinfo']['name'] ?>";
    <?php
    } else {
    ?>
      var commentAuthorFieldName = "name";
    <?php
    }
    ?>
      var commentAuthorLoggedIn = <?php echo ($user ? 'true' : 'false') ?>;
      var commentFormID         = "discussion__comment_form";
      var commentTextFieldName  = "text";
      var commentButtonName     = "submit";
      var cocomment_force       = false;
    //--><!]]></script>
    <script type="text/javascript" src="http://www.cocomment.com/js/cocomment.js">
    </script>
    <?php
  }
    
  /**
   * General button function
   */
  function _button($cid, $label, $act, $jump = false){
    global $ID;

    $anchor = ($jump ? '#discussion__comment_form' : '' );
        
    ?>
    <form class="button" method="post" action="<?php echo script().$anchor ?>">
      <div class="no">
        <input type="hidden" name="id" value="<?php echo $ID ?>" />
        <input type="hidden" name="do" value="show" />
        <input type="hidden" name="comment" value="<?php echo $act ?>" />
        <input type="hidden" name="cid" value="<?php echo $cid ?>" />
        <input type="submit" value="<?php echo $label ?>" class="button" title="<?php echo $label ?>" />
      </div>
    </form>
    <?php
    return true;
  }
  
  /**
   * Adds an entry to the comments changelog
   *
   * @author Esther Brunner <wikidesign@gmail.com>
   * @author Ben Coburn <btcoburn@silicodon.net>
   */
  function _addLogEntry($date, $id, $type = 'cc', $summary = '', $extra = ''){
    global $conf;
    
    $changelog = $conf['metadir'].'/_comments.changes';
    
    if(!$date) $date = time(); //use current time if none supplied
    $remote = $_SERVER['REMOTE_ADDR'];
    $user   = $_SERVER['REMOTE_USER'];
  
    $strip = array("\t", "\n");
    $logline = array(
      'date'  => $date,
      'ip'    => $remote,
      'type'  => str_replace($strip, '', $type),
      'id'    => $id,
      'user'  => $user,
      'sum'   => str_replace($strip, '', $summary),
      'extra' => str_replace($strip, '', $extra)
    );
      
    // add changelog line
    $logline = implode("\t", $logline)."\n";
    io_saveFile($changelog, $logline, true); //global changelog cache
    $this->_trimRecentCommentsLog($changelog);
    
    // tell the indexer to re-index the page
    @unlink(metaFN($id, '.indexed'));
  }
  
  /**
   * Trims the recent comments cache to the last $conf['changes_days'] recent
   * changes or $conf['recent'] items, which ever is larger.
   * The trimming is only done once a day.
   *
   * @author Ben Coburn <btcoburn@silicodon.net>
   */
  function _trimRecentCommentsLog($changelog){
    global $conf;

    if (@file_exists($changelog) &&
      (filectime($changelog) + 86400) < time() &&
      !@file_exists($changelog.'_tmp')){
      
      io_lock($changelog);
      $lines = file($changelog);
      if (count($lines)<$conf['recent']) {
          // nothing to trim
          io_unlock($changelog);
          return true;
      }

      io_saveFile($changelog.'_tmp', '');                  // presave tmp as 2nd lock
      $trim_time = time() - $conf['recent_days']*86400;
      $out_lines = array();

      for ($i=0; $i<count($lines); $i++) {
        $log = parseChangelogLine($lines[$i]);
        if ($log === false) continue;                      // discard junk
        if ($log['date'] < $trim_time) {
          $old_lines[$log['date'].".$i"] = $lines[$i];     // keep old lines for now (append .$i to prevent key collisions)
        } else {
          $out_lines[$log['date'].".$i"] = $lines[$i];     // definitely keep these lines
        }
      }

      // sort the final result, it shouldn't be necessary,
      // however the extra robustness in making the changelog cache self-correcting is worth it
      ksort($out_lines);
      $extra = $conf['recent'] - count($out_lines);        // do we need extra lines do bring us up to minimum
      if ($extra > 0) {
        ksort($old_lines);
        $out_lines = array_merge(array_slice($old_lines,-$extra),$out_lines);
      }

      // save trimmed changelog
      io_saveFile($changelog.'_tmp', implode('', $out_lines));
      @unlink($changelog);
      if (!rename($changelog.'_tmp', $changelog)) {
        // rename failed so try another way...
        io_unlock($changelog);
        io_saveFile($changelog, implode('', $out_lines));
        @unlink($changelog.'_tmp');
      } else {
        io_unlock($changelog);
      }
      return true;
    }
  }
  
  /**
   * Sends a notify mail on new comment
   *
   * @param  array  $comment  data array of the new comment
   *
   * @author Andreas Gohr <andi@splitbrain.org>
   * @author Esther Brunner <wikidesign@gmail.com>
   */
  function _notify($comment){
    global $conf;
    global $ID;
  
    if ((!$conf['subscribers']) && (!$conf['notify'])) return; //subscribers enabled?
    $bcc  = subscriber_addresslist($ID);
    if ((empty($bcc)) && (!$conf['notify'])) return;
    $to   = $conf['notify'];
    $text = io_readFile($this->localFN('subscribermail'));
    
    $search = array(
      '@PAGE@',
      '@TITLE@',
      '@DATE@',
      '@NAME@',
      '@TEXT@',
      '@UNSUBSCRIBE@',
      '@DOKUWIKIURL@',
    );
    $replace = array(
      $ID,
      $conf['title'],
      strftime($conf['dformat'], $comment['date']['created']),
      $comment['user']['name'],
      $comment['raw'],
      wl($ID, 'do=unsubscribe', true, '&'),
      DOKU_URL,
    );
    $text = str_replace($search, $replace, $text);
  
    $subject = '['.$conf['title'].'] '.$this->getLang('mail_newcomment');
  
    mail_send($to, $subject, $text, $conf['mailfrom'], '', $bcc);
  }
  
  /**
   * Counts the number of visible comments
   */
  function _count($data){
    $number = 0;
    foreach ($data['comments'] as $cid => $comment){
      if ($comment['parent']) continue;
      if (!$comment['show']) continue;
      $number++;
      $rids = $comment['replies'];
      if (count($rids)) $number = $number + $this->_countReplies($data, $rids);
    }
    return $number;
  }
  
  function _countReplies(&$data, $rids){
    $number = 0;
    foreach ($rids as $rid){
      if (!isset($data['comments'][$rid])) continue; // reply was removed
      if (!$data['comments'][$rid]['show']) continue;
      $number++;
      $rids = $data['comments'][$rid]['replies'];
      if (count($rids)) $number = $number + $this->_countReplies($data, $rids);
    }
    return $number;
  }
  
  /**
   * Renders the comment text
   */
  function _render($raw){
    if ($this->getConf('wikisyntaxok')){
      $xhtml = $this->render($raw);
    } else { // wiki syntax not allowed -> just encode special chars
      $xhtml = htmlspecialchars(trim($raw));
    }
    return $xhtml;
  }
    
  /**
   * Adds a TOC item for the discussion section
   */
  function add_toc_item(&$event, $param){
    if ($event->data[0] != 'xhtml') return;       // nothing to do for us
    if (!$this->_hasDiscussion($title)) return;   // no discussion section
        
    $pattern = '/<div id="toc__inside">(.*?)<\/div>\s<\/div>/s';
    if (!preg_match($pattern, $event->data[1], $match)) return; // no TOC on this page
    
    // ok, then let's do it!
    global $conf;
    
    if (!$title) $title = $this->getLang('discussion');
    $section = '#discussion__section';
    $level   = 3 - $conf['toptoclevel'];
    
    $item = '<li class="level'.$level.'">'.DOKU_LF.
      DOKU_TAB.'<div class="li">'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<span class="li"><a href="'.$section.'" class="toc">'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.$title.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'</a></span>'.DOKU_LF.
      DOKU_TAB.'</div>'.DOKU_LF.
      '</li>'.DOKU_LF;
      
    if ($level == 1) $search = "</ul>\n</div>";
    else $search = "</ul>\n</li></ul>\n</div>";
    
    $new = str_replace($search, $item.$search, $match[0]);
    $event->data[1] = preg_replace($pattern, $new, $event->data[1]);
  }
  
  /**
   * Finds out whether there is a discussion section for the current page
   */
  function _hasDiscussion(&$title){
    global $ID;
        
    $cfile = metaFN($ID, '.comments');
    
    if (!@file_exists($cfile)){
      if ($this->getConf('automatic')) return true;
      else return false;
    }
    
    $comments = unserialize(io_readFile($cfile, false));
    
    if ($comments['title']) $title = hsc($comments['title']);
    $num = $comments['number'];
    if ((!$comments['status']) || (($comments['status'] == 2) && (!$num))) return false;
    else return true;
  }
  
  /**
   * Creates a new thread page
   */
  function _newThread(){
    global $ID, $INFO;
    
    $ns    = cleanID($_REQUEST['ns']);
    $title = str_replace(':', '', $_REQUEST['title']);
    $back  = $ID;
    $ID    = ($ns ? $ns.':' : '').cleanID($title);
    $INFO  = pageinfo();
    
    // check if we are allowed to create this file
    if ($INFO['perm'] >= AUTH_CREATE){
            
      //check if locked by anyone - if not lock for my self      
      if ($INFO['locked']) return 'locked';
      else lock($ID);

      // prepare the new thread file with default stuff
      if (!@file_exists($INFO['filepath'])){
        global $TEXT;
        
        $TEXT = pageTemplate(array(($ns ? $ns.':' : '').$title));
        if (!$TEXT){
          $data = array('id' => $ID, 'ns' => $ns, 'title' => $title, 'back' => $back);
          $TEXT = $this->_pageTemplate($data);
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
   */
  function _pageTemplate($data){
    global $conf, $INFO;
    
    $id   = $data['id'];
    $user = $_SERVER['REMOTE_USER'];
    $tpl  = io_readFile(DOKU_PLUGIN.'discussion/_template.txt');
    
    // standard replacements
    $replace = array(
      '@ID@'   => $id,
      '@NS@'   => $data['ns'],
      '@PAGE@' => strtr(noNS($id),'_',' '),
      '@USER@' => $user,
      '@NAME@' => $INFO['userinfo']['name'],
      '@MAIL@' => $INFO['userinfo']['mail'],
      '@DATE@' => strftime($conf['dformat']),
    );
    
    // additional replacements
    $replace['@BACK@']  = $data['back'];
    $replace['@TITLE@'] = $data['title'];
    
    // avatar if useavatar and avatar plugin available
    if ($this->getConf('useavatar')
      && (@file_exists(DOKU_PLUGIN.'avatar/syntax.php'))
      && (!plugin_isdisabled('avatar'))){
      $replace['@AVATAR@'] = '{{avatar>'.$user.' }} ';
    } else {
      $replace['@AVATAR@'] = '';
    }
    
    // tag if tag plugin is available
    if ((@file_exists(DOKU_PLUGIN.'tag/syntax/tag.php'))
      && (!plugin_isdisabled('tag'))){
      $replace['@TAG@'] = "\n\n{{tag>}}";
    } else {
      $replace['@TAG@'] = '';
    }
    
    // do the replace
    $tpl = str_replace(array_keys($replace), array_values($replace), $tpl);
    return $tpl;
  }
  
  /**
   * Checks if the CAPTCHA string submitted is valid
   *
   * @author     Andreas Gohr <gohr@cosmocode.de>
   * @adaption   Esther Brunner <wikidesign@gmail.com>
   */
  function _captchaCheck(){
    if (@file_exists(DOKU_PLUGIN.'captcha/disabled')) return; // CAPTCHA is disabled
    
    require_once(DOKU_PLUGIN.'captcha/action.php');
    $captcha = new action_plugin_captcha;
    
    // do nothing if logged in user and no CAPTCHA required
    if (!$captcha->getConf('forusers') && $_SERVER['REMOTE_USER']) return;
    
    // compare provided string with decrypted captcha
    $rand = PMA_blowfish_decrypt($_REQUEST['plugin__captcha_secret'], auth_cookiesalt());
    $code = $captcha->_generateCAPTCHA($captcha->_fixedIdent(), $rand);

    if (!$_REQUEST['plugin__captcha_secret'] ||
      !$_REQUEST['plugin__captcha'] ||
      strtoupper($_REQUEST['plugin__captcha']) != $code){
      
      // CAPTCHA test failed! Continue to edit instead of saving
      msg($captcha->getLang('testfailed'), -1);
      if ($_REQUEST['comment'] == 'save') $_REQUEST['comment'] = 'edit';
      elseif ($_REQUEST['comment'] == 'add') $_REQUEST['comment'] = 'show';
    }
    // if we arrive here it was a valid save
  }
  
  /**
   * Adds the comments to the index
   */
  function idx_add_discussion(&$event, $param){
  
    // get .comments meta file name
    $file = metaFN($event->data[0], '.comments');
    
    if (@file_exists($file)) $data = unserialize(io_readFile($file, false));
    if ((!$data['status']) || ($data['number'] == 0)) return; // comments are turned off
    
    // now add the comments
    if (isset($data['comments'])){
      foreach ($data['comments'] as $key => $value){
        $event->data[1] .= $this->_addCommentWords($key, $data);
      }
    }
  }
  
  /**
   * Adds the words of a given comment to the index
   */
  function _addCommentWords($cid, &$data, $parent = ''){
  
    if (!isset($data['comments'][$cid])) return ''; // comment was removed
    $comment = $data['comments'][$cid];
    
    if (!is_array($comment)) return '';             // corrupt datatype
    if ($comment['parent'] != $parent) return '';   // reply to an other comment
    if (!$comment['show']) return '';               // hidden comment
    
    $text = $comment['raw'];                        // we only add the raw comment text
    if (is_array($comment['replies'])){             // and the replies
      foreach ($comment['replies'] as $rid){
        $text .= $this->_addCommentWords($rid, $data, $cid);
      }
    }
    return ' '.$text;
  }

}

//Setup VIM: ex: et ts=4 enc=utf-8 :
