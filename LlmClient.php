<?php
namespace dokuwiki\plugin\dokullm;

/**
 * LLM Client for the dokullm plugin
 * 
 * This class provides methods to interact with an LLM API for various
 * text processing tasks such as completion, rewriting, grammar correction,
 * summarization, conclusion creation, text analysis, and custom prompts.
 *
 * The client handles:
 * - API configuration and authentication
 * - Prompt template loading and processing
 * - Context-aware requests with metadata
 * - DokuWiki page content retrieval
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

/**
 * LLM Client class for handling API communications
 * 
 * Manages configuration settings and provides methods for various
 * text processing operations through an LLM API.
 * Implements caching for tool calls to avoid duplicate processing.
 */
class LlmClient
{
    /** @var string The API endpoint URL */
    private $api_url;
    
    /** @var array Cache for tool call results */
    private $toolCallCache = [];
    
    /** @var string Current text for tool usage */
    private $currentText = '';
    
    /** @var array Track tool call counts to prevent infinite loops */
    private $toolCallCounts = [];
    
    /** @var string The API authentication key */
    private $api_key;
    
    /** @var string The model identifier to use */
    private $model;
    
    /** @var int The request timeout in seconds */
    private $timeout;
    
    /** @var float The temperature setting for response randomness */
    private $temperature;
    
    /** @var float The top-p setting for nucleus sampling */
    private $top_p;
    
    /** @var int The top-k setting for token selection */
    private $top_k;
    
    /** @var float The min-p setting for minimum probability threshold */
    private $min_p;
    
    /** @var bool Whether to enable thinking in LLM responses */
    private $think;
    
    /** @var object|null ChromaDB client instance */
    private $chromaClient;
    
    /** @var string|null Page ID */
    private $pageId;
    
    /**
     * Initialize the LLM client with configuration settings
     * 
     * Retrieves configuration values from DokuWiki's configuration system
     * for API URL, key, model, timeout, and LLM sampling parameters.
     * 
     * Configuration values:
     * - api_url: The LLM API endpoint URL
     * - api_key: Authentication key for the API (optional)
     * - model: The model identifier to use for requests
     * - timeout: Request timeout in seconds
     * - profile: Profile for prompt templates
     * - temperature: Temperature setting for response randomness (0.0-1.0)
     * - top_p: Top-p (nucleus sampling) setting (0.0-1.0)
     * - top_k: Top-k setting (integer >= 1)
     * - min_p: Minimum probability threshold (0.0-1.0)
     * - think: Whether to enable thinking in LLM responses (boolean)
     * - chromaClient: ChromaDB client instance (optional)
     * - pageId: Page ID (optional)
     */
    public function __construct($api_url = null, $api_key = null, $model = null, $timeout = null, $temperature = null, $top_p = null, $top_k = null, $min_p = null, $think = null, $profile = null, $chromaClient = null, $pageId = null)
    {
        $this->api_url = $api_url;
        $this->api_key = $api_key;
        $this->model = $model;
        $this->timeout = $timeout;
        $this->temperature = $temperature;
        $this->top_p = $top_p;
        $this->top_k = $top_k;
        $this->min_p = $min_p;
        $this->think = $think;
        $this->profile = $profile;
        $this->chromaClient = $chromaClient;
        $this->pageId = $pageId;
    }
    


    public function process($action, $text, $metadata = [], $useContext = true)
    {
        // Store the current text for tool usage
        $this->currentText = $text;
        
        // Add text, think and action to metadata
        $metadata['text'] = $text;
        $metadata['think'] = $this->think ? '/think' : '/no_think';
        $metadata['action'] = $action;
        
        // If we have 'template' in metadata, move it to 'page_template'
        if (isset($metadata['template'])) {
            $metadata['page_template'] = $metadata['template'];
            unset($metadata['template']);
        }
        
        // If we have 'examples' in metadata, move it to 'page_examples'
        if (isset($metadata['examples'])) {
            $metadata['page_examples'] = $metadata['examples'];
            unset($metadata['examples']);
        }
        
        // If we have 'previous' in metadata, move it to 'page_previous'
        if (isset($metadata['previous'])) {
            $metadata['page_previous'] = $metadata['previous'];
            unset($metadata['previous']);
        }
        
        $prompt = $this->loadPrompt($action, $metadata);
        
        return $this->callAPI($action, $prompt, $metadata, $useContext);
    }
    
    /**
     * Process text with a custom user prompt
     * 
     * Sends a custom prompt to the LLM along with the provided text.
     * 
     * @param string $text The text to process
     * @param string $customPrompt The custom prompt to use
     * @param array $metadata Optional metadata containing template and examples
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The processed text
     */
    public function processCustomPrompt($text, $metadata = [], $useContext = true)
    {
        // Store the current text for tool usage
        $this->currentText = $text;
        
        // Format the prompt with the text and custom prompt
        $prompt = $metadata['prompt'] . "\n\nText to process:\n" . $text;
        
        return $this->callAPI('custom', $prompt, $metadata, $useContext);
    }
    
    /**
     * Get the list of available tools for the LLM
     * 
     * Defines the tools that can be used by the LLM during processing.
     * 
     * @return array List of tool definitions
     */
    private function getAvailableTools()
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_document',
                    'description' => 'Retrieve the full content of a specific document by providing its unique document ID. Use this when you need to access the complete text of a particular document for reference or analysis.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'description' => 'The unique identifier of the document to retrieve. This should be a valid document ID that exists in the system.'
                            ]
                        ],
                        'required' => ['id']
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_template',
                    'description' => 'Retrieve a relevant template document that matches the current context and content. Use this when you need a structural template or format example to base your response on, particularly for creating consistent reports or documents.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'description' => 'The type of the template (e.g., "mri" for MRI reports, "daily" for daily reports).',
                                'default' => ''
                            ]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_examples',
                    'description' => 'Retrieve relevant example snippets from previous reports that are similar to the current context. Use this when you need to see how similar content was previously handled, to maintain consistency in style, terminology, and structure.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'count' => [
                                'type' => 'integer',
                                'description' => 'The number of examples to retrieve (1-20). Use more examples when you need comprehensive reference material, fewer when you need just a quick reminder of the style.',
                                'default' => 5
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Call the LLM API with the specified prompt
     * 
     * Makes an HTTP POST request to the configured API endpoint with
     * the prompt and other parameters. Handles authentication if an
     * API key is configured.
     * 
     * The method constructs a conversation with system and user messages,
     * including context information from metadata when available.
     * 
     * Complex logic includes:
     * 1. Loading and enhancing the system prompt with metadata context
     * 2. Building the API request with model parameters
     * 3. Handling authentication with API key if configured
     * 4. Making the HTTP request with proper error handling
     * 5. Parsing and validating the API response
     * 6. Supporting tool usage with automatic tool calling when enabled
     * 7. Implementing context enhancement with templates, examples, and snippets
     * 
     * The context information includes:
     * - Template content: Used as a starting point for the response
     * - Example pages: Full content of specified example pages
     * - Text snippets: Relevant text examples from ChromaDB
     * 
     * When tools are enabled, the method supports automatic tool calling:
     * - Tools can retrieve documents, templates, and examples as needed
     * - Tool responses are cached to avoid duplicate calls with identical parameters
     * - Infinite loop protection prevents excessive tool calls
     * 
     * @param string $command The command name for loading command-specific system prompts
     * @param string $prompt The prompt to send to the LLM as user message
     * @param array $metadata Optional metadata containing template, examples, and snippets
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The response content from the LLM
     * @throws Exception If the API request fails or returns unexpected format
     */
    
    private function callAPI($command, $prompt, $metadata = [], $useContext = true, $useTools = false)
    {
        // Load system prompt which provides general instructions to the LLM
        $systemPrompt = $this->loadSystemPrompt($command, []);
        
        // Enhance the prompt with context information from metadata
        // This provides the LLM with additional context about templates and examples
        if ($useContext && !empty($metadata) && (!empty($metadata['template']) || !empty($metadata['examples']) || !empty($metadata['snippets']))) {
            $contextInfo = "\n\n<context>\n";
            
            // Add template content if specified in metadata
            if (!empty($metadata['template'])) {
                $templateContent = $this->getPageContent($metadata['template']);
                if ($templateContent !== false) {
                    $contextInfo .= "\n\n<template>\nPornește de la acest template (" . $metadata['template'] . "):\n" . $templateContent . "\n</template>\n";
                }
            }
            
            // Add example pages content if specified in metadata
            if (!empty($metadata['examples'])) {
                $examplesContent = [];
                foreach ($metadata['examples'] as $example) {
                    $content = $this->getPageContent($example);
                    if ($content !== false) {
                        $examplesContent[] = "\n<example_page source=\"" . $example . "\">\n" . $content . "\n</example_page>\n";
                    }
                }
                if (!empty($examplesContent)) {
                    $contextInfo .= "\n<style_examples>\nAcestea sunt rapoarte complete anterioare - studiază stilul meu de redactare:\n" . implode("\n", $examplesContent) . "\n</style_examples>\n";
                }
            }
            
            // Add text snippets if specified in metadata
            if (!empty($metadata['snippets'])) {
                $snippetsContent = [];
                foreach ($metadata['snippets'] as $index => $snippet) {
                    // These are text snippets from ChromaDB
                    $snippetsContent[] = "\n<example id=\"" . ($index + 1) . "\">\n" . $snippet . "\n</example>\n";
                }
                if (!empty($snippetsContent)) {
                    $contextInfo .= "\n\n<style_examples>\nAcestea sunt exemple din rapoartele mele anterioare - studiază stilul de redactare, terminologia și structura frazelor:\n" . implode("\n", $snippetsContent) . "\n</style_examples>\n";
                }
            }
            
            $contextInfo .= "\n</context>\n";

            // Append context information to system prompt
            $prompt = $contextInfo . "\n\n" . $prompt;
        }
        
        // Prepare API request data with model parameters
        $data = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 6144,
            'stream' => false,
            'keep_alive' => '30m',
            'think' => true
        ];
        
        // Add tools to the request only if useTools is true
        if ($useTools) {
            // Define available tools
            $data['tools'] = $this->getAvailableTools();
            $data['tool_choice'] = 'auto';
            $data['parallel_tool_calls'] = false;
        }
        
        // Only add parameters if they are defined and not null
        if ($this->temperature !== null) {
            $data['temperature'] = $this->temperature;
        }
        if ($this->top_p !== null) {
            $data['top_p'] = $this->top_p;
        }
        if ($this->top_k !== null) {
            $data['top_k'] = $this->top_k;
        }
        if ($this->min_p !== null) {
            $data['min_p'] = $this->min_p;
        }

        // Make an API call with tool responses
        return $this->callAPIWithTools($data, false);
    }
    
    /**
     * Handle tool calls from the LLM
     * 
     * Processes tool calls made by the LLM and returns appropriate responses.
     * Implements caching to avoid duplicate calls with identical parameters.
     * 
     * @param array $toolCall The tool call data from the LLM
     * @return array The tool response message
     */
    private function handleToolCall($toolCall)
    {
        $toolName = $toolCall['function']['name'];
        $arguments = json_decode($toolCall['function']['arguments'], true);
        
        // Create a cache key from the tool name and arguments
        $cacheKey = md5($toolName . serialize($arguments));
        
        // Check if we have a cached result for this tool call
        if (isset($this->toolCallCache[$cacheKey])) {
            // Return cached result and indicate it was found in cache
            $toolResponse = $this->toolCallCache[$cacheKey];
            // Update with current tool call ID
            $toolResponse['tool_call_id'] = $toolCall['id'];
            $toolResponse['cached'] = true; // Indicate this response was cached
            return $toolResponse;
        }
        
        $toolResponse = [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id'],
            'cached' => false // Indicate this is a fresh response
        ];
        
        switch ($toolName) {
            case 'get_document':
                $documentId = $arguments['id'];
                $content = $this->getPageContent($documentId);
                if ($content === false) {
                    $toolResponse['content'] = 'Document not found: ' . $documentId;
                } else {
                    $toolResponse['content'] = $content;
                }
                break;
                
            case 'get_template':
                // Get template content using the convenience function
                $toolResponse['content'] = $this->getTemplateContent();
                break;
                
            case 'get_examples':
                // Get examples content using the convenience function
                $count = isset($arguments['count']) ? (int)$arguments['count'] : 5;
                $toolResponse['content'] = '<examples>\n' . $this->getSnippets($count) . '\n</examples>';
                break;
                
            default:
                $toolResponse['content'] = 'Unknown tool: ' . $toolName;
        }
        
        // Cache the result for future calls with the same parameters
        $cacheEntry = $toolResponse;
        // Remove tool_call_id and cached flag from cache as they change per call
        unset($cacheEntry['tool_call_id']);
        unset($cacheEntry['cached']);
        $this->toolCallCache[$cacheKey] = $cacheEntry;
        
        return $toolResponse;
    }
    
    /**
     * Make an API call with tool responses
     * 
     * Sends a follow-up request to the LLM with tool responses.
     * Implements complex logic for handling tool calls with caching and loop protection.
     * 
     * Complex logic includes:
     * 1. Making HTTP requests with proper authentication and error handling
     * 2. Processing tool calls from the LLM response
     * 3. Caching tool responses to avoid duplicate calls with identical parameters
     * 4. Tracking tool call counts to prevent infinite loops
     * 5. Implementing loop protection with call count limits
     * 6. Handling recursive tool calls until final content is generated
     * 
     * Loop protection works by:
     * - Tracking individual tool call counts (max 3 per tool)
     * - Tracking total tool calls (max 10 total)
     * - Disabling tools when limits are exceeded to break potential loops
     * 
     * @param array $data The API request data including messages with tool responses
     * @param bool $toolsCalled Whether tools have already been called (used for loop protection)
     * @param bool $useTools Whether to process tool calls (used for loop protection)
     * @return string The final response content
     */
    private function callAPIWithTools($data, $toolsCalled = false, $useTools = true)
    {
        // Set up HTTP headers, including authentication if API key is configured
        $headers = [
            'Content-Type: application/json'
        ];
        
        if (!empty($this->api_key)) {
            $headers[] = 'Authorization: Bearer ' . $this->api_key;
        }
        
       // If tools have already been called, remove tools and tool_choice from data to prevent infinite loops
        if ($toolsCalled) {
            unset($data['tools']);
            unset($data['tool_choice']);
        }

        // Initialize and configure cURL for the API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Execute the API request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Handle cURL errors
        if ($error) {
            throw new Exception('API request failed: ' . $error);
        }
        
        // Handle HTTP errors
        if ($httpCode !== 200) {
            throw new Exception('API request failed with HTTP code: ' . $httpCode);
        }
        
        // Parse and validate the JSON response
        $result = json_decode($response, true);
        
        // Extract the content from the response if available
        if (isset($result['choices'][0]['message']['content'])) {
            $content = trim($result['choices'][0]['message']['content']);
            // Reset tool call counts when we get final content
            $this->toolCallCounts = [];
            return $content;
        }
        
        // Handle tool calls if present
        if ($useTools && isset($result['choices'][0]['message']['tool_calls'])) {
            $toolCalls = $result['choices'][0]['message']['tool_calls'];
            // Start with original messages
            $messages = $data['messages'];
            // Add assistant's message with tool calls, keeping all original fields except for content (which is null)
            $assistantMessage = [];
            foreach ($result['choices'][0]['message'] as $key => $value) {
                if ($key !== 'content') {
                    $assistantMessage[$key] = $value;
                }
            }
            // Add assistant's message with tool calls
            $messages[] = $assistantMessage;
            
            // Process each tool call and track counts to prevent infinite loops
            foreach ($toolCalls as $toolCall) {
                $toolName = $toolCall['function']['name'];
                // Increment tool call count
                if (!isset($this->toolCallCounts[$toolName])) {
                    $this->toolCallCounts[$toolName] = 0;
                }
                $this->toolCallCounts[$toolName]++;
                
                $toolResponse = $this->handleToolCall($toolCall);
                $messages[] = $toolResponse;
            }

            // Check if any tool has been called more than 3 times
            $toolsCalledCount = 0;
            foreach ($this->toolCallCounts as $count) {
                if ($count > 3) {
                    // If any tool called more than 3 times, disable tools to break loop
                    $toolsCalled = true;
                    break;
                }
                $toolsCalledCount += $count;
            }
            
            // If total tool calls exceed 10, also disable tools
            if ($toolsCalledCount > 10) {
                $toolsCalled = true;
            }
            
            // Make another API call with tool responses
            $data['messages'] = $messages;
            return $this->callAPIWithTools($data, $toolsCalled, $useTools);
        }
        
        // Throw exception for unexpected response format
        throw new Exception('Unexpected API response format');
    }
    
    /**
     * Load a prompt template from a DokuWiki page and replace placeholders
     * 
     * Loads prompt templates from DokuWiki pages with IDs in the format
     * dokullm:profiles:PROFILE:PROMPT_NAME
     * 
     * The method implements a profile fallback mechanism:
     * 1. First tries to load the prompt from the configured profile
     * 2. If not found, falls back to default prompts
     * 3. Throws an exception if neither is available
     * 
     * After loading the prompt, it scans for placeholders and automatically
     * adds missing ones with appropriate values before replacing all placeholders.
     * 
     * @param string $promptName The name of the prompt (e.g., 'create', 'rewrite')
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The processed prompt with placeholders replaced
     * @throws Exception If the prompt page cannot be loaded from any profile
     */
    private function loadPrompt($promptName, $variables = [])
    {
        // Default to 'default' if profile is not set
        if (empty($this->profile)) {
            $this->profile = 'default';
        }
        
        // Construct the page ID for the prompt in the configured profile
        $promptPageId = 'dokullm:profiles:' . $this->profile . ':' . $promptName;
        
        // Try to get the content of the prompt page in the configured profile
        $prompt = $this->getPageContent($promptPageId);
        
        // If the profile-specific prompt doesn't exist, try default as fallback
        if ($prompt === false && $this->profile !== 'default') {
            $promptPageId = 'dokullm:profile:default:' . $promptName;
            $prompt = $this->getPageContent($promptPageId);
        }
        
        // If still no prompt found, throw an exception
        if ($prompt === false) {
            throw new Exception('Prompt page not found: ' . $promptPageId);
        }
        
        // Find placeholders in the prompt
        $placeholders = $this->findPlaceholders($prompt);
        
        // Add missing placeholders with appropriate values
        foreach ($placeholders as $placeholder) {
            // Skip if already provided in variables
            if (isset($variables[$placeholder])) {
                continue;
            }
            
            // Add appropriate values for specific placeholders
            switch ($placeholder) {
                case 'template':
                    // If we have a page_template in variables, use it
                    $variables[$placeholder] = $this->getTemplateContent($variables['page_template']);
                    break;
                    
                case 'snippets':
                    $variables[$placeholder] = $this->chromaClient !== null ? $this->getSnippets(10) : '( no examples )';
                    break;
                    
                case 'examples':
                    // If we have example page IDs in metadata, add examples content
                    $variables[$placeholder] = $this->getExamplesContent($variables['page_examples']);
                    break;
                    
                case 'previous':
                    // If we have a previous report page ID in metadata, add previous content
                    $variables[$placeholder] = $this->getPreviousContent($variables['page_previous']);
                    
                    // Add current and previous dates to metadata
                    $variables['current_date'] = $this->getPageDate($this->pageId);
                    $variables['previous_date'] = !empty($variables['page_previous']) ? 
                                                $this->getPageDate($variables['page_previous']) : 
                                                '';
                    break;
                    
                default:
                    // For other placeholders, leave them empty or set a default value
                    $variables[$placeholder] = '';
                    break;
            }
        }
        
        // Replace placeholders with actual values
        // Placeholders are in the format {placeholder_name}
        foreach ($variables as $placeholder => $value) {
            $prompt = str_replace('{' . $placeholder . '}', $value, $prompt);
        }
        
        // Return the processed prompt
        return $prompt;
    }
    
    /**
     * Load system prompt with optional command-specific appendage
     * 
     * Loads the main system prompt and appends any command-specific system prompt
     * if available.
     * 
     * @param string $action The action/command name
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The combined system prompt
     */
    private function loadSystemPrompt($action, $variables = [])
    {
        // Load system prompt which provides general instructions to the LLM
        $systemPrompt = $this->loadPrompt('system', $variables);
        
        // Check if there's a command-specific system prompt appendage
        if (!empty($action)) {
            try {
                $commandSystemPrompt = $this->loadPrompt($action . ':system', $variables);            
                if ($commandSystemPrompt !== false) {
                    $systemPrompt .= "\n" . $commandSystemPrompt;
                }
            } catch (Exception $e) {
                // Ignore exceptions when loading command-specific system prompt
                // This allows the main system prompt to still be used
            }
        }
        
        return $systemPrompt;
    }
    
    /**
     * Get the content of a DokuWiki page
     * 
     * Retrieves the raw content of a DokuWiki page by its ID.
     * Used for loading template and example page content for context.
     * 
     * @param string $pageId The page ID to retrieve
     * @return string|false The page content or false if not found/readable
     */
    public function getPageContent($pageId)
    {
        // Convert page ID to file path
        $pageFile = wikiFN($pageId);
        
        // Check if file exists and is readable
        if (file_exists($pageFile) && is_readable($pageFile)) {
            return file_get_contents($pageFile);
        }
        
        return false;
    }
    
    /**
     * Extract date from page ID or file timestamp
     * 
     * Attempts to extract a date in YYmmdd format from the page ID.
     * If not found, uses the file's last modification timestamp.
     * 
     * @param string $pageId Optional page ID to extract date from (defaults to current page)
     * @return string Formatted date string (YYYY-MM-DD)
     */
    private function getPageDate($pageId = null)
    {
        // Use provided page ID or current page ID
        $targetPageId = $pageId ?: $this->pageId;
        
        // Try to extract date from page ID (looking for YYmmdd pattern)
        if (preg_match('/(\d{2})(\d{2})(\d{2})/', $targetPageId, $matches)) {
            // Convert YYmmdd to YYYY-MM-DD
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];
            
            // Assume 20xx for years 00-69, 19xx for years 70-99
            $fullYear = intval($year) <= 69 ? '20' . $year : '19' . $year;
            
            return $fullYear . '-' . $month . '-' . $day;
        }
        
        // Fallback to file timestamp
        $pageFile = wikiFN($targetPageId);
        if (file_exists($pageFile)) {
            $timestamp = filemtime($pageFile);
            return date('Y-m-d', $timestamp);
        }
        
        // Return empty string if no date can be determined
        return '';
    }
    
    /**
     * Get current text
     * 
     * Retrieves the current text stored from the process function.
     * 
     * @return string The current text
     */
    private function getCurrentText()
    {
        return $this->currentText;
    }
    
    /**
     * Scan text for placeholders
     * 
     * Finds all placeholders in the format {placeholder_name} in the provided text
     * and returns an array of unique placeholder names.
     * 
     * @param string $text The text to scan for placeholders
     * @return array List of unique placeholder names found in the text
     */
    public function findPlaceholders($text)
    {
        $placeholders = [];
        $pattern = '/\{([^}]+)\}/';
        
        if (preg_match_all($pattern, $text, $matches)) {
            // Get unique placeholder names
            $placeholders = array_unique($matches[1]);
        }
        
        return $placeholders;
    }
    
    /**
     * Get template content for the current text
     * 
     * Convenience function to retrieve template content. If a pageId is provided,
     * retrieves content directly from that page. Otherwise, queries ChromaDB for
     * a relevant template based on the current text.
     * 
     * @param string|null $pageId Optional page ID to retrieve template from directly
     * @return string The template content or empty string if not found
     */
    private function getTemplateContent($pageId = null)
    {
        // If pageId is provided, use it directly
        if ($pageId !== null) {
            $templateContent = $this->getPageContent($pageId);
            if ($templateContent !== false) {
                return $templateContent;
            }
        }
        
        // If ChromaDB is disabled, return empty template
        if ($this->chromaClient === null) {
            return '( no template )';
        }
        
        // Otherwise, get template suggestion for the current text
        $pageId = $this->queryChromaDBTemplate($this->getCurrentText());
        if (!empty($pageId)) {
            $templateContent = $this->getPageContent($pageId[0]);
            if ($templateContent !== false) {
                return $templateContent;
            }
        }
        return '( no template )';
    }
    
    /**
     * Get snippets content for the current text
     * 
     * Convenience function to retrieve relevant snippets for the current text.
     * Queries ChromaDB for relevant snippets and returns them formatted.
     * 
     * @param int $count Number of snippets to retrieve (default: 10)
     * @return string Formatted snippets content or empty string if not found
     */
    private function getSnippets($count = 10)
    {
        // If ChromaDB is disabled, return empty snippets
        if ($this->chromaClient === null) {
            return '( no examples )';
        }
        
        // Get example snippets for the current text
        $snippets = $this->queryChromaDBSnippets($this->getCurrentText(), $count);
        if (!empty($snippets)) {
            $formattedSnippets = [];
            foreach ($snippets as $index => $snippet) {
                $formattedSnippets[] = '<example id="' . ($index + 1) . '">\n' . $snippet . '\n</example>';
            }
            return implode("\n", $formattedSnippets);
        }
        return '( no examples )';
    }
    
    /**
     * Get examples content from example page IDs
     * 
     * Convenience function to retrieve content from example pages.
     * Returns the content of each page packed in XML elements.
     * 
     * @param array $exampleIds List of example page IDs
     * @return string Formatted examples content or empty string if not found
     */
    private function getExamplesContent($exampleIds = [])
    {
        if (empty($exampleIds) || !is_array($exampleIds)) {
            return '( no examples )';
        }
        
        $examplesContent = [];
        foreach ($exampleIds as $index => $exampleId) {
            $content = $this->getPageContent($exampleId);
            if ($content !== false) {
                $examplesContent[] = '<example_page source="' . $exampleId . '">\n' . $content . '\n</example_page>';
            }
        }
        
        return implode("\n", $examplesContent);
    }
    
    /**
     * Get previous report content from previous page ID
     * 
     * Convenience function to retrieve content from a previous report page.
     * Returns the content of the previous page or a default message if not found.
     * 
     * @param string $previousId Previous page ID
     * @return string Previous report content or default message if not found
     */
    private function getPreviousContent($previousId = '')
    {
        if (empty($previousId)) {
            return '( no previous report )';
        }
        
        $content = $this->getPageContent($previousId);
        if ($content !== false) {
            return $content;
        }
        
        return '( previous report not found )';
    }
    
    /**
     * Get ChromaDB client with configuration
     * 
     * Returns the ChromaDB client and collection name.
     * If a client was passed in the constructor, use it. Otherwise, this method
     * should not be called as it depends on getConf() which is not available.
     * 
     * @return array Array containing the ChromaDB client and collection name
     * @throws Exception If no ChromaDB client is available
     */
    private function getChromaDBClient()
    {
        // If we have a ChromaDB client passed in constructor, use it
        if ($this->chromaClient !== null) {
            // Get the collection name based on the page ID
	    // FIXME
            $chromaCollection = 'reports';
            $pageId = $pageId;
            
            if (!empty($this->pageId)) {
                // Split the page ID by ':' and take the first part as collection name
                $parts = explode(':', $this->pageId);
                if (isset($parts[0]) && !empty($parts[0])) {
                    // If the first part is 'playground', use the default collection
                    // Otherwise, use the first part as the collection name
                    if ($parts[0] === 'playground') {
                        $chromaCollection = '';
                    } else {
                        $chromaCollection = $parts[0];
                    }
                }
            }
            
            return [$this->chromaClient, $chromaCollection];
        }
        
        // If we don't have a ChromaDB client, we can't create one here
        // because getConf() is not available in this context
        throw new Exception('No ChromaDB client available');
    }
    
    /**
     * Query ChromaDB for relevant documents
     * 
     * Generates embeddings for the input text and queries ChromaDB for similar documents.
     * Extracts modality from the current page ID to use as the collection name.
     * 
     * @param string $text The text to find similar documents for
     * @param int $limit Maximum number of documents to retrieve (default: 5)
     * @param array|null $where Optional filter conditions for metadata
     * @return array List of document IDs
     */
    private function queryChromaDB($text, $limit = 5, $where = null)
    {
        try {
            // Get ChromaDB client and collection name
            list($chromaClient, $chromaCollection) = $this->getChromaDBClient();
            // Query for similar documents
            $results = $chromaClient->queryCollection($chromaCollection, [$text], $limit, $where);
            
            // Extract document IDs from results
            $documentIds = [];
            if (isset($results['ids'][0]) && is_array($results['ids'][0])) {
                foreach ($results['ids'][0] as $id) {
                    // Use the ChromaDB ID directly without conversion
                    $documentIds[] = $id;
                }
            }
            
            return $documentIds;
        } catch (Exception $e) {
            // Log error but don't fail the operation
            error_log('ChromaDB query failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Query ChromaDB for relevant documents and return text snippets
     * 
     * Generates embeddings for the input text and queries ChromaDB for similar documents.
     * Returns the actual text snippets instead of document IDs.
     * 
     * @param string $text The text to find similar documents for
     * @param int $limit Maximum number of documents to retrieve (default: 10)
     * @param array|null $where Optional filter conditions for metadata
     * @return array List of text snippets
     */
    private function queryChromaDBSnippets($text, $limit = 10, $where = null)
    {
        try {
            // Get ChromaDB client and collection name
            list($chromaClient, $chromaCollection) = $this->getChromaDBClient();
            // Query for similar documents
            $results = $chromaClient->queryCollection($chromaCollection, [$text], $limit, $where);
            
            // Extract document texts from results
            $snippets = [];
            if (isset($results['documents'][0]) && is_array($results['documents'][0])) {
                foreach ($results['documents'][0] as $document) {
                    $snippets[] = $document;
                }
            }
            
            return $snippets;
        } catch (Exception $e) {
            // Log error but don't fail the operation
            error_log('ChromaDB query failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Query ChromaDB for a template document
     * 
     * Generates embeddings for the input text and queries ChromaDB for a template document
     * by filtering with metadata 'template=true'.
     * 
     * @param string $text The text to find a template for
     * @return array List of template document IDs (maximum 1)
     */
    public function queryChromaDBTemplate($text)
    {
        $templateIds = $this->queryChromaDB($text, 1, ['type' => 'template']);
        
        // Remove chunk number (e.g., "@2") from the ID to get the base document ID
        if (!empty($templateIds)) {
            $templateIds[0] = preg_replace('/@\\d+$/', '', $templateIds[0]);
        }
        
        return $templateIds;
    }
    
}
