<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christoph Scholz <christoph.scholz@gmail.com>
 */

/**
 * print a newline terminated string
 *
 * You can give an indention as optional parameter
 *
 * This function is an exact copy of 'ptln' in DokuWiki
 * which is deprecated since 2023-08-31.
 * This is introduced here in order to get
 * rid of deprecation warnings without changing the
 * plugin too much.
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @param string $string  line of text
 * @param int    $indent  number of spaces indention
 */
function lptln($string, $indent = 0)
{
    echo str_repeat(' ', $indent) . "$string\n";
}
