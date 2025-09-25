<?php
/**
 * Options for the dokullm plugin
 * 
 * This file defines the configuration metadata for the LLM integration plugin.
 * It specifies the type and validation rules for each configuration option.
 */

/**
 * Metadata for the API URL configuration option
 * 
 * Defines the API endpoint URL as a string input field in the configuration interface.
 * 
 * @var array
 */
$meta['api_url'] = array('string');

/**
 * Metadata for the API key configuration option
 * 
 * Defines the API key as a password field in the configuration interface.
 * This ensures the value is masked when entered and stored securely.
 * 
 * @var array
 */
$meta['api_key'] = array('password');

/**
 * Metadata for the model configuration option
 * 
 * Defines the model identifier as a string input field in the configuration interface.
 * 
 * @var array
 */
$meta['model'] = array('string');

/**
 * Metadata for the timeout configuration option
 * 
 * Defines the timeout value as a numeric input field with a minimum value of 5 seconds.
 * This prevents users from setting an unreasonably low timeout value.
 * 
 * @var array
 */
$meta['timeout'] = array('numeric', '_min' => 5);

/**
 * Metadata for the language configuration option
 * 
 * Defines the language as a multichoice field with 'default' and 'ro' options.
 * 
 * @var array
 */
$meta['language'] = array('multichoice', '_choices' => array('default', 'ro'));

/**
 * Metadata for the temperature configuration option
 * 
 * Defines the temperature as a numeric field with a range from 0.0 to 1.0,
 * with a default of 0.3. This controls the randomness of the LLM responses.
 * 
 * @var array
 */
$meta['temperature'] = array('numeric', '_min' => 0.0, '_max' => 1.0, '_pattern' => '/^\d+(\.\d+)?$/');
