<?php

namespace strtob\yii2Ollama;

use Yii;
use yii\base\Component;
use yii\httpclient\Client;
use yii\httpclient\CurlTransport;
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
    public const MODEL_LLAMA2 = 'llama2';
    public const MODEL_MISTRAL = 'mistral';
    public const MODEL_GEMMA = 'gemma';

    public const SUPPORTED_MODELS = [
        self::MODEL_LLAMA2,
        self::MODEL_MISTRAL,
        self::MODEL_GEMMA,
    ];

    public string $apiUrl = 'http://localhost:11434/v1/completions';
    public string $apiKey = '';
    public string $model = self::MODEL_LLAMA2;
    public float $temperature = 0.7;
    public int $maxTokens = 512;
    public float $topP = 0.9;
    public ?array $stop = null;
    public ?VectorDbInterface $vectorDb = null;
    public int $vectorDbTopK = 5;

    public function init(): void
    {
        parent::init();

        if (!ini_get('allow_url_fopen')) {
            throw new InvalidConfigException(
                'PHP setting "allow_url_fopen" must be enabled to use OllamaComponent.'
            );
        }
    }

    public function generate(string $prompt, array $options = []): array
    {
        if (empty($this->apiUrl)) {
            throw new InvalidConfigException('Ollama API URL is not set.');
        }

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

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
                return $response->data;
            }

            throw new OllamaApiException('Ollama API returned error: ' . $response->statusCode);
        } catch (\Throwable $e) {
            $context = [
                'url' => $this->apiUrl,
                'model' => $this->model,
                'prompt' => $prompt,
                'options' => $options,
                'apiKeySet' => !empty($this->apiKey),
            ];

            throw new OllamaApiException(
                'Ollama API request failed: ' . $e->getMessage() . '. Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                0,
                $e
            );
        }
    }

    /**
     * Return only the generated text.
     */
    public function generateText(string $prompt, array $options = []): string
    {
        $response = $this->generate($prompt, $options);
        
        return $response['choices'][0]['text'] ?? '';
    }

    /**
     * Return generated text along with token usage.
     *
     * @return array ['text' => string, 'tokens' => array]
     */
    public function generateTextWithTokens(string $prompt, array $options = []): array
    {
        $response = $this->generate($prompt, $options);

        $text = '';
        if (!empty($response['choices'][0]['text'])) {
            $text = $response['choices'][0]['text'];
        }

        $tokens = $response['usage'] ?? [];

        return [
            'text' => $text,
            'tokens' => $tokens,
        ];
    }
}
