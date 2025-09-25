/**
 * JavaScript for LLM Integration Plugin
 */

(function() {
    'use strict';
    
    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        // Only run on edit pages
        if (!document.getElementById('wiki__text')) {
            return;
        }
        
        // Add LLM tools to the editor
        addLLMTools();
    });
    
    function addLLMTools() {
        const editor = document.getElementById('wiki__text');
        if (!editor) return;
        
        // Create toolbar container
        const toolbar = document.createElement('div');
        toolbar.id = 'llm-toolbar';
        toolbar.className = 'llm-toolbar';
        
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
        
        // Add CSS styles
        addStyles();
    }
    
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
        
        // Show loading indicator
        const originalButton = event.target;
        const originalText = originalButton.textContent;
        originalButton.textContent = 'Processing...';
        originalButton.disabled = true;
        
        // Get additional prompt if needed (e.g., for translation)
        let prompt = '';
        if (action === 'translate') {
            prompt = prompt('Enter target language:', 'English');
            if (!prompt) {
                resetButton(originalButton, originalText);
                return;
            }
        }
        
        // Send AJAX request
        const formData = new FormData();
        formData.append('call', 'plugin_doku_llm');
        formData.append('action', action);
        formData.append('text', textToProcess);
        formData.append('prompt', prompt);
        
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
    
    function getSelectedText(textarea) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        return textarea.value.substring(start, end);
    }
    
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
    
    function resetButton(button, originalText) {
        button.textContent = originalText;
        button.disabled = false;
    }
    
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            .llm-toolbar {
                margin-bottom: 10px;
                padding: 10px;
                background-color: #f5f5f5;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .llm-button {
                margin-right: 5px;
                margin-bottom: 5px;
                padding: 5px 10px;
                background-color: #007cba;
                color: white;
                border: none;
                border-radius: 3px;
                cursor: pointer;
            }
            
            .llm-button:hover {
                background-color: #005a87;
            }
            
            .llm-button:disabled {
                background-color: #ccc;
                cursor: not-allowed;
            }
        `;
        document.head.appendChild(style);
    }
})();