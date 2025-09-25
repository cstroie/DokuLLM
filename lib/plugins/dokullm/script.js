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
        
        // Add model selector
        const modelSelector = document.createElement('select');
        modelSelector.id = 'llm-model-selector';
        modelSelector.className = 'llm-model-selector';
        modelSelector.title = 'Select LLM Model';
        
        // Add a default option
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Loading models...';
        modelSelector.appendChild(defaultOption);
        
        toolbar.appendChild(modelSelector);
        
        // Add buttons
        const buttons = [
            {action: 'complete', label: 'Complete'},
            {action: 'rewrite', label: 'Rewrite'},
            {action: 'grammar', label: 'Grammar'},
            {action: 'summarize', label: 'Summarize'},
            {action: 'conclusion', label: 'Conclusion'},
            {action: 'translate', label: 'Translate'}
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
        
        // Load available models
        loadAvailableModels();
        
        // Add CSS styles
        addStyles();
    }
    
    /**
     * Load available models from the server
     * 
     * Fetches the list of available models and populates the model selector.
     */
    function loadAvailableModels() {
        const modelSelector = document.getElementById('llm-model-selector');
        if (!modelSelector) return;
        
        // Send AJAX request to get models
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'get_models');
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Clear the selector
            modelSelector.innerHTML = '';
            
            // Add models to the selector
            if (data.models && data.models.length > 0) {
                data.models.forEach(model => {
                    const option = document.createElement('option');
                    option.value = model;
                    option.textContent = model;
                    modelSelector.appendChild(option);
                });
                
                // Select the first model by default
                modelSelector.selectedIndex = 0;
            } else {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'No models available';
                modelSelector.appendChild(option);
            }
        })
        .catch(error => {
            console.error('Error loading models:', error);
            modelSelector.innerHTML = '';
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Error loading models';
            modelSelector.appendChild(option);
        });
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
        
        const selectedText = getSelectedText(editor);
        const fullText = editor.value;
        const textToProcess = selectedText || fullText;
        
        if (!textToProcess.trim()) {
            alert('Please select text or enter content to process');
            return;
        }
        
        // Get selected model
        const modelSelector = document.getElementById('llm-model-selector');
        const selectedModel = modelSelector ? modelSelector.value : '';
        
        if (!selectedModel) {
            alert('Please select a model first');
            return;
        }
        
        // Show loading indicator
        const originalButton = event.target;
        const originalText = originalButton.textContent;
        originalButton.textContent = 'Processing...';
        originalButton.disabled = true;
        
        // Get additional prompt if needed (e.g., for translation)
        let targetLanguage = '';
        if (action === 'translate') {
            targetLanguage = prompt('Enter target language:', 'English');
            if (!targetLanguage) {
                resetButton(originalButton, originalText);
                return;
            }
        }
        
        // Send AJAX request
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', action);
        formData.append('text', textToProcess);
        formData.append('prompt', targetLanguage);
        formData.append('model', selectedModel);
        
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
                display: flex;
                flex-wrap: wrap;
                align-items: center;
            }
            
            .llm-model-selector {
                margin-right: 10px;
                margin-bottom: 5px;
                padding: 5px;
                border-radius: 3px;
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
