<?php

namespace strtob\yii2Ollama;

use strtob\yii2Ollama\VectorDbInterface;

/**
 * Adapter to use Qdrant as a Vector DB for OllamaComponent.
 *
 * Example:
 * ```php
 * $qdrantClient = new \Qdrant\Client(['url' => 'http://localhost:6333']);
 * $adapter = new \strtob\yii2Ollama\QdrantAdapter($qdrantClient, 'my_collection');
 * ```
 */
class QdrantAdapter implements VectorDbInterface
{
    /** @var \Qdrant\Client */
    protected \Qdrant\Client $client;

    /** @var string Name of the collection in Qdrant */
    protected string $collection;

    /**
     * Constructor
     *
     * @param \Qdrant\Client $client Qdrant client instance
     * @param string $collection Name of the collection
     */
    public function __construct(\Qdrant\Client $client, string $collection)
    {
        $this->client = $client;
        $this->collection = $collection;
    }

    /**
     * Search Qdrant for relevant context.
     *
     * @param string $query Text query
     * @param int $topK Number of results to return
     * @return string[] Array of text snippets
     */
    public function search(string $query, int $topK = 5): array
    {
        // Convert the query to embedding using your preferred model
        // For example, using OpenAI embedding or Ollama embedding
        $embedding = $this->getEmbedding($query);

        $results = $this->client->search(
            $this->collection,
            $embedding,
            [
                'limit' => $topK,
            ]
        );

        // Extract text from results
        return array_map(fn($item) => $item['payload']['text'] ?? '', $results);
    }

    /**
     * Convert query to vector embedding.
     *
     * @param string $query
     * @return array Numeric embedding array
     */
    protected function getEmbedding(string $query): array
    {
        // Example: Replace with your embedding logic
        // Could be Ollama embedding, OpenAI, or custom model
        // For demonstration, returning a dummy vector
        return array_fill(0, 1536, 0.0);
    }
}
