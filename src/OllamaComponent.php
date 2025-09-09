<?php

namespace strtob\yii2Ollama;

use Yii;
use yii\base\Component;
use yii\base\Event;
use yii\httpclient\Client;
use yii\httpclient\CurlTransport;
use yii\base\InvalidConfigException;
use strtob\yii2Ollama\Adapter\VectorDbInterface;
use strtob\yii2Ollama\Exception\OllamaApiException;

/**
 * Yii2 component for Ollama API with optional vector DB support.
 *
 * This component supports:
 * - Text generation with different models
 * - Embedding generation
 * - Vector DB integration (RAG)
 * - Event triggers: before, after, error
 *
 * Example configuration in `config/web.php`:
 * 
 * ```php
 * $qdrantAdapter = new \strtob\yii2Ollama\QdrantAdapter($qdrantClient, 'my_collection');
 * 
 * 'components' => [
 *     'ollama' => [
 *         'class' => \strtob\yii2Ollama\OllamaComponent::class,
 *         'apiRequestUrl' => 'http://localhost:11434/v1/completions',
 *         'apiEmbeddingUrl' => 'http://localhost:11434/api/embeddings',
 *         'apiKey' => 'MY_SECRET_TOKEN',
 *         'model' => \strtob\yii2Ollama\OllamaComponent::MODEL_MISTRAL,
 *         'embeddingModel' => \strtob\yii2Ollama\OllamaComponent::EMBEDDING_MODEL_MISTRAL,
 *         'vectorDb' => $qdrantAdapter,
 *         'vectorDbTopK' => 5,
 *         'format' => \strtob\yii2Ollama\OllamaComponent::FORMAT_MARKDOWN,
 *     ],
 * ];
 * ```
 */
class OllamaComponent extends Component
{
    // ----------------------
    // Models
    // ----------------------
    public const MODEL_LLAMA2 = 'llama2';
    public const MODEL_MISTRAL = 'mistral';
    public const MODEL_GEMMA = 'gemma';

    public const SUPPORTED_MODELS = [
        self::MODEL_LLAMA2,
        self::MODEL_MISTRAL,
        self::MODEL_GEMMA,
    ];

    public const EMBEDDING_MODEL_MISTRAL = 'mistral-embedding';
    public const EMBEDDING_MODEL_LLAMA2 = 'llama2-embedding';
    public const EMBEDDING_MODEL_SNOWFLAKE = 'snowflake-arctic-embed2';


    // ----------------------
    // Output formats
    // ----------------------
    public const FORMAT_TEXT = 'text';
    public const FORMAT_MARKDOWN = 'markdown';
    public const FORMAT_HTML = 'html';

    // ----------------------
    // Events
    // ----------------------
    public const EVENT_BEFORE_GENERATE = 'beforeGenerate';
    public const EVENT_AFTER_GENERATE = 'afterGenerate';
    public const EVENT_GENERATE_ERROR = 'generateError';

    // ----------------------
    // Component properties
    // ----------------------

    /** @var string API URL for text generation */
    public string $apiRequestUrl = 'http://localhost:11434/v1/completions';

    /** @var string API URL for embeddings */
    public string $apiEmbeddingUrl = 'http://localhost:11434/api/embed';

    /** @var string API key */
    public string $apiKey = '';

    /** @var string Model used for text generation */
    public string $model = self::MODEL_LLAMA2;

    /** @var string Model used for embeddings */
    public string $embeddingModel = self::EMBEDDING_MODEL_SNOWFLAKE;

    /** @var float Temperature for generation */
    public float $temperature = 0.7;

    /** @var int Maximum tokens to generate */
    public int $maxTokens = 512;

    /** @var float Top-p sampling */
    public float $topP = 0.9;

    /** @var array|null Stop sequences */
    public ?array $stop = null;

    /** @var VectorDbInterface|null Optional vector DB for context */
    public ?VectorDbInterface $vectorDb = null;

    /** @var int Number of top-K vector DB results to include */
    public int $vectorDbTopK = 5;

    /** @var string Output format for text generation */
    public string $format = self::FORMAT_TEXT;

    // ----------------------
    // Initialization
    // ----------------------
    public function init(): void
    {
        parent::init();

        if (!ini_get('allow_url_fopen')) {
            throw new InvalidConfigException(
                'PHP setting "allow_url_fopen" must be enabled to use OllamaComponent.'
            );
        }
    }

    // ----------------------
    // Text generation
    // ----------------------

    /**
     * Generate text completion from Ollama API.
     *
     * Triggers events:
     * - beforeGenerate
     * - afterGenerate
     * - generateError
     *
     * @param string $prompt
     * @param array $options Optional request overrides
     * @return array Full API response
     * @throws InvalidConfigException
     * @throws OllamaApiException
     */
    public function generate(string $prompt, array $options = []): array
    {
        $this->trigger(self::EVENT_BEFORE_GENERATE, new Event(['data' => ['prompt' => $prompt, 'options' => $options]]));

        if (empty($this->apiRequestUrl)) {
            throw new InvalidConfigException('Ollama API request URL is not set.');
        }

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        // Include vector DB context if available
        if ($this->vectorDb !== null) {
            $contextItems = $this->vectorDb->search($prompt, $this->vectorDbTopK);
            if (!empty($contextItems)) {
                $contextText = "Context:\n" . implode("\n", $contextItems) . "\n\n";
                $prompt = $contextText . "Question:\n" . $prompt;
            }
        }

        $data = array_merge([
            'model' => $this->model,
            'prompt' => $prompt,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'stop' => $this->stop,
            'format' => $this->format,
        ], $options);

        if (!in_array($data['model'], self::SUPPORTED_MODELS)) {
            throw new InvalidConfigException('Unsupported model: ' . $data['model']);
        }

        $client = new Client(['transport' => CurlTransport::class]);
        $context = [];

        try {
            $response = $client->createRequest()
                ->setMethod('POST')
                ->setUrl($this->apiRequestUrl)
                ->setHeaders($headers)
                ->setData($data)
                ->setFormat(Client::FORMAT_JSON)
                ->send();

            if ($response->isOk) {
                $this->trigger(self::EVENT_AFTER_GENERATE, new Event([
                    'data' => [
                        'prompt' => $prompt,
                        'options' => $options,
                        'response' => $response->data,
                    ]
                ]));
                return $response->data;
            }

            throw new OllamaApiException('Ollama API returned error: ' . $response->statusCode);
        } catch (\Throwable $e) {
            $this->trigger(self::EVENT_GENERATE_ERROR, new Event([
                'data' => [
                    'exception' => $e,
                    'context' => [
                        'url' => $this->apiRequestUrl,
                        'model' => $this->model,
                        'prompt' => $prompt,
                        'options' => $options,
                        'apiKeySet' => !empty($this->apiKey),
                    ],
                ]
            ]));

            throw new OllamaApiException(
                'Ollama API request failed: ' . $e->getMessage() .
                '. Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                0,
                $e
            );
        }
    }

    /**
     * Generate text only (returns first choice).
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws InvalidConfigException
     * @throws OllamaApiException
     */
    public function generateText(string $prompt, array $options = []): string
    {
        $response = $this->generate($prompt, $options);
        return $response['choices'][0]['text'] ?? '';
    }

    /**
     * Generate text along with token usage.
     *
     * @param string $prompt
     * @param array $options
     * @return array ['text' => string, 'tokens' => array]
     * @throws InvalidConfigException
     * @throws OllamaApiException
     */
    public function generateTextWithTokens(string $prompt, array $options = []): array
    {
        $response = $this->generate($prompt, $options);
        return [
            'text' => $response['choices'][0]['text'] ?? '',
            'tokens' => $response['usage'] ?? [],
        ];
    }

    // ----------------------
    // Embedding generation
    // ----------------------

    /**
     * Generate an embedding vector for a given text.
     *
     * @param string $text Text to embed
     * @param array $options Optional API overrides
     * @return array ['embedding' => numeric array, 'model' => string]
     * @throws InvalidConfigException
     * @throws OllamaApiException
     */
    public function embedText(string $text, array $options = []): array
    {
        if (empty($this->apiEmbeddingUrl)) {
            throw new InvalidConfigException('Ollama embedding API URL is not set.');
        }

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $data = array_merge([
            'model' => $this->embeddingModel,
            'input' => $text,
        ], $options);

        $client = new Client(['transport' => CurlTransport::class]);

        try {
            $response = $client->createRequest()
                ->setMethod('POST')
                ->setUrl($this->apiEmbeddingUrl)
                ->setHeaders($headers)
                ->setData($data)
                ->setFormat(Client::FORMAT_JSON)
                ->send();

            if ($response->isOk) {
             
                return [
                    'embedding' => $response->data['embeddings'][0] ?? [], // erstes embedding aus Array
                    'model' => $data['model'],
                    'total_duration' => $response->data['total_duration'] ?? null,
                    'load_duration' => $response->data['load_duration'] ?? null,
                    'prompt_eval_count' => $response->data['prompt_eval_count'] ?? null,
                ];
            }

            throw new OllamaApiException('Ollama embedding API returned error: ' . $response->statusCode);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'request' => [
                    'url' => $this->apiEmbeddingUrl,
                    'model' => $this->embeddingModel,
                    'input' => $text,
                ],
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

}
