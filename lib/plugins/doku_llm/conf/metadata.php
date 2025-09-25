<?php
/**
 * Options for the llm_integration plugin
 */

$meta['api_url'] = array('string');
$meta['api_key'] = array('password');
$meta['model'] = array('string');
$meta['timeout'] = array('numeric', '_min' => 5);