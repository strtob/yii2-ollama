<?php

namespace strtob\yii2Ollama\models;

use Yii;
use yii\db\ActiveRecord;
use strtob\yii2Ollama\helpers\VectorizerHelper;

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
 * // 1) Create a new document with uploaded file
 * $doc = new DocumentModel();
 * $doc->title = 'Sample PDF';
 * $doc->user_id = 1;
 * $doc->uploadedFile = UploadedFile::getInstance($model, 'uploadedFile');
 * $doc->save(); // automatically extracts text, creates embeddings
 *
 * // 2) Update existing document
 * $doc = DocumentModel::findOne($id);
 * $doc->title = 'Updated title';
 * $doc->uploadedFile = UploadedFile::getInstance($model, 'uploadedFile'); // optional new file
 * $doc->save(); // embeddings are updated automatically
 *
 * // 3) Delete a document
 * $doc = DocumentModel::findOne($id);
 * $doc->delete(); // corresponding vectors in vector DB are deleted
 *
 * // 4) Search for similar content
 * $vectorizer = new VectorizerHelper();
 * $results = $vectorizer->search('query text', 5); // returns top 5 similar text chunks
 * ```
 *
 * @package app\models
 *
 * @property int $id Primary key
 * @property int $user_id ID of the user who created the document
 * @property string $title Document title
 * @property string $content Text content of the document
 * @property \yii\web\UploadedFile|null $uploadedFile Temporary uploaded file
 */
class DocumentModel extends ActiveRecord
{
    /**
     * Temporary uploaded file
     *
     * @var \yii\web\UploadedFile|null
     */
    public $uploadedFile;

    /**
     * Yii component ID to use for table/embedding
     *
     * @var string
     */
    public static string $vectorComponent = 'ollama';

    /**
     * Returns the table name for the ActiveRecord.
     * If the configured Ollama component has a `table` property, that will be used.
     *
     * @return string The table name
     */
    public static function tableName(): string
    {
        $componentId = static::$vectorComponent;

        if (isset(\Yii::$app->$componentId) && !empty(\Yii::$app->$componentId->table)) {
            return '{{%' . \Yii::$app->$componentId->table . '}}';
        }

        return '{{%documents}}';
    }

    /**
     * Validation rules.
     *
     * @return array Validation rules array
     */
    public function rules(): array
    {
        return [
            [['title'], 'required'],
            [['content'], 'string'],
            [['uploadedFile'], 'file', 'skipOnEmpty' => true, 'extensions' => ['pdf', 'txt', 'docx']],
        ];
    }

    /**
     * Processes the uploaded file before saving.
     * Converts PDFs to text automatically.
     *
     * @param bool $insert Whether this is a new record insertion
     * @return bool Whether the record should be saved
     */
    public function beforeSave($insert): bool
    {
        if ($this->uploadedFile) {
            $ext = strtolower($this->uploadedFile->extension);
            if ($ext === 'pdf') {
                $this->content = VectorizerHelper::pdfToText($this->uploadedFile->tempName);
            } else {
                $this->content = file_get_contents($this->uploadedFile->tempName);
            }
        }

        return parent::beforeSave($insert);
    }

    /**
     * After saving the record, vectorizes the content and stores it in the vector database.
     * Works for both new inserts and updates.
     *
     * @param bool $insert Whether this is a new record insertion
     * @param array $changedAttributes Attributes that were changed
     */
    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);

        if (!empty($this->content)) {
            $vectorizer = new VectorizerHelper(); // Automatically uses Yii::$app->ollama->vectorDb
            $vectorizer->vectorizeAndStore($this->content, (string)$this->id);
        }
    }

    /**
     * Deletes the corresponding vector(s) in the vector database when the record is deleted.
     */
    public function afterDelete(): void
    {
        parent::afterDelete();

        if (Yii::$app->ollama->vectorDb) {
            Yii::$app->ollama->vectorDb->delete((string)$this->id);
        }
    }
}
