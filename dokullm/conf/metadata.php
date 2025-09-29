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

/**
 * Metadata for the top-p configuration option
 * 
 * Defines the top-p (nucleus sampling) as a numeric field with a range from 0.0 to 1.0,
 * with a default of 0.8. This controls the cumulative probability of token selection.
 * 
 * @var array
 */
$meta['top_p'] = array('numeric', '_min' => 0.0, '_max' => 1.0, '_pattern' => '/^\d+(\.\d+)?$/');

/**
 * Metadata for the top-k configuration option
 * 
 * Defines the top-k as a numeric field with a minimum value of 1,
 * with a default of 20. This controls the number of highest probability tokens considered.
 * 
 * @var array
 */
$meta['top_k'] = array('numeric', '_min' => 1);

/**
 * Metadata for the min-p configuration option
 * 
 * Defines the min-p as a numeric field with a range from 0.0 to 1.0,
 * with a default of 0.0. This controls the minimum probability threshold for token selection.
 * 
 * @var array
 */
$meta['min_p'] = array('numeric', '_min' => 0.0, '_max' => 1.0, '_pattern' => '/^\d+(\.\d+)?$/');

/**
 * Metadata for the show_copy_button configuration option
 * 
 * Defines whether the copy button should be shown as a boolean field.
 * 
 * @var array
 */
$meta['show_copy_button'] = array('onoff');

/**
 * Metadata for the replace_id configuration option
 * 
 * Defines whether the template ID should be replaced with the new page ID
 * when copying a page with a template.
 * 
 * @var array
 */
$meta['replace_id'] = array('onoff');

/**
 * Metadata for the think configuration option
 * 
 * Defines whether the LLM should engage in deeper thinking processes before responding.
 * When enabled, the LLM will use thinking capabilities; when disabled, it will provide direct responses.
 * 
 * @var array
 */
$meta['think'] = array('onoff');
