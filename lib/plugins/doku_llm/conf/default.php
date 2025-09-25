<?php
/**
 * Default settings for the doku_llm plugin
 * 
 * This file defines the default configuration values for the LLM integration plugin.
 * These values can be overridden by the user in the plugin configuration.
 */

/**
 * The API endpoint URL for the LLM service
 * 
 * This should be the full URL to the chat completions endpoint of your LLM provider.
 * The default is set to OpenAI's GPT API endpoint.
 * 
 * @var string
 */
$conf['api_url'] = 'https://api.openai.com/v1/chat/completions';

/**
 * The API authentication key
 * 
 * This is the secret key used to authenticate with the LLM service.
 * For security, this should be left empty in the default config and set by the user.
 * 
 * @var string
 */
$conf['api_key'] = '';

/**
 * The model identifier to use for text processing
 * 
 * Specifies which LLM model to use for processing requests.
 * The default is gpt-3.5-turbo, but can be changed to other models like gpt-4.
 * 
 * @var string
 */
$conf['model'] = 'gpt-3.5-turbo';

/**
 * The request timeout in seconds
 * 
 * Maximum time to wait for a response from the LLM API before timing out.
 * Set to 30 seconds by default, which should be sufficient for most requests.
 * 
 * @var int
 */
$conf['timeout'] = 30;
