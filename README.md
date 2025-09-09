# Yii2 Ollama Component

Yii2 component for **Ollama API** with optional **vector database support** (e.g., Qdrant) for Retrieval-Augmented Generation (RAG).

## Features

- Connect to Ollama API (`llama2`, `mistral`, `gemma`)  
- Optional **vector DB integration** for context injection  
- Supports **Yii2 HTTP Client**  
- Easy configuration via Yii2 components  
- Multilingual exception messages (`Yii::t()`)  
- **Events support**: `beforeGenerate`, `afterGenerate`, `generateError`  
- **Request and User** automatically included in events for easy logging and auditing

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

```php
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
---
## Supported Models

- `llama2`  
- `mistral`  
- `gemma`  

---

### Stream Modus

See example:

```php
 public function actionIndex()
    {
        $response = Yii::$app->response;
        $response->format = \yii\web\Response::FORMAT_RAW;
        Yii::$app->response->isSent = true;

        // Output-Puffer leeren
        while (ob_get_level())
            ob_end_clean();
        ob_implicit_flush(true);

        Yii::$app->ollama->stream = true;

        try {
            Yii::$app->ollama->generate("Whats up?", [], function ($chunk) {
                echo $chunk;
                flush();
            });
        } catch (\Throwable $e) {
            echo "\n[Error]: " . $e->getMessage();
        }

    }
```

# Yii2 Ollama Component – Events

`OllamaComponent` supports **three main events** during generation:

| Event | When Triggered | Data Included |
|-------|----------------|---------------|
| `beforeGenerate` | Before sending a request to the Ollama API | `prompt`, `options`, `request`, `user` |
| `afterGenerate` | After receiving a successful response | `prompt`, `options`, `request`, `user`, `response` |
| `generateError` | When an exception occurs during generation | `prompt`, `options`, `request`, `user`, `exception` |

### Event Data Details

- Connect to Ollama API (`llama2`, `mistral`, `gemma`)  
- Optional **vector DB integration** for context injection  
- Supports **Yii2 HTTP Client**  
- **Embedding generation** (`embedText`)  
- Easy configuration via Yii2 components  
- Multilingual exception messages (`Yii::t()`)  
- **Events support**: `beforeGenerate`, `afterGenerate`, `generateError`  
- **Request and User** automatically included in events for easy logging and auditing
 

---

### Example Usage

```php
// Log after generation
\Yii::$app->ollama->on(\strtob\yii2Ollama\OllamaComponent::EVENT_AFTER_GENERATE, function($event) {
    Yii::info("Prompt generated: {$event->data['prompt']}", 'ollama');
    Yii::info("User: " . ($event->data['user']->username ?? 'guest'), 'ollama');
});

// Handle errors
\Yii::$app->ollama->on(\strtob\yii2Ollama\OllamaComponent::EVENT_GENERATE_ERROR, function($event) {
    Yii::error("Generation failed for prompt: {$event->data['prompt']}", 'ollama');
    Yii::error("Exception: " . $event->data['exception']->getMessage(), 'ollama');
});
```
---


## Embedding Generation Usage

```php
try {
    $text = "Llamas are members of the camelid family.";
    
    $embeddingResult = \Yii::$app->ollama->embedText($text);
    
    echo "Embedding vector:\n";
    print_r($embeddingResult['embedding']); // Nur die Embeddings anzeigen

} catch (\yii\base\InvalidConfigException $e) {
    echo "Configuration error: " . $e->getMessage();
} catch (\strtob\yii2Ollama\OllamaApiException $e) {
    echo "Embedding request failed: " . $e->getMessage();
}


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
