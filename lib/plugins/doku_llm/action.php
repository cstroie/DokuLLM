<?php
/**
 * DokuWiki Plugin doku_llm (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Costin Stroie <costinstroie@eridu.eu.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class action_plugin_doku_llm extends DokuWiki_Action_Plugin
{
    /**
     * Register the event handlers
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleMetaHeaders');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    /**
     * Add JavaScript to the page
     */
    public function handleMetaHeaders(Doku_Event $event, $param)
    {
        global $INFO;
        
        // Only add JS to edit pages
        if ($INFO['act'] == 'edit' || $INFO['act'] == 'preview') {
            $event->data['script'][] = array(
                'type' => 'text/javascript',
                'src' => DOKU_BASE . 'lib/plugins/doku_llm/script.js',
                '_data' => 'doku_llm'
            );
        }
    }

    /**
     * Handle AJAX requests
     */
    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'plugin_doku_llm') {
            return;
        }
        
        $event->stopPropagation();
        $event->preventDefault();
        
        // Handle the AJAX request
        $this->processRequest();
    }

    /**
     * Process the AJAX request
     */
    private function processRequest()
    {
        global $INPUT;
        
        $action = $INPUT->str('action');
        $text = $INPUT->str('text');
        $prompt = $INPUT->str('prompt', '');
        
        // Validate input
        if (empty($text)) {
            http_status(400);
            echo json_encode(['error' => 'No text provided']);
            return;
        }
        
        // Process based on action
        try {
            $result = $this->processText($action, $text, $prompt);
            echo json_encode(['result' => $result]);
        } catch (Exception $e) {
            http_status(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Process text based on action
     */
    private function processText($action, $text, $prompt = '')
    {
        require_once 'llm_client.php';
        
        $client = new llm_client_plugin_doku_llm();
        
        switch ($action) {
            case 'complete':
                return $client->completeText($text);
            case 'rewrite':
                return $client->rewriteText($text);
            case 'grammar':
                return $client->correctGrammar($text);
            case 'summarize':
                return $client->summarizeText($text);
            case 'conclusion':
                return $client->createConclusion($text);
            case 'translate':
                return $client->translateText($text, $prompt); // prompt as target language
            default:
                throw new Exception('Unknown action: ' . $action);
        }
    }
}