<?php
/**
 * @copyright Copyright (c) Rareform
 */

namespace rareform\craftplunk;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use rareform\craftplunk\transport\PlunkApiTransport;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Adapter represents the Plunk mail adapter.
 */
class Adapter extends BaseTransportAdapter
{
    public const DEFAULT_API_BASE_URL = 'https://api.useplunk.com';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Plunk';
    }

    /**
     * Plunk secret API key.
     */
    public ?string $apiKey = null;

    /**
     * Plunk API root URL.
     */
    public ?string $apiBaseUrl = self::DEFAULT_API_BASE_URL;

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'apiKey' => Craft::t('plunk', 'Secret API Key'),
            'apiBaseUrl' => Craft::t('plunk', 'API Base URL'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'apiKey',
                'apiBaseUrl',
            ],
        ];
        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['apiKey'], 'required'],
            [['apiKey', 'apiBaseUrl'], 'string'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('plunk/settings', [
            'adapter' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function defineTransport(): array|AbstractTransport
    {
        $apiBaseUrl = App::parseEnv($this->apiBaseUrl) ?: self::DEFAULT_API_BASE_URL;

        return new PlunkApiTransport(
            App::parseEnv($this->apiKey),
            $apiBaseUrl
        );
    }
}
