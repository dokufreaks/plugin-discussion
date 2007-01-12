<?php
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'admin.php');
 
/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_discussion extends DokuWiki_Admin_Plugin {
 
    /**
     * return some info
     */
    function getInfo(){
      return array(
        'author' => 'iDo',
        'email'  => 'iDo@woow-fr.com',
        'date'   => '2006-12-30',
        'name'   => 'See all discussion',
        'desc'   => '',
        'url'    => '',
      );
    }
 
    /**
     * return sort order for position in admin menu
     */
    function getMenuSort() {
      return 200;
    }
 
    /**
     * handle user request
     */
    function handle() {
    }
 
    /**
     * output appropriate html
     */
    function html() {
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
class unusedclass {
	function unusedclass(){	$this->data='admin';}
}
?>
