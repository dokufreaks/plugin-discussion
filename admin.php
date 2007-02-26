<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");

require_once(DOKU_PLUGIN.'admin.php');
 
class admin_plugin_discussion extends DokuWiki_Admin_Plugin {
 
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2007-02-22',
      'name'   => 'Discussion Plugin (admin component)',
      'desc'   => 'Manage all discussions',
      'url'    => 'http://www.wikidesign.ch/en/plugin/discussion/start',
    );
  }

  function getMenuSort(){ return 200; }
  function handle(){}
  function forAdminOnly(){ return false; }

  /**
   * output appropriate html
   */
  function html(){
    global $conf;
    
    echo '<h1>'.$this->getLang('menu').'</h1>';
    
    $my =& plugin_load('helper', 'discussion');
    
    $threads = $my->getThreads('', $conf['recent'], true);
    foreach ($threads as $thread){
      echo $this->_threadHead($thread);
      if (($thread['status'] == 0) || ($thread['num'] == 0)){
        echo '</div>';
        continue;
      }
      $comments = $my->getFullComments($thread);
      
      echo '<form action="'.script().'">'.
        '<div class="no">'.
        '<input type="hidden" name="id" value="'.$thread['id'].'" />'.
        '<input type="hidden" name="do" value="admin" />'.
        '<input type="hidden" name="page" value="discussion" />';
      echo html_buildlist($comments, 'admin_discussion', array($this, '_commentItem'), array($this, '_li_comment'));
      echo $this->_actionButtons($thread['id']);
    }
  }
  
  /**
   * Header, page ID and status of a discussion thread
   */
  function _threadHead($thread){
    $label = array(
      $this->getLang('off'),
      $this->getLang('open'),
      $this->getLang('closed')
    );
    $status = $label[$thread['status']];
    return '<h2>'.hsc($thread['title']).'</h2>'.
      '<div class="rightalign">'.$this->getLang('status').': '.$status.
      ' '.$this->getLang('btn_change').'</div>'.
      '<div class="level2">'.
      '<a href="'.wl($thread['id']).'" class="wikilinik1">'.$thread['id'].'</a> ';
  }
  
  /**
   * Checkbox and info about a comment item
   */
  function _commentItem($comment){
    global $conf;
  
    // prepare variables
    if (is_array($comment['user'])){ // new format
      $name    = $comment['user']['name'];
      $mail    = $comment['user']['mail'];
    } else {                         // old format
      $name    = $comment['name'];
      $mail    = $comment['mail'];
    }
    if (is_array($comment['date'])){ // new format
      $created  = $comment['date']['created'];
    } else {                         // old format
      $created  = $comment['date'];
    }
    $abstract = strip_tags($comment['xhtml']);
    if (utf8_strlen($abstract) > 160) $abstract = utf8_substr($abstract, 0, 160).'...';

    return '<input type="checkbox" name="cid['.$comment['id'].']" value="'.$comment['id'].'" /> '.$this->email($mail, $name, 'email').', '.
      date($conf['dformat'], $created).': <span class="abstract">'.$abstract.'</span>';
  }
  
  /**
   * list item tag
   */
  function _li_comment($comment){
    $show = ($comment['show'] ? '' : ' hidden');
    return '<li class="level'.$comment['level'].$show.'">';
  }
  
  /**
   * Show buttons to bulk remove, hide or show comments
   */
  function _actionButtons($id){
    global $lang;
    
    return '<div class="comment_buttons">'.
      '<input type="submit" name="comment" value="'.$this->getLang('btn_show').'" class="button" title="'.$this->getLang('btn_show').'" />'.
      '<input type="submit" name="comment" value="'.$this->getLang('btn_hide').'" class="button" title="'.$this->getLang('btn_hide').'" />'.
      '<input type="submit" name="comment" value="'.$lang['btn_delete'].'" class="button" title="'.$lang['btn_delete'].'" />'.
      '</div>'.
      '</div>'.
      '</form>'.
      '</div>'; // class="level2"
  }
  
    /**
     * function by iDo
     */
    function _html() {
    	require_once(DOKU_PLUGIN.'action.php');
		$actionDiscussion= new action_plugin_discussion();
    
		global $conf;
		global $INFO;
		global $ID;
		global $ADMDISCUSSION;
		
		$oID=$ID;
		$ADMDISCUSSION['page']="adm";
		//Execute action for page
		if (isset($_REQUEST['comment'])) {
			if ($_REQUEST['comment']!='edit') {

				if (($_REQUEST['comment']=='add') && (isset($_REQUEST['cid']))) {

				} else {
					$obj=new unusedclass();
					$actionDiscussion->comments($obj, null);
				}
			}
		}

		$chem=DOKU_INC.$conf['savedir']."/meta/";
		$arr=$this->globr($chem,"*.comments");
		$com =array();
		foreach ($arr as $v) {
			$ap=unserialize(io_readFile($v, false));
			if (isset($ap['comments'])){
				$ID=substr(str_replace(array($chem,".comments",'/'),array("","",':'),$v),1);
				$ADMDISCUSSION['page']=' : <a href="'.wl($ID,'').'">'.str_replace("/doku.php/","",wl($ID,'')).'</a>';

				if ((isset($_REQUEST['comment'])) && ($_REQUEST['comment']=='edit'))
					$actionDiscussion->_show(NULL, $_REQUEST['cid']);
				else
					$actionDiscussion->_show((($oID==$ID)?@$_REQUEST['cid']:null));
				
			}
		}
		$ID = $oID;
		$ADMDISCUSSION['breakaction']=true;
    }
	
	/**
	 * Recursive version of glob
	 *
	 * @return array containing all pattern-matched files.
	 *
	 * @param string $sDir      Directory to start with.
	 * @param string $sPattern  Pattern to glob for.
	 * @param int $nFlags      Flags sent to glob.
	 */
	function globr($sDir, $sPattern, $nFlags = NULL) {
	  $sDir = escapeshellcmd($sDir);
	  // Get the list of all matching files currently in the
	  // directory.
	  $aFiles = glob("$sDir/$sPattern", $nFlags);
	  // Then get a list of all directories in this directory, and
	  // run ourselves on the resulting array.  This is the
	  // recursion step, which will not execute if there are no
	  // directories.
	  foreach (glob("$sDir/*", GLOB_ONLYDIR) as $sSubDir)  {
	   $aSubFiles = $this->globr($sSubDir, $sPattern, $nFlags);
	   $aFiles = array_merge($aFiles, $aSubFiles);
	  }
	  // The array we return contains the files we found, and the
	  // files all of our children found.
	  return $aFiles;
	}
  	
}

//Setup VIM: ex: et ts=4 enc=utf-8 :