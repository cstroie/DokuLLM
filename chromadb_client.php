<?php

class ChromaDBClient {
    private $baseUrl;
    private $client;

    public function __construct($host = '10.200.8.16', $port = 8087) {
        $this->baseUrl = "http://{$host}:{$port}";
        $this->client = curl_init();
        curl_setopt($this->client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->client, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
    }

    public function __destruct() {
        curl_close($this->client);
    }

    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        curl_setopt($this->client, CURLOPT_URL, $url);
        curl_setopt($this->client, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data) {
            curl_setopt($this->client, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($this->client, CURLOPT_POSTFIELDS, null);
        }

        $response = curl_exec($this->client);
        $httpCode = curl_getinfo($this->client, CURLINFO_HTTP_CODE);
        
        if (curl_error($this->client)) {
            throw new Exception('Curl error: ' . curl_error($this->client));
        }
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: $httpCode, Response: $response");
        }
        
        return json_decode($response, true);
    }

    public function listCollections() {
        return $this->makeRequest('/api/v1/collections');
    }

    public function getCollection($name) {
        return $this->makeRequest("/api/v1/collections/{$name}");
    }

    public function createCollection($name, $metadata = null) {
        $data = ['name' => $name];
        if ($metadata) {
            $data['metadata'] = $metadata;
        }
        return $this->makeRequest('/api/v1/collections', 'POST', $data);
    }

    public function deleteCollection($name) {
        return $this->makeRequest("/api/v1/collections/{$name}", 'DELETE');
    }

    public function addDocuments($collectionName, $documents, $ids, $metadatas = null, $embeddings = null) {
        $data = [
            'ids' => $ids,
            'documents' => $documents
        ];
        
        if ($metadatas) {
            $data['metadatas'] = $metadatas;
        }
        
        if ($embeddings) {
            $data['embeddings'] = $embeddings;
        }
        
        return $this->makeRequest("/api/v1/collections/{$collectionName}/add", 'POST', $data);
    }

    public function queryCollection($collectionName, $queryTexts, $nResults = 5, $where = null) {
        $data = [
            'query_texts' => $queryTexts,
            'n_results' => $nResults
        ];
        
        if ($where) {
            $data['where'] = $where;
        }
        
        return $this->makeRequest("/api/v1/collections/{$collectionName}/query", 'POST', $data);
    }

    /**
     * Convenience function to add a DokuWiki document to the Chroma database
     * 
     * @param string $collectionName The name of the collection to add to
     * @param string $id The DokuWiki document ID (e.g. 'reports:mri:medima:250620-ivan-aisha' or 'reports:mri:2024:g287-criveanu-cristian-andrei')
     * @param string $content The document content
     * @return array The response from the ChromaDB API
     */
    public function addDokuWikiDocument($collectionName, $id, $content) {
        // Parse the DokuWiki ID to extract metadata
        $parts = explode(':', $id);
        
        // Extract metadata from the last part of the ID
        $lastPart = end($parts);
        $metadata = [];
        
        // Add the document ID as metadata
        $metadata['document_id'] = $id;
        
        // Extract modality from the second part
        if (isset($parts[1])) {
            $metadata['modality'] = $parts[1];
        }
        
        // Handle different ID formats
        if (count($parts) == 5) {
            // Format: reports:mri:medima:250620-ivan-aisha
            // Extract institution from the third part
            if (isset($parts[2])) {
                $metadata['institution'] = $parts[2];
            }
            
            // Extract date and name from the last part
            if (preg_match('/^(\d{6})-(.+)$/', $lastPart, $matches)) {
                $dateStr = $matches[1];
                $name = $matches[2];
                
                // Convert date format (250620 -> 2025-06-20)
                $day = substr($dateStr, 0, 2);
                $month = substr($dateStr, 2, 2);
                $year = substr($dateStr, 4, 2);
                // Assuming 20xx for years 20-99 and 19xx for years 00-19
                $fullYear = (int)$year >= 20 ? '20' . $year : '19' . $year;
                $formattedDate = $fullYear . '-' . $month . '-' . $day;
                
                $metadata['date'] = $formattedDate;
                $metadata['name'] = str_replace('-', ' ', $name);
            }
        } else if (count($parts) == 4) {
            // Format: reports:mri:2024:g287-criveanu-cristian-andrei
            // Extract year from the third part
            if (isset($parts[2])) {
                $metadata['year'] = $parts[2];
            }
            
            // Set default institution
            $metadata['institution'] = 'scuc';
            
            // Extract registration and name from the last part
            if (preg_match('/^([a-zA-Z0-9]+)-(.+)$/', $lastPart, $matches)) {
                $metadata['registration'] = $matches[1];
                $metadata['name'] = str_replace('-', ' ', $matches[2]);
            }
        }
        
        return $this->addDocuments($collectionName, [$content], [$id], [$metadata]);
    }
}

// Example usage:
try {
    $chroma = new ChromaDBClient('10.200.8.16', 8087);
    
    // Create a collection
    $collectionName = 'documents';
    $chroma->createCollection($collectionName);
    
    // Add documents
    $documents = [
        'This is document about artificial intelligence',
        'This document covers machine learning techniques',
        'Natural language processing is a subset of AI'
    ];
    
    $ids = ['doc1', 'doc2', 'doc3'];
    $metadatas = [
        ['topic' => 'AI'],
        ['topic' => 'ML'],
        ['topic' => 'NLP']
    ];
    
    $chroma->addDocuments($collectionName, $documents, $ids, $metadatas);
    
    // Query documents
    $results = $chroma->queryCollection($collectionName, ['AI and machine learning'], 2);
    print_r($results);
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
