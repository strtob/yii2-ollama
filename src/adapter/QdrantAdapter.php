<?php

namespace strtob\yii2Ollama;

use strtob\yii2Ollama\adapter\VectorDbInterface;

/**
 * Adapter to use Qdrant as a Vector DB for OllamaComponent.
 *
 * Example usage:
 * ```php
 * $qdrantClient = new \Qdrant\Client(['url' => 'http://localhost:6333']);
 * $adapter = new \strtob\yii2Ollama\QdrantAdapter($qdrantClient, 'my_collection', $ollamaComponent);
 * ```
 */
class QdrantAdapter implements VectorDbInterface
{
    /** @var \Qdrant\Client Qdrant client instance */
    protected \Qdrant\Client $client;

    /** @var string Name of the collection in Qdrant */
    protected string $collection;

    /** @var \strtob\yii2Ollama\OllamaComponent Optional: Ollama for embeddings */
    protected ?OllamaComponent $ollama;

    /**
     * Constructor
     *
     * @param \Qdrant\Client $client Qdrant client instance
     * @param string $collection Name of the collection
     * @param OllamaComponent|null $ollama Optional OllamaComponent for embedding generation
     */
    public function __construct(\Qdrant\Client $client, string $collection, OllamaComponent $ollama = null)
    {
        $this->client = $client;
        $this->collection = $collection;
        $this->ollama = $ollama;
    }

    /**
     * Search Qdrant for top-K relevant vectors using a text query.
     *
     * @param string $query Text query
     * @param int $topK Number of results to return
     * @return string[] Array of text snippets from payload
     */
    public function search(string $query, int $topK = 5): array
    {
        $embedding = $this->getEmbedding($query);
        return $this->searchByVector($embedding, $topK);
    }

    /**
     * Search Qdrant using a precomputed embedding vector.
     *
     * @param array $embedding Numeric embedding array
     * @param int $topK Number of results to return
     * @return string[] Array of text snippets from payload
     */
    public function searchByVector(array $embedding, int $topK = 5): array
    {
        $results = $this->client->search(
            $this->collection,
            $embedding,
            ['limit' => $topK]
        );

        return array_map(fn($item) => $item['payload']['text'] ?? '', $results);
    }

    /**
     * Insert or update a vector into Qdrant.
     *
     * @param array $vectorData Must include:
     *   - 'id' => string
     *   - 'vector' => numeric array
     *   - 'payload' => associative array with metadata (text, source, etc.)
     */
    public function upsert(array $vectorData): void
    {
        $this->client->upsert(
            $this->collection,
            $vectorData['id'],
            $vectorData['vector'],
            $vectorData['payload'] ?? []
        );
    }

    /**
     * Generate embedding for a given query string.
     *
     * @param string $query
     * @return array Numeric embedding
     */
    protected function getEmbedding(string $query): array
    {
        if ($this->ollama !== null) {
            // Use OllamaComponent embedding model
            return $this->ollama->embedText($query);
        }

        // Fallback: dummy vector (replace with your own embedding logic)
        return array_fill(0, 1536, 0.0);
    }
}
