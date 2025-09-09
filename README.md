# Yii2 Ollama Component

Yii2 component for **Ollama API** with optional **vector database support** (e.g., Qdrant) for Retrieval-Augmented Generation (RAG).

## Features

- Connect to Ollama API (`llama2`, `mistral`, `gemma`)  
- Optional **vector DB integration** for context injection  
- Supports **Yii2 HTTP Client**  
- Easy configuration via Yii2 components  
- Multilingual exception messages (`Yii::t()`)

---

## Installation

Install via Composer:

```bash
composer require strtob/yii2-ollama
```
Ensure you have yiisoft/yii2-httpclient and a Qdrant PHP client installed if you want vector DB support.

## Configuration

Example `config/web.php` using a Qdrant adapter:

```php
use strtob\yii2Ollama\QdrantAdapter;

$qdrantAdapter = new QdrantAdapter($qdrantClient, 'my_collection');

'components' => [
    'ollama' => [
        'class' => 'strtob\yii2Ollama\OllamaComponent',
        'apiUrl' => 'http://localhost:11434/v1/generate',
        'apiKey' => 'MY_SECRET_TOKEN',
        'model' => \strtob\yii2Ollama\OllamaComponent::MODEL_MISTRAL,
        'temperature' => 0.7,
        'maxTokens' => 512,
        'topP' => 0.9,
        'vectorDb' => $qdrantAdapter, // must implement VectorDbInterface
        'vectorDbTopK' => 5,
    ],
];
```

## Usage in Controller

```
try {
    $prompt = "Explain RAG with vector DB.";

    $text = \Yii::$app->ollama->generateText($prompt);

    echo $text;

    //or

    print_r(generateTextWithTokens(string $prompt, $options = []))

} catch (\yii\base\InvalidConfigException $e) {
    echo Yii::t('yii2-ollama', 'Configuration error: {message}', ['message' => $e->getMessage()]);
} catch (\strtob\yii2Ollama\OllamaApiException $e) {
    echo Yii::t('yii2-ollama', 'API request failed: {message}', ['message' => $e->getMessage()]);
}
```

## Supported Models

- `llama2`  
- `mistral`  
- `gemma`  

---

## Vector DB Support

Implement the `VectorDbInterface` to use any vector database. Example Qdrant adapter:

```php
use strtob\yii2Ollama\VectorDbInterface;
use strtob\yii2Ollama\QdrantAdapter;

$qdrantAdapter = new QdrantAdapter($qdrantClient, 'my_collection');
```
OllamaComponent will automatically prepend top-K context from the vector DB to the prompt.

## License

MIT License – see [LICENSE](LICENSE)