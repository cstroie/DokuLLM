/**
 * JavaScript for LLM Integration Plugin
 * 
 * This script adds LLM processing capabilities to DokuWiki's edit interface.
 * It creates a toolbar with buttons for various text processing operations
 * and handles the communication with the backend plugin.
 */

(function() {
    'use strict';
    
    /**
     * Initialize the plugin when the DOM is ready
     * 
     * Checks if we're on an edit page and adds the LLM tools if so.
     */
    document.addEventListener('DOMContentLoaded', function() {
        // Only run on edit pages
        if (!document.getElementById('wiki__text')) {
            return;
        }
        
        // Add LLM tools to the editor
        addLLMTools();
    });
    
    /**
     * Add the LLM toolbar to the editor interface
     * 
     * Creates a toolbar with buttons for each LLM operation and inserts
     * it before the wiki text editor.
     */
    function addLLMTools() {
        const editor = document.getElementById('wiki__text');
        if (!editor) return;
        
        // Create toolbar container
        const toolbar = document.createElement('div');
        toolbar.id = 'llm-toolbar';
        toolbar.className = 'llm-toolbar';
        
        // Get metadata to check if template exists
        const metadata = getPageMetadata();
        
        // Add template button if template is defined
        if (metadata.template) {
            const templateBtn = document.createElement('button');
            templateBtn.type = 'button';
            templateBtn.className = 'llm-button';
            templateBtn.textContent = 'Insert Template';
            templateBtn.addEventListener('click', () => insertTemplateContent(metadata.template));
            toolbar.appendChild(templateBtn);
        }
        
        // Add buttons
        const buttons = [
            {action: 'complete', label: 'Complete'},
            {action: 'rewrite', label: 'Rewrite'},
            {action: 'grammar', label: 'Grammar'},
            {action: 'summarize', label: 'Summarize'},
            {action: 'conclusion', label: 'Conclusion'},
            {action: 'analyze', label: 'Analyze'},
            {action: 'continue', label: 'Continue'}
        ];
        
        buttons.forEach(button => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'llm-button';
            btn.textContent = button.label;
            btn.dataset.action = button.action;
            btn.addEventListener('click', () => processText(button.action));
            toolbar.appendChild(btn);
        });
        
        // Insert toolbar before the editor
        editor.parentNode.insertBefore(toolbar, editor);
        
        // Add CSS styles
        addStyles();
    }
    
    /**
     * Process text using the specified LLM action
     * 
     * Gets the selected text (or full editor content), sends it to the
     * backend for processing, and replaces the text with the result.
     * 
     * @param {string} action - The action to perform (complete, rewrite, etc.)
     */
    function processText(action) {
        const editor = document.getElementById('wiki__text');
        if (!editor) return;
        
        // Get metadata from the page
        const metadata = getPageMetadata();
        
        const selectedText = getSelectedText(editor);
        const fullText = editor.value;
        const textToProcess = selectedText || fullText;
        
        if (!textToProcess.trim()) {
            alert('Please select text or enter content to process');
            return;
        }
        
        // Show loading indicator
        const originalButton = event.target;
        const originalText = originalButton.textContent;
        originalButton.textContent = 'Processing...';
        originalButton.disabled = true;
        
        // Make textarea readonly during processing
        editor.readOnly = true;
        
        // Get additional prompt if needed (e.g., for translation)
        let targetLanguage = '';
        
        // Send AJAX request
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', action);
        formData.append('text', textToProcess);
        formData.append('prompt', targetLanguage);
        formData.append('metadata', JSON.stringify(metadata));
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Replace selected text or append to editor
            if (selectedText) {
                replaceSelectedText(editor, data.result);
            } else {
                editor.value = data.result;
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        })
        .finally(() => {
            resetButton(originalButton, originalText);
            editor.readOnly = false;
        });
    }
    
    /**
     * Get the currently selected text in the textarea
     * 
     * @param {HTMLTextAreaElement} textarea - The textarea element
     * @returns {string} The selected text
     */
    function getSelectedText(textarea) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        return textarea.value.substring(start, end);
    }
    
    /**
     * Replace the selected text in the textarea with new text
     * 
     * @param {HTMLTextAreaElement} textarea - The textarea element
     * @param {string} newText - The new text to insert
     */
    function replaceSelectedText(textarea, newText) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        textarea.value = text.substring(0, start) + newText + text.substring(end);
        
        // Set cursor position after inserted text
        const newCursorPos = start + newText.length;
        textarea.setSelectionRange(newCursorPos, newCursorPos);
        textarea.focus();
    }
    
    /**
     * Reset a button to its original state
     * 
     * @param {HTMLButtonElement} button - The button to reset
     * @param {string} originalText - The original button text
     */
    function resetButton(button, originalText) {
        button.textContent = originalText;
        button.disabled = false;
    }
    
    /**
     * Get page metadata for LLM context
     * 
     * Extracts template and example page information from page metadata
     * 
     * @returns {Object} Metadata object with template and examples
     */
    function getPageMetadata() {
        const metadata = {
            template: '',
            examples: []
        };
        
        // Look for metadata in the page content
        const pageContent = document.getElementById('wiki__text')?.value || '';
        
        // Extract template page from metadata
        const templateMatch = pageContent.match(/~~LLM_TEMPLATE:([^~]+)~~/);
        if (templateMatch) {
            metadata.template = templateMatch[1].trim();
        }
        
        // Extract example pages from metadata
        const exampleMatches = pageContent.match(/~~LLM_EXAMPLES:([^~]+)~~/);
        if (exampleMatches) {
            metadata.examples = exampleMatches[1].split(',').map(example => example.trim());
        }
        
        return metadata;
    }
    
    /**
     * Insert template content into the editor
     * 
     * Fetches template content from the backend and inserts it into the editor
     * 
     * @param {string} templateId - The template page ID
     */
    function insertTemplateContent(templateId) {
        const editor = document.getElementById('wiki__text');
        if (!editor) return;
        
        // Show loading indicator
        const toolbar = document.getElementById('llm-toolbar');
        const originalContent = toolbar.innerHTML;
        toolbar.innerHTML = '<span>Loading template...</span>';
        editor.readOnly = true;
        
        // Send AJAX request to get template content
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'get_template');
        formData.append('template', templateId);
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Insert template content at cursor position or at the beginning
            const cursorPos = editor.selectionStart;
            const text = editor.value;
            editor.value = text.substring(0, cursorPos) + data.content + text.substring(cursorPos);
            
            // Set cursor position after inserted content
            const newCursorPos = cursorPos + data.content.length;
            editor.setSelectionRange(newCursorPos, newCursorPos);
            editor.focus();
        })
        .catch(error => {
            alert('Error: ' + error.message);
        })
        .finally(() => {
            toolbar.innerHTML = originalContent;
            editor.readOnly = false;
        });
    }
    
    /**
     * Add CSS styles for the LLM toolbar
     * 
     * Dynamically creates and adds CSS rules for the toolbar and buttons.
     */
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            .llm-toolbar {
                margin-bottom: 10px;
                padding: 10px;
                border-radius: 4px;
            }
            
            .llm-button {
                margin-right: 5px;
                margin-bottom: 5px;
                padding: 5px 10px;
                border: none;
                border-radius: 3px;
                cursor: pointer;
            }
            
            .llm-button:disabled {
                cursor: not-allowed;
            }
        `;
        document.head.appendChild(style);
    }
})();
