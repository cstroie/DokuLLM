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
 * - Text analysis in modal dialog
 */

(function() {
    'use strict';
    
    /**
     * Initialize the plugin when the DOM is ready
     * 
     * This is the main initialization function that runs when the DOM is fully loaded.
     * It checks if we're on an edit page and adds the LLM tools if so.
     * Only runs on pages with the wiki text editor.
     * Also sets up the copy page button event listener.
     * 
     * Complex logic includes:
     * 1. Checking for the presence of the wiki text editor element
     * 2. Conditionally adding LLM tools based on page context
     * 3. Setting up event listeners for the copy page functionality
     */
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DokuLLM: DOM loaded, initializing plugin');
        // Only run on edit pages
        if (document.getElementById('wiki__text')) {
            // Add LLM tools to the editor
            console.log('DokuLLM: Adding LLM tools to editor');
            addLLMTools();
        }
        
        // Add event listener for copy button
        const copyButton = document.querySelector('.dokullmplugin__copy');
        if (copyButton) {
            copyButton.addEventListener('click', function(event) {
                event.preventDefault();
                copyPage();
            });
        }
    });
    
    /**
     * Add the LLM toolbar to the editor interface
     * 
     * Creates a toolbar with buttons for each LLM operation and inserts
     * it before the wiki text editor. Also adds a custom prompt input
     * below the editor.
     * 
     * Dynamically adds a template button when template metadata is present.
     * 
     * Complex logic includes:
     * 1. Creating and positioning the main toolbar container
     * 2. Dynamically adding a template button based on metadata presence
     * 3. Creating standard LLM operation buttons with event handlers
     * 4. Adding a custom prompt input field with Enter key handling
     * 5. Inserting all UI elements at appropriate positions in the DOM
     * 6. Applying CSS styles for consistent appearance
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
            templateBtn.className = 'toolbutton';
            templateBtn.textContent = 'Insert Template';
            templateBtn.addEventListener('click', () => insertTemplateContent(metadata.template));
            toolbar.appendChild(templateBtn);
        }
        
        // Add buttons
        const buttons = [
            {action: 'conclusion', label: 'Conclusion'},
            {action: 'analyze', label: 'Analyze'},
            {action: 'compare', label: 'Compare'},
            {action: 'create', label: 'Create'},
            {action: 'rewrite', label: 'Rewrite'},
            {action: 'grammar', label: 'Grammar'},
            {action: 'summarize', label: 'Summarize'},
            {action: 'continue', label: 'Continue'}
        ];
        
        buttons.forEach(button => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'toolbutton';
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
        sendButton.className = 'toolbutton';
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
    

    function copyPage() {
        // Original code: https://www.dokuwiki.org/plugin:copypage
        var oldId = JSINFO.id;
        while (true) {
           var newId = prompt('Enter the new page ID:', oldId);
           // Note: When a user canceled, most browsers return the null, but Safari returns the empty string
           if (newId) {
               if (newId === oldId) {
                   alert('The new page ID must be different from the current page ID.');
                   continue;
               }
               var url = DOKU_BASE + 'doku.php?id=' + encodeURIComponent(newId) +
                         '&do=edit&copyfrom=' + encodeURIComponent(oldId);
               location.href = url;
           }
           break;
        }
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
     * Complex logic includes:
     * 1. Determining text to process (selected vs full content)
     * 2. Managing UI state during processing (loading indicators, readonly)
     * 3. Constructing and sending AJAX requests with proper metadata
     * 4. Handling response processing and error conditions
     * 5. Updating editor content while preserving metadata
     * 6. Restoring UI state after processing
     * 
     * @param {string} action - The action to perform (create, rewrite, etc.)
     */
    // Store selection range for processing
    let currentSelectionRange = null;
    
    function processText(action) {
        console.log('DokuLLM: Processing text with action:', action);
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found');
            return;
        }
        
        // Store the current selection range
        currentSelectionRange = {
            start: editor.selectionStart,
            end: editor.selectionEnd
        };
        
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
        
        // Send AJAX request
        console.log('DokuLLM: Sending AJAX request to backend');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', action);
        formData.append('text', textToProcess);
        formData.append('prompt', '');
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
            // Remove AI thinking parts (between backticks) from the result
            const cleanedResult = data.result;
            
            // Replace selected text or append to editor
            if (action === 'analyze' || action === 'summarize') {
                console.log('DokuLLM: Showing ' + action + ' in modal');
                showAnalysisModal(cleanedResult, action);
            } else if (selectedText) {
                console.log('DokuLLM: Replacing selected text');
                replaceSelectedText(editor, cleanedResult);
            } else if (action === 'conclusion' || action === 'compare') {
                console.log('DokuLLM: Appending ' + action + ' to existing text');
                // For conclusion/compare, append to the end of existing content (preserving metadata)
                const metadata = extractMetadata(editor.value);
                const contentWithoutMetadata = editor.value.substring(metadata.length);
                editor.value = metadata + contentWithoutMetadata + '\n\n' + cleanedResult;
            } else {
                console.log('DokuLLM: Replacing full text content');
                // Preserve metadata when doing full page update
                const metadata = extractMetadata(editor.value);
                editor.value = metadata + cleanedResult;
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
     * Show analysis or summarize results in a modal dialog
     * 
     * Creates and displays a modal dialog with the analysis or summarize results.
     * Includes a close button and proper styling.
     * 
     * @param {string} contentText - The content text to display
     * @param {string} action - The action type ('analyze' or 'summarize')
     */
    function showAnalysisModal(contentText, action = 'analyze') {
        // Create modal container
        const modal = document.createElement('div');
        modal.id = 'llm-' + action + '-modal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            display: flex;
            justify-content: center;
            align-items: center;
        `;
        
        // Create modal content
        const modalContent = document.createElement('div');
        modalContent.style.cssText = `
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            max-width: 80%;
            max-height: 80%;
            overflow: auto;
            position: relative;
        `;
        
        // Create close button
        const closeButton = document.createElement('button');
        closeButton.textContent = 'Close';
        closeButton.style.cssText = `
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #ccc;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        `;
        closeButton.addEventListener('click', () => {
            document.body.removeChild(modal);
        });
        
        // Create title based on action
        const title = document.createElement('h3');
        if (action === 'analyze') {
            title.textContent = 'Text Analysis';
        } else if (action === 'summarize') {
            title.textContent = 'Text Summary';
        } else if (action === 'compare') {
            title.textContent = 'Text Comparison';
        }
        title.style.marginTop = '0';
        
        // Create content area
        const content = document.createElement('div');
        content.innerHTML = contentText.replace(/\n/g, '<br>');
        content.style.cssText = `
            margin-top: 20px;
            white-space: pre-wrap;
        `;
        
        // Assemble modal
        modalContent.appendChild(closeButton);
        modalContent.appendChild(title);
        modalContent.appendChild(content);
        modal.appendChild(modalContent);
        
        // Add to document and set up close event
        document.body.appendChild(modal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                document.body.removeChild(modal);
            }
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
     * Complex logic includes:
     * 1. Validating custom prompt input
     * 2. Determining text to process (selected vs full content)
     * 3. Managing UI state for the custom prompt interface
     * 4. Constructing and sending AJAX requests with custom prompts
     * 5. Handling response processing and error conditions
     * 6. Updating editor content and clearing input fields
     * 7. Restoring UI state after processing
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
        
        // Store the current selection range
        currentSelectionRange = {
            start: editor.selectionStart,
            end: editor.selectionEnd
        };
        
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
            // Remove AI thinking parts (between backticks) from the result
            const cleanedResult = data.result;
            
            // Replace selected text or append to editor
            if (selectedText) {
                console.log('DokuLLM: Replacing selected text for custom prompt');
                replaceSelectedText(editor, cleanedResult);
            } else {
                console.log('DokuLLM: Replacing full text content for custom prompt');
                // Preserve metadata when doing full page update
                const metadata = extractMetadata(editor.value);
                editor.value = metadata + cleanedResult;
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
     * Complex logic includes:
     * 1. Determining if text is selected or if it's a full content replacement
     * 2. Preserving metadata directives when doing full content replacement
     * 3. Properly replacing only selected text when applicable
     * 4. Managing cursor position after text replacement
     * 5. Maintaining focus on the textarea
     * 
     * @param {HTMLTextAreaElement} textarea - The textarea element
     * @param {string} newText - The new text to insert
     */
    function replaceSelectedText(textarea, newText) {
        // Use stored selection range if available, otherwise use current selection
        const start = currentSelectionRange ? currentSelectionRange.start : textarea.selectionStart;
        const end = currentSelectionRange ? currentSelectionRange.end : textarea.selectionEnd;
        const text = textarea.value;
        
        // Reset the stored selection range
        currentSelectionRange = null;
        
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
     * Complex logic includes:
     * 1. Initializing metadata structure with default values
     * 2. Safely accessing page content from the editor
     * 3. Using regular expressions to extract metadata directives
     * 4. Parsing comma-separated example page lists
     * 5. Trimming whitespace from extracted values
     * 
     * @returns {Object} Metadata object with template and examples
     */
    function getPageMetadata() {
        const metadata = {
            template: '',
            examples: [],
            previous_text_page: ''
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
        
        // Extract previous text page from metadata
        const previousTextMatch = pageContent.match(/~~LLM_PREVIOUS_TEXT:([^~]+)~~/);
        if (previousTextMatch) {
            metadata.previous_text_page = previousTextMatch[1].trim();
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
     * Complex logic includes:
     * 1. Managing UI state during template loading (loading indicator, readonly)
     * 2. Constructing and sending AJAX requests for template content
     * 3. Handling response processing and error conditions
     * 4. Inserting template content at the correct cursor position
     * 5. Managing cursor position after content insertion
     * 6. Restoring UI state after template insertion
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
                margin-bottom: 0.5em;
            }
            
            .llm-custom-prompt {
                margin-bottom: 0.5em;
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
