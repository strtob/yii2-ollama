<?php

namespace strtob\yii2Ollama\helpers;

use Yii;

/**
 * Class VectorizerHelper
 *
 * Handles chunking, embedding, and storage of documents in a vector database.
 * Supports PDF, TXT, DOCX with optional page and bounding box metadata for highlighting.
 *
 * Examples:
 *
 * ```php
 * $vectorizer = new VectorizerHelper();
 *
 * // 1) Vectorize a PDF
 * $vectorizer->vectorizeAndStorePdf('/path/to/file.pdf', 'doc_123');
 *
 * // 2) Vectorize plain text
 * $vectorizer->vectorizeAndStore('Some text content...', 'doc_124');
 *
 * // 3) Search for similar chunks
 * $results = $vectorizer->search('query text', 5);
 * foreach ($results as $res) {
 *     echo "Found text chunk: " . $res['metadata']['text'] . " on page " . $res['metadata']['page'];
 * }
 * ```
 */
class VectorizerHelper
{
    /**
     * Chunk size in words
     *
     * @var int
     */
    public static int $chunkSize = 500;

    /**
     * Overlap in words between consecutive chunks
     *
     * @var int
     */
    public static int $overlap = 50;

    /**
     * Extracts text chunks from a PDF, including page number and bounding box.
     *
     * @param string $filePath Path to PDF file
     * @return array Each chunk: ['text' => string, 'page' => int, 'bbox' => [x0, y0, x1, y1]]
     */
    public static function pdfToChunks(string $filePath): array
    {
        $chunks = [];

        if (!file_exists($filePath)) {
            return $chunks;
        }

        // Example using FPDI (replace with actual PDF parser returning text blocks with bbox)
        $pdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $pdf->setSourceFile($filePath);

        for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
            $tplId = $pdf->importPage($pageNum);
            $pdf->useTemplate($tplId);

            $blocks = self::extractTextBlocksWithBBox($filePath, $pageNum);

            foreach ($blocks as $b) {
                $text = trim($b['text']);
                if ($text) {
                    $words = preg_split('/\s+/', $text);
                    $i = 0;
                    while ($i < count($words)) {
                        $chunkWords = array_slice($words, $i, self::$chunkSize);
                        $chunks[] = [
                            'text' => implode(' ', $chunkWords),
                            'page' => $pageNum,
                            'bbox' => $b['bbox'],
                        ];
                        $i += (self::$chunkSize - self::$overlap);
                    }
                }
            }
        }

        return $chunks;
    }

    /**
     * Placeholder: extract text blocks with bounding boxes from PDF page.
     * You must implement this depending on your PDF library.
     *
     * @param string $filePath
     * @param int $pageNum
     * @return array Example: [['text' => 'Block text', 'bbox' => [x0, y0, x1, y1]], ...]
     */
    private static function extractTextBlocksWithBBox(string $filePath, int $pageNum): array
    {
        // Example return for testing
        return [
            ['text' => 'Sample paragraph on page ' . $pageNum, 'bbox' => [50, 100, 500, 150]],
        ];
    }

    /**
     * Vectorizes PDF chunks and stores them in the vector database.
     *
     * @param string $filePath Path to PDF
     * @param string $sourceId Unique identifier for the document
     */
    public function vectorizeAndStorePdf(string $filePath, string $sourceId)
    {
        $chunks = self::pdfToChunks($filePath);

        foreach ($chunks as $i => $chunk) {
            $vector = Yii::$app->ollama->embeddings($chunk['text'], model: 'snowflake-arctic-embed2');

            Yii::$app->ollama->vectorDb->upsert([
                'id' => $sourceId . "_$i",
                'values' => $vector,
                'metadata' => [
                    'text' => $chunk['text'],
                    'page' => $chunk['page'],
                    'bbox' => $chunk['bbox'],
                ]
            ]);
        }
    }

    /**
     * Vectorizes plain text chunks and stores them in the vector database.
     *
     * @param string $text Plain text content
     * @param string $sourceId Unique identifier for the document
     */
    public function vectorizeAndStore(string $text, string $sourceId)
    {
        $words = preg_split('/\s+/', trim($text));
        $i = 0;
        while ($i < count($words)) {
            $chunkWords = array_slice($words, $i, self::$chunkSize);
            $chunkText = implode(' ', $chunkWords);

            $vector = Yii::$app->ollama->embeddings($chunkText, model: 'snowflake-arctic-embed2');

            Yii::$app->ollama->vectorDb->upsert([
                'id' => $sourceId . "_$i",
                'values' => $vector,
                'metadata' => [
                    'text' => $chunkText
                ]
            ]);

            $i += (self::$chunkSize - self::$overlap);
        }
    }

    /**
     * Search the vector database for similar chunks
     *
     * @param string $query Text query
     * @param int $topK Number of top results to return
     * @return array Search results including metadata
     */
    public function search(string $query, int $topK = 5): array
    {
        $queryVector = Yii::$app->ollama->embeddings($query, model: 'snowflake-arctic-embed2');

        $results = Yii::$app->ollama->vectorDb->query([
            'vector' => $queryVector,
            'topK' => $topK,
            'includeMetadata' => true,
        ]);

        return $results;
    }
}
