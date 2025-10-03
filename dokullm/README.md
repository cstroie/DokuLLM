# DokuLLM - LLM Integration Plugin for DokuWiki

A comprehensive DokuWiki plugin that integrates Large Language Model capabilities with semantic search through ChromaDB. This plugin enables advanced text processing directly within the DokuWiki editing environment while maintaining content in a vector database for intelligent content discovery.

## Key Features

### AI-Powered Text Processing
- **Content Creation**: Generate reports and documents with AI assistance
- **Text Comparison**: Highlight differences between document versions
- **Custom Prompts**: Process text with user-defined instructions
- **Template Integration**: Use predefined templates for consistent formatting

### Semantic Search & Document Management
- **Vector Storage**: Store document embeddings in ChromaDB for semantic search
- **Intelligent Chunking**: Smart document splitting with metadata preservation
- **Update Optimization**: Timestamp-based checking to avoid reprocessing unchanged files
- **Direct Retrieval**: Access documents by ID with rich metadata extraction

### DokuWiki Integration
- **Editor Toolbar**: Seamless integration with DokuWiki's editing interface
- **Page Templates**: Smart template handling with automatic metadata insertion
- **Copy Functionality**: Enhanced page duplication with template awareness
- **Context Management**: Provide examples and templates as processing context

## Architecture

The plugin consists of several key components:
- **Frontend**: JavaScript toolbar integrated into DokuWiki's editor
- **Backend**: PHP plugin handling AJAX requests and LLM communication
- **Database**: ChromaDB client for vector storage and semantic search
- **CLI Tools**: Command-line interface for batch document processing

## Requirements
- DokuWiki installation
- PHP 7.4 or higher
- ChromaDB server
- Ollama for local embedding generation (optional)
- Access to LLM API (OpenAI-compatible)

## Installation
1. Clone or download the plugin to your DokuWiki plugins directory
2. Configure the plugin settings in DokuWiki's configuration manager
3. Set up ChromaDB and Ollama services
4. Configure connection settings in `config.php`

## Configuration
The plugin is configurable through multiple levels:
- DokuWiki plugin settings interface
- `config.php` for service endpoints
- `conf/default.php` for default values
- Language files for localization

## License
GPL 2.0

## Author
Costin Stroie <costinstroie@eridu.eu.org>
