<?php
/**
 * @copyright Copyright (c) Rareform
 */

namespace rareform\craftplunk;

use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use yii\base\Event;

/**
 * Plugin represents the Plunk plugin.
 */
class Plugin extends \craft\base\Plugin
{
    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $eventName = defined(sprintf('%s::EVENT_REGISTER_MAILER_TRANSPORT_TYPES', MailerHelper::class))
            ? MailerHelper::EVENT_REGISTER_MAILER_TRANSPORT_TYPES // Craft 4
            : MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS; // Craft 5+

        Event::on(
            MailerHelper::class,
            $eventName,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Adapter::class;
            }
        );
    }
}
