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
        
        // Dirty hack to handle selection of mobile menu
        // See: https://github.com/splitbrain/dokuwiki/blob/release_stable_2018-04-22/lib/scripts/behaviour.js#L102-L115
        const quickSelect = jQuery('select.quickselect');
        if (quickSelect.length > 0) {
            quickSelect
                .unbind('change')  // Remove dokuwiki's default handler to override its behavior
                .change(function(e) {
                    if (e.target.value != 'dokullmplugin__copy') {
                        // do the default action
                        e.target.form.submit();
                        return;
                    }

                    e.target.value = '';  // Reset selection to enable re-select when a prompt is canceled
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
        const metadata = getMetadata();
        console.log('DokuLLM: Page metadata retrieved', metadata);
        
        // Add "Insert template" button if template is defined
        if (metadata.template) {
            console.log('DokuLLM: Adding insert template button for', metadata.template);
            const templateBtn = document.createElement('button');
            templateBtn.type = 'button';
            templateBtn.className = 'toolbutton';
            templateBtn.textContent = 'Insert Template';
            templateBtn.addEventListener('click', () => insertTemplateContent(metadata.template));
            toolbar.appendChild(templateBtn);
        } else {
            // Add "Find Template" button if no template is defined and ChromaDB is enabled
            // Check if ChromaDB is enabled through JSINFO
            const chromaDBEnabled = typeof JSINFO !== 'undefined' && JSINFO.plugins && JSINFO.plugins.dokullm && JSINFO.plugins.dokullm.enable_chromadb;
            if (chromaDBEnabled) {
                console.log('DokuLLM: Adding find template button');
                const findTemplateBtn = document.createElement('button');
                findTemplateBtn.type = 'button';
                findTemplateBtn.className = 'toolbutton';
                findTemplateBtn.textContent = 'Find Template';
                findTemplateBtn.addEventListener('click', findTemplate);
                toolbar.appendChild(findTemplateBtn);
            }
        }
        
        // Add loading indicator while fetching actions
        const loadingIndicator = document.createElement('span');
        loadingIndicator.textContent = 'Loading LLM actions...';
        loadingIndicator.id = 'llm-loading';
        toolbar.appendChild(loadingIndicator);
        
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
        
        // Fetch action definitions from the API
        getActions()
            .then(actions => {
                // Remove loading indicator
                const loadingElement = document.getElementById('llm-loading');
                if (loadingElement) {
                    loadingElement.remove();
                }
                
                // Add buttons based on fetched actions
                actions.forEach(action => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'toolbutton';
                    btn.textContent = action.label;
                    btn.title = action.description || '';
                    btn.dataset.action = action.id;
                    btn.dataset.result = action.result;
                    btn.addEventListener('click', function(event) {
                        processLLMAction(action.id, event);
                    });
                    toolbar.appendChild(btn);
                });
                
                console.log('DokuLLM: LLM toolbars added successfully');
            })
            .catch(error => {
                console.error('DokuLLM: Error fetching action definitions:', error);
                // Remove loading indicator and show error
                const loadingElement = document.getElementById('llm-loading');
                if (loadingElement) {
                    loadingElement.textContent = 'Failed to load LLM actions';
                }
            });
    }

    /**
     * Copy the current page to a new page ID
     * 
     * Prompts the user for a new page ID and redirects to the edit page
     * with the current page content pre-filled.
     * 
     * Validates that the new ID is different from the current page ID.
     * Handles user cancellation of the prompt dialog.
     * 
     * Complex logic includes:
     * 1. Prompting user for new page ID with current ID as default
     * 2. Validating that new ID is different from current ID
     * 3. Handling browser differences in prompt cancellation (null vs empty string)
     * 4. Constructing the redirect URL with proper encoding
     * 5. Redirecting to the new page edit view with copyfrom parameter
     * 
     * Based on the DokuWiki CopyPage plugin
     * @see https://www.dokuwiki.org/plugin:copypage
     */
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
     * backend for processing, and handles the result based on the button's
     * dataset.result property ('replace', 'append', 'insert', 'show').
     * 
     * Preserves page metadata when doing full page updates.
     * Shows loading indicators during processing.
     * 
     * Complex logic includes:
     * 1. Determining text to process (selected vs full content)
     * 2. Managing UI state during processing (loading indicators, readonly)
     * 3. Constructing and sending AJAX requests with proper metadata
     * 4. Handling response processing and error conditions
     * 5. Updating editor content while preserving metadata based on result handling mode
     * 6. Restoring UI state after processing
     * 
     * @param {string} action - The action to perform (create, rewrite, etc.)
     */
    // Store selection range for processing
    let currentSelectionRange = null;
    
    function processLLMAction(action, event) {
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
        const metadata = getMetadata();
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
        
        // Disable the entire toolbar and prompt input
        const toolbar = document.getElementById('llm-toolbar');
        const promptContainer = document.getElementById('llm-custom-prompt');
        const promptInput = promptContainer ? promptContainer.querySelector('.llm-prompt-input') : null;
        const buttons = toolbar.querySelectorAll('button:not(.llm-modal-close)');
        
        // Store original states for restoration
        const originalStates = {
            promptInput: promptInput ? promptInput.disabled : false,
            buttons: []
        };
        
        // Disable prompt input if it exists
        if (promptInput) {
            originalStates.promptInput = promptInput.disabled;
            promptInput.disabled = true;
        }
        
        // Disable all buttons and store their original states
        buttons.forEach(button => {
            originalStates.buttons.push({
                element: button,
                text: button.textContent,
                disabled: button.disabled
            });
            // Only change text of the button that triggered the action
            if (event && event.target === button) {
                button.textContent = 'Processing...';
            }
            button.disabled = true;
        });
        console.log('DokuLLM: Toolbar disabled, showing processing state');
        
        // Make textarea readonly during processing
        editor.readOnly = true;
        
        // Send AJAX request
        console.log('DokuLLM: Sending AJAX request to backend');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', action);
        formData.append('text', textToProcess);
        formData.append('prompt', '');
        // Append metadata fields generically
        for (const [key, value] of Object.entries(metadata)) {
            if (Array.isArray(value)) {
                formData.append(key, value.join('\n'));
            } else if (value) {
                formData.append(key, value);
            }
        }
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`Network response was not ok: ${response.status} ${response.statusText} - ${text}`);
                });
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
            
            // Remove some part
            const [thinkingContent, cleanedResult] = removeBetweenXmlTags(data.result, 'think');

            // Determine how to handle the result based on button's dataset.result property
            const resultHandling = event.target.dataset.result || 'replace';
            
            // Replace selected text or handle result based on resultHandling
            if (resultHandling === 'show') {
                console.log('DokuLLM: Showing result in modal');
                const buttonTitle = event.target.title || action;
                showModal(cleanedResult, action, buttonTitle);
            } else if (resultHandling === 'append') {
                console.log('DokuLLM: Appending result to existing text');
                // Append to the end of existing content (preserving metadata)
                const metadata = extractMetadata(editor.value);
                const contentWithoutMetadata = editor.value.substring(metadata.length);
                editor.value = metadata + contentWithoutMetadata + '\n\n' + cleanedResult;
                // Show thinking content in modal if it exists and thinking is enabled
                if (thinkingContent) {
                    showModal(thinkingContent, 'thinking', 'AI Thinking Process');
                }
            } else if (resultHandling === 'insert') {
                console.log('DokuLLM: Inserting result before existing text');
                // Insert before existing content (preserving metadata)
                const metadata = extractMetadata(editor.value);
                const contentWithoutMetadata = editor.value.substring(metadata.length);
                editor.value = metadata + cleanedResult + '\n\n' + contentWithoutMetadata;
                // Show thinking content in modal if it exists and thinking is enabled
                if (thinkingContent) {
                    showModal(thinkingContent, 'thinking', 'AI Thinking Process');
                }
            } else if (selectedText) {
                console.log('DokuLLM: Replacing selected text');
                replaceSelectedText(editor, cleanedResult);
                // Show thinking content in modal if it exists and thinking is enabled
                if (thinkingContent) {
                    showModal(thinkingContent, 'thinking', 'AI Thinking Process');
                }
            } else {
                console.log('DokuLLM: Replacing full text content');
                // Preserve metadata when doing full page update
                const metadata = extractMetadata(editor.value);
                editor.value = metadata + cleanedResult;
                // Show thinking content in modal if it exists and thinking is enabled
                if (thinkingContent) {
                    showModal(thinkingContent, 'thinking', 'AI Thinking Process');
                }
            }
        })
        .catch(error => {
            console.log('DokuLLM: Error during processing:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Resetting toolbar and enabling editor');
            // Re-enable the toolbar and prompt input
            if (promptInput) {
                promptInput.disabled = originalStates.promptInput;
            }
            originalStates.buttons.forEach(buttonState => {
                buttonState.element.textContent = buttonState.text;
                buttonState.element.disabled = buttonState.disabled;
            });
            editor.readOnly = false;
        });
    }
    
    /**
     * Convert markdown/DokuWiki text to HTML
     * 
     * Performs basic conversion of markdown/DokuWiki syntax to HTML.
     * Supports headings, lists, inline formatting, and code blocks.
     * 
     * @param {string} text - The markdown/DokuWiki text to convert
     * @returns {string} The converted HTML
     */
    function convertToHtml(text) {
        // Process code blocks first (```code```)
        let html = text.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        
        // Process DokuWiki file blocks ({{file>page#section}})
        html = html.replace(/\{\{file>([^}]+)\}\}/g, '<div class="include">$1</div>');
        
        // Process DokuWiki includes ({{page}})
        html = html.replace(/\{\{([^}]+)\}\}/g, '<div class="include">$1</div>');
        
        // Process inline code (`code`)
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        
        // Process DokuWiki headings (====== Heading ======)
        html = html.replace(/^====== (.*?) ======$/gm, '<h1>$1</h1>');
        html = html.replace(/^===== (.*?) =====$/gm, '<h2>$1</h2>');
        html = html.replace(/^==== (.*?) ====$/gm, '<h3>$1</h3>');
        html = html.replace(/^=== (.*?) ===$/gm, '<h4>$1</h4>');
        html = html.replace(/^== (.*?) ==$/gm, '<h5>$1</h5>');
        html = html.replace(/^= (.*?) =$/gm, '<h6>$1</h6>');
        
        // Process markdown headings (# Heading, ## Heading, etc.)
        html = html.replace(/^###### (.*$)/gm, '<h6>$1</h6>');
        html = html.replace(/^##### (.*$)/gm, '<h5>$1</h5>');
        html = html.replace(/^#### (.*$)/gm, '<h4>$1</h4>');
        html = html.replace(/^### (.*$)/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.*$)/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.*$)/gm, '<h1>$1</h1>');
        
        // Process DokuWiki bold (**text**)
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        // Process DokuWiki italic (//text//)
        html = html.replace(/\/\/(.*?)\/\//g, '<em>$1</em>');
        
        // Process markdown bold (__text__)
        html = html.replace(/__(.*?)__/g, '<strong>$1</strong>');
        
        // Process markdown italic (*text* or _text_)
        html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
        html = html.replace(/_(.*?)_/g, '<em>$1</em>');
        
        // Process DokuWiki external links ([[http://example.com|text]])
        html = html.replace(/\[\[(https?:\/\/[^\]|]+)\|([^\]]+)\]\]/g, '<a href="$1" target="_blank">$2</a>');
        
        // Process DokuWiki external links ([[http://example.com]])
        html = html.replace(/\[\[(https?:\/\/[^\]]+)\]\]/g, '<a href="$1" target="_blank">$1</a>');
        
        // Process DokuWiki internal links ([[page|text]])
        html = html.replace(/\[\[([^\]|]+)\|([^\]]+)\]\]/g, '<a href="?id=$1">$2</a>');
        
        // Process DokuWiki internal links ([[page]])
        html = html.replace(/\[\[([^\]]+)\]\]/g, '<a href="?id=$1">$1</a>');
        
        // Process unordered lists (* item or - item) with role attribute
        html = html.replace(/^\* (.*$)/gm, '<li role="ul">$1</li>');
        html = html.replace(/^- (.*$)/gm, '<li role="ul">$1</li>');
        
        // Process ordered lists (1. item) with role attribute
        html = html.replace(/^\d+\. (.*$)/gm, '<li role="ol">$1</li>');
        
        // Wrap consecutive <li role="ul"> elements in <ul>
        html = html.replace(/(<li role="ul">.*<\/li>(\s*<li role="ul">.*<\/li>)*)/g, '<ul>$1</ul>');
        
        // Wrap consecutive <li role="ol"> elements in <ol>
        html = html.replace(/(<li role="ol">.*<\/li>(\s*<li role="ol">.*<\/li>)*)/g, '<ol>$1</ol>');
        
        // Remove role attributes from li elements (they were only used for identification)
        html = html.replace(/<li role="(ul|ol)">/g, '<li>');
        
        return html;
    }
    
    /**
     * Show analysis or summarize results in a modal dialog
     * 
     * Creates and displays a modal dialog with the analysis or summarize results.
     * Includes a close button and proper styling.
     * 
     * @param {string} contentText - The content text to display
     * @param {string} action - The action type ('analyze' or 'summarize')
     * @param {string} titleText - The title to display in the modal
     */
    function showModal(contentText, action = 'analyze', titleText = '') {
        // Create modal container
        const modal = document.createElement('div');
        modal.id = 'llm-' + action + '-modal';
        modal.className = 'llm-modal';
        
        // Create modal content
        const modalContent = document.createElement('div');
        modalContent.className = 'llm-modal-content';
        
        // Create close button
        const closeButton = document.createElement('button');
        closeButton.textContent = 'Close';
        closeButton.className = 'llm-modal-close';
        closeButton.addEventListener('click', () => {
            document.body.removeChild(modal);
        });
        
        // Create append button
        const appendButton = document.createElement('button');
        appendButton.textContent = 'Append';
        appendButton.title = 'Append to report';
        appendButton.className = 'llm-modal-append';
        appendButton.addEventListener('click', () => {
            appendToReport(contentText);
            document.body.removeChild(modal);
        });
        
        // Create title based on action or use provided title
        const title = document.createElement('h3');
        if (titleText) {
            title.textContent = titleText;
        } else {
            title.textContent = action.charAt(0).toUpperCase() + action.slice(1);
        }
        title.style.marginTop = '0';
        
        // Create content area
        const content = document.createElement('div');
        content.innerHTML = convertToHtml(contentText);
        content.style.cssText = `
            margin-top: 20px;
            white-space: pre-wrap;
        `;
        
        // Assemble modal
        modalContent.appendChild(closeButton);
        modalContent.appendChild(appendButton);
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
     * Append content to the end of the report
     * 
     * Adds the provided content to the end of the editor content,
     * preserving metadata at the beginning.
     * 
     * @param {string} content - The content to append
     */
    function appendToReport(content) {
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found for appending content');
            return;
        }
        
        // Preserve metadata when appending content
        const metadata = extractMetadata(editor.value);
        const contentWithoutMetadata = editor.value.substring(metadata.length);
        
        // Append new content with proper spacing
        editor.value = metadata + contentWithoutMetadata + '\n\n' + content;
        
        // Focus the editor at the end
        editor.focus();
        editor.setSelectionRange(editor.value.length, editor.value.length);
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
        const metadata = getMetadata();
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
        // Append metadata fields generically
        for (const [key, value] of Object.entries(metadata)) {
            if (Array.isArray(value)) {
                formData.append(key, value.join('\n'));
            } else if (value) {
                formData.append(key, value);
            }
        }
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    throw new Error(`Network response was not ok: ${response.status} ${response.statusText} - ${text}`);
                });
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
            // Extract AI thinking parts (between <think> tags) from the result
            let thinkingContent = '';
            // Remove some part
            const [thinkingContent, cleanedResult] = removeBetweenXmlTags(data.result, 'think');
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
            // Show thinking content in modal if it exists and thinking is enabled
            if (thinkingContent) {
                showModal(thinkingContent, 'thinking', 'AI Thinking Process');
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
    function getMetadata() {
        const metadata = {
            template: '',
            examples: [],
            previous: ''
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
        
        // Extract previous report page from metadata
        const previousReportMatch = pageContent.match(/~~LLM_PREVIOUS:([^~]+)~~/);
        if (previousReportMatch) {
            metadata.previous = previousReportMatch[1].trim();
        }
        
        return metadata;
    }
    
    /**
     * Insert metadata after the first title in the text
     * 
     * Checks if the first line is a title (starts with = in DokuWiki)
     * and inserts the metadata line after it, otherwise inserts at the beginning.
     * 
     * @param {string} text - The text content
     * @param {string} metadataLine - The metadata line to insert
     * @returns {string} The text with metadata inserted
     */
    function insertMetadataAfterTitle(text, metadataLine) {
        // Check if the first line is a title (starts with = in DokuWiki)
        const lines = text.split('\n');
        if (lines.length > 0 && lines[0].trim() !== '' && lines[0].trim()[0] === '=') {
            // Insert after the first line (the title)
            lines.splice(1, 0, metadataLine);
            return lines.join('\n');
        } else {
            // Insert at the very beginning
            return metadataLine + '\n' + text;
        }
    }

    /**
     * Find and insert template metadata
     * 
     * Searches for an appropriate template based on the current content
     * and inserts the LLM_TEMPLATE metadata at the top of the text.
     * 
     * Shows loading indicators during the search operation.
     * 
     * @param {Event} event - The click event
     */
    function findTemplate(event) {
        console.log('DokuLLM: Finding and inserting template');
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found for template search');
            return;
        }
        
        // Disable the entire toolbar and prompt input
        const toolbar = document.getElementById('llm-toolbar');
        const promptContainer = document.getElementById('llm-custom-prompt');
        const promptInput = promptContainer ? promptContainer.querySelector('.llm-prompt-input') : null;
        const buttons = toolbar.querySelectorAll('button:not(.llm-modal-close)');
        
        // Store original states for restoration
        const originalStates = {
            promptInput: promptInput ? promptInput.disabled : false,
            buttons: []
        };
        
        // Disable prompt input if it exists
        if (promptInput) {
            originalStates.promptInput = promptInput.disabled;
            promptInput.disabled = true;
        }
        
        // Disable all buttons and store their original states
        buttons.forEach(button => {
            originalStates.buttons.push({
                element: button,
                text: button.textContent,
                disabled: button.disabled
            });
            button.textContent = 'Searching...';
            button.disabled = true;
        });
        editor.readOnly = true;
        console.log('DokuLLM: Showing loading indicator for template search');
        
        // Get the current text to use for template search
        const currentText = editor.value;
        
        // Send AJAX request to find template
        console.log('DokuLLM: Sending AJAX request to find template');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'find_template');
        formData.append('text', currentText);
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('DokuLLM: Received template search response');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error finding template:', data.error);
                throw new Error(data.error);
            }
            
            if (data.result && data.result.template) {
                console.log('DokuLLM: Template found:', data.result.template);
                // Insert template metadata at the top of the text, but after title if present
                const metadataLine = `~~LLM_TEMPLATE:${data.result.template}~~`;
                editor.value = insertMetadataAfterTitle(editor.value, metadataLine);
                    
                // Show success message
                alert(`Template found and inserted: ${data.result.template}`);
            } else {
                console.log('DokuLLM: No template found');
                alert('No suitable template found for this content.');
            }
        })
        .catch(error => {
            console.log('DokuLLM: Error during template search:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Restoring toolbar and enabling editor');
            // Re-enable the toolbar and prompt input
            if (promptInput) {
                promptInput.disabled = originalStates.promptInput;
            }
            originalStates.buttons.forEach(buttonState => {
                buttonState.element.textContent = buttonState.text;
                buttonState.element.disabled = buttonState.disabled;
            });
            editor.readOnly = false;
        });
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
     * Fetch action definitions from the API endpoint
     * 
     * Makes an AJAX request to get the LLM action definitions from the backend
     * 
     * @returns {Promise<Array>} Promise that resolves to an array of action definitions
     */
    function getActions() {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('call', 'plugin_dokullm');
            formData.append('action', 'get_actions');
            
            fetch(DOKU_BASE + 'lib/exe/ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(`Network response was not ok: ${response.status} ${response.statusText} - ${text}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                resolve(data.result);
            })
            .catch(error => {
                reject(error);
            });
        });
    }

    /**
     * Remove everything between two XML tags from a text
     * 
     * This function extracts content between specified XML opening and closing tags,
     * and returns both the content between tags and the rest of the text without the tags.
     * 
     * @param {string} text - The text to process
     * @param {string} tagName - The name of the XML tag to extract content from
     * @returns {Array} An array with two elements: [contentBetweenTags, restOfText]
     */
    function removeBetweenXmlTags(text, tagName) {
        const regex = new RegExp(`<${tagName}[^>]*>([\\s\\S]*?)<\/${tagName}>`, 'g');
        let contentBetweenTags = '';
        let match;
        
        // Extract content between tags
        while ((match = regex.exec(text)) !== null) {
            contentBetweenTags += match[1];
        }
        
        // Remove all occurrences of the tags and their content
        const restOfText = text.replace(regex, '').trim();
        
        return [contentBetweenTags, restOfText];
    }

})();
