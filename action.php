<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

if (!defined('NL')) define('NL',"\n");

class action_plugin_discussion extends DokuWiki_Action_Plugin{

  /**
   * Return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-18',
      'name'   => 'Discussion Plugin',
      'desc'   => 'Enables discussion features',
      'url'    => 'http://www.wikidesign.ch/en/plugin/discussion/start',
    );
  }

  /**
   * Register the eventhandlers
   */
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
  }
    
  /**
   * Main function; dispatches the comment actions
   */
  function comments(&$event, $param){
    if ($event->data != 'show') return; // nothing to do for us
    
    $cid  = $_REQUEST['cid'];
    
    switch ($_REQUEST['comment']){
      
      case 'add':
        $comment = array(
          'user'    => $_REQUEST['user'],
          'name'    => $_REQUEST['name'],
          'mail'    => $_REQUEST['mail'],
          'url'     => $_REQUEST['url'],
          'address' => $_REQUEST['address'],
          'date'    => $_REQUEST['date'],
          'raw'     => cleanText($_REQUEST['text'])
        );
        $repl = $_REQUEST['reply'];
        $this->_add($comment, $repl);
        break;
      
      case 'edit':
        $this->_show(NULL, $cid);
        break;
      
      case 'save':
        $raw  = cleanText($_REQUEST['text']);
        $this->_save($cid, $raw);
        break;
        
      case 'delete':
        $this->_save($cid, '');
        break;
        
      case 'toogle':
        $this->_save($cid, '', true);
        break;
            
      default: // 'show' => $this->_show(), 'reply' => $this->_show($cid)
        $this->_show($cid);
    }
  }
  
  /**
   * Shows all comments of the current page
   */
  function _show($reply = NULL, $edit = NULL){
    global $ID, $INFO;
    
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
    $title = $this->getLang('discussion');
    echo '<div class="comment_wrapper">';
    echo '<h2><a name="discussion__section" id="discussion__section">'.$title.'</a></h2>';
    echo '<div class="level2">';
        
    // now display the comments
    if (isset($data['comments'])){
      foreach ($data['comments'] as $key => $value){
        if ($key == $edit) $this->_form($value['raw'], 'save', $edit); // edit form
        else $this->_print($key, $data, '', $reply);
      }
    }
    
    // comment form
    if (($data['status'] == 1) && !$reply && !$edit) $this->_form('');
    
    echo '</div>'; // level2
    echo '</div>'; // comment_wrapper
    
    return true;
  }
  
  /**
   * Adds a new comment and then displays all comments
   */
  function _add($comment, $parent){
    global $ID;
    global $TEXT;
    
    $otxt = $TEXT; // set $TEXT to comment text for wordblock check
    $TEXT = $comment['raw'];
    
    // spamcheck against the DokuWiki blacklist
    if (checkwordblock()){
      msg($this->getLang('wordblock'), -1);
      $this->_show();
      return false;
    }
    
    $TEXT = $otxt; // restore global $TEXT
    
    // get discussion meta file name
    $file = metaFN($ID, '.comments');
    
    $data = array();
    $data = unserialize(io_readFile($file, false));
    
    if ($data['status'] != 1) return false;                // comments off or closed
    if ((!$this->getConf('allowguests'))
      && ($comment['user'] != $_SERVER['REMOTE_USER']))
      return false;                                        // guest comments not allowed 
    
    if ($comment['date']) $date = strtotime($comment['date']);
    else $date = time();
    if ($date == -1) $date = time();
    $cid  = md5($comment['user'].$date);                   // create a unique id
    
    if (!is_array($data['comments'][$parent])) $parent = NULL; // invalid parent comment
    
    // render the comment
    $xhtml = $this->_render($comment['raw']);
    
    // fill in the new comment
    $data['comments'][$cid] = array(
      'user'    => htmlspecialchars($comment['user']),
      'name'    => htmlspecialchars($comment['name']),
      'mail'    => htmlspecialchars($comment['mail']),
      'date'    => $date,
      'show'    => true,
      'raw'     => trim($comment['raw']),
      'xhtml'   => $xhtml,
      'parent'  => $parent,
      'replies' => array()
    );
    if ($comment['url'])
      $data['comments'][$cid]['url'] = htmlspecialchars($comment['url']);
    if ($comment['address'])
      $data['comments'][$cid]['address'] = htmlspecialchars($comment['address']);
    
    // update parent comment
    if ($parent) $data['comments'][$parent]['replies'][] = $cid;
    
    // update the number of comments
    $data['number']++;
        
    // save the comment metadata file
    io_saveFile($file, serialize($data));
    $this->_addLogEntry($date, $ID, 'cc', '', $cid);
    
    // notify subscribers of the page
    $this->_notify($data['comments'][$cid]);
    
    $this->_show();
    return true;
  }
    
  /**
   * Saves the comment with the given ID and then displays all comments
   */
  function _save($cid, $raw, $toogle = false){
    global $ID;
    global $INFO;

    if ($raw){
      global $TEXT;
          
      $otxt = $TEXT; // set $TEXT to comment text for wordblock check
      $TEXT = $raw;
      
      // spamcheck against the DokuWiki blacklist
      if (checkwordblock()){
        msg($this->getLang('wordblock'), -1);
        $this->_show();
        return false;
      }
      
      $TEXT = $otxt; // restore global $TEXT
    }
    
    // get discussion meta file name
    $file = metaFN($ID, '.comments');
    
    $data = array();
    $data = unserialize(io_readFile($file, false));
        
    // someone else was trying to edit our comment -> abort
    if (($data['comments'][$cid]['user'] != $_SERVER['REMOTE_USER'])
      && ($INFO['perm'] != AUTH_ADMIN)) return false;
      
    $date = time();
    
    if ($toogle){     // toogle visibility
      $now = $data['comments'][$cid]['show'];
      $data['comments'][$cid]['show'] = !$now;
      $data['number'] = $this->_count($data);
      
      $type = ($data['comments'][$cid]['show'] ? 'sc' : 'hc');
      
    } elseif (!$raw){ // remove the comment
      unset($data['comments'][$cid]);
      $data['number'] = $this->_count($data);
      
      $type = 'dc';
            
    } else {          // save changed comment
      $xhtml = $this->_render($raw);
      
      // now change the comment's content
      $data['comments'][$cid]['edited'] = $date;
      $data['comments'][$cid]['raw']    = trim($raw);
      $data['comments'][$cid]['xhtml']  = $xhtml;
      
      $type = 'ec';
    }
    
    // save the comment metadata file
    io_saveFile($file, serialize($data));
    $this->_addLogEntry($date, $ID, $type, '', $cid);
    
    $this->_show();
    return true;
  }
    
  /**
   * Prints an individual comment
   */
  function _print($cid, &$data, $parent = '', $reply = '', $visible = true){
    global $conf;
    global $lang;
    global $ID;
    global $INFO;
    
    if (!isset($data['comments'][$cid])) return false; // comment was removed
    $comment = $data['comments'][$cid];
    
    if (!is_array($comment)) return false;          // corrupt datatype
    
    if ($comment['parent'] != $parent) return true; // reply to an other comment
    
    if (!$comment['show']){                         // comment hidden
      if ($INFO['perm'] == AUTH_ADMIN) echo '<div class="comment_hidden">'.NL;
      else return true;
    }
        
    // comment head with date and user data
    echo '<div class="comment_head">'.NL;
    echo '<a name="comment__'.$cid.'" id="comment__'.$cid.'">'.NL;
    
    // show gravatar image
    if ($this->getConf('usegravatar')){
      $default = DOKU_URL.'lib/plugins/discussion/images/default.gif';
      $size    = $this->getConf('gravatar_size');
      if ($comment['mail']) $src = ml('http://www.gravatar.com/avatar.php?'.
        'gravatar_id='.md5($comment['mail']).
        '&default='.urlencode($default).
        '&size='.$size.
        '&rating='.$this->getConf('gravatar_rating').
        '&.jpg', 'cache=recache');
      else $src = $default;
      $title = ($comment['name'] ? $comment['name'] : obfuscate($comment['mail']));
      echo '<img src="'.$src.'" class="medialeft" title="'.$title.'"'.
        ' alt="'.$title.'" width="'.$size.'" height="'.$size.'" />'.NL;
      $style = ' style="margin-left: '.($size + 14).'px;"';
    } else {
      $style = ' style="margin-left: 20px;"';
    }
    
    echo '</a>'.NL;
    if ($this->getConf('linkemail') && $comment['mail']){
      echo $this->email($comment['email'], $comment['name']);
    } elseif ($comment['url']){
      echo $this->external_link($comment['url'], $comment['name'], 'urlextern');
    } else {
      echo $comment['name'];
    }
    if ($comment['address']) echo ', '.htmlentities($comment['address']);
    echo ', '.date($conf['dformat'], $comment['date']);
    if ($comment['edited']) echo ' ('.date($conf['dformat'], $comment['edited']).')';
    echo ':'.NL;
    echo '</div>'.NL; // class="comment_head"
    
    // main comment content
    echo '<div class="comment_body"'.($this->getConf('usegravatar') ? $style : '').'>'.NL;
    echo $comment['xhtml'].NL;
    echo '</div>'.NL; // class="comment_body"
    
    
    if ($visible){
      // show hide/show toogle button?
      echo '<div class="comment_buttons">'.NL;
      if ($INFO['perm'] == AUTH_ADMIN){
        if (!$comment['show']) $label = $this->getLang('btn_show');
        else $label = $this->getLang('btn_hide');
        
        $this->_button($cid, $label, 'toogle');
      }
          
      // show reply button?
      if (($data['status'] == 1) && !$reply && $comment['show']
        && ($this->getConf('allowguests') || $_SERVER['REMOTE_USER']))
        $this->_button($cid, $this->getLang('btn_reply'), 'reply', true);
      
      // show edit and delete button?
      if ((($comment['user'] == $_SERVER['REMOTE_USER']) && ($comment['user'] != ''))
        || ($INFO['perm'] == AUTH_ADMIN))
        $this->_button($cid, $lang['btn_secedit'], 'edit', true);
      if ($INFO['perm'] == AUTH_ADMIN)
        $this->_button($cid, $lang['btn_delete'], 'delete');
      echo '</div>'.NL; // class="comment_buttons"
      echo '<div class="comment_line" '.($this->getConf('usegravatar') ? $style : '').'>&nbsp;</div>'.NL; 
    }

    // replies to this comment entry?
    if (count($comment['replies'])){
      echo '<div class="comment_replies"'.$style.'>'.NL;
      $visible = ($comment['show'] && $visible);
      foreach ($comment['replies'] as $rid){
        $this->_print($rid, $data, $cid, $reply, $visible);
      }
      echo '</div>'.NL; // class="comment_replies"
    }
    
    if (!$comment['show']) echo '</div>'.NL; // class="comment_hidden"
    
    // reply form
    if ($reply == $cid){
      echo '<div class="comment_replies">'.NL;
      $this->_form('', 'add', $cid);
      echo '</div>'.NL; // class="comment_replies"
    }
  }

  /**
   * Outputs the comment form
   */
  function _form($raw = '', $act = 'add', $cid = NULL){
    global $lang;
    global $conf;
    global $ID;
    global $INFO;
  
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
        && (!($this->getConf('adminimport') && ($INFO['perm'] == AUTH_ADMIN)))){
      ?>
          <input type="hidden" name="user" value="<?php echo $_SERVER['REMOTE_USER'] ?>" />
          <input type="hidden" name="name" value="<?php echo $INFO['userinfo']['name'] ?>" />
          <input type="hidden" name="mail" value="<?php echo $INFO['userinfo']['mail'] ?>" />
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
      if ($this->getConf('adminimport') && ($INFO['perm'] == AUTH_ADMIN)){
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
            <textarea class="edit" name="text" cols="80" rows="10" id="discussion__comment_text" tabindex="5"><?php echo hsc($raw) ?></textarea>
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
    global $ID;
    global $conf;
    global $INFO;
    
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
  
    $text = str_replace('@PAGE@', $ID, $text);
    $text = str_replace('@TITLE@', $conf['title'], $text);
    $text = str_replace('@DATE@', date($conf['dformat'], $comment['date']), $text);
    $text = str_replace('@NAME@', $comment['name'], $text);
    $text = str_replace('@TEXT@', $comment['raw'], $text);
    $text = str_replace('@UNSUBSCRIBE@', wl($ID, 'do=unsubscribe', true, '&'), $text);
    $text = str_replace('@DOKUWIKIURL@', DOKU_URL, $text);
  
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
    if ($event->data[0] != 'xhtml') return; // nothing to do for us
    if (!$this->_hasDiscussion()) return;   // no discussion section
        
    $pattern = '/<div id="toc__inside">(.*?)<\/div>\s<\/div>/s';
    if (!preg_match($pattern, $event->data[1], $match)) return; // no TOC on this page
    
    // ok, then let's do it!
    global $conf;
        
    $title   = $this->getLang('discussion');
    $section = '#discussion__section';
    $level   = 3 - $conf['toptoclevel'];
    
    $item = '<li class="level'.$level.'"><div class="li"><span class="li"><a href="'.
      $section.'" class="toc">'.$title.'</a></span></div></li>';
      
    if ($level == 1) $search = "</ul>\n</div>";
    else $search = "</ul>\n</li></ul>\n</div>";
    
    $new = str_replace($search, $item.$search, $match[0]);
    $event->data[1] = preg_replace($pattern, $new, $event->data[1]);
  }
  
  /**
   * Finds out whether there is a discussion section for the current page
   */
  function _hasDiscussion(){
    global $ID;
    
    $cfile = metaFN($ID, '.comments');
    
    if (!@file_exists($cfile)){
      if ($this->getConf('automatic')) return true;
      else return false;
    }
    
    $comments = unserialize(io_readFile($cfile, false));
    
    $num = $comments['number'];
    if ((!$comments['status']) || (($comments['status'] == 2) && (!$num))) return false;
    else return true;
  }
  
  /**
   * Checks if 'newthread' was given as action or the comment form was submitted
   */
  function handle_act_preprocess(&$event, $param){
    if ($event->data == 'newthread'){
      // we can handle it -> prevent others
      // $event->stopPropagation();
      $event->preventDefault();
      
      $event->data = $this->_handle_newThread();
    }
    if ((in_array($_REQUEST['comment'], array('add', 'save')))
      && (@file_exists(DOKU_PLUGIN.'captcha/action.php'))){
      $this->_handle_captchaCheck();
    }
  }
  
  /**
   * Creates a new thread page
   */
  function _handle_newThread(){
    global $ID;
    global $INFO;
    
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
        global $conf;
        
        $TEXT = pageTemplate(array(($ns ? $ns.':' : '').$title));
        if (!$TEXT){
          $TEXT = "<- [[:$back]]\n\n====== $title ======\n\n";
          if ($this->getConf('usegravatar'))
            $TEXT .= '{{gravatar>'.$INFO['userinfo']['mail'].' }} ';
          $TEXT .= "//".$INFO['userinfo']['name'].", ".date($conf['dformat']).": //".
                   "\n\n\n\n~~DISCUSSION~~\n";
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
   * Checks if the CAPTCHA string submitted is valid
   *
   * @author     Andreas Gohr <gohr@cosmocode.de>
   * @adaption   Esther Brunner <wikidesign@gmail.com>
   */
  function _handle_captchaCheck(){
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

}

//Setup VIM: ex: et ts=4 enc=utf-8 :
