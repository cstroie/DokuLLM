/**
 * JavaScript for LLM Integration Plugin
 * 
 * This script adds LLM processing capabilities to DokuWiki's edit interface.
 * It creates a toolbar with buttons for various text processing operations
 * and handles the communication with the backend plugin.
 * 
 * Features:
 * - Context-aware text processing with metadata support
 * - Template content insertion
 * - Custom prompt input
 * - Selected text processing
 * - Full page content processing
 * - Page duplication with template metadata support
 */

(function() {
    'use strict';
    
    /**
     * Initialize the plugin when the DOM is ready
     * 
     * Checks if we're on an edit page and adds the LLM tools if so.
     * Only runs on pages with the wiki text editor.
     */
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DokuLLM: DOM loaded, initializing plugin');
        // Only run on edit pages
        if (document.getElementById('wiki__text')) {
            // Add LLM tools to the editor
            console.log('DokuLLM: Adding LLM tools to editor');
            addLLMTools();
        }
        
        // Add page copy button to sidebar
        addPageCopyButton();
        console.log('DokuLLM: Plugin initialization complete');
    });
    
    /**
     * Add the LLM toolbar to the editor interface
     * 
     * Creates a toolbar with buttons for each LLM operation and inserts
     * it before the wiki text editor. Also adds a custom prompt input
     * below the editor.
     * 
     * Dynamically adds a template button when template metadata is present.
     */
    function addLLMTools() {
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor div not found');
            return;
        }
        
        console.log('DokuLLM: Creating LLM toolbar');
        // Create toolbar container
        const toolbar = document.createElement('div');
        toolbar.id = 'llm-toolbar';
        toolbar.className = 'toolbar';
        
        // Get metadata to check if template exists
        const metadata = getPageMetadata();
        console.log('DokuLLM: Page metadata retrieved', metadata);
        
        // Add template button if template is defined
        if (metadata.template) {
            console.log('DokuLLM: Adding template button for', metadata.template);
            const templateBtn = document.createElement('button');
            templateBtn.type = 'button';
            templateBtn.className = 'toolbutton llm-button';
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
            btn.className = 'toolbutton llm-button';
            btn.textContent = button.label;
            btn.dataset.action = button.action;
            btn.addEventListener('click', function() {
                processText(button.action);
            });
            toolbar.appendChild(btn);
        });
        
        
        // Insert toolbar before the editor
        editor.parentNode.insertBefore(toolbar, editor);
        
        // Add custom prompt input below the editor
        console.log('DokuLLM: Adding custom prompt input below editor');
        const customPromptContainer = document.createElement('div');
        customPromptContainer.className = 'llm-custom-prompt';
        customPromptContainer.id = 'llm-custom-prompt';
        
        const promptInput = document.createElement('input');
        promptInput.type = 'text';
        promptInput.placeholder = 'Enter custom prompt...';
        promptInput.className = 'llm-prompt-input';
        
        // Add event listener for Enter key
        promptInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                processCustomPrompt(promptInput.value);
            }
        });
        
        const sendButton = document.createElement('button');
        sendButton.type = 'button';
        sendButton.className = 'toolbutton llm-button';
        sendButton.textContent = 'Send';
        sendButton.addEventListener('click', () => processCustomPrompt(promptInput.value));
        
        customPromptContainer.appendChild(promptInput);
        customPromptContainer.appendChild(sendButton);
        
        // Insert custom prompt container after the editor
        editor.parentNode.insertBefore(customPromptContainer, editor.nextSibling);
        
        // Add CSS styles
        addStyles();
        console.log('DokuLLM: LLM toolbars added successfully');
    }
    
    /**
     * Add a page copy button to the sidebar
     * 
     * Creates a button in the lateral menu that allows users to copy the current page
     * to a new page with a different ID. If the original page contains 'template' or
     * 'normal' in its ID, adds LLM_TEMPLATE metadata to the new page.
     */
    function addPageCopyButton() {
        // Check if we're on a page view (not edit)
        const isEditPage = !!document.getElementById('wiki__text');
        if (isEditPage) {
            return;
        }
        
        // Get current page ID from URL or document
        const pageId = JSINFO.id;
        if (!pageId) {
            return;
        }
        
        // Find the sidebar menu
        const sidebar = document.getElementById('dokuwiki__aside');
        if (!sidebar) {
            return;
        }
        
        // Create copy button
        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'toolbutton llm-button';
        copyButton.textContent = 'Copy Page';
        copyButton.style.margin = '5px';
        copyButton.style.display = 'block';
        copyButton.style.width = 'calc(100% - 10px)';
        
        copyButton.addEventListener('click', function() {
            copyPage(pageId);
        });
        
        // Add to sidebar
        sidebar.appendChild(copyButton);
    }
    
    /**
     * Copy the current page to a new page ID
     * 
     * Prompts user for new page ID, validates it's different from original,
     * and creates a new page with the content. Adds template metadata when appropriate.
     * 
     * @param {string} originalPageId - The ID of the current page
     */
    function copyPage(originalPageId) {
        // Prompt for new page ID
        const newPageId = prompt('Enter the new page ID:', originalPageId + '_copy');
        if (!newPageId) {
            return; // User cancelled
        }
        
        // Check that IDs are different
        if (newPageId === originalPageId) {
            alert('New page ID must be different from the original page ID.');
            return;
        }
        
        // Get current page content
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'get_page_content');
        formData.append('page', originalPageId);
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Prepare content with metadata if needed
            let content = data.result.content;
            
            // Check if original page ID contains 'template' or 'normal'
            if (originalPageId.toLowerCase().includes('template') || 
                originalPageId.toLowerCase().includes('normal')) {
                // Add metadata at the beginning
                content = `~~LLM_TEMPLATE:${originalPageId}~~\n` + content;
            }
            
            // Save new page
            const saveData = new FormData();
            saveData.append('call', 'plugin_dokullm');
            saveData.append('action', 'save_page');
            saveData.append('page', newPageId);
            saveData.append('content', content);
            
            return fetch(DOKU_BASE + 'lib/exe/ajax.php', {
                method: 'POST',
                body: saveData
            });
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Redirect to new page
            window.location.href = DOKU_BASE + newPageId + '?do=edit';
        })
        .catch(error => {
            alert('Error copying page: ' + error.message);
        });
    }
    
    /**
     * Process text using the specified LLM action
     * 
     * Gets the selected text (or full editor content), sends it to the
     * backend for processing, and replaces the text with the result.
     * 
     * Preserves page metadata when doing full page updates.
     * Shows loading indicators during processing.
     * 
     * @param {string} action - The action to perform (complete, rewrite, etc.)
     */
    function processText(action) {
        console.log('DokuLLM: Processing text with action:', action);
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found');
            return;
        }
        
        // Get metadata from the page
        const metadata = getPageMetadata();
        console.log('DokuLLM: Retrieved metadata:', metadata);
        
        const selectedText = getSelectedText(editor);
        const fullText = editor.value;
        const textToProcess = selectedText || fullText;
        console.log('DokuLLM: Text to process length:', textToProcess.length);
        
        if (!textToProcess.trim()) {
            console.log('DokuLLM: No text to process');
            alert('Please select text or enter content to process');
            return;
        }
        
        // Show loading indicator
        const originalButton = event ? event.target : document.querySelector(`[data-action="${action}"]`);
        const originalText = originalButton.textContent;
        originalButton.textContent = 'Processing...';
        originalButton.disabled = true;
        console.log('DokuLLM: Button disabled, showing processing state');
        
        // Make textarea readonly during processing
        editor.readOnly = true;
        
        // Get additional prompt if needed (e.g., for translation)
        let targetLanguage = '';
        
        // Send AJAX request
        console.log('DokuLLM: Sending AJAX request to backend');
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
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            console.log('DokuLLM: Received response from backend');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error from backend:', data.error);
                throw new Error(data.error);
            }
            
            console.log('DokuLLM: Processing successful, result length:', data.result.length);
            // Replace selected text or append to editor
            if (selectedText) {
                console.log('DokuLLM: Replacing selected text');
                replaceSelectedText(editor, data.result);
            } else {
                console.log('DokuLLM: Replacing full text content');
                // Preserve metadata when doing full page update
                const metadata = extractMetadata(editor.value);
                editor.value = metadata + data.result;
            }
        })
        .catch(error => {
            console.log('DokuLLM: Error during processing:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Resetting button and enabling editor');
            if (originalButton) {
                resetButton(originalButton, originalText);
            }
            editor.readOnly = false;
        });
    }
    
    /**
     * Process text with a custom user prompt
     * 
     * Sends selected or full text content to the backend with a user-provided
     * custom prompt for processing.
     * 
     * Clears the prompt input after successful processing.
     * Shows loading indicators during processing.
     * 
     * @param {string} customPrompt - The user's custom prompt
     */
    function processCustomPrompt(customPrompt) {
        console.log('DokuLLM: Processing custom prompt:', customPrompt);
        if (!customPrompt.trim()) {
            console.log('DokuLLM: No custom prompt provided');
            alert('Please enter a prompt');
            return;
        }
        
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found for custom prompt');
            return;
        }
        
        const selectedText = getSelectedText(editor);
        const fullText = editor.value;
        const textToProcess = selectedText || fullText;
        console.log('DokuLLM: Text to process length:', textToProcess.length);
        
        if (!textToProcess.trim()) {
            console.log('DokuLLM: No text to process for custom prompt');
            alert('Please select text or enter content to process');
            return;
        }
        
        // Get metadata from the page
        const metadata = getPageMetadata();
        console.log('DokuLLM: Retrieved metadata for custom prompt:', metadata);
        
        // Find the Send button and show loading state
        const toolbar = document.getElementById('llm-custom-prompt');
        const sendButton = toolbar.querySelector('.toolbutton');
        const originalText = sendButton.textContent;
        sendButton.textContent = 'Processing...';
        sendButton.disabled = true;
        console.log('DokuLLM: Send button disabled, showing processing state');
        
        // Make textarea readonly during processing
        editor.readOnly = true;
        
        // Send AJAX request
        console.log('DokuLLM: Sending custom prompt AJAX request to backend');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'custom');
        formData.append('text', textToProcess);
        formData.append('prompt', customPrompt);
        formData.append('metadata', JSON.stringify(metadata));
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            console.log('DokuLLM: Received response for custom prompt');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error from backend for custom prompt:', data.error);
                throw new Error(data.error);
            }
            
            console.log('DokuLLM: Custom prompt processing successful, result length:', data.result.length);
            // Replace selected text or append to editor
            if (selectedText) {
                console.log('DokuLLM: Replacing selected text for custom prompt');
                replaceSelectedText(editor, data.result);
            } else {
                console.log('DokuLLM: Replacing full text content for custom prompt');
                // Preserve metadata when doing full page update
                const metadata = extractMetadata(editor.value);
                editor.value = metadata + data.result;
            }
            
            // Clear the input field
            const promptInput = toolbar.querySelector('.llm-prompt-input');
            if (promptInput) {
                promptInput.value = '';
            }
        })
        .catch(error => {
            console.log('DokuLLM: Error during custom prompt processing:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Resetting send button and enabling editor');
            if (sendButton) {
                resetButton(sendButton, originalText);
            }
            editor.readOnly = false;
        });
    }
    
    /**
     * Get the currently selected text in the textarea
     * 
     * Uses the textarea's selectionStart and selectionEnd properties
     * to extract the selected portion of text.
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
     * When replacing the entire content, preserves metadata directives
     * at the beginning of the page.
     * 
     * @param {HTMLTextAreaElement} textarea - The textarea element
     * @param {string} newText - The new text to insert
     */
    function replaceSelectedText(textarea, newText) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        
        // If there's no selection (start === end), it's not a replacement of selected text
        if (start === end) {
            // No selection, so we're processing the full text
            const metadata = extractMetadata(text);
            textarea.value = metadata + newText;
        } else {
            // There is a selection, replace only the selected text
            textarea.value = text.substring(0, start) + newText + text.substring(end);
            
            // Set cursor position after inserted text
            const newCursorPos = start + newText.length;
            textarea.setSelectionRange(newCursorPos, newCursorPos);
        }
        
        textarea.focus();
    }
    
    /**
     * Extract metadata directives from the beginning of the text
     * 
     * Finds and returns LLM metadata directives (~~LLM_*~~) that appear
     * at the beginning of the page content.
     * 
     * @param {string} text - The full text content
     * @returns {string} The metadata directives
     */
    function extractMetadata(text) {
        const metadataRegex = /^(~~LLM_[A-Z]+:[^~]+~~\s*)*/;
        const match = text.match(metadataRegex);
        return match ? match[0] : '';
    }
    
    /**
     * Reset a button to its original state
     * 
     * Restores the button's text content and enables it.
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
     * directives in the page content.
     * 
     * Looks for:
     * - ~~LLM_TEMPLATE:page_id~~ for template page reference
     * - ~~LLM_EXAMPLES:page1,page2~~ for example page references
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
     * at the current cursor position.
     * 
     * Shows loading indicators during the fetch operation.
     * 
     * @param {string} templateId - The template page ID
     */
    function insertTemplateContent(templateId) {
        console.log('DokuLLM: Inserting template content for:', templateId);
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found for template insertion');
            return;
        }
        
        // Show loading indicator
        const toolbar = document.getElementById('llm-toolbar');
        const originalContent = toolbar.innerHTML;
        toolbar.innerHTML = '<span>Loading template...</span>';
        editor.readOnly = true;
        console.log('DokuLLM: Showing loading indicator for template');
        
        // Send AJAX request to get template content
        console.log('DokuLLM: Sending AJAX request to get template content');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'get_template');
        formData.append('template', templateId);
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('DokuLLM: Received template response');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error retrieving template:', data.error);
                throw new Error(data.error);
            }
            
            console.log('DokuLLM: Template retrieved successfully, content length:', data.result.content.length);
            // Insert template content at cursor position or at the beginning
            const cursorPos = editor.selectionStart;
            const text = editor.value;
            editor.value = text.substring(0, cursorPos) + data.result.content + text.substring(cursorPos);
            
            // Set cursor position after inserted content
            const newCursorPos = cursorPos + data.result.content.length;
            editor.setSelectionRange(newCursorPos, newCursorPos);
            editor.focus();
        })
        .catch(error => {
            console.log('DokuLLM: Error during template insertion:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Restoring toolbar and enabling editor');
            toolbar.innerHTML = originalContent;
            editor.readOnly = false;
        });
    }
    
    /**
     * Add CSS styles for the LLM toolbar
     * 
     * Dynamically creates and adds CSS rules for the toolbar and buttons.
     * Styles include:
     * - Button spacing and appearance
     * - Disabled button states
     * - Custom prompt input field styling
     * - Toolbar layout
     */
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            #llm-toolbar {
                margin-bottom: 10px;
            }
            
            .llm-button {
                margin-right: 5px;
                margin-bottom: 5px;
                padding: 5px 10px;
                border: 1px solid #ccc;
                border-radius: 3px;
                cursor: pointer;
                background-color: #f8f8f8;
                font-size: 12px;
            }
            
            .llm-button:hover {
                background-color: #e8e8e8;
            }
            
            .llm-button:disabled {
                cursor: not-allowed;
                opacity: 0.6;
            }
            
            .llm-custom-prompt {
                margin: 10px 0;
                display: flex;
                align-items: center;
            }
            
            .llm-prompt-input {
                flex: 1;
                margin-right: 5px;
                padding: 5px;
                border: 1px solid #ccc;
                border-radius: 3px;
            }
        `;
        document.head.appendChild(style);
    }
})();
