<?php

namespace strtob\yii2Ollama;

use Yii;
use yii\base\Component;
use yii\httpclient\Client;
use yii\base\InvalidConfigException;
use strtob\yii2Ollama\Adapter\VectorDbInterface;
use strtob\yii2Ollama\Exception\OllamaApiException;

/**
 * Yii2 component for Ollama API with optional vector DB support.
 *
 * === Example configuration in `config/web.php` ===
 *
 * ```php
 * $qdrantAdapter = new \strtob\yii2Ollama\QdrantAdapter($qdrantClient, 'my_collection');
 *
 * 'components' => [
 *     'ollama' => [
 *         'class' => 'strtob\yii2Ollama\OllamaComponent',
 *         'apiUrl' => 'http://localhost:11434/v1/generate',
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
 *     $text = \Yii::$app->ollama->generateText($prompt);
 *
 *     echo $text;
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
    // Model constants
    public const MODEL_LLAMA2 = 'llama2';
    public const MODEL_MISTRAL = 'mistral';
    public const MODEL_GEMMA = 'gemma';

    /** @var array List of supported models */
    public const SUPPORTED_MODELS = [
        self::MODEL_LLAMA2,
        self::MODEL_MISTRAL,
        self::MODEL_GEMMA,
    ];

    /** @var string API endpoint URL for Ollama */
    public string $apiUrl = 'http://localhost:11434/v1/completions';

    /** @var string API access token */
    public string $apiKey = '';

    /** @var string Default model to use for requests */
    public string $model = self::MODEL_LLAMA2;

    /** @var float Default temperature for text generation (0–1) */
    public float $temperature = 0.7;

    /** @var int Maximum tokens for a generated response */
    public int $maxTokens = 512;

    /** @var float Top-p (nucleus sampling) for token selection */
    public float $topP = 0.9;

    /** @var array|null Stop sequences to end text generation */
    public ?array $stop = null;

    /** @var VectorDbInterface|null Optional vector DB for RAG context */
    public ?VectorDbInterface $vectorDb = null;

    /** @var int Number of top vector DB results to include */
    public int $vectorDbTopK = 5;

    /**
     * Generate a full response array from Ollama API.
     *
     * If a vector DB is set, automatically injects top-K context into the prompt.
     *
     * @param string $prompt
     * @param array $options Overrides for model, temperature, max_tokens, top_p, stop
     * @return array
     * @throws InvalidConfigException
     * @throws OllamaApiException
     */
    public function generate(string $prompt, array $options = []): array
    {
        if (empty($this->apiUrl)) {
            throw new InvalidConfigException(Yii::t('yii2-ollama', 'Ollama API URL is not set.'));
        }

        // Authorization header optional
        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        // Inject vector DB context if available
        if ($this->vectorDb !== null) {
            $contextItems = $this->vectorDb->search($prompt, $this->vectorDbTopK);
            if (!empty($contextItems)) {
                $contextText = "Context:\n" . implode("\n", $contextItems) . "\n\n";
                $prompt = $contextText . "Question:\n" . $prompt;
            }
        }

        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
        ];


        // merge if exists
        if (!empty($options)) {
            $data = array_merge($data, $options);
        }

        // Validate model
        if (!in_array($data['model'], self::SUPPORTED_MODELS)) {
            throw new InvalidConfigException(Yii::t(
                'yii2-ollama',
                'Unsupported model: {model}',
                ['model' => $data['model']]
            ));
        }

        $client = new Client();

        try {
            $response = $client->createRequest()
                ->setMethod('POST')
                ->setUrl($this->apiUrl)
                ->setHeaders($headers)
                ->setData($data)
                ->setFormat(Client::FORMAT_JSON) // <<< wichtig!
                ->send();

            if ($response->isOk) {
                return $response->data;
            }

            throw new OllamaApiException(Yii::t(
                'yii2-ollama',
                'Ollama API returned error: {status} - {content}',
                ['status' => $response->statusCode, 'content' => $response->content]
            ));

        } catch (\Throwable $e) {
            // Build detailed context for debugging
            $context = [
                'url' => $this->apiUrl,
                'model' => $this->model,
                'prompt' => $prompt,
                'options' => $options,
                'apiKeySet' => !empty($this->apiKey),
            ];

            throw new OllamaApiException(Yii::t(
                'yii2-ollama',
                'Ollama API request failed: {message}. Context: {context}',
                [
                    'message' => $e->getMessage(),
                    'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                ]
            ), 0, $e);
        }

    }


    /**
     * Helper method to return only the generated text.
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
        return $response['text'] ?? '';
    }
}
