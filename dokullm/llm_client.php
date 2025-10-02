<?php
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

// Include ChromaDB client
require_once DOKU_PLUGIN . 'dokullm/chromadb_client.php';

/**
 * LLM Client class for handling API communications
 * 
 * Manages configuration settings and provides methods for various
 * text processing operations through an LLM API.
 */
class llm_client_plugin_dokullm
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
    
    /** @var bool Whether to enable thinking in the LLM responses */
    private $think;
    
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
     * - language: Language code for prompt templates
     * - temperature: Temperature setting for response randomness (0.0-1.0)
     * - top_p: Top-p (nucleus sampling) setting (0.0-1.0)
     * - top_k: Top-k setting (integer >= 1)
     * - min_p: Minimum probability threshold (0.0-1.0)
     * - think: Whether to enable thinking in LLM responses (boolean)
     */
    public function __construct()
    {
        global $conf;
        $this->api_url = $conf['plugin']['dokullm']['api_url'];
        $this->api_key = $conf['plugin']['dokullm']['api_key'];
        $this->model = $conf['plugin']['dokullm']['model'];
        $this->timeout = $conf['plugin']['dokullm']['timeout'];
        $this->temperature = $conf['plugin']['dokullm']['temperature'];
        $this->top_p = $conf['plugin']['dokullm']['top_p'];
        $this->top_k = $conf['plugin']['dokullm']['top_k'];
        $this->min_p = $conf['plugin']['dokullm']['min_p'];
        $this->think = $conf['plugin']['dokullm']['think'];
    }
    


    public function process($action, $text, $metadata = [], $useContext = true)
    {
        // Store the current text for tool usage
        $this->currentText = $text;
        
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt($action, ['text' => $text, 'think' => $think]);
        
        return $this->callAPI($action, $prompt, $metadata, $useContext);
    }
    


    /**
     * Create the provided text using the LLM
     * 
     * Sends a prompt to the LLM asking it to create the given text.
     * First queries ChromaDB for relevant documents to include as examples.
     * If no template is defined, queries ChromaDB for a template.
     * 
     * @param string $text The text to create
     * @param array $metadata Optional metadata containing template, examples, and snippets
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The created text
     */
    public function createReport($text, $metadata = [], $useContext = true)
    {
        // Store the current text for tool usage
        $this->currentText = $text;
        
        // Check if tools should be used based on configuration
        global $conf;
        $useTools = $conf['plugin']['dokullm']['use_tools'] ?? true;
        
        // Only try to find template and add snippets if tools are not enabled
        // When tools are enabled, the LLM will call get_template and get_examples as needed
        if (!$useTools) {
            // If no template is defined, try to find one using ChromaDB
            if (empty($metadata['template'])) {
                $templateResult = $this->queryChromaDBTemplate($text);
                if (!empty($templateResult)) {
                    // Use the first result as template
                    $metadata['template'] = $templateResult[0];
                }
            }

            // Query ChromaDB for relevant documents to use as examples
            $chromaResults = $this->queryChromaDBSnippets($text, 10);
            
            // Add ChromaDB results to metadata as snippets
            if (!empty($chromaResults)) {
                // Merge with existing snippets
                $metadata['snippets'] = array_merge(
                    isset($metadata['snippets']) ? $metadata['snippets'] : [],
                    $chromaResults
                );
            }
        }
        
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('create', ['text' => $text, 'think' => $think]);
        
        return $this->callAPI('create', $prompt, $metadata, $useContext);
    }
    
    /**
     * Compare two texts and highlight differences
     * 
     * Sends a prompt to the LLM asking it to compare two texts and
     * highlight their similarities and differences.
     * 
     * @param string $text The current text to compare
     * @param array $metadata Optional metadata containing template, examples, and previous report reference
     * @return string The comparison results
     */
    public function compareText($text, $metadata = [], $useContext = false)
    {
        // Store the current text for tool usage
        $this->currentText = $text;
        
        // Load previous report from metadata if specified
        $previousText = '';
        if (!empty($metadata['previous_report_page'])) {
            $previousText = $this->getPageContent($metadata['previous_report_page']);
            if ($previousText === false) {
                $previousText = '';
            }
        }
        
        // Extract dates for placeholders
        $currentDate = $this->getPageDate();
        $previousDate = !empty($metadata['previous_report_page']) ? 
                        $this->getPageDate($metadata['previous_report_page']) : 
                        '';
        
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('compare', [
            'text' => $text, 
            'previous_text' => $previousText,
            'current_date' => $currentDate,
            'previous_date' => $previousDate,
            'think' => $think
        ]);
        
        return $this->callAPI('compare', $prompt, $metadata, $useContext);
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
    public function processCustomPrompt($text, $customPrompt, $metadata = [], $useContext = true)
    {
        // Store the current text for tool usage
        $this->currentText = $text;
        
        // Format the prompt with the text and custom prompt
        $prompt = $customPrompt . "\n\nText to process:\n" . $text;
        
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
                            'language' => [
                                'type' => 'string',
                                'description' => 'The language the template should be written in (e.g., "ro" for Romanian, "en" for English).',
                                'default' => 'ro'
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
     * 
     * The context information includes:
     * - Template content: Used as a starting point for the response
     * - Example pages: Full content of specified example pages
     * - Text snippets: Relevant text examples from ChromaDB
     * 
     * @param string $command The command name for loading command-specific system prompts
     * @param string $prompt The prompt to send to the LLM as user message
     * @param array $metadata Optional metadata containing template, examples, and snippets
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The response content from the LLM
     * @throws Exception If the API request fails or returns unexpected format
     */
    
    private function callAPI($command, $prompt, $metadata = [], $useContext = true)
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
        
        // Check if tools should be used based on configuration
        global $conf;
        $useTools = $conf['plugin']['dokullm']['use_tools'] ?? true;
        
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
            // Return cached result
            $toolResponse = $this->toolCallCache[$cacheKey];
            // Update with current tool call ID
            $toolResponse['tool_call_id'] = $toolCall['id'];
            return $toolResponse;
        }
        
        $toolResponse = [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id']
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
                // Get template suggestion for the current text
                // This would typically use the same logic as queryChromaDBTemplate
                // Note: We ignore the language parameter for now as all reports are in Romanian
                $templateIds = $this->queryChromaDBTemplate($this->getCurrentText());
                if (!empty($templateIds)) {
                    $templateContent = $this->getPageContent($templateIds[0]);
                    if ($templateContent !== false) {
                        $toolResponse['content'] = $templateContent;
                    } else {
                        $toolResponse['content'] = 'Template found but content could not be retrieved: ' . $templateIds[0];
                    }
                } else {
                    $toolResponse['content'] = 'No template found for the current context';
                }
                break;
                
            case 'get_examples':
                // Get example snippets for the current text
                $count = isset($arguments['count']) ? (int)$arguments['count'] : 5;
                $examples = $this->queryChromaDBSnippets($this->getCurrentText(), $count);
                if (!empty($examples)) {
                    $formattedExamples = [];
                    foreach ($examples as $index => $example) {
                        $formattedExamples[] = '<example id="' . ($index + 1) . '">' . $example . '</example>';
                    }
                    $toolResponse['content'] = '<examples>' . implode("\n", $formattedExamples) . '</examples>';
                } else {
                    $toolResponse['content'] = 'No examples found for the current context';
                }
                break;
                
            default:
                $toolResponse['content'] = 'Unknown tool: ' . $toolName;
        }
        
        // Cache the result for future calls with the same parameters
        $cacheEntry = $toolResponse;
        unset($cacheEntry['tool_call_id']); // Remove tool_call_id from cache as it changes per call
        $this->toolCallCache[$cacheKey] = $cacheEntry;
        
        return $toolResponse;
    }
    
    /**
     * Make an API call with tool responses
     * 
     * Sends a follow-up request to the LLM with tool responses.
     * 
     * @param array $data The API request data including messages with tool responses
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
            // Remove content between <think> and </think> tags
            $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
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
            
            // Process each tool call
            foreach ($toolCalls as $toolCall) {
                $toolResponse = $this->handleToolCall($toolCall);
                $messages[] = $toolResponse;
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
     * dokullm:prompts:LANGUAGE:PROMPT_NAME
     * 
     * The method implements a language fallback mechanism:
     * 1. First tries to load the prompt in the configured language
     * 2. If not found, falls back to English prompts
     * 3. Throws an exception if neither is available
     * 
     * After loading the prompt, it replaces placeholders with actual values
     * using a simple string replacement mechanism.
     * 
     * @param string $promptName The name of the prompt (e.g., 'create', 'rewrite')
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The processed prompt with placeholders replaced
     * @throws Exception If the prompt page cannot be loaded in any language
     */
    private function loadPrompt($promptName, $variables = [])
    {
        global $conf;
        $language = $conf['plugin']['dokullm']['language'];
        
        // Default to 'en' if language is 'default' or not set
        if ($language === 'default' || empty($language)) {
            $language = 'en';
        }
        
        // Construct the page ID for the prompt in the configured language
        $promptPageId = 'dokullm:prompts:' . $language . ':' . $promptName;
        
        // Try to get the content of the prompt page in the configured language
        $prompt = $this->getPageContent($promptPageId);
        
        // If the language-specific prompt doesn't exist, try English as fallback
        if ($prompt === false && $language !== 'en') {
            $promptPageId = 'dokullm:prompts:en:' . $promptName;
            $prompt = $this->getPageContent($promptPageId);
        }
        
        // If still no prompt found, throw an exception
        if ($prompt === false) {
            throw new Exception('Prompt page not found: ' . $promptPageId);
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
            global $conf;
            $language = $conf['plugin']['dokullm']['language'];
            
            // Default to 'en' if language is 'default' or not set
            if ($language === 'default' || empty($language)) {
                $language = 'en';
            }
            
            $commandSystemPrompt = $this->getPageContent('dokullm:prompts:' . $language . ':' . $action . ':system');
            
            if ($commandSystemPrompt !== false) {
                $systemPrompt .= "\n" . $commandSystemPrompt;
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
        global $ID;
        
        // Use provided page ID or current page ID
        $targetPageId = $pageId ?: $ID;
        
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
     * Get ChromaDB client with configuration
     * 
     * Creates and returns a ChromaDB client with the appropriate configuration.
     * Extracts modality from the current page ID to use as the collection name.
     * 
     * @return array Array containing the ChromaDB client and collection name
     */
    private function getChromaDBClient()
    {
        // Include config.php to get ChromaDB configuration
        require_once 'config.php';
        
        // Get ChromaDB configuration from config.php
        $chromaHost = defined('CHROMA_HOST') ? CHROMA_HOST : 'localhost';
        $chromaPort = defined('CHROMA_PORT') ? CHROMA_PORT : 8000;
        $chromaTenant = defined('CHROMA_TENANT') ? CHROMA_TENANT : 'dokullm';
        $chromaDatabase = defined('CHROMA_DATABASE') ? CHROMA_DATABASE : 'dokullm';
        $chromaDefaultCollection = defined('CHROMA_COLLECTION') ? CHROMA_COLLECTION : 'documents';
        
        // Use the first part of the current page ID as collection name, fallback to default
        global $ID;
        $chromaCollection = $chromaDefaultCollection; // Default collection name
        
        if (!empty($ID)) {
            // Split the page ID by ':' and take the first part as collection name
            $parts = explode(':', $ID);
            if (isset($parts[0]) && !empty($parts[0])) {
                // If the first part is 'playground', use the default collection
                // Otherwise, use the first part as the collection name
                if ($parts[0] === 'playground') {
                    $chromaCollection = $chromaDefaultCollection;
                } else {
                    $chromaCollection = $parts[0];
                }
            }
        }
        
        // Create ChromaDB client
        $chromaClient = new ChromaDBClient($chromaHost, $chromaPort, $chromaTenant, $chromaDatabase);
        

        return [$chromaClient, $chromaCollection];
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
