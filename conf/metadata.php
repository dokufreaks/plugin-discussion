<?php
/**
 * Metadata for configuration manager plugin
 * Additions for the discussion plugin
 *
 * @author    Esther Brunner <wikidesign@gmail.com>
 */

$meta['automatic']    = array('onoff');
$meta['allowguests']  = array('onoff');
$meta['linkemail']    = array('onoff');
$meta['useavatar']    = array('onoff');
$meta['urlfield']     = array('onoff');
$meta['addressfield'] = array('onoff');
$meta['adminimport']  = array('onoff');
$meta['usecocomment'] = array('onoff');
$meta['wikisyntaxok'] = array('onoff');

$meta['threads_formposition'] = array(
                                  'multichoice',
                                  '_choices' => array('top', 'bottom')
                                );

//Setup VIM: ex: et ts=2 enc=utf-8 :
