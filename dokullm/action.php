<?php
/**
 * DokuWiki Plugin dokullm (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Costin Stroie <costinstroie@eridu.eu.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

/**
 * Main action component for the dokullm plugin
 * 
 * This class handles:
 * - Registering event handlers for page rendering and AJAX calls
 * - Adding JavaScript to edit pages
 * - Processing AJAX requests from the frontend
 * - Handling page template loading with metadata support
 * - Adding copy page button to page tools
 * 
 * The plugin provides integration with LLM APIs for text processing
 * operations directly within the DokuWiki editor.
 * 
 * Configuration options:
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
 * - show_copy_button: Whether to show the copy page button (boolean)
 * - replace_id: Whether to replace template ID when copying (boolean)
 */
class action_plugin_dokullm extends DokuWiki_Action_Plugin
{
    /**
     * Register the event handlers for this plugin
     * 
     * Hooks into:
     * - TPL_METAHEADER_OUTPUT: To add JavaScript to edit pages
     * - AJAX_CALL_UNKNOWN: To handle plugin-specific AJAX requests
     * 
     * @param Doku_Event_Handler $controller The event handler controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleMetaHeaders');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('COMMON_PAGETPL_LOAD', 'BEFORE', $this, 'handleTemplate');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addCopyPageButton', array());
    }

    /**
     * Add JavaScript to the page header for edit pages
     * 
     * This method checks if we're on an edit or preview page and adds
     * the plugin's JavaScript file to the page header.
     * 
     * @param Doku_Event $event The event object
     * @param mixed $param Additional parameters
     */
    public function handleMetaHeaders(Doku_Event $event, $param)
    {
        global $INFO;
        
        // Only add JS to edit pages
        if ($INFO['act'] == 'edit' || $INFO['act'] == 'preview') {
            $event->data['script'][] = array(
                'type' => 'text/javascript',
                'src' => DOKU_BASE . 'lib/plugins/dokullm/script.js',
                '_data' => 'dokullm'
            );
        }
    }

    /**
     * Handle AJAX requests for the plugin
     * 
     * Processes AJAX calls with the identifier 'plugin_dokullm' and
     * routes them to the appropriate text processing method.
     * 
     * @param Doku_Event $event The event object
     * @param mixed $param Additional parameters
     */
    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'plugin_dokullm') {
            return;
        }
        
        $event->stopPropagation();
        $event->preventDefault();
        
        // Handle the AJAX request
        $this->processRequest();
    }

    /**
     * Process the AJAX request and return JSON response
     * 
     * Extracts action, text, prompt, metadata, and template parameters from the request,
     * validates the input, and calls the appropriate processing method.
     * Returns JSON encoded result or error.
     * 
     * @return void
     */
    private function processRequest()
    {
        global $INPUT;
        
        $action = $INPUT->str('action');
        $text = $INPUT->str('text');
        $prompt = $INPUT->str('prompt', '');
        $metadata = $INPUT->str('metadata', '{}');
        $template = $INPUT->str('template', '');
        $examples = $INPUT->str('examples', '');
        
        // Parse metadata
        $metadataArray = json_decode($metadata, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $metadataArray = [];
        }
        
        // Parse examples - split by newline and filter out empty lines
        $examplesArray = array_filter(array_map('trim', explode("\n", $examples)));
        if (!empty($examplesArray)) {
            $metadataArray['examples'] = $examplesArray;
        }
        
        // Handle the special case of get_actions action
        if ($action === 'get_actions') {
            try {
                $actions = $this->getActions();
                echo json_encode(['result' => $actions]);
            } catch (Exception $e) {
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        
        // Handle the special case of get_template action
        if ($action === 'get_template') {
            try {
                $templateId = $template;
                $templateContent = $this->getPageContent($templateId);
                if ($templateContent === false) {
                    throw new Exception('Template not found: ' . $templateId);
                }
                echo json_encode(['result' => ['content' => $templateContent]]);
            } catch (Exception $e) {
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        
        // Handle the special case of find_template action
        if ($action === 'find_template') {
            try {
                $searchText = $INPUT->str('text');
                $template = $this->findTemplate($searchText);
                if (!empty($template)) {
                    echo json_encode(['result' => ['template' => $template[0]]]);
                } else {
                    echo json_encode(['result' => ['template' => null]]);
                }
            } catch (Exception $e) {
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        
        // Validate input
        if (empty($text)) {
            http_status(400);
            echo json_encode(['error' => 'No text provided']);
            return;
        }
        
        // Process based on action
        try {
            $result = $this->processText($action, $text, $prompt, $metadataArray, $template);
            echo json_encode(['result' => $result]);
        } catch (Exception $e) {
            // If we get an unknown action error, try to process it directly through the LLM client
            if (strpos($e->getMessage(), 'Unknown action') !== false) {
                try {
                    require_once DOKU_PLUGIN . 'dokullm/llm_client.php';
                    $client = new llm_client_plugin_dokullm();
                    $result = $client->process($action, $text, $metadataArray);
                    echo json_encode(['result' => $result]);
                } catch (Exception $e2) {
                    http_status(500);
                    echo json_encode(['error' => $e2->getMessage()]);
                }
            } else {
                http_status(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Process text based on the specified action
     * 
     * Routes the text processing request to the appropriate method in
     * the LLM client based on the action parameter.
     * 
     * The processing behavior is affected by the 'think' configuration parameter:
     * - When 'think' is true, the LLM will engage in deeper thinking processes
     * - When 'think' is false, the LLM will provide direct responses
     * 
     * @param string $action The action to perform (create, rewrite, grammar, etc.)
     * @param string $text The text to process
     * @param string $prompt Additional prompt information (used for custom prompts)
     * @param array $metadata Metadata array containing template and examples information
     * @param string $template Template identifier for get_template action
     * @return string|array The processed text result or array for template content
     * @throws Exception If an unknown action is provided
     */
    private function processText($action, $text, $prompt = '', $metadata = [], $template = '')
    {
        require_once DOKU_PLUGIN . 'dokullm/llm_client.php';
        
        $client = new llm_client_plugin_dokullm();
        
        switch ($action) {
            case 'create':
                return $client->createReport($text, $metadata);
            case 'compare':
                return $client->compareText($text, $metadata, false);
            case 'custom':
                return $client->processCustomPrompt($text, $prompt, $metadata);
            default:
                throw new Exception('Unknown action: ' . $action);
        }
    }

    /**
     * Get action definitions from the DokuWiki table at dokullm:prompts
     * 
     * Parses the table containing action definitions with columns:
     * ID, Label, Icon, Action
     * 
     * Stops parsing after the first table ends to avoid processing
     * additional tables with disabled or work-in-progress commands.
     * 
     * @return array Array of action definitions
     */
    private function getActions()
    {
        // Get the content of the prompts page
        $content = $this->getPageContent('dokullm:prompts');
        
        if ($content === false) {
            // Return empty list if page doesn't exist
            return [];
        }
        
        // Parse the table from the page content
        $actions = [];
        $lines = explode("\n", $content);
        $inTable = false;
        
        foreach ($lines as $line) {
            // Check if this is a table row
            if (preg_match('/^\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|\s*([^\|]+)\s*\|$/', $line, $matches)) {
                $inTable = true;
                
                // Skip header row
                if (trim($matches[1]) === 'ID' || trim($matches[1]) === 'id') {
                    continue;
                }
                
                $actions[] = [
                    'id' => trim($matches[1]),
                    'label' => trim($matches[2]),
                    'description' => trim($matches[3]),
                    'icon' => trim($matches[4]),
                    'result' => trim($matches[5])
                ];
            } else if ($inTable) {
                // We've exited the table, so stop parsing
                break;
            }
        }
        
        return $actions;
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
    private function getPageContent($pageId)
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
     * Find an appropriate template based on the provided text
     * 
     * Uses ChromaDB to search for the most relevant template based on the content.
     * 
     * @param string $text The text to use for finding a template
     * @return array The template ID array or empty array if none found
     * @throws Exception If an error occurs during the search
     */
    private function findTemplate($text) {
        try {
            // Get ChromaDB client through the LLM client
            require_once DOKU_PLUGIN . 'dokullm/llm_client.php';
            $client = new llm_client_plugin_dokullm();
            
            // Query ChromaDB for the most relevant template
            $template = $client->queryChromaDBTemplate($text);
            
            return $template;
        } catch (Exception $e) {
            throw new Exception('Error finding template: ' . $e->getMessage());
        }
    }


   /**
     * Handler to load page template.
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handleTemplate(Doku_Event &$event, $param) {
        if (strlen($_REQUEST['copyfrom']) > 0) {
            $template_id = $_REQUEST['copyfrom'];
            if (auth_quickaclcheck($template_id) >= AUTH_READ) {
                $tpl = io_readFile(wikiFN($template_id));
                if ($this->getConf('replace_id')) {
                    $id = $event->data['id'];
                    $tpl = str_replace($template_id, $id, $tpl);
                }
                // Add LLM_TEMPLATE metadata if the original page ID contains 'template'
                if (strpos($template_id, 'template') !== false) {
                    $tpl = '~~LLM_TEMPLATE:' . $template_id . '~~' . "\n" . $tpl;
                }
                $event->data['tpl'] = $tpl;
                $event->preventDefault();
            }
        }
    }



   /**
     * Add 'Copy page' button to page tools, SVG based
     *
     * @param Doku_Event $event
     */
    public function addCopyPageButton(Doku_Event $event)
    {
        global $INFO;
        if ($event->data['view'] != 'page' || !$this->getConf('show_copy_button')) {
            return;
        }
        if (! $INFO['exists']) {
            return;
        }
        array_splice($event->data['items'], -1, 0, [new \dokuwiki\plugin\dokullm\MenuItem()]);
    }
}
