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
     * Extracts action, text, and prompt parameters from the request,
     * validates the input, and calls the appropriate processing method.
     * Returns JSON encoded result or error.
     */
    private function processRequest()
    {
        global $INPUT;
        
        $action = $INPUT->str('action');
        $text = $INPUT->str('text');
        $prompt = $INPUT->str('prompt', '');
        $metadata = $INPUT->str('metadata', '{}');
        
        // Parse metadata
        $metadataArray = json_decode($metadata, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $metadataArray = [];
        }
        
        // Validate input
        if (empty($text)) {
            http_status(400);
            echo json_encode(['error' => 'No text provided']);
            return;
        }
        
        // Process based on action
        try {
            $result = $this->processText($action, $text, $prompt, $metadataArray);
            echo json_encode(['result' => $result]);
        } catch (Exception $e) {
            http_status(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Process text based on the specified action
     * 
     * Routes the text processing request to the appropriate method in
     * the LLM client based on the action parameter.
     * 
     * @param string $action The action to perform (complete, rewrite, grammar, etc.)
     * @param string $text The text to process
     * @param string $prompt Additional prompt information (used for translation target language)
     * @return string The processed text result
     * @throws Exception If an unknown action is provided
     */
    private function processText($action, $text, $prompt = '', $metadata = [])
    {
        require_once 'llm_client.php';
        
        $client = new llm_client_plugin_dokullm();
        
        switch ($action) {
            case 'complete':
                return $client->completeText($text, $metadata);
            case 'rewrite':
                return $client->rewriteText($text, $metadata);
            case 'grammar':
                return $client->correctGrammar($text, $metadata);
            case 'summarize':
                return $client->summarizeText($text, $metadata);
            case 'conclusion':
                return $client->createConclusion($text, $metadata);
            case 'analyze':
                return $client->analyzeText($text, $metadata);
            case 'continue':
                return $client->continueText($text, $metadata);
            case 'translate':
                return $client->translateText($text, $prompt, $metadata); // prompt as target language
            default:
                throw new Exception('Unknown action: ' . $action);
        }
    }
}
