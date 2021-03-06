<?php

namespace AppBundle\Service;

use AppBundle\Entity\ApiUser;
use AppBundle\Entity\RemotePushToken;
use Kreait\Firebase\Factory as FirebaseFactory;
use Kreait\Firebase\Exception\ServiceAccountDiscoveryFailed;
use Kreait\Firebase\Messaging\CloudMessage;
use Psr\Log\LoggerInterface;
use Pushok;

class RemotePushNotificationManager
{
    private $firebaseFactory;
    private $apnsClient;
    private static $enabled = true;
    private $logger;

    public function __construct(
        FirebaseFactory $firebaseFactory,
        Pushok\Client $apnsClient,
        LoggerInterface $logger)
    {
        $this->firebaseFactory = $firebaseFactory;
        $this->apnsClient = $apnsClient;
        $this->logger = $logger;
    }

    public static function isEnabled()
    {
        return self::$enabled;
    }

    public static function disable()
    {
        self::$enabled = false;
    }

    public static function enable()
    {
        self::$enabled = true;
    }

    /**
     * @see https://firebase.google.com/docs/cloud-messaging/http-server-ref
     */
    private function fcm($notification, array $tokens, $data)
    {
        if (count($tokens) === 0) {
            return;
        }

        try {
            $firebaseMessaging = $this->firebaseFactory->createMessaging();
        } catch (ServiceAccountDiscoveryFailed $e) {
            $this->logger->error($e);
            return;
        }

        // @see https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages
        // @see https://developer.android.com/guide/topics/ui/notifiers/notifications#ManageChannels
        $payload['android'] = [
            'priority' => 'high'
        ];

        if (null !== $notification) {
            $payload['notification'] = [
                'title' => $notification,
                'body' => $notification,
            ];
            $payload['android']['notification'] = [
                'sound' => 'default',
                'channel_id' => 'coopcycle_important',
            ];

            if (!empty($data) && array_key_exists('event', $data)) {
                $event = $data['event'];
                if (!empty($event['name'])) {
                    // set a tag, only one notification per tag could be shown
                    // in the notification center (new notifications replace old ones)
                    $payload['android']['notification']['tag'] = $event['name'];
                }
            }
        }

        // TODO Make sure data are key/value pairs as strings
        if (!empty($data)) {
            $dataFlat = [];
            foreach ($data as $key => $value) {
                if (!is_string($value)) {
                    $value = json_encode($value);
                }
                $dataFlat[$key] = $value;
            }

            $payload['data'] = $dataFlat;
        }

        $message = CloudMessage::fromArray($payload);

        $deviceTokens = array_map(function (RemotePushToken $token) {
            return $token->getToken();
        }, $tokens);

        // Make sure to have a zero-indexed array
        $deviceTokens = array_values($deviceTokens);

        try {
            $firebaseMessaging->sendMulticast($message, $deviceTokens);
        } catch (\Exception $e) {
            $this->logger->error($e);
        }
    }

    private function apns($text, array $tokens, $data = [])
    {
        if (count($tokens) === 0) {
            return;
        }

        $alert = Pushok\Payload\Alert::create()->setTitle($text);
        // $alert = $alert->setBody('Lorem ipsum');

        $payload = Pushok\Payload::create()->setAlert($alert);
        $payload->setSound('default');

        foreach ($data as $key => $value) {
            $payload->setCustomValue($key, $value);
        }

        $notifications = [];
        foreach ($tokens as $token) {
            $notifications[] = new Pushok\Notification($payload, $token->getToken());
        }

        $this->apnsClient->addNotifications($notifications);

        $this->apnsClient->push();
    }

    /**
     * @param string $textMessage
     * @param mixed $recipients
     */
    public function send($textMessage, $recipients, $data = [])
    {
        if (!is_array($recipients)) {
            $recipients = [ $recipients ];
        }

        $tokens = [];
        foreach ($recipients as $recipient) {
            if (!$recipient instanceof RemotePushToken && !$recipient instanceof ApiUser) {
                throw new \InvalidArgumentException(sprintf('$recipients must be an instance of %s or %s',
                    RemotePushToken::class, ApiUser::class));
            }

            if ($recipient instanceof RemotePushToken) {
                $tokens[] = $recipient;
            }
            if ($recipient instanceof ApiUser) {
                foreach ($recipient->getRemotePushTokens() as $remotePushToken) {
                    $tokens[] = $remotePushToken;
                }
            }
        }

        $fcmTokens = array_filter($tokens, function (RemotePushToken $token) {
            return $token->getPlatform() === 'android';
        });

        $apnsTokens = array_filter($tokens, function (RemotePushToken $token) {
            return $token->getPlatform() === 'ios';
        });

        //todo send both "notification+data" and "data-only" messages on android
        // until we figure out if we need to handle it differently
        // reasons:
        // 1. in the background android is able to handle only "data-only" messages
        // impact:
        // for the versions before this change - nothing, they don't handle "data-only" messages at all
        // for the versions after this change - implementation should expect to receive
        // both "notification+data" and "data-only" messages and handle them correctly

        $this->fcm($textMessage, $fcmTokens, $data); // send "notification+data" message
        $this->fcm(null, $fcmTokens, $data); // send "data-only" message

        $this->apns($textMessage, $apnsTokens, $data);
    }
}
