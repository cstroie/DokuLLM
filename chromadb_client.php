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
