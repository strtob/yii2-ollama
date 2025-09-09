<?php

namespace strtob\yii2Ollama\migrations;

use yii\db\Migration;

/**
 * Handles the creation of table `{{%documents}}`.
 */
class m230909_120000_create_documents_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%documents}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'title' => $this->string(255)->notNull(),
            'content' => $this->text(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        // Optional: add index for user_id
        $this->createIndex(
            'idx-documents-user_id',
            '{{%documents}}',
            'user_id'
        );

        // Optional: add foreign key to user table
        $this->addForeignKey(
            'fk-documents-user_id',
            '{{%documents}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropForeignKey('fk-documents-user_id', '{{%documents}}');
        $this->dropIndex('idx-documents-user_id', '{{%documents}}');
        $this->dropTable('{{%documents}}');
    }
}
