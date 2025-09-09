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
 * This version supports events for before generation, after generation, and errors.
 *
 * === Example configuration in `config/web.php` ===
 *
 * ```php
 * $qdrantAdapter = new \strtob\yii2Ollama\QdrantAdapter($qdrantClient, 'my_collection');
 *
 * 'components' => [
 *     'ollama' => [
 *         'class' => 'strtob\yii2Ollama\OllamaComponent',
 *         'apiUrl' => 'http://localhost:11434/v1/completions',
 *         'apiKey' => 'MY_SECRET_TOKEN',
 *         'model' => \strtob\yii2Ollama\OllamaComponent::MODEL_MISTRAL,
 *         'temperature' => 0.7,
 *         'maxTokens' => 512,
 *         'topP' => 0.9,
 *         'vectorDb' => $qdrantAdapter, // must implement VectorDbInterface
 *         'vectorDbTopK' => 5,
 *     ],
 * ];
 * ```
 *
 * === Example usage in a controller ===
 *
 * ```php
 * try {
 *     $prompt = "Explain RAG with vector DB.";
 *
 *     // Only text
 *     $text = \Yii::$app->ollama->generateText($prompt);
 *
 *     // Text + token usage
 *     $result = \Yii::$app->ollama->generateTextWithTokens($prompt);
 *     echo $result['text'];
 *     print_r($result['tokens']);
 *
 * } catch (\yii\base\InvalidConfigException $e) {
 *     echo Yii::t('yii2-ollama', 'Configuration error: {message}', ['message' => $e->getMessage()]);
 * } catch (\strtob\yii2Ollama\OllamaApiException $e) {
 *     echo Yii::t('yii2-ollama', 'API request failed: {message}', ['message' => $e->getMessage()]);
 * }
 * ```
 */
class OllamaComponent extends Component
{
    /**
     * Supported model constants
     */
    public const MODEL_LLAMA2 = 'llama2';
    public const MODEL_MISTRAL = 'mistral';
    public const MODEL_GEMMA = 'gemma';

    /**
     * Supported models
     */
    public const SUPPORTED_MODELS = [
        self::MODEL_LLAMA2,
        self::MODEL_MISTRAL,
        self::MODEL_GEMMA,
    ];

    /**
     * Event constants
     */
    public const EVENT_BEFORE_GENERATE = 'beforeGenerate';
    public const EVENT_AFTER_GENERATE = 'afterGenerate';
    public const EVENT_GENERATE_ERROR = 'generateError';

    /**
     * @var string Ollama API URL
     */
    public string $apiUrl = 'http://localhost:11434/v1/completions';

    /**
     * @var string API key for authorization
     */
    public string $apiKey = '';

    /**
     * @var string Model name
     */
    public string $model = self::MODEL_LLAMA2;

    /**
     * @var float Temperature for generation
     */
    public float $temperature = 0.7;

    /**
     * @var int Maximum tokens to generate
     */
    public int $maxTokens = 512;

    /**
     * @var float Top-p sampling
     */
    public float $topP = 0.9;

    /**
     * @var array|null Stop sequences
     */
    public ?array $stop = null;

    /**
     * @var VectorDbInterface|null Optional vector DB for context
     */
    public ?VectorDbInterface $vectorDb = null;

    /**
     * @var int Number of top-k vector DB results to include
     */
    public int $vectorDbTopK = 5;

    /**
     * Initialize component
     *
     * @throws InvalidConfigException if PHP allow_url_fopen is disabled
     */
    public function init(): void
    {
        parent::init();

        if (!ini_get('allow_url_fopen')) {
            throw new InvalidConfigException(
                'PHP setting "allow_url_fopen" must be enabled to use OllamaComponent.'
            );
        }
    }

    /**
     * Generate completion from Ollama API
     *
     * Triggers the following events:
     * - EVENT_BEFORE_GENERATE
     * - EVENT_AFTER_GENERATE
     * - EVENT_GENERATE_ERROR
     *
     * @param string $prompt
     * @param array $options
     * @return array
     * @throws InvalidConfigException
     * @throws OllamaApiException
     */
    public function generate(string $prompt, array $options = []): array
    {
        // Trigger BEFORE event
        $beforeEvent = new Event();
        $beforeEvent->data = ['prompt' => $prompt, 'options' => $options];
        $this->trigger(self::EVENT_BEFORE_GENERATE, $beforeEvent);

        if (empty($this->apiUrl)) {
            throw new InvalidConfigException('Ollama API URL is not set.');
        }

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        // Include context from vector DB if available
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
        ], $options);

        if (!in_array($data['model'], self::SUPPORTED_MODELS)) {
            throw new InvalidConfigException('Unsupported model: ' . $data['model']);
        }

        $client = new Client(['transport' => CurlTransport::class]);

        try {
            $response = $client->createRequest()
                ->setMethod('POST')
                ->setUrl($this->apiUrl)
                ->setHeaders($headers)
                ->setData($data)
                ->setFormat(Client::FORMAT_JSON)
                ->send();

            if ($response->isOk) {
                // Trigger AFTER event
                $afterEvent = new Event();
                $afterEvent->data = [
                    'prompt' => $prompt,
                    'options' => $options,
                    'response' => $response->data,
                ];
                $this->trigger(self::EVENT_AFTER_GENERATE, $afterEvent);

                return $response->data;
            }

            throw new OllamaApiException('Ollama API returned error: ' . $response->statusCode);
        } catch (\Throwable $e) {
            // Trigger ERROR event
            $context = [
                'url' => $this->apiUrl,
                'model' => $this->model,
                'prompt' => $prompt,
                'options' => $options,
                'apiKeySet' => !empty($this->apiKey),
            ];
            $errorEvent = new Event();
            $errorEvent->data = ['exception' => $e, 'context' => $context];
            $this->trigger(self::EVENT_GENERATE_ERROR, $errorEvent);

            throw new OllamaApiException(
                'Ollama API request failed: ' . $e->getMessage() .
                '. Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                0,
                $e
            );
        }
    }

    /**
     * Generate text only (returns first choice)
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
     * Generate text along with token usage
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

        $text = $response['choices'][0]['text'] ?? '';
        $tokens = $response['usage'] ?? [];

        return [
            'text' => $text,
            'tokens' => $tokens,
        ];
    }
}
