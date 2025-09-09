<?php

namespace strtob\yii2Ollama\helpers;

use Yii;
use strtob\yii2Ollama\OllamaComponent;
use strtob\yii2Ollama\Adapter\VectorDbInterface;

class VectorizerHelper
{
    private VectorDbInterface $vectorDb;
    private OllamaComponent $ollama;
    private int $chunkSize;

    /**
     * Constructor
     *
     * @param VectorDbInterface|null $vectorDb Optional Vector DB adapter (defaults to OllamaComponent's vectorDb)
     * @param int $chunkSize Number of words per chunk for embedding
     */
    public function __construct(VectorDbInterface $vectorDb = null, int $chunkSize = 500)
    {
        $this->ollama = Yii::$app->ollama;
        $this->chunkSize = $chunkSize;

        if ($vectorDb !== null) {
            $this->vectorDb = $vectorDb;
        } elseif ($this->ollama->vectorDb !== null) {
            $this->vectorDb = $this->ollama->vectorDb;
        } else {
            throw new \InvalidArgumentException("No VectorDbInterface instance provided or configured in OllamaComponent.");
        }
    }

    /**
     * Convert PDF file to plain text
     *
     * @param string $pdfPath Path to PDF file
     * @return string Extracted text
     */
    public static function pdfToText(string $pdfPath): string
    {
        if (!file_exists($pdfPath)) {
            throw new \InvalidArgumentException("File does not exist: $pdfPath");
        }

        $text = shell_exec("pdftotext " . escapeshellarg($pdfPath) . " -");
        return trim($text ?: '');
    }

    /**
     * Split text into chunks for embedding
     *
     * @param string $text
     * @return array Array of text chunks
     */
    private function chunkText(string $text): array
    {
        $words = preg_split('/\s+/', $text);
        $chunks = [];
        $current = [];

        foreach ($words as $word) {
            $current[] = $word;
            if (count($current) >= $this->chunkSize) {
                $chunks[] = implode(' ', $current);
                $current = [];
            }
        }

        if (!empty($current)) {
            $chunks[] = implode(' ', $current);
        }

        return $chunks;
    }

    /**
     * Convert text to embeddings and store in Vector DB
     *
     * @param string $text The text to embed
     * @param string $sourceId Unique identifier for source (used for chunk IDs)
     * @return array Array of embeddings
     */
    public function vectorizeAndStore(string $text, string $sourceId): array
    {
        $embeddings = [];
        $chunks = $this->chunkText($text);

        foreach ($chunks as $i => $chunk) {
            // Generate embedding using Ollama
            // The embedding model is configured in OllamaComponent (can be defined as const or in config)
            $embedding = $this->ollama->embedText($chunk);

            // Store embedding in Vector DB
            $this->vectorDb->upsert([
                'id' => $sourceId . '_' . $i,
                'vector' => $embedding,
                'payload' => [
                    'text' => $chunk,
                    'source' => $sourceId
                ]
            ]);

            $embeddings[] = $embedding;
        }

        return $embeddings;
    }

    /**
     * Convert PDF directly to embeddings
     *
     * @param string $pdfPath Path to PDF file
     * @return array Array of embeddings
     */
    public function pdfToVectors(string $pdfPath): array
    {
        $text = self::pdfToText($pdfPath);
        return $this->vectorizeAndStore($text, uniqid('pdf_'));
    }

    /**
     * Search for top-K similar vectors
     *
     * @param string $query Query text
     * @param int|null $topK Number of results to return
     * @return array Search results from Vector DB
     */
    public function search(string $query, int $topK = null): array
    {
        $topK = $topK ?? $this->ollama->vectorDbTopK;

        $embedding = $this->ollama->embedText($query);
        return $this->vectorDb->searchByVector($embedding, $topK);
    }
}
