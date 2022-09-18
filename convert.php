<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

/* ----- Settings ----- */

define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../') . '/');
const DISCUSSION_NS = 'discussion';

/* ----- Main ----- */

// conversion script should only be run once
if (@file_exists(dirname(__FILE__) . '/convert_completed')) {
    die('Conversion already completed.');
}

require_once(DOKU_INC . 'inc/init.php');

$files = getDiscussionPages();
$n = 0;

foreach ($files as $file) {
    if (convertDiscussionPage($file)) {
        echo $file['id'] . '<br />';
        $n++;
    }
}

if ($n > 0) {
    io_saveFile(dirname(__FILE__) . '/convert_completed', '');
    echo '<br />Successfully converted ' . $n . ' discussion pages to new comments meta files.';
} else {
    echo 'No discussion pages found.';
}

/* ----- Functions ----- */

/**
 * returns a list of all discussion pages in the wiki
 */
function getDiscussionPages()
{
    global $conf;

    $data = [];
    search($data, $conf['datadir'], 'search_discussionpages', []);
    return $data;
}

/**
 * function for the search callback
 */
function search_discussionpages(&$data, $base, $file, $type, $lvl, $opts)
{
    global $conf;

    // recurse into directories
    if ($type == 'd') {
        return true;
    }
    if (!preg_match('#' . preg_quote('/' . DISCUSSION_NS . '/', '#') . '#u', $file)) {
        return false;
    }
    if (!preg_match('#\.txt$#', $file)) {
        return false;
    }

    $id = pathID(str_replace(DISCUSSION_NS . '/', '', $file));
    $data[] = [
        'id' => $id,
        'old' => $conf['datadir'] . $file,
        'new' => metaFN($id, '.comments')
    ];
    return true;
}

/**
 * this converts individual discussion pages to .comment meta files
 */
function convertDiscussionPage($file)
{

    // read the old file
    $data = io_readFile($file['old'], false);

    // handle file with no comments yet
    if (trim($data) == '') {
        io_saveFile($file['new'], serialize(['status' => 1, 'number' => 0]));
        @unlink($file['old']);
        return true;
    }

    // break it up into pieces
    $old = explode('----', $data);

    // merge with possibly already existing (newer) comments
    $comments = [];
    if (@file_exists($file['new'])) {
        $comments = unserialize(io_readFile($file['old'], false));
    }

    // set general info
    if (!isset($comments['status'])) {
        $comments['status'] = 1;
    }
    $comments['number'] += count($old);

    foreach ($old as $comment) {

        // prepare comment data
        if (strpos($comment, '<sub>') !== false) {
            $in = '<sub>';
            $out = ':</sub>';
        } else {
            $in = '//';
            $out = ': //';
        }
        list($meta, $raw) = array_pad(explode($out, $comment, 2), 2, '');
        $raw = trim($raw);

        // skip empty comments
        if (!$raw) {
            $comments['number']--;
            continue;
        }

        list($mail, $meta) = array_pad(explode($in, $meta, 2), 2, '');
        list($name, $strd) = array_pad(explode(', ', $meta, 2), 2, '');
        $date = strtotime($strd);
        if ($date == -1) {
            $date = time();
        }
        if ($mail) {
            list($mail) = array_pad(explode(' |', $mail, 2), 2, '');
            $mail = substr(strrchr($mail, '>'), 1);
        }
        $cid = md5($name . $date);

        // render comment
        $xhtml = p_render('xhtml', p_get_instructions($raw), $info);

        // fill in the converted comment
        $comments['comments'][$cid] = [
            'user' => [
                'name' => hsc($name),
                'mail' => hsc($mail)],
            'date' => ['created' => $date],
            'show' => true,
            'raw' => $raw,
            'xhtml' => $xhtml,
            'replies' => []
        ];
    }

    // save the new file
    io_saveFile($file['new'], serialize($comments));

    // remove the old file
    @unlink($file['old']);

    return true;
}
