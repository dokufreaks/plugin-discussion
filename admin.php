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
		global $conf;
		$chem=DOKU_INC.$conf['savedir']."/meta/";
		$arr=$this->globr($chem,"*.comments");
		$com =array();
		foreach ($arr as $v) {
			$ap=unserialize(io_readFile($v, false));
			//si ya des commentaire
			if (isset($ap['comments'])) {
				//pour chaque commentaire
				foreach ($ap['comments'] as $vv) {
					$page=str_replace(array($chem,".comments"),array("",""),$v);
					$com[$page][$vv['date']]=array("name"=>$vv['name'],"com"=>$vv['xhtml']);
					//old method
					//$com[$vv['date']]=array("name"=>$vv['name'],"com"=>$vv['xhtml'],"page"=>wl($page,''));
				}
			}
		}
		
		if (count($com > 1)) {
			//sort discussion	for all page	
			foreach ($com as $k => $v)
				krsort($com[$k],SORT_NUMERIC);
								
				
			//for all page with discussion thread
			echo "<ul>";
			foreach ($com as $page => $thread) {
				echo "<li>";
				echo '<div class="cPage"><a href="'.wl($page,'').'">'.str_replace("/doku.php//","",wl($page,'')).'</a></div><div class="cComBlock">';
				
				foreach ($thread as $dte => $coments) {
					echo '<div class="cComSolo">';
					echo '<div class="cDate">'.date("d/m/Y H:i:s",$dte).'</div>';
					echo '<span class="cName">'.$coments['name'].'</span>';
					echo '<span class="cCom">'.$coments['com'].'</span>';
					echo "</div>";
				}
				echo "</div></li>";				
			}
			echo "</ul>";
		}
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
?>
