<?php

namespace strtob\yii2Ollama\models;

use Yii;
use yii\db\ActiveRecord;
use strtob\yii2Ollama\helpers\VectorizerHelper;
use yii\web\UploadedFile;

/**
 * Class DocumentModel
 *
 * ActiveRecord representing documents that are automatically embedded in a vector database (e.g., Qdrant).
 * Supports PDF, TXT, and DOCX file uploads.
 *
 * Vector embeddings are automatically updated on insert or update and deleted on record removal.
 *
 * Examples:
 *
 * ```php
 * $doc = new DocumentModel();
 * $doc->title = 'Sample PDF';
 * $doc->user_id = 1;
 * $doc->uploadedFile = UploadedFile::getInstance($model, 'uploadedFile');
 * $doc->save(); // automatically extracts text, creates embeddings
 *
 * // Update
 * $doc = DocumentModel::findOne($id);
 * $doc->title = 'Updated title';
 * $doc->uploadedFile = UploadedFile::getInstance($model, 'uploadedFile');
 * $doc->save(); // embeddings updated
 *
 * // Delete
 * $doc = DocumentModel::findOne($id);
 * $doc->delete(); // vectors removed
 *
 * // Search
 * $vectorizer = new VectorizerHelper();
 * $results = $vectorizer->search('query text', 5);
 * foreach ($results as $res) {
 *     echo "Text: " . $res['metadata']['text'] . " on page " . ($res['metadata']['page'] ?? 'N/A');
 * }
 * ```
 *
 * @package app\models
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $content
 * @property UploadedFile|null $uploadedFile
 */
class DocumentModel extends ActiveRecord
{
    public $uploadedFile;
    public static string $vectorComponent = 'ollama';

    public static function tableName(): string
    {
        $componentId = static::$vectorComponent;
        if (isset(\Yii::$app->$componentId) && !empty(\Yii::$app->$componentId->table)) {
            return '{{%' . \Yii::$app->$componentId->table . '}}';
        }
        return '{{%documents}}';
    }

    public function rules(): array
    {
        return [
            [['title'], 'required'],
            [['content'], 'string'],
            [['uploadedFile'], 'file', 'skipOnEmpty' => true, 'extensions' => ['pdf', 'txt', 'docx']],
        ];
    }

    public function beforeSave($insert): bool
    {
        if ($this->uploadedFile) {
            $ext = strtolower($this->uploadedFile->extension);
            if ($ext === 'pdf') {
                // Convert PDF to text (or keep chunks with bbox)
                $this->content = VectorizerHelper::pdfToText($this->uploadedFile->tempName);
            } else {
                $this->content = file_get_contents($this->uploadedFile->tempName);
            }
        }

        return parent::beforeSave($insert);
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);

        if (!empty($this->content)) {
            $vectorizer = new VectorizerHelper();

            if ($this->uploadedFile && strtolower($this->uploadedFile->extension) === 'pdf') {
                // Use PDF chunking with page + bbox
                $vectorizer->vectorizeAndStorePdf($this->uploadedFile->tempName, (string)$this->id);
            } else {
                // TXT/DOCX: simple chunking
                $vectorizer->vectorizeAndStore($this->content, (string)$this->id);
            }
        }
    }

    public function afterDelete(): void
    {
        parent::afterDelete();
        if (Yii::$app->ollama->vectorDb) {
            Yii::$app->ollama->vectorDb->delete((string)$this->id);
        }
    }
}
