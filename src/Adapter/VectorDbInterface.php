<?php

namespace strtob\yii2Ollama\Adapter;

/**
 * Interface for vector database clients.
 *
 * Any vector database you want to integrate with `OllamaComponent`
 * must implement this interface. The component will use it to retrieve
 * top-K relevant context to inject into the prompt.
 *
 * === Example implementation using a hypothetical Qdrant client ===
 *
 * ```php
 * use strtob\yii2Ollama\VectorDbInterface;
 *
 * class QdrantClientAdapter implements VectorDbInterface
 * {
 *     protected $qdrantClient;
 *
 *     public function __construct($qdrantClient)
 *     {
 *         $this->qdrantClient = $qdrantClient;
 *     }
 *
 *     public function search(string $query, int $topK = 5): array
 *     {
 *         $results = $this->qdrantClient->search($query, $topK);
 *         // Convert results to array of text context
 *         return array_map(fn($item) => $item['text'], $results);
 *     }
 * }
 * ```
 *
 * === Usage in `OllamaComponent` config ===
 *
 * ```php
 * $qdrantAdapter = new QdrantClientAdapter($qdrantClient);
 *
 * 'components' => [
 *     'ollama' => [
 *         'class' => 'strtob\yii2Ollama\OllamaComponent',
 *         'apiUrl' => 'http://localhost:11434/v1/generate',
 *         'apiKey' => 'MY_SECRET_TOKEN',
 *         'vectorDb' => $qdrantAdapter,
 *         'vectorDbTopK' => 5,
 *     ],
 * ];
 * ```
 *
 * @package strtob\yii2Ollama
 */
interface VectorDbInterface
{
    /**
     * Search the vector DB for relevant context.
     *
     * @param string $query Query text
     * @param int $topK Number of top results to return
     * @return string[] Array of strings representing context
     */
    public function search(string $query, int $topK = 5): array;
}
